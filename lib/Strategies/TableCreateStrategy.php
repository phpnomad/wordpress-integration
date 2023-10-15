<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\TableCreateFailedException;
use Phoenix\Database\Factories\Column;
use Phoenix\Database\Factories\Index;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Database\Interfaces\TableCreateStrategy as CoreTableCreateStrategy;
use Phoenix\Integrations\WordPress\Traits\CanModifyWordPressDatabase;
use Phoenix\Utils\Helpers\Arr;

class TableCreateStrategy implements CoreTableCreateStrategy
{
    use CanModifyWordPressDatabase;

    protected array $prepare = [];

    /**
     * @param Table $table
     * @return void
     * @throws TableCreateFailedException
     */
    public function create(Table $table): void
    {
        try {
            $this->wpdbQuery($this->buildCreateQuery($table), Arr::values($this->prepare));
        } catch (DatabaseErrorException $e) {
            throw new TableCreateFailedException($e);
        }
    }

    /**
     * Gets the specified create table
     *
     * @param Table $table
     * @return string
     */
    protected function buildCreateQuery(Table $table): string
    {
        $args = Arr::process([$this->convertColumnsToSqlString($table), $this->convertIndicesToSqlString($table)])
            ->setSeparator(",\n ")
            ->toString();

        return <<<SQL
            CREATE TABLE IF NOT EXISTS {$table->getName()} (
                $args
            ) CHARACTER SET {$table->getCharset()} COLLATE {$table->getCollation()};
        SQL;
    }

    protected function convertColumnsToSqlString(Table $table): string
    {
        return Arr::process($table->getColumns())
            ->map(function(Column $column){
                return $this->convertColumnToSchemaString($column);
            })
            ->setSeparator(",\n ")
            ->toString();
    }

    protected function convertIndicesToSqlString(Table $table): string
    {
        return Arr::process($table->getIndices())
            ->map(function(Index $index){
                return $this->convertIndexToSchemaString($index);
            })
            ->setSeparator(",\n ")
            ->toString();
    }

    /**
     * Converts the specified column into a MySQL formatted string.
     *
     * @param Column $column
     * @return string
     */
    protected function convertColumnToSchemaString(Column $column): string
    {
        return Arr::process([
            $column->getName(),
            is_null($column->getLength()) ? $column->getType() : $column->getType() . "({$column->getLength()})",
        ])
            ->merge($column->getAttributes())
            ->whereNotNull()
            ->setSeparator(' ')
            ->toString();
    }

    protected function convertIndexToSchemaString(Index $index): string
    {
        $pieces = [];

        if ($index->getType()) {
            $pieces[] = strtoupper($index->getType());
        }

        if ($index->getName()) {
            $pieces[] = $index->getName();
        }

        $pieces[] = "(" . implode(', ', $index->getColumns()) . ")";

        return implode(' ', $pieces);
    }
}
