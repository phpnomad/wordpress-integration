<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Database\DatabaseStrategy as CoreDatabaseStrategy;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\QueryBuilder;
use Phoenix\Integrations\WordPress\Traits\CanQueryWordPressDatabase;
use Phoenix\Utils\Helpers\Arr;

class DatabaseStrategy implements CoreDatabaseStrategy
{
    use CanQueryWordPressDatabase;

    protected QueryBuilder $queryBuilder;

    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /** @inheritDoc */
    public function find(string $table, $id): array
    {
        $this->queryBuilder
            ->select(['*'])
            ->from($table)
            ->where('id', '=', $id);

        return $this->wpdbGetRow();
    }

    /** @inheritDoc */
    public function all(string $table): array
    {
        $this->queryBuilder
            ->select(['*'])
            ->from($table);

        return $this->wpdbGetResults();
    }

    /** @inheritDoc */
    public function where(string $table, array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        $this->queryBuilder
            ->select(['*'])
            ->from($table);

        if ($limit) {
            $this->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->queryBuilder->offset($offset);
        }

        $this->buildConditions($conditions);

        return $this->wpdbGetResults();
    }

    /** @inheritDoc */
    public function create(string $table, array $attributes): int
    {
        return $this->wpdbInsert($table, $attributes);
    }

    /** @inheritDoc */
    public function update(string $table, $id, array $attributes): void
    {
        $this->wpdbUpdate($table, $attributes, ['id' => $id]);
    }

    public function delete(string $table, $id): void
    {
        $this->wpdbDelete($table, $id);
    }

    /** @inheritDoc */
    public function count(string $table, array $conditions = []): int
    {
        $this->queryBuilder
            ->count('id')
            ->from($table);

        $this->buildConditions($conditions);

        return (int)$this->wpdbGetVar();
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

    /** @inheritDoc */
    public function findBy(string $table, string $column, $value): array
    {
        $results = $this->where($table, ['column' => $column, 'operator' => '=', 'value' => $value], 1);

        if (empty($results)) {
            throw new RecordNotFoundException('Could not find record');
        }

        return Arr::get($results, 0);
    }
}
