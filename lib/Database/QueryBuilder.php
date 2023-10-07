<?php

namespace Phoenix\Integrations\WordPress\Database;

use Phoenix\Database\Exceptions\QueryBuilderException;
use Phoenix\Database\QueryBuilder as QueryBuilderInterface;
use Phoenix\Utils\Helpers\Arr;
use wpdb;

class QueryBuilder implements QueryBuilderInterface
{
    protected array $select = [];

    protected array $where = [];

    protected array $from = [];

    protected array $sql = [];

    private array $preparedValues = [];

    private array $prepare = [];

    protected array $raw = [];

    protected array $items = [];

    protected array $operands = [];

    protected array $limit = [];

    protected array $offset = [];

    protected array $orderBy = [];

    /** @inheritDoc */
    public function select(array $fields)
    {
        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        $this->select[] = Arr::process($fields)
            ->each(function (string $field, $key) {
                return is_string($key) ? "$field AS $key" : $field;
            })
            ->toString();

        return $this;
    }

    /** @inheritDoc */
    public function from(string $table, ?string $alias = null)
    {
        if (empty($this->from)) {
            $this->from[] = 'FROM';
        }

        $this->from[] = $table;

        if ($alias) {
            $this->from[] = $alias;
        }

        return $this;
    }

    /** @inheritDoc */
    public function where(string $field, string $operand, $value, ...$values)
    {
        if (!isset($this->where)) {
            $this->where = ['WHERE'];
        }

        $this->operands[] = $operand;
        $this->where = Arr::merge($this->where, [$field, $operand]);

        // Add the value to the list of values to prepare via wpdb->prepare
        if ($operand === 'NOT IN' || $operand === 'IN') {
            $this->where[] = '(';

            foreach (Arr::merge([$value], $values) as $value) {
                $this->where[] = $this->prepareValue($field, $value);
                $this->where[] = ',';
            }

            // Pop off extra comma.
            array_pop($this->where);
            $this->where[] = ')';
        } elseif ('LIKE' === $operand) {
            $this->where[] = $field;
            $this->where[] = 'LIKE';
            $this->where[] = $this->wpdb()->esc_like($value);
        } else {
            $this->where[] = $this->prepareValue($field, $value);
        }

        return $this;
    }

    /** @inheritDoc */
    public function andWhere(string $field, string $operand, $value, ...$values)
    {
        $this->operands[] = $operand;
        $this->where[] = 'AND';
        $this->where($field, $operand, $value, ...$values);

        return $this;
    }

    /** @inheritDoc */
    public function orWhere(string $field, string $operand, $value, ...$values)
    {
        $this->operands[] = $operand;
        $this->where[] = 'OR';
        $this->where($field, $operand, $value, ...$values);

        return $this;
    }

    /** @inheritDoc */
    public function join(string $type, string $table, string $alias, string $column, string $onColumn)
    {
        $join = [
            $type,
            'JOIN',
            $table,
            $alias,
            'ON',
            $column,
            '=',
            $onColumn,
        ];

        if (isset($this->join)) {
            $this->join = Arr::merge($this->join, $join);
        } else {
            // Build join
            $this->join = $join;
        }

        return $this;
    }

    /** @inheritDoc */
    public function groupBy(string $column, string ...$columns)
    {
        foreach (Arr::merge([$column], $columns) as $columnToGroup) {
            // Build group by
            if (empty($this->groupBy)) {
                $this->groupBy = ['GROUP BY', $columnToGroup];
            } else {
                $this->groupBy[] = ',';
                $this->groupBy[] = $columnToGroup;
            }
        }

        return $this;
    }

    /** @inheritDoc */
    public function sum(string $fieldToSum, ?string $alias = null)
    {
        $alias = $alias ?: $fieldToSum . '_sum';
        // Prepare select
        $select = ['SUM(' . $fieldToSum . ')', 'as', $alias];

        // Add a comma to the end if it isn't the only field
        if (count($this->select) > 1) {
            array_unshift($select, ',');
        }

        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        // Merge into select statement.
        $this->select = array_merge($this->select, $select);

        return $this;
    }

    /** @inheritDoc */
    public function count(string $fieldToCount, ?string $alias = null)
    {
        $alias = $alias ?: $fieldToCount . '_count';

        // Prepare select
        $select = ['COUNT(' . $fieldToCount . ')', 'as', $alias];

        // Add a comma to the end if it isn't the only field
        if (count($this->select) > 1) {
            array_unshift($select, ',');
        }

        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        // Merge into select
        $this->select = array_merge($this->select, $select);

        return $this;
    }

    /** @inheritDoc */
    public function limit(int $limit)
    {
        $this->limit = ['LIMIT', $limit];

        return $this;
    }

    /** @inheritDoc */
    public function offset(int $offset)
    {
        $this->offset = ['OFFSET', $offset];

        return $this;
    }

    /** @inheritDoc */
    public function orderBy(string $field, string $order)
    {
        // Ensure order is uppercase
        $order = strtoupper($order);

        // Ensure order is valid
        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = 'ASC';
        }

        // Add order by
        $this->orderBy = ['ORDER BY', $field, $order];

        return $this;
    }

    /** @inheritDoc */
    public function build(): string
    {
        if (empty($this->select)) {
            throw new QueryBuilderException('Missing select field');
        }

        if (empty($this->from)) {
            throw new QueryBuilderException('Missing from field');
        }

        foreach ($this->operands as $operand) {
            if (!$this->isValidOperand($operand)) {
                throw new QueryBuilderException('Invalid operand' . $operand);
            }
        }

        $this->sql = Arr::merge($this->select, $this->from);
        $this->maybeAppend('join');
        $this->maybeAppend('where');
        $this->maybeAppend('group_by');
        $this->maybeAppend('order_by');
        $this->maybeAppend('limit');

        // Convert to string
        $sql = implode(' ', $this->sql);

        // If necessary, prepare the query
        if (!empty($this->prepare)) {
            $sql = $this->wpdb()->prepare($sql, ...$this->prepare);
        }

        $this->reset();

        return $sql;
    }

    protected function reset(): void
    {
        $this->select = [];
        $this->where = [];
        $this->from = [];
        $this->sql = [];
        $this->preparedValues = [];
        $this->prepare = [];
        $this->raw = [];
        $this->items = [];
        $this->operands = [];
        $this->limit = [];
        $this->offset = [];
        $this->orderBy = [];
    }

    /**
     * Validates operands.
     *
     * @param string $operand The operand to check for
     * @return bool true if it exists, otherwise false.
     * @since 1.2.3
     *
     */
    private function isValidOperand($operand): bool
    {
        return in_array($operand, ['>', '<', '=', '<=', '>=', '!>', '!<', '!=', '!<=', '!>=', 'IN', 'NOT IN', 'LIKE']);
    }

    /**
     * Appends a query clause if it is set.
     *
     * @param string $key The query clause key
     *
     */
    private function maybeAppend(string $key)
    {
        if (isset($this->$key) && is_array($this->$key)) {
            foreach ($this->$key as $id => $value) {
                if (is_array($value)) {
                    $this->prepare[] = $value['value'];
                    $this->$key[$id] = $value['type'];
                }
            }

            $this->sql = array_merge($this->sql, array_values($this->$key));
        }
    }

    /**
     * Prepares a value to be processed by wpdb->prepare.
     * This doesn't actually do any processing, just formats the value in a way that allows the build method
     * to detect if it is a field, and process it accordingly.
     *
     * @param string $field The field to process
     * @param string|int|float $value The value to set
     * @return array{type:string, value:string|int|float} Array containing the expected field type for wpdb->prepare, as well as the unprocessed value.
     *
     */
    public function prepareValue(string $field, $value): array
    {
        if (!isset($this->preparedValues[$field])) {
            $this->preparedValues[$field] = [
                'type' => $this->getFieldSprintfType($field),
                'value' => $value,
            ];
        }

        return $this->preparedValues[$field];
    }

    /**
     * Determines the wpdb->prepare field type based on the field's column type.
     *
     * @param string|int|float $value The field to check against.
     * @return string The field type. Either %d, %f, of %s.
     *
     */
    private function getFieldSprintfType($value): string
    {
        if (is_int($value)) {
            return '%d';
        } elseif (is_float($value)) {
            return '%f';
        } else {
            return '%s';
        }
    }

    /**
     * Gets the WPDB object.
     * @return wpdb
     */
    private function wpdb(): wpdb
    {
        global $wpdb;

        return $wpdb;
    }
}