<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase;

class QueryStrategy implements CoreQueryStrategy
{
    use CanQueryWordPressDatabase;

    /**
     * Tracks whether this strategy has written during the current request.
     *
     * After a write, managed hosts with read replicas may route normal reads to a
     * stale replica. This flag lets only post-write reads use the writer-consistent
     * path, so normal reads stay cheap while read-after-write hydration can see
     * the row that was just inserted or changed.
     */
    protected bool $hasWritten = false;

    /** @inheritDoc */
    public function query(QueryBuilder $builder): array
    {
        if ($this->hasWritten) {
            return $this->wpdbGetResultsAfterWrite($builder);
        }

        return $this->wpdbGetResults($builder);
    }

    /** @inheritDoc */
    public function insert(Table $table, array $data): array
    {
        $ids = $this->wpdbInsert($table, $data);
        $this->hasWritten = true;

        return $ids;
    }

    /** @inheritDoc */
    public function delete(Table $table, array $ids): void
    {
        $this->wpdbDelete($table, $ids);
        $this->hasWritten = true;
    }

    /** @inheritDoc */
    public function update(Table $table, array $where, array $data): void
    {
        $this->wpdbUpdate($table, $data, $where);
        $this->hasWritten = true;
    }

    /** @inheritDoc */
    public function estimatedCount(Table $table): int
    {
        global $wpdb;
        $rows = $wpdb->get_var("SELECT COUNT(*) FROM " . $table->getName());

        if ($rows !== null) {
            return (int)$rows;
        } else {
            throw new DatastoreErrorException('Something went wrong when fetching the record count');
        }
    }
}
