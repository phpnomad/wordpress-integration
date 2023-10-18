<?php

namespace Phoenix\Integrations\WordPress\Cache;

use Phoenix\Cache\Exceptions\CachedItemNotFoundException;
use Phoenix\Cache\Interfaces\InMemoryCacheStrategy;
use Phoenix\Cache\Traits\CanLoadCacheTrait;
use Phoenix\Cache\Traits\WithExistsCheck;

class ObjectCacheStrategy implements InMemoryCacheStrategy
{
    use CanLoadCacheTrait;
    use WithExistsCheck;

    /** @inheritDoc */
    public function get(string $key)
    {
        $found = false;
        $cache = wp_cache_get($key, '', false, $found);

        if (!$found) {
            throw new CachedItemNotFoundException('Cached item ' . $key . ' Was not found');
        }

        return $cache;
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