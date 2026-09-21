<?php

declare(strict_types=1);

namespace PHPNomad\Integrations\WordPress\Tests\Integration\Database;

use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\DatabaseHandler;
use PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\OperationDatabaseHandlerBridge;
use PHPNomad\Events\Interfaces\Event;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Database\QueryBuilder;
use PHPNomad\Integrations\WordPress\Strategies\CoordinatedQueryStrategy;
use PHPNomad\Integrations\WordPress\Strategies\WordPressInitializer;
use PHPNomad\Integrations\WordPress\Strategies\WordPressOperationDatabaseProviderFactory;
use PHPNomad\Integrations\WordPress\Tests\Integration\Support\ContractTable;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\Logger\Enums\LoggerLevel;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use wpdb;

final class BridgeCacheStrategy implements CacheStrategy
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key)
    {
        if (!array_key_exists($key, $this->items)) {
            throw new RuntimeException('Cache item missing.');
        }
        return $this->items[$key];
    }

    public function set(string $key, $value, ?int $ttl): void
    {
        $this->items[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }
}

final class BridgeCachePolicy implements CachePolicy
{
    public function getCacheKey(array $context): string
    {
        return json_encode($context, JSON_THROW_ON_ERROR);
    }

    public function shouldCache(string $operation, array $context = []): bool
    {
        return true;
    }

    public function getTtl(array $context = []): ?int
    {
        return null;
    }

    public function shouldInvalidate(string $operation, array $context = []): bool
    {
        return true;
    }
}

final class BridgeEvent implements Event
{
    public static function getId(): string
    {
        return 'bridge.event';
    }
}

final class BridgeEventStrategy implements EventStrategy
{
    /** @var list<Event> */
    public array $events = [];

    public function broadcast(Event $event): void
    {
        $this->events[] = $event;
    }

    public function attach(string $event, callable $action, ?int $priority = null): void
    {
    }

    public function detach(string $event, callable $action, ?int $priority = null): void
    {
    }
}

final class BridgeLogger implements LoggerStrategy
{
    public function emergency(string $message, array $context = []): void {}
    public function alert(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
    public function logException(\Exception $e, string $message = '', array $context = [], string $level = null) {}
}

final class BridgeHandler implements DatabaseHandler
{
    public function __construct(private Table $table, private DatabaseServiceProvider $provider)
    {
    }

    public function getDatabaseTable(): Table
    {
        return $this->table;
    }

    public function getDatabaseServiceProvider(): DatabaseServiceProvider
    {
        return $this->provider;
    }

    public function cloneForOperation(DatabaseServiceProvider $serviceProvider): DatabaseHandler
    {
        return new self($this->table, $serviceProvider);
    }
}

final class RealWpdbOperationDatabaseHandlerBridgeTest extends TestCase
{
    private const PARENT = 'nomad_wpdb_bridge_parent';
    private const CHILD = 'nomad_wpdb_bridge_child';

    private static wpdb $wpdb;
    private static ContractTable $parent;
    private static ContractTable $child;
    private static BridgeCacheStrategy $cacheStrategy;
    private static BridgeEventStrategy $eventStrategy;
    private static DatabaseServiceProvider $sourceProvider;
    private static BridgeHandler $handler;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(wpdb::class) || !extension_loaded('mysqli')) {
            self::markTestSkipped('The operation bridge suite requires official WordPress and ext-mysqli.');
        }
        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $user = getenv('MYSQL_USER') ?: 'root';
        $password = getenv('MYSQL_PASSWORD') ?: '';
        $database = getenv('MYSQL_DATABASE') ?: 'phpnomad_wordpress_integration_test';
        $probe = mysqli_init();
        mysqli_options($probe, MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        if (!@mysqli_real_connect($probe, $host, $user, $password, $database, (int) $port)) {
            self::markTestSkipped('The real MySQL 8.0 operation bridge fixture is unavailable.');
        }
        mysqli_close($probe);

        self::$wpdb = new wpdb($user, $password, $database, $host . ':' . $port);
        self::$wpdb->suppress_errors(false);
        $GLOBALS['wpdb'] = self::$wpdb;
        self::$parent = new ContractTable(self::PARENT, 'bridge_parent', [new Column('id', 'INT', null, 'PRIMARY KEY')], ['id']);
        self::$child = new ContractTable(self::CHILD, 'bridge_child', [new Column('id', 'INT', null, 'PRIMARY KEY'), new Column('value', 'VARCHAR', [64])], ['id']);
        self::query('DROP TABLE IF EXISTS ' . self::CHILD);
        self::query('DROP TABLE IF EXISTS ' . self::PARENT);
        self::query('CREATE TABLE ' . self::PARENT . ' (id INT PRIMARY KEY) ENGINE=InnoDB');
        self::query('CREATE TABLE ' . self::CHILD . ' (id INT PRIMARY KEY, value VARCHAR(64) NULL) ENGINE=InnoDB');

        self::$cacheStrategy = new BridgeCacheStrategy();
        self::$eventStrategy = new BridgeEventStrategy();
        self::$sourceProvider = new DatabaseServiceProvider(
            new BridgeLogger(),
            new CoordinatedQueryStrategy(),
            new QueryBuilder(),
            new ClauseBuilder(),
            new \PHPNomad\Cache\Services\CacheableService(
                self::$eventStrategy,
                self::$cacheStrategy,
                new BridgeCachePolicy()
            ),
            self::$eventStrategy
        );
        self::$handler = new BridgeHandler(self::$child, self::$sourceProvider);
    }

    protected function setUp(): void
    {
        self::query('DELETE FROM ' . self::CHILD);
        self::query('DELETE FROM ' . self::PARENT);
        self::query('INSERT INTO ' . self::PARENT . ' (id) VALUES (1)');
        self::$cacheStrategy->clear();
        self::$eventStrategy->events = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$wpdb)) {
            self::query('DROP TABLE IF EXISTS ' . self::CHILD);
            self::query('DROP TABLE IF EXISTS ' . self::PARENT);
            unset($GLOBALS['wpdb']);
        }
    }

    public function testInitializerBindsFactoryAndFactoryRefusesOutsideCallback(): void
    {
        $container = new \PHPNomad\Di\Container\Container();
        (new Bootstrapper($container, new WordPressInitializer()))->load();
        $factory = $container->get(OperationDatabaseProviderFactory::class);
        self::assertInstanceOf(WordPressOperationDatabaseProviderFactory::class, $factory);
        $ordinary = $container->get(\PHPNomad\Database\Interfaces\QueryStrategy::class);
        $coordinated = $container->get(\PHPNomad\Database\Interfaces\CoordinatedQueryStrategy::class);
        self::assertSame($ordinary, $coordinated);
        $factoryCoordinator = (new \ReflectionProperty($factory, 'coordinator'))->getValue($factory);
        self::assertSame($coordinated, $factoryCoordinator);
        self::assertInstanceOf(\PHPNomad\Integrations\WordPress\Providers\DatabaseProvider::class, $container->get(\PHPNomad\Integrations\WordPress\Providers\DatabaseProvider::class));
        self::assertInstanceOf(\PHPNomad\Cache\Interfaces\HasDefaultTtl::class, $container->get(\PHPNomad\Cache\Interfaces\HasDefaultTtl::class));

        $cache = new \PHPNomad\Database\Services\OperationCacheableService(self::$sourceProvider->cacheableService);
        $events = new \PHPNomad\Database\Services\OperationEventStrategy(self::$eventStrategy);
        self::expectException(\PHPNomad\Database\Exceptions\UnsupportedCoordinationException::class);
        $factory->create(self::$handler, self::$sourceProvider->queryStrategy, $cache, $events);
    }

    public function testBridgeUsesBoundBuildersSuppressesPublicationOnRollback(): void
    {
        $factory = new WordPressOperationDatabaseProviderFactory(self::$sourceProvider->queryStrategy);
        $bridge = new OperationDatabaseHandlerBridge($factory);
        $coordinator = self::$sourceProvider->queryStrategy;
        $this->assertInstanceOf(CoordinatedQueryStrategy::class, $coordinator);

        try {
            $bridge->coordinate($coordinator, self::$parent, ['id' => 1], [self::$parent, self::$child], ['child' => self::$handler], function (array $handlers): void {
                $provider = $handlers['child']->getDatabaseServiceProvider();
                $queryDatabase = $this->builderDatabase($provider->queryBuilder);
                $clauseDatabase = $this->builderDatabase($provider->clauseBuilder);
                self::assertSame(self::$wpdb, $queryDatabase);
                self::assertSame(self::$wpdb, $clauseDatabase);
                self::assertNotSame(self::$sourceProvider->queryBuilder, $provider->queryBuilder);
                self::assertNotSame(self::$sourceProvider->clauseBuilder, $provider->clauseBuilder);

                $provider->queryStrategy->insert(self::$child, ['id' => 21, 'value' => 'rolled-back']);
                $clause = $provider->clauseBuilder->reset()->useTable(self::$child)->where('id', '=', 21);
                $builder = $provider->queryBuilder->reset()->select('*')->from(self::$child)->where($clause);
                self::assertSame('rolled-back', $provider->queryStrategy->query($builder)[0]['value']);
                $provider->cacheableService->set(['id' => 21], ['value' => 'rolled-back']);
                $provider->eventStrategy->broadcast(new BridgeEvent());
                throw new RuntimeException('bridge rollback');
            });
            self::fail('Expected bridge rollback.');
        } catch (RuntimeException $failure) {
            self::assertSame('bridge rollback', $failure->getMessage());
        }

        self::assertFalse((bool) self::$wpdb->get_var('SELECT 1 FROM ' . self::CHILD . ' WHERE id = 21'));
        self::assertFalse(self::$cacheStrategy->exists(json_encode(['id' => 21], JSON_THROW_ON_ERROR)));
        self::assertCount(0, self::$eventStrategy->events);
    }

    public function testBridgePublishesInvalidationAndEventsAfterConfirmedCommit(): void
    {
        $factory = new WordPressOperationDatabaseProviderFactory(self::$sourceProvider->queryStrategy);
        $bridge = new OperationDatabaseHandlerBridge($factory);
        $result = $bridge->coordinate(self::$sourceProvider->queryStrategy, self::$parent, ['id' => 1], [self::$parent, self::$child], ['child' => self::$handler], function (array $handlers): string {
            $provider = $handlers['child']->getDatabaseServiceProvider();
            $provider->queryStrategy->insert(self::$child, ['id' => 22, 'value' => 'committed']);
            $clause = $provider->clauseBuilder->reset()->useTable(self::$child)->where('id', '=', 22);
            $builder = $provider->queryBuilder->reset()->select('*')->from(self::$child)->where($clause);
            if ($provider->queryStrategy->query($builder)[0]['value'] !== 'committed') {
                throw new RuntimeException('The operation query did not read its own write.');
            }
            $provider->cacheableService->set(['id' => 22], ['value' => 'committed']);
            $provider->eventStrategy->broadcast(new BridgeEvent());
            return 'done';
        });

        self::assertSame('done', $result->getValue());
        self::assertSame('committed', self::$wpdb->get_var('SELECT value FROM ' . self::CHILD . ' WHERE id = 22'));
        self::assertFalse(self::$cacheStrategy->exists(json_encode(['id' => 22], JSON_THROW_ON_ERROR)));
        self::assertCount(1, self::$eventStrategy->events);
        self::assertInstanceOf(BridgeEvent::class, self::$eventStrategy->events[0]);
    }

    private function builderDatabase(object $builder): wpdb
    {
        $property = new \ReflectionProperty($builder, 'database');
        $property->setAccessible(true);
        $database = $property->getValue($builder);
        self::assertInstanceOf(wpdb::class, $database);
        return $database;
    }

    private static function query(string $sql): void
    {
        if (self::$wpdb->query($sql) === false) {
            throw new RuntimeException(self::$wpdb->last_error);
        }
    }
}
