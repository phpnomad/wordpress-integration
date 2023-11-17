<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Cache\Interfaces\HasDefaultTtl;
use PHPNomad\Database\Events\RecordDeleted;
use PHPNomad\Database\Interfaces\CanConvertDatabaseStringToDateTime;
use PHPNomad\Database\Interfaces\CanConvertToDatabaseDateString;
use PHPNomad\Database\Interfaces\HasCharsetProvider;
use PHPNomad\Database\Interfaces\HasCollateProvider;
use PHPNomad\Database\Interfaces\HasGlobalDatabasePrefix;
use PHPNomad\Database\Interfaces\QueryBuilder as CoreQueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\TableCreateStrategy as CoreTableCreateStrategyAlias;
use PHPNomad\Database\Interfaces\TableDeleteStrategy as CoreTableDeleteStrategyAlias;
use PHPNomad\Database\Interfaces\TableExistsStrategy as CoreTableExistsStrategyAlias;
use PHPNomad\Events\Interfaces\EventStrategy as CoreEventStrategy;
use PHPNomad\Events\Interfaces\HasEventBindings;
use PHPNomad\Events\Interfaces\HasListeners;
use PHPNomad\Integrations\WordPress\Adapters\DatabaseDateAdapter;
use PHPNomad\Integrations\WordPress\Cache\CachePolicy;
use PHPNomad\Cache\Interfaces\CachePolicy as CoreCachePolicy;
use PHPNomad\Integrations\WordPress\Cache\ObjectCacheStrategy;
use PHPNomad\Integrations\WordPress\Database\QueryBuilder;
use PHPNomad\Integrations\WordPress\Listeners\FrontEndRequestListener;
use PHPNomad\Integrations\WordPress\Providers\DatabaseProvider;
use PHPNomad\Integrations\WordPress\Providers\DefaultCacheTtlProvider;
use PHPNomad\Integrations\WordPress\Rest\Response;
use PHPNomad\Loader\Interfaces\HasClassDefinitions;
use PHPNomad\Loader\Interfaces\HasLoadCondition;
use PHPNomad\Mutator\Interfaces\MutationStrategy as CoreMutationStrategy;
use PHPNomad\Rest\Interfaces\Response as CoreResponse;
use PHPNomad\Rest\Interfaces\RestStrategy as CoreRestStrategy;
use \PHPNomad\Events\Interfaces\ActionBindingStrategy as CoreActionBindingStrategy;
use PHPNomad\Utils\Helpers\Arr;

class WordPressInitializer implements HasLoadCondition, HasClassDefinitions, HasListeners, HasEventBindings
{
    public const REQUIRED_WORDPRESS_VERSION = '6.3.0';

    /**
     * @return array
     */
    public function getClassDefinitions(): array
    {
        return [
            EventStrategy::class => CoreEventStrategy::class,
            Response::class => CoreResponse::class,
            MutationStrategy::class => CoreMutationStrategy::class,
            ActionBindingStrategy::class => CoreActionBindingStrategy::class,
            ObjectCacheStrategy::class => CacheStrategy::class,
            CachePolicy::class => CoreCachePolicy::class,
            QueryStrategy::class => CoreQueryStrategy::class,
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

    public function getListeners(): array
    {
        return [
            FrontEndRequestListener::class
        ];
    }

    public function getEventBindings(): array
    {
        return [
            RecordDeleted::class => [
                ['action' => 'deleted_user', 'transformer' => fn(int $id) => new RecordDeleted('user', Arr::wrap($id))]
            ]
        ];
    }
}
