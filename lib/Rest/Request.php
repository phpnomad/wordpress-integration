<?php

namespace PHPNomad\Integrations\WordPress\Rest;

use PHPNomad\Auth\Interfaces\User;
use PHPNomad\Http\Interfaces\Request as CoreRequest;
use PHPNomad\Utils\Helpers\Arr;
use WP_REST_Request;

final class Request implements CoreRequest
{
    private WP_REST_Request $request;
    protected ?User $user;

    public function __construct(WP_REST_Request $request, ?User $user = null)
    {
        $this->request = $request;
        $this->user = $user;
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
        return Arr::dot($this->request->get_params(), $name);
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
     * Returns true when the client sent the param, even when it sent it as null.
     *
     * A dot in the name walks into nested arrays, the same way getParam() reads
     * them. Keys are matched with array_key_exists rather than isset, so a body
     * like {"description": null} reads as "sent", which is what a client means
     * when it clears a field. This matches WP_REST_Request::has_param().
     *
     * @inheritDoc
     */
    public function hasParam(string $name): bool
    {
        $cursor = $this->request->get_params();

        foreach (explode('.', $name) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    public function removeParam(string $name): void
    {
        $this->request->offsetUnset($name);
    }

    public function getBody(): string
    {
        return $this->request->get_body();
    }
}
