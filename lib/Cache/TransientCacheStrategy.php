<?php

namespace Phoenix\Integrations\WordPress\Cache;

use Phoenix\Cache\Interfaces\PersistentCacheStrategy;
use Phoenix\Cache\Traits\CanLoadCacheTrait;

class TransientCacheStrategy implements PersistentCacheStrategy
{
    use CanLoadCacheTrait;

    /** @inheritDoc */
    public function get(string $key)
    {
        return get_transient($key);
    }

    /** @inheritDoc */
    public function set(string $key, $value, ?int $ttl): void
    {
        set_transient($key, $value, $ttl);
    }

    /** @inheritDoc */
    public function delete(string $key): void
    {
        delete_transient($key);
    }
}