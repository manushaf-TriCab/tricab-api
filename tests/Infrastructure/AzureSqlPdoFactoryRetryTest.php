<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\DatabaseAuthFailed;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\PdoConnector;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;

final class AzureSqlPdoFactoryRetryTest extends TestCase
{
    public function test_connection_succeeds_on_first_attempt(): void
    {
        $connector = new SequencePdoConnector([self::sqlitePdo()]);
        $metrics = new RequestPerformanceMetrics();
        $slept = [];
        $factory = $this->factory($connector, $metrics, $slept);

        $pdo = $factory->create();

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame(1, $connector->connectCount);
        self::assertSame([], $slept);
        $snap = $metrics->snapshot();
        self::assertSame(1, $snap['db_connect_attempts']);
        self::assertSame(0, $snap['db_connect_retry']);
        self::assertSame('', $snap['db_connect_retry_reason']);
        self::assertSame(0.0, $snap['db_connect_retry_ms']);
        self::assertStringContainsString('LoginTimeout=20', $connector->dsns[0] ?? '');
        self::assertSame(0, $connector->queryCount);
    }

    public function test_hyt00_then_success_retries_once(): void
    {
        $connector = new SequencePdoConnector([
            self::hyt00(),
            self::sqlitePdo(),
        ]);
        $metrics = new RequestPerformanceMetrics();
        $slept = [];
        $factory = $this->factory($connector, $metrics, $slept, [
            'DB_CONNECT_RETRY_DELAY_MS' => '200',
        ]);

        $pdo = $factory->create();

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame(2, $connector->connectCount);
        self::assertSame([200], $slept);
        self::assertSame(0, $connector->queryCount);
        $snap = $metrics->snapshot();
        self::assertSame(2, $snap['db_connect_attempts']);
        self::assertSame(1, $snap['db_connect_retry']);
        self::assertSame('HYT00', $snap['db_connect_retry_reason']);
        self::assertGreaterThanOrEqual(0, $snap['db_connect_first_attempt_ms']);
        self::assertGreaterThanOrEqual(0, $snap['pdo_ms']);
        self::assertArrayHasKey('pdo_connect_ms', $snap);
    }

    public function test_hyt00_on_both_attempts_fails_as_temporarily_unavailable(): void
    {
        $connector = new SequencePdoConnector([self::hyt00(), self::hyt00()]);
        $metrics = new RequestPerformanceMetrics();
        $slept = [];
        $factory = $this->factory($connector, $metrics, $slept, [
            'DB_CONNECT_RETRY_DELAY_MS' => '200',
        ]);

        try {
            $factory->create();
            self::fail('Expected DatabaseTemporarilyUnavailable');
        } catch (DatabaseTemporarilyUnavailable $exception) {
            self::assertSame('HYT00', $exception->retryReason);
            self::assertSame('The database is temporarily unavailable.', $exception->getMessage());
        }

        self::assertSame(2, $connector->connectCount);
        self::assertSame([200], $slept);
        self::assertSame(0, $connector->queryCount);
        $snap = $metrics->snapshot();
        self::assertSame(2, $snap['db_connect_attempts']);
        self::assertSame(1, $snap['db_connect_retry']);
        self::assertSame('HYT00', $snap['db_connect_retry_reason']);
    }

    public function test_non_transient_error_is_not_retried(): void
    {
        $connector = new SequencePdoConnector([
            self::pdoException('42000', 102, 'Incorrect syntax near SELECT'),
        ]);
        $metrics = new RequestPerformanceMetrics();
        $slept = [];
        $factory = $this->factory($connector, $metrics, $slept);

        try {
            $factory->create();
            self::fail('Expected DatabaseUnavailable');
        } catch (DatabaseTemporarilyUnavailable $exception) {
            unset($exception);
            self::fail('Syntax errors must not be classified as temporary unavailability.');
        } catch (DatabaseUnavailable $exception) {
            self::assertSame('Azure SQL connection failed.', $exception->getMessage());
        }

        self::assertSame(1, $connector->connectCount);
        self::assertSame([], $slept);
        self::assertSame(0, $metrics->snapshot()['db_connect_retry']);
    }

    public function test_authentication_failure_is_not_retried(): void
    {
        $connector = new SequencePdoConnector([
            self::pdoException('28000', 18456, 'Login failed for user \'app\'.'),
        ]);
        $metrics = new RequestPerformanceMetrics();
        $slept = [];
        $factory = $this->factory($connector, $metrics, $slept);

        $this->expectException(DatabaseAuthFailed::class);
        try {
            $factory->create();
        } finally {
            self::assertSame(1, $connector->connectCount);
            self::assertSame([], $slept);
            self::assertSame(0, $metrics->snapshot()['db_connect_retry']);
        }
    }

    public function test_retry_attempts_are_capped_at_two(): void
    {
        $connector = new SequencePdoConnector([self::hyt00(), self::hyt00(), self::sqlitePdo()]);
        $slept = [];
        $factory = $this->factory($connector, new RequestPerformanceMetrics(), $slept, [
            'DB_CONNECT_RETRY_MAX_ATTEMPTS' => '2',
            'DB_CONNECT_RETRY_DELAY_MS' => '200',
        ]);

        $this->expectException(DatabaseTemporarilyUnavailable::class);
        try {
            $factory->create();
        } finally {
            self::assertSame(2, $connector->connectCount);
            self::assertCount(1, $slept);
        }
    }

    public function test_retry_disabled_does_not_retry_hyt00(): void
    {
        $connector = new SequencePdoConnector([self::hyt00(), self::sqlitePdo()]);
        $metrics = new RequestPerformanceMetrics();
        $slept = [];
        $factory = $this->factory($connector, $metrics, $slept, [
            'DB_CONNECT_RETRY_ENABLED' => 'false',
        ]);

        $this->expectException(DatabaseTemporarilyUnavailable::class);
        try {
            $factory->create();
        } finally {
            self::assertSame(1, $connector->connectCount);
            self::assertSame([], $slept);
            self::assertSame(1, $metrics->snapshot()['db_connect_attempts']);
            self::assertSame(0, $metrics->snapshot()['db_connect_retry']);
        }
    }

    public function test_configured_login_timeout_is_written_to_dsn(): void
    {
        $connector = new SequencePdoConnector([self::sqlitePdo()]);
        $slept = [];
        $factory = $this->factory($connector, new RequestPerformanceMetrics(), $slept, [
            'DB_LOGIN_TIMEOUT_SECONDS' => '30',
        ]);

        $factory->create();

        self::assertStringContainsString('LoginTimeout=30', $connector->dsns[0] ?? '');
    }

    public function test_max_attempts_one_never_retries(): void
    {
        $connector = new SequencePdoConnector([self::hyt00(), self::sqlitePdo()]);
        $slept = [];
        $factory = $this->factory($connector, new RequestPerformanceMetrics(), $slept, [
            'DB_CONNECT_RETRY_ENABLED' => 'true',
            'DB_CONNECT_RETRY_MAX_ATTEMPTS' => '1',
        ]);

        $this->expectException(DatabaseTemporarilyUnavailable::class);
        try {
            $factory->create();
        } finally {
            self::assertSame(1, $connector->connectCount);
            self::assertSame([], $slept);
        }
    }

    /**
     * @param array<string, string> $env
     * @param list<int> $slept
     */
    private function factory(
        SequencePdoConnector $connector,
        RequestPerformanceMetrics $metrics,
        array &$slept,
        array $env = [],
    ): AzureSqlPdoFactory {
        $settings = Settings::fromEnvironment(array_merge([
            'USER_REPOSITORY' => 'azure-sql',
            'DB_AUTH_MODE' => 'sql-login',
            'DB_HOST' => 'example.database.windows.net',
            'DB_DATABASE' => 'tc',
            'DB_USERNAME' => 'app-user',
            'DB_PASSWORD' => 'app-password',
            'DB_CONNECT_RETRY_ENABLED' => 'true',
            'DB_CONNECT_RETRY_MAX_ATTEMPTS' => '2',
            'DB_CONNECT_RETRY_DELAY_MS' => '200',
            'DB_LOGIN_TIMEOUT_SECONDS' => '20',
            'APP_CACHE_DRIVER' => 'array',
        ], $env));

        return new AzureSqlPdoFactory(
            $settings,
            new AzureCliAccessTokenProvider('/missing-az'),
            $metrics,
            $connector,
            static function (int $milliseconds) use (&$slept): void {
                $slept[] = $milliseconds;
            },
        );
    }

    private static function sqlitePdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }

    private static function hyt00(): PDOException
    {
        return self::pdoException(
            'HYT00',
            0,
            'SQLSTATE[HYT00]: [Microsoft][ODBC Driver 18 for SQL Server]Login timeout expired',
        );
    }

    private static function pdoException(string $sqlState, int $driverCode, string $message): PDOException
    {
        $exception = new PDOException($message, 0);
        $exception->errorInfo = [$sqlState, $driverCode, $message];

        return $exception;
    }
}

/**
 * @internal
 */
final class SequencePdoConnector implements PdoConnector
{
    public int $connectCount = 0;

    public int $queryCount = 0;

    /** @var list<string> */
    public array $dsns = [];

    /**
     * @param list<PDO|PDOException> $results
     */
    public function __construct(private array $results)
    {
    }

    public function connect(string $dsn, ?string $username, ?string $password, array $options): PDO
    {
        unset($username, $password, $options);
        $this->dsns[] = $dsn;
        ++$this->connectCount;
        $next = array_shift($this->results);
        if ($next instanceof PDOException) {
            throw $next;
        }
        if ($next instanceof PDO) {
            return $next;
        }

        throw new PDOException('SequencePdoConnector exhausted');
    }
}
