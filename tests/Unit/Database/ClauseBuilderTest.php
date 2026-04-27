<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Database;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Tests\TestCase;

class ClauseBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        parent::tearDown();
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Database\ClauseBuilder::build
     */
    public function testSingleFieldInClauseUsesPlainColumnLookup(): void
    {
        $table = new FakeTable();
        $builder = (new ClauseBuilder())->useTable($table);

        $result = $builder->andWhere(['id'], 'IN', ['id' => 44])->build();

        $this->assertSame("rec.id IN ('44')", $result);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Database\ClauseBuilder::build
     */
    public function testSingleFieldInClauseExpandsMultipleIdentityRows(): void
    {
        $table = new FakeTable();
        $builder = (new ClauseBuilder())->useTable($table);

        $result = $builder
            ->andWhere(['id'], 'IN', ['id' => 44], ['id' => 45])
            ->build();

        $this->assertSame("rec.id IN ('44', '45')", $result);
    }

    /**
     * @covers \PHPNomad\Integrations\WordPress\Database\ClauseBuilder::build
     */
    public function testCompoundFieldInClauseKeepsRowConstructorLookup(): void
    {
        $table = new FakeTable();
        $builder = (new ClauseBuilder())->useTable($table);

        $result = $builder
            ->andWhere(['id', 'orgId'], 'IN', ['id' => 44, 'orgId' => 2], ['id' => 45, 'orgId' => 3])
            ->build();

        $this->assertSame("(rec.id, rec.orgId) IN (('44', '2'), ('45', '3'))", $result);
    }
}

class FakeWpdb
{
    /**
     * Prepares a SQL string for assertions.
     *
     * @param string $query The query with placeholders.
     * @param mixed ...$values The values to quote.
     * @return string
     */
    public function prepare(string $query, ...$values): string
    {
        foreach ($values as $value) {
            $query = preg_replace('/%s/', "'" . addslashes((string)$value) . "'", $query, 1);
        }

        return $query;
    }
}

class FakeTable implements Table
{
    /** @inheritDoc */
    public function getName(): string
    {
        return 'wp_records';
    }

    /** @inheritDoc */
    public function getAlias(): string
    {
        return 'rec';
    }

    /** @inheritDoc */
    public function getTableVersion(): string
    {
        return '1';
    }

    /** @inheritDoc */
    public function getColumns(): array
    {
        return [
            new Column('id', 'BIGINT'),
            new Column('orgId', 'BIGINT'),
        ];
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
        return ['id'];
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
