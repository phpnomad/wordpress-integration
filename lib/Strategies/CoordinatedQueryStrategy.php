<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use mysqli;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy as CoordinatedQueryStrategyInterface;
use PHPNomad\Database\Interfaces\QueryBuilder as CoreQueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Strategies\OperationQueryStrategy;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use Throwable;
use wpdb;

/**
 * Optional coordinated operations for an official WordPress 6.8+ wpdb.
 *
 * The supported profile is deliberately narrow: the exact core wpdb class,
 * one selected MySQL database, and one mysqli session. Drop-ins, subclasses,
 * routers, and reconnecting wrappers remain ordinary-CRUD only.
 */
class CoordinatedQueryStrategy extends QueryStrategy implements CoordinatedQueryStrategyInterface
{
    private ?wpdb $database = null;
    private ?mysqli $connection = null;
    private int $connectionId = 0;
    private bool $transactionActive = false;
    private ?CoreQueryStrategy $activeOperationStrategy = null;
    private ?PinnedQueryStrategy $activePinnedStrategy = null;

    public function __construct(private ?LoggerStrategy $logger = null)
    {
    }

    /** @inheritDoc */
    public function coordinate(
        Table $coordinationTable,
        array $identity,
        array $participants,
        callable $operation
    ) {
        if ($this->database !== null || $this->connection !== null) {
            throw new UnsupportedCoordinationException('A coordinated operation is already active on this strategy.');
        }
        $names = [];
        $operationStrategy = null;

        try {
            $participants = $this->validateInput($coordinationTable, $identity, $participants, $names);
            [$database, $connection] = $this->pinCoreWpdb();
            $this->database = $database;
            $this->connection = $connection;
            $state = $this->sessionState();
            $schema = $state['schema'];
            $this->validateGrantProfile($schema, $names);
            $coordinationPrimary = $this->primaryFields($schema, $coordinationTable->getName());
            $this->validateIdentity($identity, $coordinationPrimary);
        } catch (Throwable $failure) {
            $this->clearAttempt();
            $this->reportFailure('validation', $names, 'unchanged', false, $failure);
            throw $failure;
        }

        try {
            $this->raw('START TRANSACTION');
        } catch (Throwable $failure) {
            $this->clearAttempt();
            $this->reportFailure('coordination', $names, 'unchanged', false, $failure);
            throw $failure;
        }

        try {
            try {
                $this->guard($schema, $coordinationTable->getName(), $coordinationPrimary, $identity);
                $coordinationPrimary = $this->primaryFields($schema, $coordinationTable->getName());
                $this->validateIdentity($identity, $coordinationPrimary);
                $this->validateParticipants($schema, $participants, $coordinationPrimary);
            } catch (Throwable $failure) {
                $this->abort($names, 'coordination', $failure);
            }

            try {
                $delegate = new PinnedQueryStrategy(
                    $this->database,
                    $this->connection,
                    $this->connectionId,
                    function (): void {
                        $this->assertPinned();
                    },
                    function (string $table) use ($schema): array {
                        return $this->primaryFields($schema, $table);
                    }
                );
                $operationStrategy = new OperationQueryStrategy($delegate, $participants);
                $this->activeOperationStrategy = $operationStrategy;
                $this->activePinnedStrategy = $delegate;
                $result = $operation($operationStrategy);
            } catch (Throwable $failure) {
                $this->abort($names, 'callback', $failure);
            }

            $this->assertPinned();
            if (!$this->inTransaction()) {
                $failure = new CoordinatedOperationOutcomeUnknownException(
                    'The coordinated WordPress operation outcome is unknown.',
                    0,
                    new DatastoreErrorException('The transaction ended before commit.')
                );
                $this->reportFailure('commit', $names, 'unknown', false, $failure);
                throw $failure;
            }

            try {
                $this->raw('COMMIT');
            } catch (Throwable $failure) {
                $this->handleCommitFailure($names, $failure);
            }

            return $result;
        } finally {
            if ($operationStrategy instanceof OperationQueryStrategy) {
                $operationStrategy->close();
            }
            $this->activeOperationStrategy = null;
            $this->activePinnedStrategy = null;
            $this->clearAttempt();
        }
    }

    /**
     * Create a fresh builder for the currently active coordinated callback.
     *
     * The operation provider factory uses this seam before handlers prepare
     * clauses. It never exposes the wpdb object or permits a stale callback.
     */
    public function createOperationQueryBuilder(CoreQueryStrategy $operation): CoreQueryBuilder
    {
        $pinned = $this->assertActiveOperation($operation);

        return $pinned->createQueryBuilder();
    }

    /** Create a fresh operation-local clause builder bound to the pinned wpdb. */
    public function createOperationClauseBuilder(CoreQueryStrategy $operation): \PHPNomad\Database\Interfaces\ClauseBuilder
    {
        $pinned = $this->assertActiveOperation($operation);

        return $pinned->createClauseBuilder();
    }

    private function assertActiveOperation(CoreQueryStrategy $operation): PinnedQueryStrategy
    {
        if ($this->activeOperationStrategy === null || $operation !== $this->activeOperationStrategy || $this->activePinnedStrategy === null) {
            throw new UnsupportedCoordinationException('Operation-local WordPress builders are available only inside the active coordinated callback.');
        }

        return $this->activePinnedStrategy;
    }

    private function clearAttempt(): void
    {
        $this->database = null;
        $this->connection = null;
        $this->connectionId = 0;
        $this->transactionActive = false;
    }

    /** @param list<string> $names */
    /** @return non-empty-list<Table> */
    private function validateInput(Table $coordinationTable, array $identity, array $participants, array &$names): array
    {
        if ($participants === [] || !$this->isList($participants)) {
            throw new \InvalidArgumentException('Participants must be a nonempty list of tables.');
        }
        foreach ($participants as $participant) {
            if (!$participant instanceof Table) {
                throw new \InvalidArgumentException('Every participant must be a table descriptor.');
            }
            $name = $participant->getName();
            $names[] = $name;
            $this->identifier($name);
        }
        $coordinationName = $coordinationTable->getName();
        $this->identifier($coordinationName);
        if (!in_array($coordinationName, $names, true)) {
            throw new \InvalidArgumentException('The coordination table must be a participant.');
        }
        if ($identity === []) {
            throw new \InvalidArgumentException('The coordination identity must be nonempty.');
        }
        foreach ($identity as $field => $value) {
            if (!is_string($field)) {
                throw new \InvalidArgumentException('Coordination identity fields must be strings.');
            }
            $this->identifier($field);
            if (!is_int($value) && !is_string($value)) {
                throw new \InvalidArgumentException('Coordination identity values must be integers or strings.');
            }
        }

        $ordered = [];
        foreach ($participants as $participant) {
            if ($participant->getName() === $coordinationName) {
                $ordered[] = $participant;
                break;
            }
        }
        foreach ($participants as $participant) {
            if ($participant->getName() !== $coordinationName) {
                $ordered[] = $participant;
            }
        }

        return $ordered;
    }

    /** @return array{0: wpdb, 1: mysqli} */
    private function pinCoreWpdb(): array
    {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof wpdb) || get_class($wpdb) !== wpdb::class) {
            throw new UnsupportedCoordinationException('Coordination supports only the official core wpdb class.');
        }
        $connection = $wpdb->dbh;
        if (!$connection instanceof mysqli || !$wpdb->ready) {
            throw new UnsupportedCoordinationException('Coordination requires a ready core wpdb mysqli session.');
        }
        $this->connectionId = mysqli_thread_id($connection);
        if ($this->connectionId <= 0) {
            throw new UnsupportedCoordinationException('The core wpdb session identity could not be established.');
        }

        return [$wpdb, $connection];
    }

    /** @return array{schema: string, isolation: string} */
    private function sessionState(): array
    {
        try {
            $rows = $this->rows(
                'SELECT VERSION() AS server_version, @@autocommit AS autocommit, '
                . '@@session.transaction_isolation AS isolation, DATABASE() AS schema_name, '
                . 'CURRENT_ROLE() AS current_role, @@global.partial_revokes AS partial_revokes'
            );
        } catch (Throwable $failure) {
            throw new UnsupportedCoordinationException('The MySQL 8 coordination session profile could not be established.', 0, $failure);
        }
        $state = $rows[0] ?? [];
        if (!is_string($state['server_version'] ?? null) || version_compare($state['server_version'], '8.0.0', '<')) {
            throw new UnsupportedCoordinationException('Coordination requires MySQL 8.0 or newer.');
        }
        if ((string) ($state['autocommit'] ?? '') !== '1' || $this->hasAmbientTransaction()) {
            throw new UnsupportedCoordinationException('Coordination cannot join an ambient or disabled-autocommit transaction.');
        }
        $isolation = strtoupper((string) ($state['isolation'] ?? ''));
        if (!in_array($isolation, ['READ-COMMITTED', 'REPEATABLE-READ'], true)) {
            throw new UnsupportedCoordinationException('The selected transaction isolation is unsupported.');
        }
        if (($state['current_role'] ?? null) !== 'NONE') {
            throw new UnsupportedCoordinationException('Active MySQL roles are unsupported for coordination.');
        }
        if (!in_array(strtoupper((string) ($state['partial_revokes'] ?? '')), ['OFF', '0'], true)) {
            throw new UnsupportedCoordinationException('MySQL partial privilege revokes are unsupported for coordination.');
        }
        $schema = (string) ($state['schema_name'] ?? '');
        if ($schema === '') {
            throw new UnsupportedCoordinationException('Coordination requires a selected database.');
        }

        return ['schema' => $schema, 'isolation' => $isolation];
    }

    /** @param list<string> $tables */
    private function validateGrantProfile(string $schema, array $tables): void
    {
        $rows = $this->rows('SHOW GRANTS FOR CURRENT_USER()');
        $grants = array_map(static fn (array $row): string => (string) array_values($row)[0], $rows);
        foreach ($tables as $table) {
            $visible = false;
            foreach ($grants as $grant) {
                if ($this->grantAllowsTriggers($grant, $schema, $table)) {
                    $visible = true;
                    break;
                }
            }
            if (!$visible) {
                throw new UnsupportedCoordinationException('Direct trigger visibility is required for every participant.');
            }
        }
    }

    private function grantAllowsTriggers(string $grant, string $schema, string $table): bool
    {
        if (stripos($grant, 'REVOKE ') === 0 || !preg_match('/^GRANT (.+) ON (.+) TO /i', $grant, $match)) {
            return false;
        }
        $privileges = array_map('trim', explode(',', strtoupper($match[1])));
        if (!in_array('TRIGGER', $privileges, true) && !in_array('ALL PRIVILEGES', $privileges, true)) {
            return false;
        }
        $resource = trim($match[2]);
        if ($resource === '*.*') {
            return true;
        }
        if (preg_match('/^`((?:``|[^`])*)`\.\*$/D', $resource, $scope)) {
            return str_replace('``', '`', $scope[1]) === $schema;
        }
        if (preg_match('/^`((?:``|[^`])*)`\.`((?:``|[^`])*)`$/D', $resource, $scope)) {
            return str_replace('``', '`', $scope[1]) === $schema
                && str_replace('``', '`', $scope[2]) === $table;
        }

        return false;
    }

    /** @return list<string> */
    private function primaryFields(string $schema, string $table): array
    {
        $rows = $this->rows(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '
            . $this->literal($schema) . ' AND TABLE_NAME = ' . $this->literal($table)
            . " AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX"
        );

        return array_values(array_filter(array_map(static fn (array $row): mixed => $row['COLUMN_NAME'] ?? null, $rows), 'is_string'));
    }

    /** @param list<string> $primary */
    private function validateIdentity(array $identity, array $primary): void
    {
        if ($primary === []) {
            throw new UnsupportedCoordinationException('The coordination table must have a primary key.');
        }
        $fields = array_keys($identity);
        if (count($fields) !== count($primary) || array_diff($fields, $primary) !== [] || array_diff($primary, $fields) !== []) {
            throw new \InvalidArgumentException('The coordination identity must contain every primary field exactly once.');
        }
    }

    /** @param list<string> $primary */
    private function guard(string $schema, string $table, array $primary, array $identity): void
    {
        $conditions = [];
        foreach ($primary as $field) {
            $conditions[] = $this->identifier($field) . ' = ' . $this->literal($identity[$field]);
        }
        $rows = $this->rows(
            'SELECT 1 FROM ' . $this->identifier($table) . ' WHERE ' . implode(' AND ', $conditions) . ' FOR UPDATE'
        );
        if ($rows === []) {
            throw new RecordNotFoundException('The coordination record does not exist.');
        }
    }

    /** @param list<Table> $participants @param list<string> $coordinationPrimary */
    private function validateParticipants(string $schema, array $participants, array $coordinationPrimary): void
    {
        foreach ($participants as $index => $participant) {
            $table = $participant->getName();
            $this->raw('SELECT 1 FROM ' . $this->identifier($table) . ' WHERE 1 = 0 FOR UPDATE');
            $createRows = $this->rows('SHOW CREATE TABLE ' . $this->identifier($table));
            $definition = $createRows[0] ?? [];
            if (isset($definition['Create View']) || preg_match('/^CREATE\s+TEMPORARY\s+TABLE\b/i', (string) ($definition['Create Table'] ?? ''))) {
                throw new UnsupportedCoordinationException('Temporary tables and views are unsupported participants.');
            }

            $metadata = $this->rows(
                'SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
                . $this->literal($schema) . ' AND TABLE_NAME = ' . $this->literal($table)
            )[0] ?? [];
            if (($metadata['TABLE_TYPE'] ?? null) !== 'BASE TABLE' || strtoupper((string) ($metadata['ENGINE'] ?? '')) !== 'INNODB') {
                throw new UnsupportedCoordinationException('Every participant must be an InnoDB base table.');
            }

            $primary = $this->primaryFields($schema, $table);
            if ($primary === []) {
                throw new UnsupportedCoordinationException('Every participant must have a primary key.');
            }
            if ($table === $participants[0]->getName() && $primary !== $coordinationPrimary) {
                throw new \InvalidArgumentException('The coordination identity must match the stable primary key.');
            }

            $triggers = $this->rows(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '
                . $this->literal($schema) . ' AND EVENT_OBJECT_TABLE = ' . $this->literal($table)
            );
            if ($triggers !== []) {
                throw new UnsupportedCoordinationException('Trigger-bearing tables are unsupported participants.');
            }
        }
    }

    /**
     * @param list<string> $tables
     * @return never
     */
    private function abort(array $tables, string $phase, Throwable $failure): void
    {
        try {
            $this->assertPinned();
            if (!$this->inTransaction() || !$this->raw('ROLLBACK')) {
                throw new DatastoreErrorException('The coordinated rollback was not acknowledged.');
            }
        } catch (Throwable $cleanup) {
            $composite = new CoordinatedOperationCleanupFailedException($failure, $cleanup);
            $this->reportFailure('rollback', $tables, 'unknown', false, $composite, $cleanup);
            throw $composite;
        }

        $operationFailure = $this->isContention($failure)
            ? new CoordinatedOperationConflictException('The coordinated WordPress operation conflicted.', 0, $failure)
            : $failure;
        $this->reportFailure($phase, $tables, 'rolled_back', $operationFailure instanceof CoordinatedOperationConflictException, $operationFailure, $failure);
        throw $operationFailure;
    }

    /**
     * @param list<string> $tables
     * @return never
     */
    private function handleCommitFailure(array $tables, Throwable $failure): void
    {
        try {
            if (!$this->inTransaction()) {
                $unknown = new CoordinatedOperationOutcomeUnknownException('The coordinated WordPress operation outcome is unknown.', 0, $failure);
                $this->reportFailure('commit', $tables, 'unknown', false, $unknown, $failure);
                throw $unknown;
            }
            if (!$this->raw('ROLLBACK')) {
                throw new DatastoreErrorException('The coordinated rollback was not acknowledged.');
            }
        } catch (CoordinatedOperationOutcomeUnknownException $unknown) {
            throw $unknown;
        } catch (Throwable $cleanup) {
            $composite = new CoordinatedOperationCleanupFailedException($failure, $cleanup);
            $this->reportFailure('rollback', $tables, 'unknown', false, $composite, $cleanup);
            throw $composite;
        }

        $failed = new DatastoreErrorException('The coordinated WordPress commit failed.', 0, $failure);
        $this->reportFailure('commit', $tables, 'rolled_back', false, $failed, $failure);
        throw $failed;
    }

    /** @param list<string> $tables */
    private function reportFailure(string $phase, array $tables, string $outcome, bool $retryable, Throwable $operationFailure, ?Throwable $cause = null): void
    {
        if ($this->logger === null) {
            return;
        }
        $cause ??= $operationFailure;
        try {
            $this->logger->error('Coordinated database operation failed.', [
                'phase' => $phase,
                'tables' => $tables,
                'outcome' => $outcome,
                'retryable' => $retryable,
                'causeClass' => get_class($cause),
                'sqlState' => $this->sqlState($cause),
                'driverCode' => $this->driverCode($cause),
            ]);
        } catch (Throwable $reportingFailure) {
            throw new CoordinatedOperationReportingFailedException($operationFailure, $reportingFailure);
        }
    }

    private function isContention(Throwable $failure): bool
    {
        $code = $this->driverCode($failure);
        $state = $this->sqlState($failure);
        return ($state === '40001' && $code === 1213) || ($state === 'HY000' && $code === 1205) || in_array($code, [1205, 1213], true);
    }

    private function sqlState(Throwable $failure): ?string
    {
        for ($node = $failure; $node !== null; $node = $node->getPrevious()) {
            if (property_exists($node, 'sqlstate') && is_string($node->sqlstate)) {
                return $node->sqlstate;
            }
        }
        return null;
    }

    private function driverCode(Throwable $failure): ?int
    {
        for ($node = $failure; $node !== null; $node = $node->getPrevious()) {
            if ($node->getCode() !== 0) {
                return (int) $node->getCode();
            }
        }
        return null;
    }

    private function inTransaction(): bool
    {
        if (!$this->transactionActive) {
            return false;
        }
        $this->assertPinned();
        $probe = mysqli_query($this->connection, 'SAVEPOINT __nomad_coordination_probe');
        if ($probe === false) {
            return false;
        }
        $rollback = mysqli_query($this->connection, 'ROLLBACK TO SAVEPOINT __nomad_coordination_probe');

        return $rollback !== false;
    }

    private function hasAmbientTransaction(): bool
    {
        $this->assertPinned();
        $probe = mysqli_query($this->connection, 'SAVEPOINT __nomad_coordination_ambient_probe');
        if ($probe === false) {
            return false;
        }
        $rollback = mysqli_query($this->connection, 'ROLLBACK TO SAVEPOINT __nomad_coordination_ambient_probe');

        return $rollback !== false;
    }

    private function raw(string $sql): bool
    {
        $this->assertPinned();
        $result = mysqli_query($this->connection, $sql);
        $this->assertPinned();
        if ($result === false) {
            throw new DatastoreErrorException('WordPress database command failed.', mysqli_errno($this->connection), new \RuntimeException(mysqli_error($this->connection)));
        }
        if ($result instanceof \mysqli_result) {
            mysqli_free_result($result);
        }
        if (preg_match('/^START\s+TRANSACTION\b/i', $sql)) {
            $this->transactionActive = true;
        } elseif (preg_match('/^(COMMIT|ROLLBACK)\b/i', $sql)) {
            $this->transactionActive = false;
        }
        return true;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $this->assertPinned();
        $result = mysqli_query($this->connection, $sql);
        $this->assertPinned();
        if ($result === false) {
            throw new DatastoreErrorException('WordPress database query failed.', mysqli_errno($this->connection), new \RuntimeException(mysqli_error($this->connection)));
        }
        if (!$result instanceof \mysqli_result) {
            return [];
        }
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_free_result($result);
        return $rows;
    }

    private function assertPinned(): void
    {
        global $wpdb;
        if ($this->database === null || $this->connection === null || $wpdb !== $this->database || $wpdb->dbh !== $this->connection) {
            throw new CoordinatedOperationOutcomeUnknownException('The core wpdb resource changed during coordination.');
        }
        if (mysqli_thread_id($this->connection) !== $this->connectionId) {
            throw new CoordinatedOperationOutcomeUnknownException('The core wpdb session changed during coordination.');
        }
    }

    private function identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid WordPress database identifier.');
        }
        return '`' . $identifier . '`';
    }

    private function literal(string $value): string
    {
        return "'" . mysqli_real_escape_string($this->connection, $value) . "'";
    }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
