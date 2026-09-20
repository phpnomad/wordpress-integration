<?php

declare(strict_types=1);

namespace PHPNomad\Integrations\WordPress\Tests\Integration\Database;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Di\Container\Container;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Database\QueryBuilder;
use PHPNomad\Integrations\WordPress\Strategies\CoordinatedQueryStrategy as WordPressCoordinatedQueryStrategy;
use PHPNomad\Integrations\WordPress\Strategies\WordPressInitializer;
use PHPNomad\Integrations\WordPress\Tests\Integration\Support\ContractTable;
use PHPUnit\Framework\TestCase;
use PHPNomad\Loader\Bootstrapper;
use RuntimeException;
use Throwable;
use wpdb;

final class RealWpdbCoordinationContractTest extends TestCase
{
    private const PARENT = 'nomad_wpdb_coordination_parent';
    private const CHILD = 'nomad_wpdb_coordination_child';

    private static wpdb $wpdb;
    private static ContractTable $parent;
    private static ContractTable $child;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(wpdb::class) || !extension_loaded('mysqli')) {
            self::markTestSkipped('The coordinated suite requires official WordPress and ext-mysqli.');
        }
        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $user = getenv('MYSQL_USER') ?: 'root';
        $password = getenv('MYSQL_PASSWORD') ?: '';
        $database = getenv('MYSQL_DATABASE') ?: 'phpnomad_wordpress_integration_test';
        $probe = mysqli_init();
        mysqli_options($probe, MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        if (!@mysqli_real_connect($probe, $host, $user, $password, $database, (int) $port)) {
            self::markTestSkipped('The real MySQL 8.0 coordination fixture is unavailable.');
        }
        mysqli_close($probe);

        self::$wpdb = new wpdb($user, $password, $database, $host . ':' . $port);
        self::$wpdb->suppress_errors(false);
        $GLOBALS['wpdb'] = self::$wpdb;
        self::$parent = new ContractTable(self::PARENT, 'coordination_parent', [new Column('id', 'INT', null, 'PRIMARY KEY')], ['id']);
        self::$child = new ContractTable(self::CHILD, 'coordination_child', [new Column('id', 'INT', null, 'PRIMARY KEY'), new Column('value', 'VARCHAR', [64])], ['id']);
        self::query('DROP TABLE IF EXISTS ' . self::CHILD);
        self::query('DROP TABLE IF EXISTS ' . self::PARENT);
        self::query('CREATE TABLE ' . self::PARENT . ' (id INT PRIMARY KEY) ENGINE=InnoDB');
        self::query('CREATE TABLE ' . self::CHILD . ' (id INT PRIMARY KEY, value VARCHAR(64) NULL) ENGINE=InnoDB');
    }

    protected function setUp(): void
    {
        self::query('DELETE FROM ' . self::CHILD);
        self::query('DELETE FROM ' . self::PARENT);
        self::query('INSERT INTO ' . self::PARENT . ' (id) VALUES (1)');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$wpdb)) {
            self::query('DROP TABLE IF EXISTS ' . self::CHILD);
            self::query('DROP TABLE IF EXISTS ' . self::PARENT);
            unset($GLOBALS['wpdb']);
        }
    }

    public function testSuccessCommitsOnceAndSupportsNonFirstCoordinationTable(): void
    {
        $calls = 0;
        $strategy = new WordPressCoordinatedQueryStrategy();
        self::assertInstanceOf(CoordinatedQueryStrategy::class, $strategy);
        $strategy->coordinate(self::$parent, ['id' => 1], [self::$child, self::$parent], function ($operation) use (&$calls): void {
            $calls++;
            $operation->insert(self::$child, ['id' => 10, 'value' => 'committed']);
        });

        self::assertSame(1, $calls);
        self::assertSame('committed', self::$wpdb->get_var('SELECT value FROM ' . self::CHILD . ' WHERE id = 10'));
    }

    public function testInitializerSharesTheCoordinatorWithTheOrdinaryProvider(): void
    {
        $container = new Container();
        (new Bootstrapper($container, new WordPressInitializer()))->load();
        $ordinary = $container->get(QueryStrategy::class);
        $coordinated = $container->get(CoordinatedQueryStrategy::class);
        self::assertSame($ordinary, $coordinated);
    }

    public function testCallbackFailureRollsBackAndIsNotRetried(): void
    {
        $calls = 0;
        $strategy = new WordPressCoordinatedQueryStrategy();
        try {
            $strategy->coordinate(self::$parent, ['id' => 1], [self::$parent, self::$child], function ($operation) use (&$calls): void {
                $calls++;
                $operation->insert(self::$child, ['id' => 11, 'value' => 'rolled-back']);
                throw new RuntimeException('callback failed');
            });
            self::fail('Expected callback failure.');
        } catch (RuntimeException $failure) {
            self::assertSame('callback failed', $failure->getMessage());
        }
        self::assertSame(1, $calls);
        self::assertFalse((bool) self::$wpdb->get_var('SELECT 1 FROM ' . self::CHILD . ' WHERE id = 11'));
    }

    public function testDuplicateWriteRollsBackWithoutCallbackReplay(): void
    {
        self::query("INSERT INTO " . self::CHILD . " (id, value) VALUES (12, 'existing')");
        $calls = 0;
        try {
            (new WordPressCoordinatedQueryStrategy())->coordinate(self::$parent, ['id' => 1], [self::$parent, self::$child], function ($operation) use (&$calls): void {
                $calls++;
                $operation->insert(self::$child, ['id' => 12, 'value' => 'duplicate']);
            });
            self::fail('Expected duplicate write failure.');
        } catch (DatastoreErrorException) {
            self::assertSame(1, $calls);
        }
        self::assertSame('existing', self::$wpdb->get_var('SELECT value FROM ' . self::CHILD . ' WHERE id = 12'));
    }

    public function testMissingUpdateIdentityIsRecordNotFoundAndRollsBack(): void
    {
        try {
            (new WordPressCoordinatedQueryStrategy())->coordinate(self::$parent, ['id' => 1], [self::$parent, self::$child], function ($operation): void {
                $operation->update(self::$child, ['id' => 404], ['value' => 'missing']);
            });
            self::fail('Expected a missing update identity.');
        } catch (\PHPNomad\Datastore\Exceptions\RecordNotFoundException) {
            self::assertTrue(true);
        }
    }

    public function testNullMutationPredicateUsesIsNull(): void
    {
        self::query("INSERT INTO " . self::CHILD . " (id, value) VALUES (13, NULL)");
        (new WordPressCoordinatedQueryStrategy())->coordinate(self::$parent, ['id' => 1], [self::$parent, self::$child], function ($operation): void {
            $operation->update(self::$child, ['value' => null], ['value' => 'updated']);
        });
        self::assertSame('updated', self::$wpdb->get_var('SELECT value FROM ' . self::CHILD . ' WHERE id = 13'));
    }

    public function testMissingCoordinationRecordDoesNotInvokeCallback(): void
    {
        $calls = 0;
        try {
            (new WordPressCoordinatedQueryStrategy())->coordinate(self::$parent, ['id' => 999], [self::$parent], function () use (&$calls): void {
                $calls++;
            });
            self::fail('Expected missing coordination record.');
        } catch (Throwable) {
            self::assertSame(0, $calls);
        }
    }

    public function testGlobalWpdbReplacementIsDetectedBeforePinnedQueryRuns(): void
    {
        $original = $GLOBALS['wpdb'];
        try {
            (new WordPressCoordinatedQueryStrategy())->coordinate(self::$parent, ['id' => 1], [self::$parent], function ($operation) use ($original): void {
                $GLOBALS['wpdb'] = new class () {
                };
                try {
                    $operation->estimatedCount(self::$parent);
                } finally {
                    $GLOBALS['wpdb'] = $original;
                }
            });
            self::fail('Expected resource replacement failure.');
        } catch (CoordinatedOperationOutcomeUnknownException|RuntimeException) {
            self::assertSame($original, $GLOBALS['wpdb']);
        }
    }

    public function testCustomWpdbSubclassIsRefusedBeforeCallback(): void
    {
        $original = $GLOBALS['wpdb'];
        $calls = 0;
        $GLOBALS['wpdb'] = new class () extends wpdb {
            public function __construct()
            {
            }
        };
        try {
            (new WordPressCoordinatedQueryStrategy())->coordinate(self::$parent, ['id' => 1], [self::$parent], function () use (&$calls): void {
                $calls++;
            });
            self::fail('Expected custom wpdb refusal.');
        } catch (Throwable) {
            self::assertSame(0, $calls);
        } finally {
            $GLOBALS['wpdb'] = $original;
        }
    }

    public function testOperationBuilderIsConsumedAndFreshReadUsesPinnedSession(): void
    {
        $builder = (new QueryBuilder())
            ->select('*')
            ->from(self::$parent)
            ->where((new ClauseBuilder())->useTable(self::$parent)->where('id', '=', 1));
        $rows = [];
        (new WordPressCoordinatedQueryStrategy())->coordinate(self::$parent, ['id' => 1], [self::$parent], function ($operation) use ($builder, &$rows): void {
            $rows = $operation->query($builder);
        });
        self::assertCount(1, $rows);
        try {
            $builder->build();
            self::fail('Expected the operation to consume the builder.');
        } catch (QueryBuilderException) {
            self::assertTrue(true);
        }
    }

    private static function query(string $sql): void
    {
        if (self::$wpdb->query($sql) === false) {
            throw new RuntimeException(self::$wpdb->last_error);
        }
    }
}
