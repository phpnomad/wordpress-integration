<?php

namespace PHPNomad\Integrations\WordPress\Rest;

use PHPNomad\Rest\Interfaces\Request as CoreRequest;
use WP_REST_Request;

final class Request implements CoreRequest
{
    private WP_REST_Request $request;

    public function __construct(WP_REST_Request $request)
    {
        $this->request = $request;
    }

    /** @inheritDoc */
    public function getHeader(string $name): ?string
    {
        return $this->request->get_header($name);
    }

    /** @inheritDoc */
    public function setHeader(string $name, $value): void
    {
        $this->request->set_header($name, $value);
    }

    /** @inheritDoc */
    public function getHeaders(): array
    {
        return $this->request->get_headers();
    }

    /** @inheritDoc */
    public function getParam(string $name)
    {
        return $this->request->get_param($name);
    }

    /** @inheritDoc */
    public function setParam(string $name, $value): void
    {
        $this->request->set_param($name, $value);
    }

    /** @inheritDoc */
    public function getParams(): array
    {
        return $this->request->get_params();
    }

    /**
     * @return WP_REST_Request
     */
    public function getRequest(): WP_REST_Request
    {
        return $this->request;
    }

    /**
     * @inheritDoc
     */
    public function hasParam(string $name): bool
    {
        return isset($this->request->get_params()[$name]);
    }
}
