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
     * callback, mapped to the middleware outcome (true, or the caught
     * non-auth RestException to be re-thrown by the route callback so
     * clients keep the rich legacy error shape).
     *
     * @var WeakMap<WP_REST_Request, true|RestException>
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

        $request = $this->resolveRequest($wpRequest);

        try {
            Arr::each($controller->getMiddleware($request), fn(Middleware $middleware) => $middleware->process($request));
            $this->middlewareOutcomes[$wpRequest] = true;
        } catch (RestException $e) {
            if (in_array($e->getCode(), [401, 403], true)) {
                return new WP_Error(
                    $e->getCode() === 401 ? 'rest_not_logged_in' : 'rest_forbidden',
                    $e->getMessage(),
                    ['status' => $e->getCode()]
                );
            }

            $this->middlewareOutcomes[$wpRequest] = $e;
        } catch (Exception $e) {
            $this->logger->logException($e);

            return new WP_Error('rest_unexpected_error', 'An unexpected error occurred.', ['status' => 500]);
        }

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
        $outcome = $wpRequest !== null ? ($this->middlewareOutcomes[$wpRequest] ?? null) : null;

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
