<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Cache\Interfaces\CacheStrategy;
use Phoenix\Cache\Interfaces\InMemoryCacheStrategy;
use Phoenix\Cache\Interfaces\PersistentCacheStrategy;
use Phoenix\Core\Bootstrap\Interfaces\Initializer;
use Phoenix\Events\Interfaces\EventStrategy as CoreEventStrategy;
use Phoenix\Database\Interfaces\QueryBuilder as CoreQueryBuilder;
use Phoenix\Integrations\WordPress\Cache\ObjectCacheStrategy;
use Phoenix\Integrations\WordPress\Cache\TransientCacheStrategy;
use Phoenix\Integrations\WordPress\Database\QueryBuilder;
use Phoenix\Rest\Interfaces\RestStrategy as CoreRestStrategy;

class WordPressInitializer implements Initializer
{
    public const REQUIRED_WORDPRESS_VERSION = '6.3.1';

    /**
     * @return array
     */
    public function getClassDefinitions(): array
    {
        return [
            EventStrategy::class => CoreEventStrategy::class,
            TransientCacheStrategy::class => PersistentCacheStrategy::class,
            ObjectCacheStrategy::class => [CacheStrategy::class, InMemoryCacheStrategy::class],
            QueryBuilder::class => CoreQueryBuilder::class,
            RestStrategy::class => CoreRestStrategy::class
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

        return isset($wp_version) && version_compare($wp_version, static::REQUIRED_WORDPRESS_VERSION, '>=');
    }

    public function init(): void
    {
        // No-op
    }

    public function getConfigDirectories(): array
    {
        return [];
    }
}
