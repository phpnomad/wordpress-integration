<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Cache\Interfaces\CacheStrategy;
use Phoenix\Cache\Interfaces\InMemoryCacheStrategy;
use Phoenix\Cache\Interfaces\PersistentCacheStrategy;
use Phoenix\Database\Interfaces\DatabaseStrategy as CoreDatabaseStrategy;
use Phoenix\Database\Interfaces\HasDatabaseDefaultCacheTtl;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;
use Phoenix\Events\Interfaces\EventStrategy as CoreEventStrategy;
use Phoenix\Database\Interfaces\QueryBuilder as CoreQueryBuilder;
use Phoenix\Integrations\WordPress\Cache\ObjectCacheStrategy;
use Phoenix\Integrations\WordPress\Cache\TransientCacheStrategy;
use Phoenix\Integrations\WordPress\Database\QueryBuilder;
use Phoenix\Integrations\WordPress\Providers\DatabaseProvider;
use Phoenix\Loader\Interfaces\HasClassDefinitions;
use Phoenix\Loader\Interfaces\HasLoadCondition;
use Phoenix\Rest\Interfaces\RestStrategy as CoreRestStrategy;

class WordPressInitializer implements HasLoadCondition, HasClassDefinitions
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
            DatabaseStrategy::class => CoreDatabaseStrategy::class,
            QueryBuilder::class => CoreQueryBuilder::class,
            RestStrategy::class => CoreRestStrategy::class,
            DatabaseProvider::class => [HasDatabaseDefaultCacheTtl::class, HasGlobalDatabasePrefix::class]
        ];
    }

    /**
     * Determines if this site meets the minimum criteria for this plugin to function.
     *
     * @return bool
     */
    public function shouldLoad(): bool
    {
        global $wp_version;

        return isset($wp_version) && version_compare($wp_version, static::REQUIRED_WORDPRESS_VERSION, '>=');
    }
}
