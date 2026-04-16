<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Traits;

use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
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
            public string $error = '';

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
        $this->expectExceptionMessage('Replica read failed');

        $subject->getResults($queryBuilder);
    }
}
