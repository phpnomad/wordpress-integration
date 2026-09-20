<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use mysqli;
use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Integrations\WordPress\Database\QueryBuilder as WordPressQueryBuilder;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder as WordPressClauseBuilder;
use PHPNomad\Integrations\WordPress\Traits\CanGetDataFormats;
use wpdb;

/**
 * QueryStrategy for one already-owned core wpdb/mysqli session.
 *
 * This class deliberately does not call wpdb::query(), insert(), update(), or
 * delete(). Those methods run filters and may reconnect and replay a statement.
 * The coordinator verifies the handle before and after each call.
 */
final class PinnedQueryStrategy implements QueryStrategy
{
    use CanGetDataFormats;

    public function __construct(
        private wpdb $database,
        private mysqli $connection,
        private int $connectionId,
        private \Closure $assertResource,
        private \Closure $primaryFields
    ) {
    }

    /** Create a fresh builder already bound to this operation's wpdb. */
    public function createQueryBuilder(): WordPressQueryBuilder
    {
        ($this->assertResource)();

        return new WordPressQueryBuilder($this->database);
    }

    /** Create a fresh clause builder already bound to this operation's wpdb. */
    public function createClauseBuilder(): WordPressClauseBuilder
    {
        ($this->assertResource)();

        return new WordPressClauseBuilder($this->database);
    }

    /** @inheritDoc */
    public function query(QueryBuilder $builder): array
    {
        ($this->assertResource)();
        try {
            $queryBuilder = $builder instanceof WordPressQueryBuilder
                ? $builder->forDatabase($this->database)
                : throw new \PHPNomad\Database\Exceptions\UnsupportedCoordinationException(
                    'Coordinated WordPress queries require the official WordPress query builder.'
                );
            $sql = $queryBuilder->build();
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get results failed: invalid query.', 500, $e);
        } finally {
            // The supplied builder belongs to the caller's mutable provider.
            // Consume it exactly once without retaining state for a later
            // operation, including when binding/building fails.
            $builder->reset();
        }

        $result = $this->execute($sql);
        if (!$result instanceof \mysqli_result) {
            throw new DatastoreErrorException('Get results failed.');
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_free_result($result);

        if ($rows === []) {
            throw new RecordNotFoundException('No records found for the query.');
        }

        return $rows;
    }

    /** @inheritDoc */
    public function insert(Table $table, array $data): array
    {
        ($this->assertResource)();
        $name = $this->identifier($table->getName());

        if ($data === []) {
            $sql = 'INSERT INTO ' . $name . ' () VALUES ()';
        } else {
            $fields = [];
            $values = [];
            foreach ($data as $field => $value) {
                $fields[] = $this->identifier((string) $field);
                $values[] = $this->literal($value);
            }
            $sql = 'INSERT INTO ' . $name . ' (' . implode(', ', $fields) . ') VALUES ('
                . implode(', ', $values) . ')';
        }

        $this->execute($sql);
        $identity = [];
        $primaryFields = ($this->primaryFields)($table->getName());
        foreach ($primaryFields as $field) {
            if (array_key_exists($field, $data)) {
                $identity[$field] = $data[$field];
            }
        }

        if (count($identity) === count($primaryFields)) {
            return $identity;
        }

        $insertId = mysqli_insert_id($this->connection);
        if (count($primaryFields) !== count($identity) + 1 || $insertId === 0) {
            throw new DatastoreErrorException('Insert identity could not be established.');
        }

        foreach ($primaryFields as $field) {
            if (!array_key_exists($field, $identity)) {
                $identity[$field] = $insertId;
                break;
            }
        }

        return $identity;
    }

    /** @inheritDoc */
    public function delete(Table $table, array $ids): void
    {
        ($this->assertResource)();
        $this->execute($this->mutationSql('DELETE FROM', $table, [], $ids));
    }

    /** @inheritDoc */
    public function update(Table $table, array $where, array $data): void
    {
        if ($data === []) {
            throw new DatastoreErrorException('Update failed - no update data provided.');
        }

        ($this->assertResource)();
        $set = [];
        foreach ($data as $field => $value) {
            $set[] = $this->identifier((string) $field) . ' = ' . $this->literal($value);
        }
        $this->execute($this->mutationSql('UPDATE', $table, $set, $where));
        if (mysqli_affected_rows($this->connection) === 0 && !$this->exists($table, $where)) {
            throw new RecordNotFoundException('Update failed because the record does not exist.');
        }
    }

    /** @inheritDoc */
    public function estimatedCount(Table $table): int
    {
        ($this->assertResource)();
        $result = $this->execute('SELECT COUNT(*) AS `count` FROM ' . $this->identifier($table->getName()));
        if (!$result instanceof \mysqli_result) {
            throw new DatastoreErrorException('Count query failed.');
        }
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        return (int) ($row['count'] ?? 0);
    }

    private function mutationSql(string $verb, Table $table, array $set, array $where): string
    {
        $name = $this->identifier($table->getName());
        $whereSql = $this->conditions($where);

        if ($verb === 'UPDATE') {
            return 'UPDATE ' . $name . ' SET ' . implode(', ', $set) . ' WHERE ' . $whereSql;
        }

        return $verb . ' ' . $name . ' WHERE ' . $whereSql;
    }

    private function exists(Table $table, array $where): bool
    {
        $result = $this->execute('SELECT 1 FROM ' . $this->identifier($table->getName()) . ' WHERE '
            . $this->conditions($where) . ' LIMIT 1');
        if (!$result instanceof \mysqli_result) {
            return false;
        }
        $exists = mysqli_fetch_row($result) !== null;
        mysqli_free_result($result);

        return $exists;
    }

    private function conditions(array $where): string
    {
        $conditions = [];
        foreach ($where as $field => $value) {
            if ($value === null) {
                $conditions[] = $this->identifier((string) $field) . ' IS NULL';
                continue;
            }
            $conditions[] = $this->identifier((string) $field) . ' = ' . $this->literal($value);
        }
        if ($conditions === []) {
            throw new \InvalidArgumentException('A coordinated mutation requires a nonempty identity.');
        }

        return implode(' AND ', $conditions);
    }

    private function execute(string $sql): \mysqli_result|bool
    {
        ($this->assertResource)();
        $result = mysqli_query($this->connection, $sql);
        ($this->assertResource)();
        if ($result === false) {
            throw new DatastoreErrorException(
                'WordPress database query failed.',
                mysqli_errno($this->connection),
                new \RuntimeException(mysqli_error($this->connection))
            );
        }

        return $result;
    }

    private function identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid WordPress database identifier.');
        }

        return '`' . $identifier . '`';
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('WordPress mutation values must be scalar.');
        }

        return "'" . mysqli_real_escape_string($this->connection, $value) . "'";
    }
}
