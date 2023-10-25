<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Cache\Interfaces\CacheStrategy;
use Phoenix\Cache\Interfaces\HasDefaultTtl;
use Phoenix\Database\Interfaces\CanConvertDatabaseStringToDateTime;
use Phoenix\Database\Interfaces\CanConvertToDatabaseDateString;
use Phoenix\Database\Interfaces\HasCharsetProvider;
use Phoenix\Database\Interfaces\HasCollateProvider;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;
use Phoenix\Database\Interfaces\QueryBuilder as CoreQueryBuilder;
use Phoenix\Database\Interfaces\TableCreateStrategy as CoreTableCreateStrategyAlias;
use Phoenix\Database\Interfaces\TableDeleteStrategy as CoreTableDeleteStrategyAlias;
use Phoenix\Database\Interfaces\TableExistsStrategy as CoreTableExistsStrategyAlias;
use Phoenix\Datastore\Interfaces\Datastore as CoreDatastore;
use Phoenix\Events\Interfaces\EventStrategy as CoreEventStrategy;
use Phoenix\Integrations\WordPress\Adapters\DatabaseDateAdapter;
use Phoenix\Integrations\WordPress\Cache\CachePolicy;
use Phoenix\Cache\Interfaces\CachePolicy as CoreCachePolicy;
use Phoenix\Integrations\WordPress\Cache\ObjectCacheStrategy;
use Phoenix\Integrations\WordPress\Database\QueryBuilder;
use Phoenix\Integrations\WordPress\Providers\DatabaseProvider;
use Phoenix\Integrations\WordPress\Providers\DefaultCacheTtlProvider;
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
            ObjectCacheStrategy::class => CacheStrategy::class,
            CachePolicy::class => CoreCachePolicy::class,
            Datastore::class => CoreDatastore::class,
            DefaultCacheTtlProvider::class => HasDefaultTtl::class,
            TableCreateStrategy::class => CoreTableCreateStrategyAlias::class,
            TableDeleteStrategy::class => CoreTableDeleteStrategyAlias::class,
            TableExistsStrategy::class => CoreTableExistsStrategyAlias::class,
            QueryBuilder::class => CoreQueryBuilder::class,
            RestStrategy::class => CoreRestStrategy::class,
            DatabaseProvider::class => [HasDefaultTtl::class, HasGlobalDatabasePrefix::class, HasCollateProvider::class, HasCharsetProvider::class],
            DatabaseDateAdapter::class => [CanConvertToDatabaseDateString::class, CanConvertDatabaseStringToDateTime::class]
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
