<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use Exception;
use PHPNomad\Auth\Interfaces\CurrentUserResolverStrategy;
use PHPNomad\Integrations\WordPress\Rest\Request as WordPressRequest;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\Rest\Exceptions\RestException;
use PHPNomad\Rest\Interfaces\Controller;
use PHPNomad\Rest\Interfaces\HasInterceptors;
use PHPNomad\Rest\Interfaces\HasMiddleware;
use PHPNomad\Rest\Interfaces\HasRestNamespace;
use PHPNomad\Rest\Interfaces\Interceptor;
use PHPNomad\Rest\Interfaces\Middleware;
use PHPNomad\Http\Interfaces\Request;
use PHPNomad\Http\Interfaces\Response;
use PHPNomad\Rest\Interfaces\RestStrategy as CoreRestStrategy;
use PHPNomad\Utils\Helpers\Arr;
use WeakMap;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

//TODO: EXTRACT VALIDATION AND MIDDLEWARE SO THIS LOGIC CAN BE RE-USED IN OTHER SERVICES.
class RestStrategy implements CoreRestStrategy
{
    /**
     * @var HasRestNamespace
     */
    protected HasRestNamespace $restNamespaceProvider;
    protected Response $response;
    protected CurrentUserResolverStrategy $currentUserResolver;
    protected LoggerStrategy $logger;

    /**
     * Per-WP_REST_Request memoization so the permission callback and the
     * route callback operate on the SAME framework request instance, and
     * the middleware chain runs exactly once per request (middleware may
     * mutate request state or perform lookups).
     *
     * @var WeakMap<WP_REST_Request, Request>
     */
    private WeakMap $resolvedRequests;

    /**
     * Requests whose middleware chain already ran in the permission
     * callback, mapped per controller class to the middleware outcome
     * (true, the caught non-auth RestException to be re-thrown by the
     * route callback so clients keep the rich legacy error shape, or
     * the WP_Error returned for auth rejections).
     *
     * Keyed by controller class because WordPress invokes the permission
     * callback of EVERY method registered on a matched route for the same
     * WP_REST_Request (rest_send_allow_header does this to build the
     * Allow header), and one controller's outcome must not clobber
     * another's. Memoized so the chain runs at most once per controller
     * per request — middleware is not required to be idempotent (e.g.
     * CSV conversion mutates request params).
     *
     * @var WeakMap<WP_REST_Request, array<class-string, true|RestException|WP_Error>>
     */
    private WeakMap $middlewareOutcomes;

    public function __construct(HasRestNamespace $restNamespaceProvider, Response $response, CurrentUserResolverStrategy $currentUserResolverStrategy, LoggerStrategy $loggerStrategy)
    {
        $this->logger = $loggerStrategy;
        $this->restNamespaceProvider = $restNamespaceProvider;
        $this->response = $response;
        $this->currentUserResolver = $currentUserResolverStrategy;
        $this->resolvedRequests = new WeakMap();
        $this->middlewareOutcomes = new WeakMap();
    }

    private function resolveRequest(WP_REST_Request $wpRequest): Request
    {
        if (!isset($this->resolvedRequests[$wpRequest])) {
            $this->resolvedRequests[$wpRequest] = new WordPressRequest($wpRequest, $this->currentUserResolver->getCurrentUser());
        }

        return $this->resolvedRequests[$wpRequest];
    }

    /**
     * WordPress-level permission gate derived from the controller's own
     * middleware — no controller contract change. The chain runs here,
     * once; authorization rejections (401/403) surface as WP_Error so
     * WordPress treats the route as protected (and stops advertising it
     * as public). Non-auth middleware failures (validation, existence)
     * are stored and re-thrown by the route callback, preserving the
     * legacy error payload shape clients rely on.
     *
     * @return true|WP_Error
     */
    private function checkPermissions(Controller $controller, WP_REST_Request $wpRequest)
    {
        if (!$controller instanceof HasMiddleware) {
            return true;
        }

        // WordPress invokes EVERY method's permission callback on the
        // matched route while rest_send_allow_header builds the Allow
        // header — including methods that are not being dispatched. Running
        // another verb's middleware against a request shaped for this verb
        // produces spurious validation failures, null-param warnings, and
        // pointless queries. Only the dispatching method's callback can
        // run, so only its middleware outcome matters; advertise the
        // method and let a real request of that verb run its own chain.
        if (strtoupper($wpRequest->get_method()) !== strtoupper($controller->getMethod())) {
            return true;
        }

        $outcomes = $this->middlewareOutcomes[$wpRequest] ?? [];
        $controllerClass = get_class($controller);

        // Already ran for this controller — return the same answer without
        // re-running the chain. WordPress calls this more than once per
        // request (dispatch, then rest_send_allow_header for every method
        // on the route), and middleware may not be idempotent.
        if (array_key_exists($controllerClass, $outcomes)) {
            $stored = $outcomes[$controllerClass];

            return $stored instanceof WP_Error ? $stored : true;
        }

        $request = $this->resolveRequest($wpRequest);

        try {
            Arr::each($controller->getMiddleware($request), fn(Middleware $middleware) => $middleware->process($request));
            $outcomes[$controllerClass] = true;
        } catch (RestException $e) {
            if (in_array($e->getCode(), [401, 403], true)) {
                $error = new WP_Error(
                    $e->getCode() === 401 ? 'rest_not_logged_in' : 'rest_forbidden',
                    $e->getMessage(),
                    ['status' => $e->getCode()]
                );
                $outcomes[$controllerClass] = $error;
                $this->middlewareOutcomes[$wpRequest] = $outcomes;

                return $error;
            }

            $outcomes[$controllerClass] = $e;
        } catch (\Throwable $e) {
            // \Throwable, not Exception: a TypeError thrown by middleware
            // must surface as a 500, not fatal the whole request before
            // WordPress can serve a body. logException() takes Exception,
            // so non-Exception throwables are wrapped.
            $this->logger->logException(
                $e instanceof Exception ? $e : new Exception($e->getMessage(), (int) $e->getCode(), $e)
            );

            return new WP_Error('rest_unexpected_error', 'An unexpected error occurred.', ['status' => 500]);
        }

        $this->middlewareOutcomes[$wpRequest] = $outcomes;

        return true;
    }

    /**
     * @param Controller $controller
     * @param Response $request
     * @return WP_REST_Response
     * @throws RestException
     */
    private function wrapCallback(Controller $controller, Request $request, ?WP_REST_Request $wpRequest = null): Response
    {
        $outcomes = $wpRequest !== null ? ($this->middlewareOutcomes[$wpRequest] ?? []) : [];
        $outcome = $outcomes[get_class($controller)] ?? null;

        if ($outcome instanceof RestException) {
            // Middleware already ran in the permission callback and failed
            // with a non-auth error — surface it through the legacy formatter.
            throw $outcome;
        }

        // Run middleware only if the permission callback didn't already.
        if ($outcome !== true && $controller instanceof HasMiddleware) {
            Arr::each($controller->getMiddleware($request), fn(Middleware $middleware) => $middleware->process($request));
        }

        /** @var \PHPNomad\Integrations\WordPress\Rest\Response $response */
        $response = $controller->getResponse($request);

        // Maybe process interceptors.
        if ($controller instanceof HasInterceptors) {
            Arr::each($controller->getInterceptors($request, $response), fn(Interceptor $interceptor) => $interceptor->process($request, $response));
        }

        return $response;
    }

    /**
     * Converts the endpoint format to match WordPress format.
     *
     * @param string $input
     * @return string
     */
    private function convertEndpointFormat(string $input): string
    {
        return preg_replace('/\{([\w-]+)}/', '(?P<$1>[\w-]+)', $input);
    }

    protected function handleRequest(Controller $controller, Request $request, ?WP_REST_Request $wpRequest = null)
    {
        try {
            $response = $this->wrapCallback($controller, $request, $wpRequest);
        } catch (RestException $e) {
            $response = $this->response->setStatus($e->getCode())->setJson(
                [
                    'error' => [
                        'message' => $e->getMessage(),
                        'context' => $e->getContext()
                    ],
                ]
            );
        } catch(Exception $e){
            $this->logger->logException($e);
            $response = $this->response->setStatus(500)->setJson(
        		[
        			'error' => [
        				'message' => 'An unexpected error occurred. Sorry, that is all I know.',
        			],
        		]
        	);
        }

        return $response->getResponse();
    }

    /** @inheritDoc */
    public function registerRoute(callable $controllerGetter)
    {
        add_action('rest_api_init', function () use ($controllerGetter) {
            /** @var Controller $controller */
            $controller = $controllerGetter();

            register_rest_route(
                $this->restNamespaceProvider->getRestNamespace(),
                $this->convertEndpointFormat($controller->getEndpoint()),
                [
                    'methods' => $controller->getMethod(),
                    'callback' => fn(WP_REST_Request $request) => $this->handleRequest($controller, $this->resolveRequest($request), $request),
                    'permission_callback' => fn(WP_REST_Request $request) => $this->checkPermissions($controller, $request),
                ]
            );
        });
    }
}
