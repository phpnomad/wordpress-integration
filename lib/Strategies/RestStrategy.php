<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Core\Repositories\Config;
use Phoenix\Integrations\WordPress\Rest\Request;
use Phoenix\Rest\Interfaces\Request as CoreRequest;
use Phoenix\Rest\Interfaces\Response;
use Phoenix\Rest\Interfaces\RestStrategy as CoreRestStrategy;
use Phoenix\Rest\Interfaces\Validation;
use Phoenix\Utils\Helpers\Arr;
use WP_REST_Request;
use WP_REST_Response;

class RestStrategy implements CoreRestStrategy
{
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
                'validation_callback' => function($param, $request, $key) use ($validation) {
                    return $validation->isValid($key, Request::fromRequest($request));
                }
            ];
        });
    }

    /**
     * @param callable(CoreRequest $request): Response $callback
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    private function wrapCallback(callable $callback, WP_REST_Request $request): WP_REST_Response
    {
        $response = $callback(Request::fromRequest($request));

        return $response->getResponse();
    }

    /**
     * Register a route.
     *
     * @param string $endpoint
     * @param array $validations
     * @param callable(CoreRequest $request): Response $callback
     * @param string $method
     * @return void
     */
    protected function registerRoute(string $endpoint, array $validations, callable $callback, string $method)
    {
        add_action('rest_api_init', function () use ($endpoint, $validations, $callback, $method) {
            $namespace = Config::get('core.rest.namespace') . '/' . Config::get('core.rest.version');
            register_rest_route($namespace, $endpoint, [
                'methods' => $method,
                'callback' => function (WP_REST_Request $request) use ($callback) {
                    return $this->wrapCallback($callback, $request);
                },
                'args' => [
                    $this->convertValidationsToArgs($validations)
                ]
            ]);
        });
    }

    /** @inheritDoc */
    public function get(string $endpoint, array $validations, callable $callback): void
    {
        $this->registerRoute($endpoint, $validations, $callback, 'GET');
    }

    /** @inheritDoc */
    public function post(string $endpoint, array $validations, callable $callback): void
    {
        $this->registerRoute($endpoint, $validations, $callback, 'POST');
    }

    /** @inheritDoc */
    public function put(string $endpoint, array $validations, callable $callback): void
    {
        $this->registerRoute($endpoint, $validations, $callback, 'PUT');
    }

    /** @inheritDoc */
    public function delete(string $endpoint, array $validations, callable $callback): void
    {
        $this->registerRoute($endpoint, $validations, $callback, 'DELETE');
    }

    /** @inheritDoc */
    public function patch(string $endpoint, array $validations, callable $callback): void
    {
        $this->registerRoute($endpoint, $validations, $callback, 'PATCH');
    }

    /** @inheritDoc */
    public function options(string $endpoint, array $validations, callable $callback): void
    {
        $this->registerRoute($endpoint, $validations, $callback, 'OPTIONS');
    }
}
