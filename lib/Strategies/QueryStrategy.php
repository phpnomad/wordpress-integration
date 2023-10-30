<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase;

class QueryStrategy implements CoreQueryStrategy
{
    use CanQueryWordPressDatabase;

    /** @inheritDoc */
    public function query(QueryBuilder $builder): array
    {
        return $this->wpdbGetResults($builder);
    }

    /** @inheritDoc */
    public function insert(Table $table, array $data): array
    {
        return $this->wpdbInsert($table, $data);
    }

    /** @inheritDoc */
    public function delete(Table $table, array $ids): void
    {
        $this->wpdbDelete($table, $ids);
    }

    /** @inheritDoc */
    public function update(Table $table, array $ids, array $data): void
    {
        $this->wpdbUpdate($table, $data, $ids);
    }
}
