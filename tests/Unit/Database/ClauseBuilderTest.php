<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Database;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Tests\TestCase;

class ClauseBuilderTest extends TestCase
{
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->table = $this->createMock(Table::class);
        $this->table->method('getAlias')->willReturn('records');
        $this->table->method('getColumns')->willReturn([
            new Column('id', 'INT'),
            new Column('score', 'INT'),
            new Column('label', 'VARCHAR', [128]),
        ]);

        $GLOBALS['wpdb'] = new class () {
            public function prepare(string $format, ...$values): string
            {
                return vsprintf($format, array_map(
                    static fn ($value): string => "'" . addslashes((string) $value) . "'",
                    $values
                ));
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testBuildPreservesNullsAndLiteralMarkerTextAcrossGroups(): void
    {
        $literal = "__NOMADIC_SUBQUERY__1 %s %i %% O'Reilly";
        $child = (new ClauseBuilder())
            ->useTable($this->table)
            ->where('score', 'IN', null, 30);
        $builder = (new ClauseBuilder())
            ->useTable($this->table)
            ->where('label', '=', $literal)
            ->orGroup('AND', $child);

        self::assertSame(
            "records.label = '__NOMADIC_SUBQUERY__1 %s %i %% O\\'Reilly' OR (records.score IN (NULL, '30'))",
            $builder->build()
        );
    }

    public function testInvalidTupleWidthLeavesExistingClauseIntact(): void
    {
        $builder = (new ClauseBuilder())
            ->useTable($this->table)
            ->where('id', '=', 50);

        try {
            $builder->andWhere(['id', 'score'], 'IN', [1]);
            self::fail('A short tuple row must be rejected.');
        } catch (QueryBuilderException $exception) {
            self::assertSame('Operator IN tuple width must match the field list.', $exception->getMessage());
        }

        self::assertSame("records.id = '50'", $builder->build());
    }

    public function testProtectedConditionSeamNormalizesValidLogic(): void
    {
        $builder = $this->conditionSeamBuilder();
        $builder->where('id', '=', 50)->addUsingLogic('and');

        self::assertSame("records.id = '50' AND records.score = '30'", $builder->build());
    }

    public function testProtectedConditionSeamRejectsInvalidLogicWithoutChangingState(): void
    {
        $builder = $this->conditionSeamBuilder();
        $builder->where('id', '=', 50);
        $caught = null;

        try {
            $builder->addUsingLogic('XOR');
        } catch (QueryBuilderException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(QueryBuilderException::class, $caught);
        self::assertSame("records.id = '50'", $builder->build());
    }

    private function conditionSeamBuilder(): ClauseBuilder
    {
        return (new class () extends ClauseBuilder {
            public function addUsingLogic(string $logic): self
            {
                return $this->addCondition('score', '=', [30], $logic);
            }
        })->useTable($this->table);
    }
}
