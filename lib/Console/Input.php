<?php

namespace PHPNomad\Integrations\WordPress\Console;

use PHPNomad\Console\Interfaces\Input as CoreInput;
use PHPNomad\Utils\Helpers\Arr;

/**
 * WP-CLI Input decorator for PHPNomad console commands.
 *
 * Holds resolved param values in a flat array.
 * Does not handle mapping from $args or $assoc_args — that is handled by the ConsoleStrategy.
 */
class Input implements CoreInput
{
    protected array $params = [];

    /**
     * @param array<string, mixed> $params Fully resolved input parameters
     */
    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    /** @inheritDoc */
    public function getParam(string $name, mixed $default = null): mixed
    {
        return Arr::get($this->params, $name, $default);
    }

    /** @inheritDoc */
    public function hasParam(string $name): bool
    {
        return array_key_exists($name, $this->params);
    }

    /** @inheritDoc */
    public function setParam(string $name, mixed $value): static
    {
        $this->params[$name] = $value;
        return $this;
    }

    /** @inheritDoc */
    public function removeParam(string $name): static
    {
        unset($this->params[$name]);
        return $this;
    }

    /** @inheritDoc */
    public function getParams(): array
    {
        return $this->params;
    }

    /** @inheritDoc */
    public function replaceParams(array $params): static
    {
        $this->params = $params;
        return $this;
    }
}