<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Traits;

use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Integrations\WordPress\Tests\TestCase;
use PHPNomad\Integrations\WordPress\Traits\CanQueryWordPressDatabase;

class CanQueryWordPressDatabaseSqlErrorTest extends TestCase
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

    public function testWpdbGetResultsTreatsEmptyArrayWithLastErrorAsDatastoreFailure(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('build')->willReturn('SELECT * FROM missing_table');

        $GLOBALS['wpdb'] = new class () {
            public string $last_error = 'Table does not exist';

            public function get_results(string $query, string $output): array
            {
                return [];
            }
        };

        $subject = new class () {
            use CanQueryWordPressDatabase;

            public function getResults(QueryBuilder $queryBuilder): array
            {
                return $this->wpdbGetResults($queryBuilder);
            }
        };

        $this->expectException(DatastoreErrorException::class);
        $this->expectExceptionMessage('Get results failed.');

        $subject->getResults($queryBuilder);
    }
}
