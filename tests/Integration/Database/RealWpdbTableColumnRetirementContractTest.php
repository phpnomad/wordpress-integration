<?php

declare(strict_types=1);

namespace PHPNomad\Integrations\WordPress\Tests\Integration\Database;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Integrations\WordPress\Strategies\TableUpdateStrategy;
use PHPNomad\Integrations\WordPress\Tests\Integration\Support\ContractTable;
use PHPUnit\Framework\TestCase;
use wpdb;

/** Persisted-schema contract through the official WordPress wpdb adapter. */
final class RealWpdbTableColumnRetirementContractTest extends TestCase
{
    private const TABLE = 'nomad_wpdb_column_retirement';
    private const CHILD_TABLE = 'nomad_wpdb_column_retirement_child';

    private static wpdb $wpdb;
    private TableUpdateStrategy $strategy;
    private ContractTable $table;
    private string $shadowSchema;

    public static function setUpBeforeClass(): void
    {
        $host = self::environment('MYSQL_HOST', '127.0.0.1');
        $port = self::environment('MYSQL_PORT', '3306');
        $user = self::environment('MYSQL_USER', 'root');
        $password = self::environment('MYSQL_PASSWORD', '');
        $database = self::environment('MYSQL_DATABASE', 'phpnomad_wordpress_integration_test');

        self::$wpdb = new wpdb($user, $password, $database, $host . ':' . $port);
        self::$wpdb->suppress_errors(true);
        $GLOBALS['wpdb'] = self::$wpdb;

        if (!self::$wpdb->ready) {
            throw new \RuntimeException('wpdb could not connect: ' . self::$wpdb->last_error);
        }
    }

    protected function setUp(): void
    {
        $this->strategy = new TableUpdateStrategy();
        $this->table = new ContractTable(
            self::TABLE,
            'retirement',
            [new Column('id', 'INT', null, 'PRIMARY KEY'), new Column('modernValue', 'INT')],
            ['id']
        );
        $this->shadowSchema = 'nomad_wpdb_retirement_shadow_' . getmypid();
        $this->resetFixtures();
    }

    protected function tearDown(): void
    {
        self::rawQuery('DROP TABLE IF EXISTS ' . self::CHILD_TABLE);
        self::rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
        self::rawQuery('DROP DATABASE IF EXISTS ' . self::quoteIdentifier($this->shadowSchema));
    }

    public static function tearDownAfterClass(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function testSyncIsAdditiveAndColumnExistenceIsScopedToTheActiveSchema(): void
    {
        self::markTestIncomplete('Remove this marker when implementing the accepted retirement contract.');

        self::rawQuery('CREATE DATABASE ' . self::quoteIdentifier($this->shadowSchema));
        self::rawQuery(
            'CREATE TABLE ' . self::quoteIdentifier($this->shadowSchema) . '.' . self::TABLE
            . ' (crossSchemaOnly INT NULL) ENGINE=InnoDB'
        );

        self::assertTrue($this->strategy->columnExists($this->table, 'legacyValue'));
        self::assertFalse($this->strategy->columnExists($this->table, 'crossSchemaOnly'));

        $this->strategy->syncColumns($this->table);

        self::assertSame(
            ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name', 'modernValue'],
            self::columns()
        );
        self::assertSame(
            [['id' => '1', 'legacyValue' => '41', 'unrelatedUnknown' => 'keep']],
            self::rawSelect('SELECT id, legacyValue, unrelatedUnknown FROM ' . self::TABLE)
        );
    }

    public function testRetirementPersistsOnlyNamedDropsAndIsIdempotent(): void
    {
        self::markTestIncomplete('Remove this marker when implementing the accepted retirement contract.');

        $this->strategy->retireColumns($this->table, 'legacyValue', 'legacy value', 'odd`name');
        $this->strategy->retireColumns($this->table, 'legacyValue', 'legacy value', 'odd`name');

        self::assertSame(['id', 'unrelatedUnknown'], self::columns());
        self::assertSame(
            [['id' => '1', 'unrelatedUnknown' => 'keep']],
            self::rawSelect('SELECT id, unrelatedUnknown FROM ' . self::TABLE)
        );
    }

    public function testWholeBatchPreflightRejectsADeclaredCaseVariantBeforeDdl(): void
    {
        self::markTestIncomplete('Remove this marker when implementing the accepted retirement contract.');

        try {
            $this->strategy->retireColumns($this->table, 'legacyValue', 'ID');
            self::fail('A case-variant declared column must reject the whole batch.');
        } catch (\InvalidArgumentException $expected) {
            self::assertSame(
                ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name'],
                self::columns()
            );
        }
    }

    public function testIndexedAndInboundForeignKeyColumnsAreRefusedWithoutSchemaChanges(): void
    {
        self::markTestIncomplete('Remove this marker when implementing the accepted retirement contract.');

        self::rawQuery('ALTER TABLE ' . self::TABLE . ' ADD UNIQUE INDEX legacy_value_unique (legacyValue)');
        self::rawQuery(
            'CREATE TABLE ' . self::CHILD_TABLE
            . ' (id INT PRIMARY KEY, parentLegacyValue INT NULL, '
            . 'CONSTRAINT wpdb_retirement_parent_fk FOREIGN KEY (parentLegacyValue) REFERENCES '
            . self::TABLE . ' (legacyValue)) ENGINE=InnoDB'
        );

        try {
            $this->strategy->retireColumns($this->table, 'legacyValue');
            self::fail('Retirement must not remove an index or inbound foreign key implicitly.');
        } catch (\InvalidArgumentException $expected) {
            self::assertContains('legacyValue', self::columns());
            self::assertSame(
                'wpdb_retirement_parent_fk',
                self::rawSelect(
                    "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS "
                    . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::CHILD_TABLE . "'"
                )[0]['CONSTRAINT_NAME']
            );
        }
    }

    private function resetFixtures(): void
    {
        self::rawQuery('DROP TABLE IF EXISTS ' . self::CHILD_TABLE);
        self::rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
        self::rawQuery('DROP DATABASE IF EXISTS ' . self::quoteIdentifier($this->shadowSchema));
        self::rawQuery(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id INT PRIMARY KEY, legacyValue INT NULL, unrelatedUnknown VARCHAR(32) NULL, '
            . '`legacy value` INT NULL, `odd``name` INT NULL) ENGINE=InnoDB'
        );
        self::rawQuery(
            "INSERT INTO " . self::TABLE
            . " (id, legacyValue, unrelatedUnknown, `legacy value`, `odd``name`) VALUES (1, 41, 'keep', 42, 43)"
        );
    }

    /** @return list<string> */
    private static function columns(): array
    {
        return array_column(self::rawSelect(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' ORDER BY ORDINAL_POSITION"
        ), 'COLUMN_NAME');
    }

    private static function rawQuery(string $sql): void
    {
        $result = self::$wpdb->query($sql);

        if ($result === false || self::$wpdb->last_error !== '') {
            throw new \RuntimeException('wpdb query failed: ' . self::$wpdb->last_error . ' SQL=' . $sql);
        }
    }

    /** @return list<array<string, string>> */
    private static function rawSelect(string $sql): array
    {
        $result = self::$wpdb->get_results($sql, ARRAY_A);

        if (!is_array($result) || self::$wpdb->last_error !== '') {
            throw new \RuntimeException('wpdb SELECT failed: ' . self::$wpdb->last_error . ' SQL=' . $sql);
        }

        return $result;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
