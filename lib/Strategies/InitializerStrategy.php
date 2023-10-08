<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Cache\Interfaces\InMemoryCacheStrategy;
use Phoenix\Cache\Interfaces\PersistentCacheStrategy;
use Phoenix\Core\Bootstrap\Abstracts\BaseInitializer;
use Phoenix\Events\Interfaces\EventStrategy as CoreEventStrategy;
use Phoenix\Database\Interfaces\QueryBuilder as CoreQueryBuilder;
use Phoenix\Integrations\WordPress\Cache\ObjectCacheStrategy;
use Phoenix\Integrations\WordPress\Cache\TransientCacheStrategy;
use Phoenix\Integrations\WordPress\Database\QueryBuilder;

class InitializerStrategy extends BaseInitializer
{
    public const REQUIRED_PHP_VERSION = '7.4';

    public const REQUIRED_WORDPRESS_VERSION = '6.3.1';

    /**
     * @return array
     */
    public function getClassDefinitions(): array
    {
        return [
            CoreEventStrategy::class => EventStrategy::class,
            PersistentCacheStrategy::class => TransientCacheStrategy::class,
            InMemoryCacheStrategy::class => ObjectCacheStrategy::class,
            CoreQueryBuilder::class => QueryBuilder::class
        ];
    }

    /**
     * Determines if this site meets the minimum criteria for this plugin to function.
     *
     * @return bool
     */
    public function requirementsMet(): bool
    {
        global $wp_version;
        if (version_compare(phpversion(), static::REQUIRED_PHP_VERSION, '<')) {
            return false;
        }

        if (!isset($wp_version) || version_compare($wp_version, static::REQUIRED_WORDPRESS_VERSION, '<')) {
            return false;
        }

        return true;
    }
}
