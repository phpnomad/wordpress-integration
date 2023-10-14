<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Integrations\WordPress\Traits\CanQueryWordPressDatabase;
use Phoenix\Utils\Helpers\Arr;

class QueryStrategy implements CoreQueryStrategy
{
    use CanQueryWordPressDatabase;

    protected QueryBuilder $queryBuilder;

    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /** @inheritDoc */
    public function prefix(): ?string
    {
        global $wpdb;

        if (!isset($wpdb)) {
            throw new DatabaseErrorException('The wpdb global is not set. This indicates you probably tried to access the database too early.');
        }

        return $wpdb->prefix;
    }

    /** @inheritDoc */
    public function find(Table $table, $id): array
    {
        $this->queryBuilder
            ->useTable($table)
            ->select('*')
            ->from()
            ->where('id', '=', $id);

        return $this->wpdbGetRow();
    }

    /** @inheritDoc */
    public function all(Table $table): array
    {
        $this->queryBuilder
            ->useTable($table)
            ->select('*')
            ->from();

        return $this->wpdbGetResults();
    }

    /** @inheritDoc */
    public function where(Table $table, array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        return $this->getResults(['*'], $table, $conditions, $limit, $offset);
    }

    /** @inheritDoc */
    public function create(Table $table, array $attributes): int
    {
        return $this->wpdbInsert($table->getName(), $attributes);
    }

    /** @inheritDoc */
    public function update(Table $table, $id, array $attributes): void
    {
        $this->wpdbUpdate($table->getName(), $attributes, ['id' => $id]);
    }

    public function delete(Table $table, $id): void
    {
        $this->wpdbDelete($table->getName(), $id);
    }

    /** @inheritDoc */
    public function count(Table $table, array $conditions = []): int
    {
        $this->queryBuilder
            ->useTable($table)
            ->count('id')
            ->from();

        $this->buildConditions($conditions);

        return (int)$this->wpdbGetVar();
    }

    /** @inheritDoc */
    public function findBy(Table $table, string $column, $value): array
    {
        $results = $this->where($table, ['column' => $column, 'operator' => '=', 'value' => $value], 1);

        if (empty($results)) {
            throw new RecordNotFoundException('Could not find record');
        }

        return Arr::get($results, 0);
    }

    /** @inheritDoc */
    public function findIds(Table $table, array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        return $this->getResults(['id'], $table, $conditions, $limit, $offset);
    }

    /**
     * Query the database with conditions.
     *
     * @param array $fields
     * @param Table $table
     * @param array{column: string, operator: string, value: mixed}[] $conditions
     * @param positive-int|null $limit
     * @param positive-int|null $offset
     * @return int[]
     * @throws DatabaseErrorException
     * @throws RecordNotFoundException
     */
    protected function getResults(array $fields, Table $table, array $conditions, ?int $limit = null, ?int $offset = null)
    {
        $this->queryBuilder
            ->useTable($table)
            ->select(...$fields)
            ->from();

        if ($limit) {
            $this->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->queryBuilder->offset($offset);
        }

        $this->buildConditions($conditions);

        return $this->wpdbGetResults();
    }

    /**
     * Takes the given array of conditions and adds it to the query builder as a where statement.
     *
     * @param array $conditions
     * @return void
     */
    protected function buildConditions(array $conditions)
    {
        $firstCondition = array_shift($conditions);
        $column = Arr::get($firstCondition, 'column');
        $operator = Arr::get($firstCondition, 'operator');
        $value = Arr::get($firstCondition, 'value');

        $this->queryBuilder->where($column, $operator, $value);

        foreach ($conditions as $condition) {
            $column = Arr::get($condition, 'column');
            $operator = Arr::get($condition, 'operator');
            $value = Arr::get($condition, 'value');

            $this->queryBuilder->andWhere($column, $operator, $value);
        }
    }

    /** @inheritdoc */
    public function query(QueryBuilder $builder): array
    {
        $current = $this->queryBuilder;
        $this->queryBuilder = $builder;

        $results = $this->wpdbGetResults();

        $this->queryBuilder = $current;

        return $results;
    }
}
