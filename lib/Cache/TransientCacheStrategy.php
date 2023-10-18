<?php

namespace Phoenix\Integrations\WordPress\Cache;

use cache\lib\Traits\WithExistsCheck;
use Phoenix\Cache\Exceptions\CachedItemNotFoundException;
use Phoenix\Cache\Interfaces\PersistentCacheStrategy;
use Phoenix\Cache\Traits\CanLoadCacheTrait;

class TransientCacheStrategy implements PersistentCacheStrategy
{
    use CanLoadCacheTrait;
    use WithExistsCheck;

    /** @inheritDoc */
    public function get(string $key)
    {
        $result = get_transient($key);

        if (false === $result) {
            throw new CachedItemNotFoundException();
        }

        return $result;
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