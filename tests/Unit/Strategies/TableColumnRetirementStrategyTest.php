<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Strategies;

use Mockery;
use PHPNomad\Database\Exceptions\TableUpdateFailedException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableColumnRetirementStrategy as RetirementContract;
use PHPNomad\Integrations\WordPress\Strategies\TableUpdateStrategy;
use PHPNomad\Integrations\WordPress\Tests\TestCase;

/**
 * Acceptance contract for additive sync and explicit named-column retirement.
 *
 * These tests intentionally precede the adapter implementation.
 * @covers \PHPNomad\Integrations\WordPress\Strategies\TableUpdateStrategy
 */
final class TableColumnRetirementStrategyTest extends TestCase
{
    private RetirementWpdb $database;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        $this->database = new RetirementWpdb();
        $GLOBALS['wpdb'] = $this->database;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testStrategyExposesTheOptionalCapability(): void
    {
        self::assertInstanceOf(RetirementContract::class, new TableUpdateStrategy());
    }

    public function testDefaultSyncIsAdditiveAndPreservesUnknownColumns(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id', 'unrelatedUnknown'];

        (new TableUpdateStrategy())->syncColumns($this->table(['id']));

        self::assertSame([], $this->database->alterQueries());
    }

    public function testColumnExistsUsesTheActiveSchemaMetadata(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['legacyValue'];

        self::assertTrue((new TableUpdateStrategy())->columnExists($this->table(), 'legacyValue'));
        self::assertStringContainsString('TABLE_SCHEMA = DATABASE()', $this->database->queries[0]);
    }

    public function testMissingColumnIsReportedAsAbsent(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id'];

        self::assertFalse((new TableUpdateStrategy())->columnExists($this->table(), 'legacyValue'));
    }

    public function testMetadataFailureIsNotClassifiedAsAbsence(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['legacyValue'];
        $this->database->failMetadata = true;

        $this->expectException(TableUpdateFailedException::class);
        (new TableUpdateStrategy())->columnExists($this->table(), 'legacyValue');
    }

    public function testRetirementDropsOnlyTheNamedColumn(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id', 'legacyValue', 'unrelatedUnknown'];
        (new TableUpdateStrategy())->retireColumns($this->table(), 'legacyValue');

        self::assertCount(1, $this->database->alterQueries());
        self::assertStringContainsString('DROP COLUMN `legacyValue`', $this->database->alterQueries()[0]);
        self::assertStringNotContainsString('unrelatedUnknown', $this->database->alterQueries()[0]);
    }

    public function testAbsentNamedColumnIsAnIdempotentNoOp(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id', 'unrelatedUnknown'];
        $strategy = new TableUpdateStrategy();

        $strategy->retireColumns($this->table(), 'legacyValue');
        $strategy->retireColumns($this->table(), 'legacyValue');

        self::assertSame([], $this->database->alterQueries());
    }

    public function testEmptyRetirementRequestIsRejectedBeforeDdl(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        try {
            (new TableUpdateStrategy())->retireColumns($this->table());
            self::fail('An empty retirement request must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $this->database->alterQueries());
        }
    }

    /** @dataProvider invalidColumnNames */
    public function testEmptyOrNulColumnNameIsRejectedBeforeDdl(string $name): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        try {
            (new TableUpdateStrategy())->retireColumns($this->table(), 'legacyValue', $name);
            self::fail('The invalid name must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $this->database->alterQueries());
        }
    }

    public function invalidColumnNames(): array
    {
        return [[''], ["legacy\0Value"]];
    }

    public function testCaseVariantOfDeclaredColumnIsRejectedBeforeDdl(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id'];

        try {
            (new TableUpdateStrategy())->retireColumns($this->table(), 'ID');
            self::fail('MySQL column identifiers are case-insensitive.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $this->database->alterQueries());
        }
    }

    public function testOneDeclaredNameRejectsTheWholeBatchBeforeDdl(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id', 'legacyValue'];

        try {
            (new TableUpdateStrategy())->retireColumns($this->table(), 'legacyValue', 'ID');
            self::fail('A mixed valid/invalid batch must not partially execute.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $this->database->alterQueries());
        }
    }

    public function testQuotedBackendIdentifiersAreAccepted(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id', 'legacy value', 'odd`name'];
        (new TableUpdateStrategy())->retireColumns($this->table(), 'legacy value', 'odd`name');

        self::assertStringContainsString('DROP COLUMN `legacy value`', $this->database->alterQueries()[0]);
        self::assertStringContainsString('DROP COLUMN `odd``name`', $this->database->alterQueries()[0]);
        self::assertStringStartsWith('ALTER TABLE `sample-table` ', $this->database->alterQueries()[0]);
    }

    public function testIndexedColumnIsRejectedWithoutDdl(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id', 'legacyValue'];
        $this->database->indexedColumns = ['legacyValue'];

        try {
            (new TableUpdateStrategy())->retireColumns($this->table(), 'legacyValue');
            self::fail('Index retirement is outside this capability.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $this->database->alterQueries());
        }
    }

    public function testForeignKeyColumnIsRejectedWithoutDdl(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id', 'legacyValue'];
        $this->database->foreignKeyColumns = ['legacyValue'];

        try {
            (new TableUpdateStrategy())->retireColumns($this->table(), 'legacyValue');
            self::fail('Foreign-key retirement is outside this capability.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $this->database->alterQueries());
        }
    }

    public function testDdlFailureIsWrapped(): void
    {
        $this->markTestIncomplete('Implementation follows architecture approval.');
        $this->database->columns = ['id', 'legacyValue'];
        $this->database->failAlter = true;

        $this->expectException(TableUpdateFailedException::class);
        (new TableUpdateStrategy())->retireColumns($this->table(), 'legacyValue');
    }

    /** @param list<string> $declaredNames */
    private function table(array $declaredNames = ['id']): Table
    {
        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getName')->andReturn('sample-table');
        $table->shouldReceive('getColumns')->andReturn(array_map(
            static fn (string $name): Column => new Column($name, 'BIGINT'),
            $declaredNames
        ));

        return $table;
    }
}

/** Minimal wpdb boundary fixture: metadata in, recorded DDL out. */
final class RetirementWpdb
{
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = [];
    /** @var list<string> */
    public array $columns = [];
    /** @var list<string> */
    public array $indexedColumns = [];
    /** @var list<string> */
    public array $foreignKeyColumns = [];
    public bool $failMetadata = false;
    public bool $failAlter = false;

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        foreach ($args as $arg) {
            $position = strpos($query, '%i');
            if ($position !== false) {
                $quoted = '`' . str_replace('`', '``', (string) $arg) . '`';
                $query = substr_replace($query, $quoted, $position, 2);
                continue;
            }

            $position = strpos($query, '%s');
            if ($position !== false) {
                $quoted = "'" . str_replace("'", "''", (string) $arg) . "'";
                $query = substr_replace($query, $quoted, $position, 2);
            }
        }

        return $query;
    }

    public function get_results(string $query, string $output): array
    {
        $this->queries[] = $query;
        if ($this->failMetadata) {
            $this->last_error = 'metadata failed';
            return [];
        }
        if (stripos($query, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            return array_map(static fn (string $name): array => [
                'COLUMN_NAME' => $name,
                'COLUMN_TYPE' => 'bigint',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ], $this->columns);
        }
        if (stripos($query, 'INFORMATION_SCHEMA.STATISTICS') !== false) {
            return array_map(static fn (string $name): array => ['COLUMN_NAME' => $name], $this->indexedColumns);
        }
        if (stripos($query, 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE') !== false) {
            return array_map(static fn (string $name): array => ['COLUMN_NAME' => $name], $this->foreignKeyColumns);
        }

        return [];
    }

    public function query(string $query)
    {
        $this->queries[] = $query;
        if (stripos($query, 'ALTER TABLE') !== false && $this->failAlter) {
            $this->last_error = 'DDL failed';
            return false;
        }

        $this->last_error = '';
        return 1;
    }

    /** @return list<string> */
    public function alterQueries(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $query): bool => stripos($query, 'ALTER TABLE') !== false
        ));
    }
}
