<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Database;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\ClauseBuilder as ClauseBuilderInterface;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Database\QueryBuilder;
use PHPNomad\Integrations\WordPress\Tests\Fixtures\PreparingWpdb;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use RuntimeException;

/**
 * @covers \PHPNomad\Integrations\WordPress\Database\ClauseBuilder
 */
class ClauseBuilderTest extends TestCase
{
    private const MARKER = '__NOMADIC_SUBQUERY__';

    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new PreparingWpdb();

        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('programs');
        $table->method('getAlias')->willReturn('programs');
        $table->method('getColumns')->willReturn([
            new Column('id', 'BIGINT'),
            new Column('orgId', 'BIGINT'),
            new Column('status', 'VARCHAR'),
            new Column('name', 'VARCHAR'),
            new Column('description', 'TEXT'),
            new Column('dateDeleted', 'DATETIME'),
        ]);
        $this->table = $table;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function builder(): ClauseBuilder
    {
        return (new ClauseBuilder())->useTable($this->table);
    }

    /**
     * The SQL for ordinary values and fewer than ten groups was recorded from
     * the 4.3.3 builder. It must not change.
     *
     * @dataProvider typicalConditionsProvider
     */
    public function test_typical_conditions_build_the_same_sql_as_before(callable $build, string $expected): void
    {
        $this->assertSame($expected, $build($this));
    }

    public static function typicalConditionsProvider(): array
    {
        return [
            'single condition' => [
                fn (self $t) => $t->builder()->where('status', '=', 'active')->build(),
                "programs.status = 'active'",
            ],
            'conditions joined with AND and OR' => [
                fn (self $t) => $t->builder()
                    ->where('orgId', '=', 5)
                    ->andWhere('status', '!=', 'archived')
                    ->orWhere('name', 'LIKE', '%spring%')
                    ->build(),
                "programs.orgId = '5' AND programs.status != 'archived' OR programs.name LIKE '{escape}spring{escape}'",
            ],
            'IN, IS NULL and quoting' => [
                fn (self $t) => $t->builder()
                    ->where('id', 'IN', 1, 2, 3)
                    ->andWhere('dateDeleted', 'IS NULL')
                    ->andWhere('name', '=', "O'Brien \"50%\"")
                    ->build(),
                "programs.id IN ('1', '2', '3') AND programs.dateDeleted IS NULL  AND programs.name = 'O\\'Brien \\\"50{escape}\\\"'",
            ],
            'IN with a list of value pairs' => [
                fn (self $t) => $t->builder()->where(['orgId', 'id'], 'IN', [5, 1], [5, 2])->build(),
                "(programs.orgId, programs.id) IN (('5', '1'), ('5', '2'))",
            ],
            'tenant, filter and search groups' => [
                fn (self $t) => $t->builder()
                    ->andGroup('AND', $t->builder()->where('orgId', '=', 5))
                    ->andGroup('AND', $t->builder()->where('orgId', '=', 5)->andWhere('status', '=', 'active'))
                    ->andGroup('OR', $t->builder()->where('name', 'LIKE', '%a%')->orWhere('description', 'LIKE', '%a%'))
                    ->build(),
                "(programs.orgId = '5') AND (programs.orgId = '5' AND programs.status = 'active')"
                . " AND (programs.name LIKE '{escape}a{escape}' OR programs.description LIKE '{escape}a{escape}')",
            ],
            'conditions before and between groups' => [
                fn (self $t) => $t->builder()
                    ->where('orgId', '=', 5)
                    ->orGroup('AND', $t->builder()->where('status', '=', 'a'), $t->builder()->where('name', '=', 'b'))
                    ->andWhere('id', '>', 10)
                    ->build(),
                "programs.orgId = '5' OR (programs.status = 'a' AND programs.name = 'b') AND programs.id > '10'",
            ],
            'nested groups' => [
                fn (self $t) => $t->builder()
                    ->andGroup('AND', $t->builder()
                        ->where('orgId', '=', 5)
                        ->andGroup('OR', $t->builder()->where('status', '=', 'a'), $t->builder()->where('status', '=', 'b')))
                    ->build(),
                "(programs.orgId = '5' AND (programs.status = 'a' OR programs.status = 'b'))",
            ],
            'nine groups' => [
                fn (self $t) => $t->groupsOfIds(9)->build(),
                implode(' OR ', array_map(fn ($i) => "(programs.id = '$i')", range(1, 9))),
            ],
        ];
    }

    public function test_value_equal_to_a_group_marker_stays_a_literal(): void
    {
        $sql = $this->builder()
            ->andGroup('AND', $this->builder()->where('status', '=', self::MARKER . '2'))
            ->andGroup('AND', $this->builder()->where('name', '=', 'b'))
            ->build();

        $this->assertSame("(programs.status = '__NOMADIC_SUBQUERY__2') AND (programs.name = 'b')", $sql);
    }

    public function test_value_containing_a_group_marker_stays_a_literal(): void
    {
        $sql = $this->builder()
            ->where('orgId', '=', 5)
            ->andGroup('AND', $this->builder()->where('status', '=', 'x ' . self::MARKER . '2 y'))
            ->andGroup('OR', $this->builder()->where('name', '=', 'z ' . self::MARKER . '1'))
            ->build();

        $this->assertSame(
            "programs.orgId = '5' AND (programs.status = 'x __NOMADIC_SUBQUERY__2 y')"
            . " AND (programs.name = 'z __NOMADIC_SUBQUERY__1')",
            $sql
        );
    }

    public function test_marker_text_in_an_IN_list_stays_a_literal(): void
    {
        $sql = $this->builder()
            ->andGroup('AND', $this->builder()->where('status', 'IN', self::MARKER . '2', 'b'))
            ->andGroup('AND', $this->builder()->where('name', '=', 'c'))
            ->build();

        $this->assertSame("(programs.status IN ('__NOMADIC_SUBQUERY__2', 'b')) AND (programs.name = 'c')", $sql);
    }

    public function test_eleven_or_more_groups_keep_each_group_intact(): void
    {
        $expected = implode(' OR ', array_map(fn ($i) => "(programs.id = '$i')", range(1, 12)));

        $this->assertSame($expected, $this->groupsOfIds(12)->build());
    }

    public function test_nested_groups_with_marker_values_stay_literals(): void
    {
        $sql = $this->builder()
            ->andGroup('AND', $this->builder()->where('orgId', '=', 5))
            ->andGroup('AND', $this->builder()
                ->where('status', '=', self::MARKER . '1')
                ->andGroup('OR', $this->builder()->where('name', '=', self::MARKER . '2'), $this->builder()->where('name', '=', 'b')))
            ->build();

        $this->assertSame(
            "(programs.orgId = '5') AND (programs.status = '__NOMADIC_SUBQUERY__1'"
            . " AND (programs.name = '__NOMADIC_SUBQUERY__2' OR programs.name = 'b'))",
            $sql
        );
    }

    public function test_build_resets_the_builder(): void
    {
        $builder = $this->builder()->andGroup('AND', $this->builder()->where('status', '=', 'a'));
        $builder->build();

        $this->assertSame("programs.name = 'b'", $builder->where('name', '=', 'b')->build());
    }

    public function test_a_build_that_throws_leaves_nothing_for_the_next(): void
    {
        $failing = $this->createMock(ClauseBuilderInterface::class);
        $failing->method('build')->willThrowException(new RuntimeException('group failed'));
        $builder = $this->builder()->where('status', '=', 'left-behind')->andGroup('AND', $failing);

        try {
            $builder->build();
            $this->fail('The build did not throw.');
        } catch (RuntimeException $e) {
        }

        $this->assertSame("programs.orgId = '5'", $builder->where('orgId', '=', 5)->build());
    }

    public function test_a_query_the_query_builder_refuses_leaves_nothing_for_the_next(): void
    {
        $shared = $this->builder();
        $queryBuilder = new QueryBuilder();

        // Started as a datastore starts a query, then refused for want of a select.
        $this->startQuery($shared, $queryBuilder);
        $shared->andGroup('AND', (clone $shared)->reset()->useTable($this->table)->where('status', '=', 'left-behind'));
        $queryBuilder->where($shared);

        try {
            $queryBuilder->build();
            $this->fail('The query built.');
        } catch (QueryBuilderException $e) {
        }

        $this->assertSame("SELECT programs.id FROM programs AS programs WHERE (programs.orgId = '5')", $this->nextQuery($shared, $queryBuilder));
    }

    public function test_keeps_conditions_the_query_builder_takes(): void
    {
        $shared = $this->builder();
        $queryBuilder = new QueryBuilder();

        $this->startQuery($shared, $queryBuilder);
        $queryBuilder->select('id');
        $shared->where('orgId', '=', 5)->andGroup('AND', $this->builder()->where('status', '=', 'active'));
        $queryBuilder->where($shared);

        $this->assertSame(
            "SELECT programs.id FROM programs AS programs WHERE programs.orgId = '5' AND (programs.status = 'active')",
            $queryBuilder->build()
        );
    }

    /**
     * What the datastores do as they start each query.
     */
    private function startQuery(ClauseBuilder $shared, QueryBuilder $queryBuilder): void
    {
        $shared->useTable($this->table);
        $queryBuilder->reset()->from($this->table);
    }

    private function nextQuery(ClauseBuilder $shared, QueryBuilder $queryBuilder): string
    {
        $this->startQuery($shared, $queryBuilder);
        $queryBuilder->select('id');
        $shared->andGroup('AND', (clone $shared)->reset()->useTable($this->table)->where('orgId', '=', 5));
        $queryBuilder->where($shared);

        return $queryBuilder->build();
    }

    public function groupsOfIds(int $count): ClauseBuilder
    {
        $builder = $this->builder();

        foreach (range(1, $count) as $id) {
            $builder->orGroup('AND', $this->builder()->where('id', '=', $id));
        }

        return $builder;
    }
}
