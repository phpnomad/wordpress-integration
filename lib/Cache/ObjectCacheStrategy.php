<?php

namespace Phoenix\Integrations\WordPress\Cache;

use Phoenix\Cache\Interfaces\InMemoryCacheStrategy;

class ObjectCacheStrategy implements InMemoryCacheStrategy
{
    /** @inheritDoc */
    public function get(string $key)
    {
        return wp_cache_get($key);
    }

    /** @inheritDoc */
    public function set(string $key, $value, ?int $ttl): void
    {
        wp_cache_set($key, $value, '', $ttl);
    }

    /** @inheritDoc */
    public function delete(string $key): void
    {
        wp_cache_delete($key);
    }
}