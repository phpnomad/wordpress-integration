<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Strategies;

use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Integrations\WordPress\Strategies\QueryStrategy;
use PHPNomad\Integrations\WordPress\Tests\TestCase;

class QueryStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testQueryUsesTransactionBackedReadAfterInsert(): void
    {
        $GLOBALS['wpdb'] = new class {
            public int $insert_id = 123;
            public string $last_error = '';
            public array $queries = [];
            private bool $inTransaction = false;

            public function query(string $query): bool
            {
                $this->queries[] = $query;

                if ($query === 'START TRANSACTION') {
                    $this->inTransaction = true;
                }

                if ($query === 'COMMIT' || $query === 'ROLLBACK') {
                    $this->inTransaction = false;
                }

                return true;
            }

            public function insert(string $table, array $data, array $formats): int
            {
                return 1;
            }

            public function get_results(string $query, string $output): array
            {
                return $this->inTransaction ? [['id' => 123, 'name' => 'Example']] : [];
            }
        };

        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('wp_test_records');
        $table->method('getFieldsForIdentity')->willReturn(['id']);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects($this->once())
            ->method('build')
            ->willReturn('SELECT * FROM wp_test_records WHERE id = 123');

        $strategy = new QueryStrategy();
        $strategy->insert($table, ['name' => 'Example']);

        $this->assertSame([['id' => 123, 'name' => 'Example']], $strategy->query($queryBuilder));
        $this->assertSame(['START TRANSACTION', 'COMMIT', 'START TRANSACTION', 'COMMIT'], $GLOBALS['wpdb']->queries);
    }
}
