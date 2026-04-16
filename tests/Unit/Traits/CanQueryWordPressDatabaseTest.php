<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Traits;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase;

class CanQueryWordPressDatabaseTest extends TestCase
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

    public function testWpdbGetResultsUsesLastErrorWhenQueryReturnsNull(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects($this->once())
            ->method('build')
            ->willReturn('SELECT * FROM test_table');

        $GLOBALS['wpdb'] = new class {
            public string $last_error = 'Replica read failed';

            public function get_results(string $query, string $output): ?array
            {
                return null;
            }
        };

        $subject = new class {
            use CanQueryWordPressDatabase;

            public function getResults(QueryBuilder $queryBuilder): array
            {
                return $this->wpdbGetResults($queryBuilder);
            }
        };

        $this->expectException(DatastoreErrorException::class);
        $this->expectExceptionMessage('Get results failed - Replica read failed');

        $subject->getResults($queryBuilder);
    }

    public function testWpdbUpdateIncludesTableIdentityAndPayloadWhenRecordIsMissing(): void
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('wp_test_records');
        $table->method('getAlias')->willReturn('records');
        $table->method('getFieldsForIdentity')->willReturn(['id']);
        $table->method('getColumns')->willReturn([
            new Column('id', 'INT'),
        ]);

        $GLOBALS['wpdb'] = new class {
            public string $last_error = '';

            public function update(string $table, array $data, array $where, array $formats, array $whereFormats): int
            {
                return 0;
            }

            public function get_var(string $query): string
            {
                return '0';
            }

            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }
        };

        $subject = new class {
            use CanQueryWordPressDatabase;

            public function updateRecord(Table $table, array $data, array $where): void
            {
                $this->wpdbUpdate($table, $data, $where);
            }
        };

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('Update failed because no record exists in table "wp_test_records"');
        $this->expectExceptionMessage('"id":123');
        $this->expectExceptionMessage('"status":"complete"');

        $subject->updateRecord($table, ['status' => 'complete'], ['id' => 123]);
    }
}
