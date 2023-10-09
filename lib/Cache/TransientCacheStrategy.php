<?php

namespace Phoenix\Integrations\WordPress\Cache;

use Phoenix\Cache\Interfaces\PersistentCacheStrategy;

class TransientCacheStrategy implements PersistentCacheStrategy
{
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