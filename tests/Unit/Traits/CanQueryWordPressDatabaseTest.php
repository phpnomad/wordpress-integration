<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Traits;

use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase;

class CanQueryWordPressDatabaseTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        parent::tearDown();
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase::wpdbInsert
     */
    public function testInsertReturnsProvidedIdentityFields(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb(99);
        $table = new FakeTable('wp_records', ['externalId', 'source']);
        $strategy = new WordPressDatabaseQueryHarness();

        $result = $strategy->insert($table, [
            'externalId' => 123,
            'source' => 'wc_order',
            'status' => 'active',
        ]);

        $this->assertSame([
            'externalId' => 123,
            'source' => 'wc_order',
        ], $result);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase::wpdbInsert
     */
    public function testInsertReturnsAutoIncrementIdUsingTableIdentityField(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb(44);
        $table = new FakeTable('wp_records', ['recordId']);
        $strategy = new WordPressDatabaseQueryHarness();

        $result = $strategy->insert($table, ['status' => 'active']);

        $this->assertSame(['recordId' => 44], $result);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase::wpdbInsert
     */
    public function testInsertThrowsWhenAutoIncrementIdCannotBeResolved(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb(0);
        $table = new FakeTable('wp_records', ['id']);
        $strategy = new WordPressDatabaseQueryHarness();

        $this->expectException(DatastoreErrorException::class);
        $this->expectExceptionMessage('Insert succeeded for table "wp_records", but WordPress did not report an insert ID.');

        $strategy->insert($table, ['status' => 'active']);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase::wpdbInsert
     */
    public function testInsertThrowsWhenCompoundIdentityCannotBeResolved(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb(44);
        $table = new FakeTable('wp_records', ['externalId', 'source']);
        $strategy = new WordPressDatabaseQueryHarness();

        $this->expectException(DatastoreErrorException::class);
        $this->expectExceptionMessage('Insert succeeded for table "wp_records", but the record identity could not be resolved.');

        $strategy->insert($table, ['externalId' => 123]);
    }
}

class WordPressDatabaseQueryHarness
{
    use CanQueryWordPressDatabase;

    /**
     * Inserts the provided data through the protected WordPress query helper.
     *
     * @param Table $table The table to insert into.
     * @param array<string, mixed> $data The insert payload.
     * @return array<string, int|string>
     */
    public function insert(Table $table, array $data): array
    {
        return $this->wpdbInsert($table, $data);
    }
}

class FakeWpdb
{
    public string $last_error = '';
    public string $error = '';

    /**
     * @param int $insert_id The ID exposed through wpdb::insert_id.
     */
    public function __construct(public int $insert_id)
    {
    }

    /**
     * Fakes wpdb::insert().
     *
     * @param string $table The table name.
     * @param array<string, mixed> $data The row data.
     * @param string[] $formats The value formats.
     * @return int|false
     */
    public function insert(string $table, array $data, array $formats)
    {
        return 1;
    }

    /**
     * Fakes wpdb::query().
     *
     * @param string $query The query.
     * @return int|false
     */
    public function query(string $query)
    {
        return 1;
    }
}

class FakeTable implements Table
{
    /**
     * @param string $name The full table name.
     * @param non-empty-array<string> $identityFields The identity fields.
     */
    public function __construct(private string $name, private array $identityFields)
    {
    }

    /** @inheritDoc */
    public function getName(): string
    {
        return $this->name;
    }

    /** @inheritDoc */
    public function getAlias(): string
    {
        return 'fake';
    }

    /** @inheritDoc */
    public function getTableVersion(): string
    {
        return '1';
    }

    /** @inheritDoc */
    public function getColumns(): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getIndices(): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getCharset(): ?string
    {
        return null;
    }

    /** @inheritDoc */
    public function getCollation(): ?string
    {
        return null;
    }

    /** @inheritDoc */
    public function getFieldsForIdentity(): array
    {
        return $this->identityFields;
    }

    /** @inheritDoc */
    public function getUnprefixedName(): string
    {
        return 'records';
    }

    /** @inheritDoc */
    public function getSingularUnprefixedName(): string
    {
        return 'record';
    }
}
