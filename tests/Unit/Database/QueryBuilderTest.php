<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Database;

use PHPNomad\Database\Interfaces\HasQueryTables;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Database\QueryBuilder;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use ReflectionClass;

class QueryBuilderTest extends TestCase
{
    public function testReportsExactRootAndJoinTablesAcrossReplacementAndReset(): void
    {
        $root = $this->table('records', 'records');
        $replacement = $this->table('replacement_records', 'replacement');
        $firstJoin = $this->table('first_links', 'first_links');
        $secondJoin = $this->table('second_links', 'second_links');
        $builder = (new QueryBuilder())
            ->from($root)
            ->leftJoin($firstJoin, 'id', 'recordId')
            ->rightJoin($secondJoin, 'id', 'recordId');

        self::assertInstanceOf(HasQueryTables::class, $builder);
        self::assertSame([$root, $firstJoin, $secondJoin], $builder->getReferencedTables());

        $builder->resetClauses('join');
        self::assertSame([$root], $builder->getReferencedTables());

        $builder->leftJoin($firstJoin, 'id', 'recordId');
        $builder->from($replacement);
        self::assertSame([$replacement, $firstJoin], $builder->getReferencedTables());

        $builder->resetClauses('from');
        self::assertSame([], $builder->getReferencedTables());

        $builder->reset();
        self::assertSame([], $builder->getReferencedTables());
    }

    public function testSuccessfulBuildClearsJoinSqlAndMetadataBeforeReuse(): void
    {
        $root = $this->table('records', 'records');
        $join = $this->table('links', 'links');
        $builder = (new QueryBuilder())
            ->select('*')
            ->from($root)
            ->leftJoin($join, 'id', 'recordId');

        self::assertStringContainsString('LEFT JOIN links AS links', $builder->build());
        self::assertSame([], $builder->getReferencedTables());

        $sql = $builder
            ->select('*')
            ->from($root)
            ->build();

        self::assertStringNotContainsString('JOIN', $sql);
        self::assertSame([], $builder->getReferencedTables());
    }

    public function testBuildersKeepZeroArgumentConstruction(): void
    {
        $queryConstructor = (new ReflectionClass(QueryBuilder::class))->getConstructor();
        $clauseConstructor = (new ReflectionClass(ClauseBuilder::class))->getConstructor();

        self::assertNotNull($queryConstructor);
        self::assertNotNull($clauseConstructor);
        self::assertSame(0, $queryConstructor->getNumberOfRequiredParameters());
        self::assertSame(0, $clauseConstructor->getNumberOfRequiredParameters());
    }

    private function table(string $name, string $alias): Table
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn($name);
        $table->method('getAlias')->willReturn($alias);

        return $table;
    }
}
