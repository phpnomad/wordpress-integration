<?php

declare(strict_types=1);

namespace PHPNomad\Integrations\WordPress\Tests\Integration\Database;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Di\Container\Container;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Database\QueryBuilder;
use PHPNomad\Integrations\WordPress\Strategies\QueryStrategy;
use PHPNomad\Integrations\WordPress\Strategies\WordPressInitializer;
use PHPNomad\Integrations\WordPress\Tests\Integration\Support\ContractTable;
use PHPNomad\Loader\Bootstrapper;
use PHPUnit\Framework\TestCase;
use Throwable;
use wpdb;

final class RealWpdbQueryContractTest extends TestCase
{
    private const PREDICATE_TABLE = 'nomad_wpdb_contract_predicates';
    private const COMPOUND_TABLE = 'nomad_wpdb_contract_compound';
    private const COMPOUND_CONTROL_TABLE = 'nomad_wpdb_contract_compound_control';

    private static wpdb $wpdb;
    private static QueryStrategy $strategy;
    private static ContractTable $predicateTable;
    private static ContractTable $compoundTable;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(wpdb::class)) {
            self::markTestSkipped('Set WORDPRESS_ROOT to the pinned official WordPress source tree.');
        }

        $host = self::environment('MYSQL_HOST', '127.0.0.1');
        $port = self::environment('MYSQL_PORT', '3306');
        $user = self::environment('MYSQL_USER', 'root');
        $password = self::environment('MYSQL_PASSWORD', '');
        $database = self::environment('MYSQL_DATABASE', 'phpnomad_wordpress_integration_test');

        if (!extension_loaded('mysqli')) {
            self::markTestSkipped('The real wpdb integration suite requires ext-mysqli.');
        }

        $probe = mysqli_init();
        mysqli_options($probe, MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        try {
            $connected = @mysqli_real_connect($probe, $host, $user, $password, $database, (int) $port);
        } catch (\mysqli_sql_exception) {
            $connected = false;
        }

        if (!$connected) {
            self::markTestSkipped(sprintf(
                'The real wpdb integration database is unavailable at %s:%s for schema %s.',
                $host,
                $port,
                $database
            ));
        }

        mysqli_close($probe);

        self::$wpdb = new wpdb($user, $password, $database, $host . ':' . $port);
        self::$wpdb->suppress_errors(true);
        $GLOBALS['wpdb'] = self::$wpdb;

        if (!self::$wpdb->ready) {
            throw new \RuntimeException('wpdb could not connect: ' . self::$wpdb->last_error);
        }

        self::$strategy = new QueryStrategy();
        self::$predicateTable = new ContractTable(
            self::PREDICATE_TABLE,
            'predicates',
            [
                new Column('id', 'INT', null, 'PRIMARY KEY'),
                new Column('score', 'INT'),
                new Column('label', 'VARCHAR', [128]),
            ],
            ['id']
        );
        self::$compoundTable = new ContractTable(
            self::COMPOUND_TABLE,
            'compound_records',
            [
                new Column('leftId', 'INT'),
                new Column('rightId', 'INT'),
                new Column('label', 'VARCHAR', [128]),
                new Column('value', 'VARCHAR', [128]),
            ],
            ['leftId', 'rightId']
        );

        self::rawQuery(
            'CREATE TEMPORARY TABLE ' . self::PREDICATE_TABLE
            . ' (id INT PRIMARY KEY, score INT NULL, label VARCHAR(128) NOT NULL) ENGINE=InnoDB'
        );

        foreach ([self::COMPOUND_TABLE, self::COMPOUND_CONTROL_TABLE] as $tableName) {
            self::rawQuery(
                "CREATE TEMPORARY TABLE {$tableName} ("
                . 'leftId INT NOT NULL, rightId INT NOT NULL, label VARCHAR(128) NOT NULL, '
                . 'value VARCHAR(128) NOT NULL, PRIMARY KEY (leftId, rightId)) ENGINE=InnoDB'
            );
        }
    }

    protected function setUp(): void
    {
        self::rawQuery('DELETE FROM ' . self::PREDICATE_TABLE);
        self::rawQuery(
            "INSERT INTO " . self::PREDICATE_TABLE . " (id, score, label) VALUES "
            . "(1,30,'first'),(50,70,'target'),(60,NULL,'null')"
        );
        self::preparedQuery(
            'INSERT INTO ' . self::PREDICATE_TABLE . ' (id, score, label) VALUES (%d,%d,%s)',
            [70, 90, self::literalMarkerValue()]
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$wpdb)) {
            return;
        }

        foreach ([self::PREDICATE_TABLE, self::COMPOUND_TABLE, self::COMPOUND_CONTROL_TABLE] as $tableName) {
            self::rawQuery("DROP TEMPORARY TABLE IF EXISTS {$tableName}");
        }

        unset($GLOBALS['wpdb']);
    }

    public function testPackageBootstrapResolvesAndExecutesCoreQueryStrategy(): void
    {

        $container = new Container();
        (new Bootstrapper($container, new WordPressInitializer()))->load();
        $strategy = $container->get(CoreQueryStrategy::class);
        $clause = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('id', '=', 50);

        self::assertInstanceOf(QueryStrategy::class, $strategy);
        self::assertSame(
            self::rawSelect('SELECT * FROM ' . self::PREDICATE_TABLE . ' WHERE id=50 ORDER BY id'),
            $strategy->query(self::selectQuery($clause))
        );
    }

    /**
     * @dataProvider validPredicateProvider
     * @param list<mixed> $values
     */
    public function testValidPredicatesMatchLiteralSqlControl(
        string $entryMethod,
        string $operator,
        array $values,
        string $literal
    ): void {

        $clause = (new ClauseBuilder())->useTable(self::$predicateTable);
        $prefix = '';

        if ($entryMethod === 'andWhere') {
            $clause->where('id', '>', 0);
            $prefix = 'id > 0 AND ';
        } elseif ($entryMethod === 'orWhere') {
            $clause->where('id', '=', -1);
            $prefix = 'id = -1 OR ';
        }

        $clause->{$entryMethod}('score', $operator, ...$values)
            ->orWhere('id', '=', 50);

        $actual = self::$strategy->query(self::selectQuery($clause));
        $control = self::rawSelect(
            'SELECT * FROM ' . self::PREDICATE_TABLE
            . ' WHERE ' . $prefix . $literal . ' OR id = 50 ORDER BY id'
        );

        self::assertSame($control, $actual);
    }

    public static function validPredicateProvider(): iterable
    {
        yield 'where scalar null' => ['where', '=', [null], 'score = NULL'];
        yield 'andWhere scalar' => ['andWhere', '>=', [30], 'score >= 30'];
        yield 'orWhere scalar' => ['orWhere', '<', [40], 'score < 40'];
        yield 'where less than or equal' => ['where', '<=', [30], 'score <= 30'];
        yield 'andWhere alternate not equal' => ['andWhere', '<>', [30], 'score <> 30'];
        yield 'orWhere not equal' => ['orWhere', '!=', [30], 'score != 30'];
        yield 'where not like' => ['where', 'NOT LIKE', [30], 'score NOT LIKE 30'];
        yield 'where between with null lower bound' => ['where', 'BETWEEN', [null, 40], 'score BETWEEN NULL AND 40'];
        yield 'andWhere between' => ['andWhere', 'BETWEEN', [20, 40], 'score BETWEEN 20 AND 40'];
        yield 'orWhere not between' => ['orWhere', 'NOT BETWEEN', [20, 40], 'score NOT BETWEEN 20 AND 40'];
        yield 'where variadic list with null' => ['where', 'IN', [null, 30], 'score IN (NULL, 30)'];
        yield 'andWhere nested list with null' => ['andWhere', 'IN', [[null, 30]], 'score IN (NULL, 30)'];
        yield 'orWhere nested empty list' => ['orWhere', 'IN', [[]], 'score IN (NULL)'];
        yield 'where not in list with null' => ['where', 'NOT IN', [30, null], 'score NOT IN (30, NULL)'];
        yield 'andWhere is null without value' => ['andWhere', 'IS NULL', [], 'score IS NULL'];
        yield 'orWhere is null with null value' => ['orWhere', 'IS NULL', [null], 'score IS NULL'];
        yield 'where is not null' => ['where', 'IS NOT NULL', [], 'score IS NOT NULL'];
        yield 'orWhere like' => ['orWhere', 'LIKE', [30], 'score LIKE 30'];
    }

    /** @dataProvider tupleListProvider */
    public function testTupleListMatchesLiteralSqlControl(string $operator): void
    {

        $clause = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where(
                ['id', 'score'],
                $operator,
                ['id' => 1, 'score' => 30],
                ['id' => 50, 'score' => 70]
            );

        $actual = self::$strategy->query(self::selectQuery($clause));
        $control = self::rawSelect(
            'SELECT * FROM ' . self::PREDICATE_TABLE
            . " WHERE (id, score) {$operator} ((1,30),(50,70)) ORDER BY id"
        );

        self::assertSame($control, $actual);
    }

    public static function tupleListProvider(): iterable
    {
        yield 'IN' => ['IN'];
        yield 'NOT IN' => ['NOT IN'];
    }

    /** @dataProvider groupEntryProvider */
    public function testGroupsKeepParentAndChildValuePositions(string $groupMethod): void
    {

        $firstChild = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('label', '=', 'first')
            ->andWhere('score', '=', 30);
        $secondChild = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('id', '=', 50);
        $clause = (new ClauseBuilder())->useTable(self::$predicateTable);

        if ($groupMethod === 'group') {
            $clause->group('OR', $firstChild, $secondChild)->andWhere('id', '>', 0);
            $literal = "((label = 'first' AND score = 30) OR id = 50) AND id > 0";
        } elseif ($groupMethod === 'andGroup') {
            $clause->where('id', '>', 0)->andGroup('OR', $firstChild, $secondChild)->orWhere('id', '=', 60);
            $literal = "id > 0 AND ((label = 'first' AND score = 30) OR id = 50) OR id = 60";
        } else {
            $clause->where('id', '=', -1)->orGroup('OR', $firstChild, $secondChild)->andWhere('id', '<', 60);
            $literal = "id = -1 OR ((label = 'first' AND score = 30) OR id = 50) AND id < 60";
        }

        $actual = self::$strategy->query(self::selectQuery($clause));
        $control = self::rawSelect(
            'SELECT * FROM ' . self::PREDICATE_TABLE . ' WHERE ' . $literal . ' ORDER BY id'
        );

        self::assertSame($control, $actual);
    }

    public static function groupEntryProvider(): iterable
    {
        yield 'group' => ['group'];
        yield 'andGroup' => ['andGroup'];
        yield 'orGroup' => ['orGroup'];
    }

    public function testLiteralPlaceholderAndSubqueryMarkerTextStaysDataAcrossNestedGroups(): void
    {

        $literal = self::literalMarkerValue();
        $grandchild = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('id', '=', 50);
        $child = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('label', '=', $literal)
            ->orGroup('AND', $grandchild);
        $clause = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('label', '=', $literal)
            ->orGroup('AND', $child);

        $actual = self::$strategy->query(self::selectQuery($clause));
        $control = self::preparedSelect(
            'SELECT * FROM ' . self::PREDICATE_TABLE
            . ' WHERE label=%s OR (label=%s OR (id=%d)) ORDER BY id',
            [$literal, $literal, 50]
        );

        self::assertSame($control, $actual);
    }

    /**
     * @dataProvider invalidConditionProvider
     * @param string|list<string> $field
     * @param list<mixed> $values
     */
    public function testInvalidConditionsRejectBeforeChangingBuilderState(
        string $entryMethod,
        $field,
        string $operator,
        array $values
    ): void {

        $clause = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('id', '=', 50);
        $lastQuery = self::$wpdb->last_query;
        $caught = null;

        try {
            $clause->{$entryMethod}($field, $operator, ...$values);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertSame($lastQuery, self::$wpdb->last_query);

        $actual = self::$strategy->query(
            self::selectQuery($clause->andWhere('score', '=', 70))
        );

        self::assertInstanceOf(QueryBuilderException::class, $caught);
        self::assertSame(
            [['id' => '50', 'score' => '70', 'label' => 'target']],
            $actual
        );
    }

    public static function invalidConditionProvider(): iterable
    {
        yield 'where zero-argument IN' => ['where', 'score', 'IN', []];
        yield 'andWhere zero-argument NOT IN' => ['andWhere', 'score', 'NOT IN', []];
        yield 'orWhere short BETWEEN' => ['orWhere', 'score', 'BETWEEN', [20]];
        yield 'where long NOT BETWEEN' => ['where', 'score', 'NOT BETWEEN', [20, 40, 60]];
        yield 'andWhere missing scalar value' => ['andWhere', 'score', '=', []];
        yield 'orWhere extra scalar value' => ['orWhere', 'score', '=', [30, 70]];
        yield 'where invalid null arity' => ['where', 'score', 'IS NULL', [null, null]];
        yield 'andWhere unknown operator' => ['andWhere', 'score', 'CONTAINS', [30]];
        yield 'orWhere unknown scalar field' => ['orWhere', 'missing', '=', [30]];
        yield 'where tuple with unknown field' => ['where', ['id', 'missing'], 'IN', [[1, 30]]];
        yield 'andWhere empty field tuple' => ['andWhere', [], 'IN', [[1, 30]]];
        yield 'orWhere short tuple row' => ['orWhere', ['id', 'score'], 'IN', [[1]]];
        yield 'where long tuple row' => ['where', ['id', 'score'], 'IN', [[1, 30, 999]]];
        yield 'andWhere scalar tuple row' => ['andWhere', ['id', 'score'], 'IN', [1]];
        yield 'orWhere mixed tuple rows' => ['orWhere', ['id', 'score'], 'IN', [[1, 30], 50]];
    }

    /** @dataProvider groupEntryProvider */
    public function testInvalidGroupLogicRejectsBeforeChangingBuilderState(string $groupMethod): void
    {

        $clause = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('id', '=', 50);
        $child = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('score', '=', 70);
        $caught = null;

        try {
            $clause->{$groupMethod}('XOR', $child);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        $actual = self::$strategy->query(
            self::selectQuery($clause->andWhere('label', '=', 'target'))
        );

        self::assertInstanceOf(QueryBuilderException::class, $caught);
        self::assertSame(
            [['id' => '50', 'score' => '70', 'label' => 'target']],
            $actual
        );
    }

    /** @dataProvider groupEntryProvider */
    public function testEmptyGroupRejectsBeforeChangingBuilderState(string $groupMethod): void
    {

        $clause = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('id', '=', 50);
        $caught = null;

        try {
            $clause->{$groupMethod}('AND');
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        $actual = self::$strategy->query(
            self::selectQuery($clause->andWhere('score', '=', 70))
        );

        self::assertInstanceOf(QueryBuilderException::class, $caught);
        self::assertSame(
            [['id' => '50', 'score' => '70', 'label' => 'target']],
            $actual
        );
    }

    public function testMalformedNestedGroupFailsBeforeWpdbExecutesSql(): void
    {

        $lastQuery = self::$wpdb->last_query;
        $clause = null;
        $assemblyFailure = self::captureFailure(static function () use (&$clause): void {
            $emptyChild = (new ClauseBuilder())->useTable(self::$predicateTable);
            $clause = (new ClauseBuilder())
                ->useTable(self::$predicateTable)
                ->where('id', '=', 50)
                ->andGroup('AND', $emptyChild);
        });

        if ($assemblyFailure !== null) {
            self::assertInstanceOf(QueryBuilderException::class, $assemblyFailure);
            self::assertSame($lastQuery, self::$wpdb->last_query);
            return;
        }

        $strategyFailure = self::captureFailure(
            static fn () => self::$strategy->query(self::selectQuery($clause))
        );

        self::assertSame(DatastoreErrorException::class, $strategyFailure ? get_class($strategyFailure) : null);
        self::assertInstanceOf(QueryBuilderException::class, $strategyFailure?->getPrevious());
        self::assertSame($lastQuery, self::$wpdb->last_query);
    }

    public function testQueryStrategyWrapsBuilderFailureWithOriginalCause(): void
    {

        $cause = new QueryBuilderException('Malformed query contract fixture.');
        $builder = new class ($cause) extends QueryBuilder {
            public function __construct(private QueryBuilderException $failure)
            {
            }

            public function build(): string
            {
                throw $this->failure;
            }
        };
        $caught = self::captureFailure(static fn () => self::$strategy->query($builder));

        self::assertSame(DatastoreErrorException::class, $caught ? get_class($caught) : null);
        self::assertSame($cause, $caught?->getPrevious());
    }

    public function testQueryStrategyClassifiesWpdbSqlErrorAsDatastoreError(): void
    {

        $builder = new class extends QueryBuilder {
            public function build(): string
            {
                return 'SELECT * FROM nomad_contract_table_that_does_not_exist';
            }
        };
        $caught = self::captureFailure(static fn () => self::$strategy->query($builder));

        self::assertSame(DatastoreErrorException::class, $caught ? get_class($caught) : null);
        self::assertNotSame('', self::$wpdb->last_error);
    }

    public function testQueryStrategyKeepsGenuineEmptyResultAsRecordNotFound(): void
    {

        $clause = (new ClauseBuilder())
            ->useTable(self::$predicateTable)
            ->where('id', '=', -999);
        $caught = self::captureFailure(
            static fn () => self::$strategy->query(self::selectQuery($clause))
        );

        self::assertSame(RecordNotFoundException::class, $caught ? get_class($caught) : null);
        self::assertSame('', self::$wpdb->last_error);
    }

    public function testCompoundInsertReturnsAndPersistsWholeIdentity(): void
    {

        self::resetCompoundTables();
        $data = ['leftId' => 4, 'rightId' => 40, 'label' => 'inserted', 'value' => 'four'];
        $identity = self::$strategy->insert(self::$compoundTable, $data);
        self::preparedQuery(
            'INSERT INTO ' . self::COMPOUND_CONTROL_TABLE
            . ' (leftId, rightId, label, value) VALUES (%d,%d,%s,%s)',
            array_values($data)
        );

        self::assertSame(['leftId' => 4, 'rightId' => 40], $identity);
        self::assertSame(self::compoundRows(self::COMPOUND_CONTROL_TABLE), self::compoundRows(self::COMPOUND_TABLE));
    }

    /** @dataProvider compoundWriteProvider */
    public function testCompoundWritesMatchPreparedSqlControl(string $operation): void
    {

        self::resetCompoundTables();

        if ($operation === 'update') {
            self::$strategy->update(
                self::$compoundTable,
                ['leftId' => 2, 'rightId' => 20],
                ['value' => 'compound-updated']
            );
            self::preparedQuery(
                'UPDATE ' . self::COMPOUND_CONTROL_TABLE . ' SET value=%s WHERE leftId=%d AND rightId=%d',
                ['compound-updated', 2, 20]
            );
        } else {
            self::$strategy->delete(self::$compoundTable, ['leftId' => 2, 'rightId' => 20]);
            self::preparedQuery(
                'DELETE FROM ' . self::COMPOUND_CONTROL_TABLE . ' WHERE leftId=%d AND rightId=%d',
                [2, 20]
            );
        }

        self::assertSame(self::compoundRows(self::COMPOUND_CONTROL_TABLE), self::compoundRows(self::COMPOUND_TABLE));
    }

    public static function compoundWriteProvider(): iterable
    {
        yield 'update' => ['update'];
        yield 'delete' => ['delete'];
    }

    public function testLiteralPlaceholderAndMarkerStringsStayDataOnInsert(): void
    {

        self::resetCompoundTables();
        $literal = self::literalMarkerValue() . ' ?s ?n ?i ?a ?u ?p';
        $data = ['leftId' => 4, 'rightId' => 40, 'label' => $literal, 'value' => $literal];
        $identity = self::$strategy->insert(self::$compoundTable, $data);
        self::preparedQuery(
            'INSERT INTO ' . self::COMPOUND_CONTROL_TABLE
            . ' (leftId, rightId, label, value) VALUES (%d,%d,%s,%s)',
            array_values($data)
        );

        self::assertSame(['leftId' => 4, 'rightId' => 40], $identity);
        self::assertSame(self::compoundRows(self::COMPOUND_CONTROL_TABLE), self::compoundRows(self::COMPOUND_TABLE));
    }

    public function testLiteralPlaceholderStringsStayDataAcrossCompoundWrites(): void
    {

        $literal = 'literal %s %d %f %i %% ?s ?n ?i ?a ?u ?p';
        self::resetCompoundTables($literal);
        self::$strategy->update(
            self::$compoundTable,
            ['leftId' => 2, 'rightId' => 20],
            ['value' => $literal]
        );
        self::preparedQuery(
            'UPDATE ' . self::COMPOUND_CONTROL_TABLE . ' SET value=%s WHERE leftId=%d AND rightId=%d',
            [$literal, 2, 20]
        );

        self::assertSame(self::compoundRows(self::COMPOUND_CONTROL_TABLE), self::compoundRows(self::COMPOUND_TABLE));

        self::$strategy->delete(self::$compoundTable, ['leftId' => 2, 'rightId' => 20]);
        self::preparedQuery(
            'DELETE FROM ' . self::COMPOUND_CONTROL_TABLE . ' WHERE leftId=%d AND rightId=%d',
            [2, 20]
        );

        self::assertSame(self::compoundRows(self::COMPOUND_CONTROL_TABLE), self::compoundRows(self::COMPOUND_TABLE));
    }

    public function testZeroRowUpdateWrapsExistenceProbeBuilderFailure(): void
    {

        self::resetCompoundTables();
        $incompleteMetadata = new ContractTable(
            self::COMPOUND_TABLE,
            'compound_records',
            [new Column('value', 'VARCHAR', [128])],
            ['leftId', 'rightId']
        );
        $caught = self::captureFailure(static function () use ($incompleteMetadata): void {
            self::$strategy->update(
                $incompleteMetadata,
                ['leftId' => 999, 'rightId' => 999],
                ['value' => 'missing']
            );
        });

        self::assertSame(DatastoreErrorException::class, $caught ? get_class($caught) : null);
        self::assertInstanceOf(QueryBuilderException::class, $caught?->getPrevious());
        self::assertSame(self::compoundRows(self::COMPOUND_CONTROL_TABLE), self::compoundRows(self::COMPOUND_TABLE));
    }

    private static function selectQuery(ClauseBuilder $clause): QueryBuilder
    {
        return (new QueryBuilder())
            ->from(self::$predicateTable)
            ->select('*')
            ->where($clause)
            ->orderBy('id', 'ASC');
    }

    private static function resetCompoundTables(string $secondValue = 'two'): void
    {
        foreach ([self::COMPOUND_TABLE, self::COMPOUND_CONTROL_TABLE] as $tableName) {
            self::rawQuery("DELETE FROM {$tableName}");
            self::preparedQuery(
                "INSERT INTO {$tableName} (leftId, rightId, label, value) VALUES "
                . '(%d,%d,%s,%s),(%d,%d,%s,%s),(%d,%d,%s,%s),(%d,%d,%s,%s)',
                [
                    1, 10, 'keep-one', 'one',
                    2, 20, 'target', $secondValue,
                    2, 21, 'sibling', 'sibling',
                    3, 30, 'keep-three', 'three',
                ]
            );
        }
    }

    /** @return list<array<string, string>> */
    private static function compoundRows(string $tableName): array
    {
        return self::rawSelect(
            "SELECT leftId, rightId, label, value FROM {$tableName} ORDER BY leftId, rightId"
        );
    }

    private static function rawQuery(string $sql): void
    {
        $result = self::$wpdb->query($sql);

        if ($result === false || self::$wpdb->last_error !== '') {
            throw new \RuntimeException('wpdb query failed: ' . self::$wpdb->last_error . ' SQL=' . $sql);
        }
    }

    /** @param list<mixed> $values */
    private static function preparedQuery(string $sql, array $values): void
    {
        $prepared = self::$wpdb->prepare($sql, ...$values);

        if (!is_string($prepared) || $prepared === '') {
            throw new \RuntimeException('wpdb could not prepare control SQL: ' . $sql);
        }

        self::rawQuery($prepared);
    }

    /** @return list<array<string, string>> */
    private static function rawSelect(string $sql): array
    {
        $result = self::$wpdb->get_results($sql, ARRAY_A);

        if (!is_array($result) || self::$wpdb->last_error !== '') {
            throw new \RuntimeException('wpdb control SELECT failed: ' . self::$wpdb->last_error . ' SQL=' . $sql);
        }

        return $result;
    }

    /**
     * @param list<mixed> $values
     * @return list<array<string, string>>
     */
    private static function preparedSelect(string $sql, array $values): array
    {
        $prepared = self::$wpdb->prepare($sql, ...$values);

        if (!is_string($prepared) || $prepared === '') {
            throw new \RuntimeException('wpdb could not prepare control SELECT: ' . $sql);
        }

        return self::rawSelect($prepared);
    }

    private static function literalMarkerValue(): string
    {
        return "__NOMADIC_SUBQUERY__1 %s %i %% O'Reilly";
    }

    private static function captureFailure(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
