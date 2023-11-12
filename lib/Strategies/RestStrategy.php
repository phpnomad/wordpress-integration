<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Integrations\WordPress\Rest\Request;
use PHPNomad\Integrations\WordPress\Rest\Response;
use PHPNomad\Rest\Enums\Method;
use PHPNomad\Rest\Exceptions\ValidationException;
use PHPNomad\Rest\Interfaces\Controller;
use PHPNomad\Rest\Interfaces\HasMiddleware;
use PHPNomad\Rest\Interfaces\HasRestNamespace;
use PHPNomad\Rest\Interfaces\HasValidations;
use PHPNomad\Rest\Interfaces\Middleware;
use PHPNomad\Rest\Interfaces\RestStrategy as CoreRestStrategy;
use PHPNomad\Rest\Interfaces\Validation;
use PHPNomad\Utils\Helpers\Arr;
use WP_REST_Request;
use WP_REST_Response;

class RestStrategy implements CoreRestStrategy
{
    /**
     * @var HasRestNamespace
     */
    protected HasRestNamespace $restNamespaceProvider;

    public function __construct(HasRestNamespace $restNamespaceProvider)
    {
        $this->restNamespaceProvider = $restNamespaceProvider;
    }

    protected function validate(HasValidations $validations, Request $request)
    {
        foreach ($validations->getValidations() as $key => $validations) {
            /** @var Validation $validation */
            foreach ($validations as $validation) {
                $validation->isValid($key, $request);
            }
        }
    }

    /**
     * @param Controller $controller
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     * @throws ValidationException
     */
    private function wrapCallback(Controller $controller, WP_REST_Request $request): WP_REST_Response
    {
        $request = Request::fromRequest($request);

        if ($controller instanceof HasValidations) {
            $this->validate($controller, $request);
        }

        // Maybe process middleware.
        if ($controller instanceof HasMiddleware) {
            Arr::each($controller->getMiddleware(), fn(Middleware $middleware) => $middleware->process($request));
        }

        /** @var Response $response */
        $response = $controller->getResponse($request);

        return $response->getResponse();
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
                    'callback' => fn(WP_REST_Request $request) => $this->wrapCallback($controller, $request),
                ]
            );
        });
    }

    /** @inheritDoc */
    public function get(Controller $controller): void
    {
        $this->registerRoute($controller, Method::Get);
    }

    /** @inheritDoc */
    public function post(Controller $controller): void
    {
        $this->registerRoute($controller, Method::Post);
    }

    /** @inheritDoc */
    public function put(Controller $controller): void
    {
        $this->registerRoute($controller, Method::Put);
    }

    /** @inheritDoc */
    public function delete(Controller $controller): void
    {
        $this->registerRoute($controller, Method::Delete);
    }

    /** @inheritDoc */
    public function patch(Controller $controller): void
    {
        $this->registerRoute($controller, Method::Patch);
    }

    /** @inheritDoc */
    public function options(Controller $controller): void
    {
        $this->registerRoute($controller, Method::Options);
    }
}
