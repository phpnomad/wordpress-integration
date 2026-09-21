<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use InvalidArgumentException;
use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy as CoreCoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\DatabaseHandler;
use PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\OperationCacheableService;
use PHPNomad\Database\Services\OperationEventStrategy;

/**
 * Creates database-handler providers for one active core-wpdb operation.
 *
 * Builder construction is delegated to the coordinator while its pinned
 * mysqli session is active. The factory never clones a global builder and it
 * cannot be used as a general provider replacement outside a callback.
 */
final class WordPressOperationDatabaseProviderFactory implements OperationDatabaseProviderFactory
{
    private CoordinatedQueryStrategy $coordinator;

    public function __construct(CoreCoordinatedQueryStrategy $coordinator)
    {
        if (!$coordinator instanceof CoordinatedQueryStrategy) {
            throw new InvalidArgumentException('The WordPress operation factory requires the WordPress coordinator.');
        }
        $this->coordinator = $coordinator;
    }

    public function create(
        DatabaseHandler $handler,
        QueryStrategy $queryStrategy,
        OperationCacheableService $cache,
        OperationEventStrategy $events
    ): DatabaseServiceProvider {
        $source = $handler->getDatabaseServiceProvider();
        // Ask the coordinator first. This refuses stale or out-of-callback
        // factory use before inspecting or cloning any provider state.
        $queryBuilder = $this->coordinator->createOperationQueryBuilder($queryStrategy);
        $clauseBuilder = $this->coordinator->createOperationClauseBuilder($queryStrategy);
        if ($source->queryStrategy !== $this->coordinator) {
            throw new InvalidArgumentException('The WordPress handler must use the coordinating query strategy.');
        }

        $provider = $source->forOperation(
            $queryStrategy,
            $queryBuilder,
            $clauseBuilder,
            $cache,
            $events
        );

        if ($provider->queryStrategy !== $queryStrategy
            || $provider->cacheableService !== $cache
            || $provider->eventStrategy !== $events
            || $provider->loggerStrategy !== $source->loggerStrategy
            || $provider->queryBuilder === $source->queryBuilder
            || $provider->clauseBuilder === $source->clauseBuilder) {
            throw new InvalidArgumentException('The WordPress operation provider did not preserve operation resources.');
        }

        return $provider;
    }
}
