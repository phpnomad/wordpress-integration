<?php

namespace Phoenix\Integrations\WordPress\Cache;

use Phoenix\Cache\Exceptions\CachedItemNotFoundException;
use Phoenix\Cache\Interfaces\PersistentCacheStrategy;
use Phoenix\Cache\Traits\CanLoadCacheTrait;
use Phoenix\Cache\Traits\WithExistsCheck;

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

    /** @inheritDoc */
    public function clear(): void
    {
        global $wpdb;

        $sql = "SELECT `option_name` AS `name`
            FROM  $wpdb->options
            WHERE `option_name` LIKE '%\_transient\_%'";
        $transients = $wpdb->get_results( $sql );

        foreach( $transients as $transient ) {
            $key = str_replace('_transient_', '', $transient->name);
            delete_transient($key);
        }
    }
}