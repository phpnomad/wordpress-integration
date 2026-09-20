<?php

namespace PHPNomad\Integrations\WordPress\Database;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\ClauseBuilder as ClauseBuilderInterface;
use PHPNomad\Database\Traits\WithPrependedFields;

class ClauseBuilder implements ClauseBuilderInterface
{
    use WithPrependedFields;

    protected array $clauses = [];
    protected array $preparedValues = [];
    protected array $validOperators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE', 'NOT LIKE',
        'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS NULL', 'IS NOT NULL',
    ];

    /** @inheritDoc */
    public function where($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values);
        return $this;
    }

    /** @inheritDoc */
    public function andWhere($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values, 'AND');
        return $this;
    }

    /** @inheritDoc */
    public function orWhere($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values, 'OR');
        return $this;
    }

    /** @inheritDoc */
    public function group(string $logic, ClauseBuilderInterface ...$clauses)
    {
        $this->appendGroup($logic, $clauses);
        return $this;
    }

    /** @inheritDoc */
    public function andGroup(string $logic, ClauseBuilderInterface ...$clauses)
    {
        $logic = $this->validateGroup($logic, $clauses);

        if ($this->clauses !== []) {
            $this->clauses[] = 'AND';
        }

        $this->clauses[] = ['logic' => $logic, 'clauses' => $clauses];
        return $this;
    }

    /** @inheritDoc */
    public function orGroup(string $logic, ClauseBuilderInterface ...$clauses)
    {
        $logic = $this->validateGroup($logic, $clauses);

        if ($this->clauses !== []) {
            $this->clauses[] = 'OR';
        }

        $this->clauses[] = ['logic' => $logic, 'clauses' => $clauses];
        return $this;
    }

    /**
     * Gets the field string, rejecting invalid fields.
     *
     * @param string|string[] $field
     * @return string|null
     * @throws QueryBuilderException
     */
    protected function getFieldString($field): ?string
    {
        if (!is_array($field)) {
            if (!is_string($field) || !$this->tableHasField($field)) {
                throw new QueryBuilderException('Unknown field: ' . (string) $field);
            }

            return $this->prependField($field);
        }

        if ($field === []) {
            throw new QueryBuilderException('A condition field list cannot be empty.');
        }

        $fields = [];
        foreach ($field as $member) {
            if (!is_string($member) || !$this->tableHasField($member)) {
                throw new QueryBuilderException('Unknown field: ' . (string) $member);
            }

            $fields[] = $this->prependField($member);
        }

        return '(' . implode(', ', $fields) . ')';
    }

    /**
     * Adds a condition to the clause builder.
     *
     * @param string|string[] $field The field, or fields to be compared.
     * @param string $operator The operator to be used in the comparison.
     * @param array $values The values to be compared against.
     * @param ?string $logic The logic operator to be prepended to the condition.
     * @return $this
     * @throws QueryBuilderException
     */
    protected function addCondition($field, string $operator, array $values, ?string $logic = null): self
    {
        if ($logic !== null) {
            $logic = strtoupper($logic);
            if (!in_array($logic, ['AND', 'OR'], true)) {
                throw new QueryBuilderException("Unknown condition logic: {$logic}");
            }
        }

        $operator = strtoupper($operator);
        if (!in_array($operator, $this->validOperators, true)) {
            throw new QueryBuilderException("Unknown operator: {$operator}");
        }

        $fieldString = $this->getFieldString($field);
        $values = $this->normalizeValues($field, $operator, $values);
        $placeholder = $this->generatePlaceholder($field, $values, $operator);
        $condition = "{$fieldString} {$operator}" . ($placeholder === '' ? '' : " {$placeholder}");

        $preparedValues = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                foreach ($value as $tupleValue) {
                    if ($tupleValue !== null) {
                        $preparedValues[] = $tupleValue;
                    }
                }
            } elseif ($value !== null) {
                $preparedValues[] = $value;
            }
        }

        if ($preparedValues !== []) {
            global $wpdb;
            $condition = $wpdb->prepare($condition, ...$preparedValues);
            if (!is_string($condition) || $condition === '') {
                throw new QueryBuilderException('WordPress could not prepare a condition.');
            }
        }

        if ($this->clauses !== [] && $logic !== null) {
            $this->clauses[] = $logic;
        }

        $this->clauses[] = $condition;
        return $this;
    }

    /** @inheritDoc */
    public function build(): string
    {
        $queryParts = [];

        foreach ($this->clauses as $clause) {
            if (is_string($clause)) {
                $queryParts[] = $clause;
                continue;
            }

            $groupParts = [];
            foreach ($clause['clauses'] as $groupClause) {
                $builtClause = $groupClause->build();
                if ($builtClause === '') {
                    throw new QueryBuilderException('A grouped condition cannot be empty.');
                }

                $groupParts[] = $builtClause;
            }

            $queryParts[] = '(' . implode(" {$clause['logic']} ", $groupParts) . ')';
        }

        $query = implode(' ', $queryParts);
        $this->reset();

        return $query;
    }

    /** @inheritDoc */
    public function reset()
    {
        $this->clauses = [];
        $this->preparedValues = [];
        return $this;
    }

    protected function generatePlaceholder($field, array $values, string $operator): string
    {
        $operator = strtoupper($operator);
        $placeholderFor = static fn ($value): string => $value === null ? 'NULL' : '%s';

        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            return '';
        }

        if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
            return $placeholderFor($values[0]) . ' AND ' . $placeholderFor($values[1]);
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            if (is_array($field)) {
                $rows = array_map(
                    static fn (array $tuple): string => '('
                        . implode(', ', array_map($placeholderFor, $tuple)) . ')',
                    $values
                );
                $placeholderGroup = implode(', ', $rows);
            } else {
                $placeholderGroup = implode(', ', array_map($placeholderFor, $values));
            }

            return "({$placeholderGroup})";
        }

        return $placeholderFor($values[0]);
    }

    /** @param string|string[] $field */
    private function normalizeValues($field, string $operator, array $values): array
    {
        $count = count($values);

        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            if ($values === [] || $values === [null]) {
                return [];
            }

            throw new QueryBuilderException("Operator {$operator} accepts no values or one null value.");
        }

        if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
            if ($count !== 2 || is_array($values[0]) || is_array($values[1])) {
                throw new QueryBuilderException("Operator {$operator} expects exactly two scalar or null values.");
            }

            return $values;
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            if ($count === 0) {
                throw new QueryBuilderException("Operator {$operator} expects at least one value.");
            }

            if (is_array($field)) {
                foreach ($values as $tuple) {
                    if (!is_array($tuple) || count($tuple) !== count($field)) {
                        throw new QueryBuilderException("Operator {$operator} tuple width must match the field list.");
                    }
                }

                return array_map('array_values', $values);
            }

            if ($count === 1 && is_array($values[0])) {
                $nested = array_values($values[0]);
                return $nested === [] ? [null] : $nested;
            }

            foreach ($values as $value) {
                if (is_array($value)) {
                    throw new QueryBuilderException("Operator {$operator} received an invalid nested value.");
                }
            }

            return $values;
        }

        if ($count !== 1 || is_array($values[0])) {
            throw new QueryBuilderException("Operator {$operator} expects exactly one scalar or null value.");
        }

        return $values;
    }

    /** @param ClauseBuilderInterface[] $clauses */
    private function appendGroup(string $logic, array $clauses): void
    {
        $this->clauses[] = [
            'logic' => $this->validateGroup($logic, $clauses),
            'clauses' => $clauses,
        ];
    }

    /** @param ClauseBuilderInterface[] $clauses */
    private function validateGroup(string $logic, array $clauses): string
    {
        $logic = strtoupper($logic);
        if (!in_array($logic, ['AND', 'OR'], true)) {
            throw new QueryBuilderException("Unknown group logic: {$logic}");
        }

        if ($clauses === []) {
            throw new QueryBuilderException('A condition group cannot be empty.');
        }

        return $logic;
    }
}
