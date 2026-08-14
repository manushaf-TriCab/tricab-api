<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\LazySqlConnection;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMiddleware;
use TriCab\Api\Tests\TestAppFactory;

final class RequestPerformanceMetricsTest extends TestCase
{
    public function test_pdo_sql_connection_records_sql_count_and_duration(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $metrics = new RequestPerformanceMetrics();
        $connection = new PdoSqlConnection($pdo, $metrics);

        $connection->fetchAll('/*q:cv.product*/ SELECT 1');
        $connection->fetchAll('SELECT 2');

        $snap = $metrics->snapshot();
        self::assertSame(2, $snap['sql_count']);
        self::assertGreaterThan(0, $snap['sql_ms']);
        self::assertGreaterThan(0, $snap['first_query_ms']);
        self::assertArrayHasKey('pdo_connect_ms', $snap);
        self::assertCount(1, $snap['named_queries']);
        self::assertSame('cv.product', $snap['named_queries'][0]['name']);
        self::assertSame(1, $snap['named_queries'][0]['rows']);
    }

    public function test_container_shares_one_metrics_instance(): void
    {
        $app = TestAppFactory::create([
            'USER_REPOSITORY' => 'azure-sql',
            'DB_AUTH_MODE' => 'entra-cli',
            'DB_HOST' => 'tricab-mel.database.windows.net',
            'DB_DATABASE' => 'tc',
            'GUEST_JWT_SECRET' => str_repeat('t', 64),
            'APP_CACHE_DRIVER' => 'array',
        ]);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        $metrics = $container->get(RequestPerformanceMetrics::class);
        $middleware = $container->get(RequestPerformanceMiddleware::class);
        $factory = $container->get(AzureSqlPdoFactory::class);
        $sql = $container->get(SqlConnection::class);

        self::assertInstanceOf(RequestPerformanceMetrics::class, $metrics);
        self::assertInstanceOf(RequestPerformanceMiddleware::class, $middleware);
        self::assertInstanceOf(AzureSqlPdoFactory::class, $factory);
        self::assertInstanceOf(LazySqlConnection::class, $sql);
        self::assertFalse($sql->isConnected());

        $middlewareMetrics = new ReflectionProperty(RequestPerformanceMiddleware::class, 'metrics');
        $factoryMetrics = new ReflectionProperty(AzureSqlPdoFactory::class, 'metrics');

        self::assertSame($metrics, $middlewareMetrics->getValue($middleware));
        self::assertSame($metrics, $factoryMetrics->getValue($factory));
    }

    public function test_connect_attempt_telemetry(): void
    {
        $metrics = new RequestPerformanceMetrics();
        $metrics->recordConnectAttempt(1, 15100.4, 'HYT00');
        $metrics->recordConnectAttempt(2, 10676.2, null);

        $snap = $metrics->snapshot();
        self::assertSame(2, $snap['db_connect_attempts']);
        self::assertSame(1, $snap['db_connect_retry']);
        self::assertSame('HYT00', $snap['db_connect_retry_reason']);
        self::assertSame(15100.4, $snap['db_connect_first_attempt_ms']);
        self::assertSame(10676.2, $snap['db_connect_retry_ms']);
    }

    public function test_lazy_connection_does_not_connect_until_query(): void
    {
        $connected = false;
        $metrics = new RequestPerformanceMetrics();
        $lazy = new LazySqlConnection(static function () use (&$connected, $metrics): PdoSqlConnection {
            $connected = true;

            return new PdoSqlConnection(new PDO('sqlite::memory:'), $metrics);
        });

        self::assertFalse($lazy->isConnected());
        self::assertFalse($connected);
        $lazy->fetchAll('SELECT 1');
        self::assertTrue($lazy->isConnected());
        self::assertTrue($connected);
        self::assertSame(1, $metrics->snapshot()['sql_count']);
    }
}
