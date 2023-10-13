<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Integrations\WordPress\Rest\Request;
use Phoenix\Rest\Interfaces\Handler;
use Phoenix\Rest\Interfaces\HasRestNamespace;
use Phoenix\Rest\Interfaces\RestStrategy as CoreRestStrategy;
use Phoenix\Rest\Interfaces\Validation;
use Phoenix\Utils\Helpers\Arr;
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
    /**
     * Converts
     *
     * @param array $validations
     * @return array
     */
    protected function convertValidationsToArgs(array $validations): array
    {
        return Arr::each($validations, function (Validation $validation) {
            return [
                'validation_callback' => function ($param, $request, $key) use ($validation) {
                    return $validation->isValid($key, Request::fromRequest($request));
                }
            ];
        });
    }

    /**
     * @param Handler $handler
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    private function wrapCallback(Handler $handler, WP_REST_Request $request): WP_REST_Response
    {
        /** @var \Phoenix\Integrations\WordPress\Rest\Response $response */
        $response = $handler->getResponse(Request::fromRequest($request));

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

    /**
     * Register a route.
     *
     * @param string $endpoint
     * @param array $validations
     * @param Handler $handler
     * @param string $method
     * @return void
     */
    protected function registerRoute(string $endpoint, array $validations, Handler $handler, string $method)
    {
        add_action('rest_api_init', function () use ($endpoint, $validations, $handler, $method) {
            register_rest_route($this->restNamespaceProvider->getRestNamespace(), $this->convertEndpointFormat($endpoint), [
                'methods' => $method,
                'callback' => function (WP_REST_Request $request) use ($handler) {
                    return $this->wrapCallback($handler, $request);
                },
                'args' => [
                    $this->convertValidationsToArgs($validations)
                ]
            ]);
        });
    }

    /** @inheritDoc */
    public function get(string $endpoint, array $validations, Handler $handler): void
    {
        $this->registerRoute($endpoint, $validations, $handler, 'GET');
    }

    /** @inheritDoc */
    public function post(string $endpoint, array $validations, Handler $handler): void
    {
        $this->registerRoute($endpoint, $validations, $handler, 'POST');
    }

    /** @inheritDoc */
    public function put(string $endpoint, array $validations, Handler $handler): void
    {
        $this->registerRoute($endpoint, $validations, $handler, 'PUT');
    }

    /** @inheritDoc */
    public function delete(string $endpoint, array $validations, Handler $handler): void
    {
        $this->registerRoute($endpoint, $validations, $handler, 'DELETE');
    }

    /** @inheritDoc */
    public function patch(string $endpoint, array $validations, Handler $handler): void
    {
        $this->registerRoute($endpoint, $validations, $handler, 'PATCH');
    }

    /** @inheritDoc */
    public function options(string $endpoint, array $validations, Handler $handler): void
    {
        $this->registerRoute($endpoint, $validations, $handler, 'OPTIONS');
    }
}
