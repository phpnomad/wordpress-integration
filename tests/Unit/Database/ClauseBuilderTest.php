<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Database;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Tests\Fixtures\PreparingWpdb;
use PHPNomad\Integrations\WordPress\Tests\TestCase;

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

    public function groupsOfIds(int $count): ClauseBuilder
    {
        $builder = $this->builder();

        foreach (range(1, $count) as $id) {
            $builder->orGroup('AND', $this->builder()->where('id', '=', $id));
        }

        return $builder;
    }
}
