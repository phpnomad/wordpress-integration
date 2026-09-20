<?php

namespace PHPNomad\Integrations\WordPress\Tests\Unit\Strategies;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Integrations\WordPress\Strategies\QueryStrategy;
use PHPNomad\Integrations\WordPress\Tests\TestCase;

class QueryStrategyTest extends TestCase
{
    public function testUpdateWrapsExistenceProbeBuilderFailure(): void
    {
        $cause = new QueryBuilderException('Malformed update probe.');
        $strategy = new class ($cause) extends QueryStrategy {
            public function __construct(private QueryBuilderException $cause)
            {
            }

            protected function wpdbUpdate(Table $table, array $data, array $where): void
            {
                throw $this->cause;
            }
        };

        try {
            $strategy->update($this->createMock(Table::class), ['id' => 1], ['value' => 'changed']);
            self::fail('The builder failure must be translated at the strategy boundary.');
        } catch (DatastoreErrorException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
    }
}
