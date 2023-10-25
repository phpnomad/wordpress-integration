<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use DateTime;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\CanConvertToDatabaseDateString;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Datastore\Exceptions\DatastoreErrorException;
use Phoenix\Datastore\Interfaces\Datastore as CoreDatastore;
use Phoenix\Integrations\WordPress\Traits\CanQueryWordPressDatabase;
use Phoenix\Utils\Helpers\Arr;

class Datastore implements CoreDatastore
{
    use CanQueryWordPressDatabase;

    protected QueryBuilder $queryBuilder;
    protected CanConvertToDatabaseDateString $databaseDateAdapter;

    public function __construct(QueryBuilder $queryBuilder, CanConvertToDatabaseDateString $databaseDateAdapter)
    {
        $this->queryBuilder = $queryBuilder;
        $this->databaseDateAdapter = $databaseDateAdapter;
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
    public function where(Table $table, array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        return $this->getResults(['*'], $table, $conditions, $limit, $offset);
    }

    /** @inheritDoc */
    public function create(Table $table, array $attributes): int
    {
        return $this->wpdbInsert($table->getName(), $this->prepareAttributes($attributes));
    }

    /** @inheritDoc */
    public function update(Table $table, $id, array $attributes): void
    {
        $this->wpdbUpdate($table, $this->prepareAttributes($attributes), ['id' => $id]);
    }

    public function delete(Table $table, $id): void
    {
        $this->wpdbDelete($table->getName(), ['id' => $id]);
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
     * @throws DatastoreErrorException
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

    /**
     * Prepares attributes for MySQL query.
     *
     * @param array $attributes The attributes, prepared for a query.
     * @return array
     */
    protected function prepareAttributes(array $attributes): array
    {
        return Arr::each(
            $attributes,
            fn($value, string $key) => $value instanceof DateTime ? $this->databaseDateAdapter->toDatabaseDateString($value) : $value
        );
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

    /** @inheritDoc */
    public function deleteWhere(Table $table, array $conditions): void
    {
        $this->wpdbDelete($table->getName(), $conditions);
    }
}