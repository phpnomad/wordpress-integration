<?php

namespace PHPNomad\Integrations\WordPress\Database;

use PHPNomad\Database\Interfaces\ClauseBuilder as ClauseBuilderInterface;
use PHPNomad\Database\Traits\WithPrependedFields;
use PHPNomad\Integrations\WordPress\Traits\CanGetDataFormats;

class ClauseBuilder implements ClauseBuilderInterface
{
    use CanGetDataFormats, WithPrependedFields;

    protected array $clauses = [];
    protected array $preparedValues = [];

    /**
     * @inheritDoc
     */
    public function where($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function andWhere($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values, 'AND');
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function orWhere($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values, 'OR');
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function group(string $logic, ClauseBuilderInterface ...$clauses)
    {
        $group = ['logic' => $logic, 'clauses' => $clauses];
        $this->clauses[] = $group;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function andGroup(string $logic, ClauseBuilderInterface ...$clauses)
    {
        if(!empty($this->clauses)) {
            $this->clauses[] = 'AND';
        }

        return $this->group($logic, ...$clauses);
    }

    /**
     * @inheritDoc
     */
    public function orGroup(string $logic, ClauseBuilderInterface ...$clauses)
    {
        if(!empty($this->clauses)) {
            $this->clauses[] = 'OR';
        }

        return $this->group($logic, ...$clauses);
    }

    /**
     * Adds a condition to the clause builder.
     *
     * @param string|string[] $field The field, or fields to be compared.
     * @param string $operator The operator to be used in the comparison.
     * @param array $values The values to be compared against.
     * @param ?string $logic (optional) The logic operator to be prepended to the condition.
     * @return $this
     */
    protected function addCondition($field, string $operator, array $values, ?string $logic = null): self
    {
        $placeholder = $this->generatePlaceholder($field, $operator);
        $fieldStr = is_array($field) ? '(' . implode(', ', array_map([$this, 'prependField'], $field)) . ')' : $this->prependField($field);

        if(!empty($this->clauses) && $logic && in_array(strtoupper($logic), ['AND', 'OR'])){
            $this->clauses[] = strtoupper($logic);
        }

        $this->clauses[] = $fieldStr;
        $this->clauses[] = $operator;
        $this->clauses[] = $placeholder;

        foreach ($values as $value) {
            $this->preparedValues[] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function build(): string
    {
        global $wpdb;
        $queryParts = [];
        $allValues = $this->preparedValues; // Initially prepared values
        $subQueryReplacements = [];
        $query = "";
        $marker = 0;

        foreach ($this->clauses as $clause) {
            if (is_string($clause)) {
                // Directly append logical operators or raw SQL parts
                $queryParts[] = $clause;
            } elseif (is_array($clause) && isset($clause['logic'], $clause['clauses'])) {
                // Process group of clauses
                $groupParts = [];
                foreach ($clause['clauses'] as $groupClause) {
                    if ($groupClause instanceof ClauseBuilderInterface) {
                        $marker++;
                        $uniqueMarker = '__NOMADIC_SUBQUERY__' . $marker;
                        $builtClause = $groupClause->build();
                        // Store built clause for later replacement to prevent double processing
                        $subQueryReplacements[$uniqueMarker] = $builtClause;
                        $groupParts[] = $uniqueMarker;
                        // Assume $groupClause->build() also prepares values correctly
                    }
                }
                if (!empty($groupParts)) {
                    // Combine group parts with the group's logic
                    $queryParts[] = '(' . implode(" {$clause['logic']} ", $groupParts) . ')';
                }
            } elseif ($clause instanceof ClauseBuilderInterface) {
                // Process individual ClauseBuilder instances
                $marker++;
                $uniqueMarker = '__NOMADIC_SUBQUERY__' . $marker;
                $builtClause = $clause->build();
                $subQueryReplacements[$uniqueMarker] = $builtClause;
                $queryParts[] = $uniqueMarker;
                // Prepared values should be already correctly handled within each ClauseBuilder instance's build method
            }
        }

        if (!empty($queryParts)) {
            $query = implode(' ', $queryParts);

            // Prepare the query with initial values if available
            if (!empty($allValues)) {
                $query = $wpdb->prepare($query, ...$allValues);
            }

            // Replace subquery markers with their actual queries
            foreach ($subQueryReplacements as $marker => $subQuery) {
                $query = str_replace($marker, $subQuery, $query);
            }
        }

        $this->reset();

        return $query;
    }

    /**
     * @inheritDoc
     */
    public function reset()
    {
        $this->clauses = [];
        $this->preparedValues = [];

        return $this;
    }

    protected function generatePlaceholder($values, string $operator): string
    {
        if (strtoupper($operator) === 'IN' || strtoupper($operator) === 'NOT IN') {
            if(is_array($values)) {
                $placeholderGroup = '(' . implode(', ', array_fill(0, count($values), '%s')) . ')';
            } else{
                $placeholderGroup = implode(', ', array_fill(0, count($values), '%s'));
            }

            return "($placeholderGroup)";
        }

        return '%s';
    }
}