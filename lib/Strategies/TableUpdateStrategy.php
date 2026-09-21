<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Database\Exceptions\TableUpdateFailedException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableColumnRetirementStrategy as CoreTableColumnRetirementStrategy;
use PHPNomad\Database\Interfaces\TableUpdateStrategy as CoreTableUpdateStrategy;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Integrations\WordPress\Traits\CanModifyWordPressDatabase;
use PHPNomad\Utils\Helpers\Arr;

class TableUpdateStrategy implements CoreTableUpdateStrategy, CoreTableColumnRetirementStrategy
{
    use CanModifyWordPressDatabase;

    protected array $prepare = [];

    /**
     * @param Table $table
     * @return void
     * @throws TableUpdateFailedException
     */
    public function syncColumns(Table $table): void
    {
        try {
            $query = $this->buildSyncColumnsQuery($table);

            if(!$query){
                return;
            }
            $this->wpdbQuery($query);
        } catch (DatastoreErrorException $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    public function columnExists(Table $table, string $columnName): bool
    {
        $this->assertValidColumnName($columnName);

        try {
            return $this->findCurrentColumnName($table->getName(), $columnName) !== null;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    public function retireColumns(Table $table, string ...$columnNames): void
    {
        if ($columnNames === []) {
            throw new \InvalidArgumentException('At least one column must be named for retirement.');
        }

        foreach ($columnNames as $columnName) {
            $this->assertValidColumnName($columnName);
        }

        try {
            $targets = [];
            foreach ($columnNames as $columnName) {
                $currentName = $this->findCurrentColumnName($table->getName(), $columnName);
                if ($currentName !== null) {
                    $targets[$currentName] = $currentName;
                }
            }

            foreach ($table->getColumns() as $column) {
                foreach ($columnNames as $columnName) {
                    if ($this->identifiersEqual($column->getName(), $columnName)) {
                        throw new \InvalidArgumentException('A declared column cannot be retired.');
                    }
                }
            }

            if ($targets === []) {
                return;
            }

            $this->assertNoColumnDependencies($table->getName(), $targets);

            global $wpdb;
            $drops = array_map(
                fn(string $columnName): string => 'DROP COLUMN ' . $wpdb->prepare('%i', $columnName),
                array_values($targets)
            );
            $this->wpdbQuery(
                'ALTER TABLE ' . $wpdb->prepare('%i', $table->getName()) . ' ' . implode(', ', $drops)
            );
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    private function assertValidColumnName(string $columnName): void
    {
        if ($columnName === '' || str_contains($columnName, "\0")) {
            throw new \InvalidArgumentException('Column names must be non-empty and cannot contain NUL.');
        }
    }

    private function findCurrentColumnName(string $tableName, string $columnName): ?string
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $tableName,
            $columnName
        ), ARRAY_A);
        $this->assertMetadataSucceeded($rows);

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['COLUMN_NAME']) || !is_string($row['COLUMN_NAME'])) {
                throw new \UnexpectedValueException('Column metadata did not contain a valid name.');
            }

            return $row['COLUMN_NAME'];
        }

        return null;
    }

    private function identifiersEqual(string $left, string $right): bool
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT candidate = %s AS identifiers_equal FROM ('
            . 'SELECT COLUMN_NAME AS candidate FROM INFORMATION_SCHEMA.COLUMNS WHERE 1 = 0 '
            . 'UNION ALL SELECT %s) AS identifier_semantics',
            $right,
            $left
        ), ARRAY_A);
        $this->assertMetadataSucceeded($rows);
        $value = $rows[0]['identifiers_equal'] ?? null;

        if ($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
            throw new \UnexpectedValueException('Failed to compare column identifiers.');
        }

        return (string) $value === '1';
    }

    /** @param array<string, string> $targets persisted name => persisted name */
    private function assertNoColumnDependencies(string $tableName, array $targets): void
    {
        global $wpdb;
        $statistics = $wpdb->get_results($wpdb->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $tableName
        ), ARRAY_A);
        $this->assertMetadataSucceeded($statistics);

        foreach ($statistics as $statistic) {
            if (!is_array($statistic) || !array_key_exists('COLUMN_NAME', $statistic)) {
                throw new \UnexpectedValueException('Index metadata did not contain a column identity.');
            }
            $columnName = $statistic['COLUMN_NAME'] ?? null;
            if ($columnName !== null && !is_string($columnName)) {
                throw new \UnexpectedValueException('Index metadata contained a malformed column identity.');
            }
            if (is_string($columnName) && isset($targets[$columnName])) {
                throw new \InvalidArgumentException('An indexed column cannot be retired implicitly.');
            }
            if ($columnName === null) {
                throw new \InvalidArgumentException('An unresolved functional index prevents column retirement.');
            }
        }

        $foreignKeys = $wpdb->get_results($wpdb->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
            . 'FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND (TABLE_NAME = %s OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s))',
            $tableName,
            $tableName
        ), ARRAY_A);
        $this->assertMetadataSucceeded($foreignKeys);

        foreach ($foreignKeys as $foreignKey) {
            if (!is_array($foreignKey)) {
                throw new \UnexpectedValueException('Foreign-key metadata contained a malformed row.');
            }
            $localColumn = $foreignKey['COLUMN_NAME'] ?? null;
            $referencedColumn = $foreignKey['REFERENCED_COLUMN_NAME'] ?? null;
            if (!is_string($localColumn)
                || ($referencedColumn !== null && !is_string($referencedColumn))) {
                throw new \UnexpectedValueException('Foreign-key metadata contained a malformed column identity.');
            }
            if ((is_string($localColumn) && isset($targets[$localColumn]))
                || (is_string($referencedColumn) && isset($targets[$referencedColumn]))) {
                throw new \InvalidArgumentException('A foreign-key column cannot be retired implicitly.');
            }
        }
    }

    private function assertMetadataSucceeded($results): void
    {
        global $wpdb;
        if (!is_array($results) || $wpdb->last_error !== '') {
            throw new DatastoreErrorException('Failed to inspect table metadata.');
        }
    }

    protected function convertColumnToSql(Column $column): string
    {
        // Get the column name and type
        $columnName = $column->getName();
        $columnType = $column->getType();

        // Handle type arguments (e.g., VARCHAR(255))
        $typeArgs = $column->getTypeArgs();
        if (!empty($typeArgs)) {
            $columnType .= '(' . implode(',', $typeArgs) . ')';
        }

        // Handle attributes (e.g., NOT NULL, DEFAULT 'value')
        $attributes = implode(' ', $column->getAttributes());

        return "`{$columnName}` {$columnType} {$attributes}";
    }

    protected function getCurrentColumns(string $tableName): array
    {
        global $wpdb;
        $query = 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s';

        $results = $wpdb->get_results($wpdb->prepare($query, $tableName), ARRAY_A);
        $this->assertMetadataSucceeded($results);
        $columns = [];

        foreach ($results as $row) {
            $columns[$row['COLUMN_NAME']] = $row;
        }

        return $columns;
    }

    protected function needsColumnModification(array $currentColumnData, Column $newColumn): bool
    {
        //TODO: THIS ONLY DOES A BASIC CHECK FOR TYPE, DOES NOT INCLUDE OTHER MODIFIER CHECKS

        // Check if the column type and type arguments match
        $currentType = $currentColumnData['COLUMN_TYPE'];
        $newType = $newColumn->getType();

        // Append type arguments to the new type if they exist
        $typeArgs = $newColumn->getTypeArgs();
        if (!empty($typeArgs)) {
            $newType .= '(' . implode(',', $typeArgs) . ')';
        }

        // Check if the types are different
        if (!str_contains(strtolower($currentType), strtolower($newType))) {
            return true;
        }

        return false;
    }

    /**
     * Gets the specified update table
     *
     * @param Table $table
     * @return string
     */
    protected function buildSyncColumnsQuery(Table $table): ?string
    {
        global $wpdb;

        $currentColumns = $this->getCurrentColumns($table->getName());
        $newColumns = $table->getColumns();
        $queries = [];

        // Add or modify columns
        foreach ($newColumns as $newColumn) {
            $columnName = $newColumn->getName();
            if (!array_key_exists($columnName, $currentColumns)) {
                // Column does not exist, add it
                $queries[] = "ADD COLUMN " . $this->convertColumnToSql($newColumn);
            } else {
                // Column exists, check if it needs to be modified
                if ($this->needsColumnModification($currentColumns[$columnName], $newColumn)) {
                    $queries[] = "MODIFY COLUMN " . $this->convertColumnToSql($newColumn);
                }
            }
        }

        $args = Arr::process($queries)
            ->whereNotEmpty()
            ->setSeparator(",\n ")
            ->toString();

        if(empty($args)){
            return null;
        }

        $tableIdentifier = $wpdb->prepare('%i', $table->getName());

        return <<<SQL
            ALTER TABLE {$tableIdentifier} 
            $args 
        SQL;
    }

    protected function convertColumnsToSqlString(Table $table): string
    {
        return Arr::process($table->getColumns())
            ->map(fn(Column $column) => $this->convertColumnToSchemaString($column))
            ->setSeparator(",\n ")
            ->toString();
    }

    protected function convertIndicesToSqlString(Table $table): string
    {
        return Arr::process($table->getIndices())
            ->map(fn(Index $index) => $this->convertIndexToSchemaString($index))
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
        $type = $column->getType();
        if ($args = $column->getTypeArgs()) {
            $type .= '(' . implode(',', $args) . ')';
        }

        return Arr::process([
            $column->getName(),
            $type,
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

        return implode(' ', Arr::merge($pieces, $index->getAttributes()));
    }
}
