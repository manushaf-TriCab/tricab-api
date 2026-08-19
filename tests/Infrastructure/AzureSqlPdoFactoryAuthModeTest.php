<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PDO;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Authentication\AzureCliProcessRunner;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentitySqlAccessTokenProvider;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentityTokenClient;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\DatabaseAuthFailed;
use TriCab\Api\Infrastructure\Database\PdoConnector;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

final class AzureSqlPdoFactoryAuthModeTest extends TestCase
{
    private const SECRET_TOKEN = 'secret-mi-token-do-not-log-9f3a7c';

    private const IDENTITY_HEADER = 'header-secret-do-not-log';

    public function test_managed_identity_uses_access_token_path_and_not_sql_login(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'access_token' => self::SECRET_TOKEN,
            'expires_on' => '4102444800',
        ], JSON_THROW_ON_ERROR);
        $cliRunner = new CountingAzureCliProcessRunner();
        $connector = new RecordingAuthPdoConnector();
        $factory = $this->factory(
            'managed-identity',
            $connector,
            $cliRunner,
            $http,
        );

        $factory->create();

        self::assertCount(1, $http->calls);
        self::assertSame([], $cliRunner->commands);
        self::assertCount(1, $connector->calls);
        self::assertNull($connector->calls[0]['username']);
        self::assertNull($connector->calls[0]['password']);
        self::assertStringContainsString('AccessToken', $connector->calls[0]['dsn']);
        self::assertStringNotContainsString('app-user', $connector->calls[0]['dsn']);
    }

    public function test_entra_cli_uses_cli_token_provider_and_not_managed_identity(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'access_token' => 'should-not-be-used',
            'expires_on' => '4102444800',
        ], JSON_THROW_ON_ERROR);
        $cliRunner = new CountingAzureCliProcessRunner();
        $cliRunner->queueJson([
            'accessToken' => 'cli-token-value',
            'expires_on' => time() + 3600,
        ]);
        $connector = new RecordingAuthPdoConnector();
        $factory = $this->factory(
            'entra-cli',
            $connector,
            $cliRunner,
            $http,
        );

        $factory->create();

        self::assertSame([], $http->calls);
        self::assertCount(1, $cliRunner->commands);
        self::assertSame('get-access-token', $cliRunner->commands[0][2] ?? null);
        self::assertNull($connector->calls[0]['username']);
        self::assertNull($connector->calls[0]['password']);
        self::assertStringContainsString('AccessToken', $connector->calls[0]['dsn']);
    }

    public function test_sql_login_uses_username_password_and_not_token_providers(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'access_token' => 'should-not-be-used',
            'expires_on' => '4102444800',
        ], JSON_THROW_ON_ERROR);
        $cliRunner = new CountingAzureCliProcessRunner();
        $connector = new RecordingAuthPdoConnector();
        $factory = $this->factory(
            'sql-login',
            $connector,
            $cliRunner,
            $http,
            [
                'DB_USERNAME' => 'app-user',
                'DB_PASSWORD' => 'app-password',
            ],
        );

        $factory->create();

        self::assertSame([], $http->calls);
        self::assertSame([], $cliRunner->commands);
        self::assertSame('app-user', $connector->calls[0]['username']);
        self::assertSame('app-password', $connector->calls[0]['password']);
        self::assertStringNotContainsString('AccessToken', $connector->calls[0]['dsn']);
    }

    public function test_managed_identity_does_not_fall_back_to_sql_login_when_username_is_empty(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'access_token' => self::SECRET_TOKEN,
            'expires_on' => '4102444800',
        ], JSON_THROW_ON_ERROR);
        $connector = new RecordingAuthPdoConnector();
        $factory = $this->factory(
            'managed-identity',
            $connector,
            new CountingAzureCliProcessRunner(),
            $http,
            [
                'DB_USERNAME' => '',
                'DB_PASSWORD' => '',
            ],
        );

        $factory->create();

        self::assertCount(1, $http->calls);
        self::assertNull($connector->calls[0]['username']);
        self::assertNull($connector->calls[0]['password']);
        self::assertStringContainsString('AccessToken', $connector->calls[0]['dsn']);
    }

    public function test_sql_login_without_credentials_does_not_use_token_providers(): void
    {
        $http = new FakeJsonHttpClient();
        $cliRunner = new CountingAzureCliProcessRunner();
        $connector = new RecordingAuthPdoConnector();
        $factory = $this->factory(
            'sql-login',
            $connector,
            $cliRunner,
            $http,
            [
                'DB_USERNAME' => '',
                'DB_PASSWORD' => '',
            ],
        );

        try {
            $factory->create();
            self::fail('Expected DatabaseAuthFailed');
        } catch (DatabaseAuthFailed $exception) {
            self::assertStringContainsString('sql-login', $exception->getMessage());
        }

        self::assertSame([], $http->calls);
        self::assertSame([], $cliRunner->commands);
        self::assertSame([], $connector->calls);
    }

    public function test_token_and_identity_header_are_not_written_to_diagnostics(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'access_token' => self::SECRET_TOKEN,
            'expires_on' => '4102444800',
        ], JSON_THROW_ON_ERROR);
        $errorLog = sys_get_temp_dir() . '/tricab-db-auth-mode-' . bin2hex(random_bytes(8)) . '.log';
        $previous = ini_set('error_log', $errorLog);
        $diagLog = dirname(__DIR__, 2) . '/var/log/database-auth.log';
        $diagBefore = is_file($diagLog) ? (string) file_get_contents($diagLog) : '';

        try {
            $this->factory(
                'managed-identity',
                new RecordingAuthPdoConnector(),
                new CountingAzureCliProcessRunner(),
                $http,
            )->create();

            $errorContents = is_file($errorLog) ? (string) file_get_contents($errorLog) : '';
            $diagAfter = is_file($diagLog) ? (string) file_get_contents($diagLog) : '';
            $diagDelta = substr($diagAfter, strlen($diagBefore));

            self::assertStringNotContainsString(self::SECRET_TOKEN, $errorContents);
            self::assertStringNotContainsString(self::IDENTITY_HEADER, $errorContents);
            self::assertStringNotContainsString(self::SECRET_TOKEN, $diagDelta);
            self::assertStringNotContainsString(self::IDENTITY_HEADER, $diagDelta);
        } finally {
            if (is_string($previous)) {
                ini_set('error_log', $previous);
            }
            if (is_file($errorLog)) {
                unlink($errorLog);
            }
        }
    }

    /**
     * @param array<string, string> $env
     */
    private function factory(
        string $authMode,
        RecordingAuthPdoConnector $connector,
        CountingAzureCliProcessRunner $cliRunner,
        FakeJsonHttpClient $http,
        array $env = [],
    ): AzureSqlPdoFactory {
        $settings = Settings::fromEnvironment(array_merge([
            'USER_REPOSITORY' => 'azure-sql',
            'DB_AUTH_MODE' => $authMode,
            'DB_HOST' => 'example.database.windows.net',
            'DB_DATABASE' => 'tc',
            'DB_USERNAME' => 'app-user',
            'DB_PASSWORD' => 'app-password',
            'DB_CONNECT_RETRY_ENABLED' => 'false',
            'APP_CACHE_DRIVER' => 'array',
        ], $env));

        return new AzureSqlPdoFactory(
            $settings,
            new AzureCliAccessTokenProvider('az', $cliRunner, null, static fn (): int => time(), new ArrayApplicationCache()),
            new RequestPerformanceMetrics(),
            $connector,
            null,
            new ManagedIdentitySqlAccessTokenProvider(
                new ManagedIdentityTokenClient($http, [
                    'IDENTITY_ENDPOINT' => 'http://127.0.0.1:41741/MSI/token',
                    'IDENTITY_HEADER' => self::IDENTITY_HEADER,
                ]),
                new ArrayApplicationCache(),
            ),
        );
    }
}

/**
 * @internal
 */
final class RecordingAuthPdoConnector implements PdoConnector
{
    /** @var list<array{dsn: string, username: ?string, password: ?string}> */
    public array $calls = [];

    public function connect(string $dsn, ?string $username, ?string $password, array $options): PDO
    {
        unset($options);
        $this->calls[] = [
            'dsn' => $dsn,
            'username' => $username,
            'password' => $password,
        ];

        return new PDO('sqlite::memory:');
    }
}

/**
 * @internal
 */
final class CountingAzureCliProcessRunner implements AzureCliProcessRunner
{
    /** @var list<array{0: int, 1: string, 2: string}> */
    private array $queue = [];

    /** @var list<list<string>> */
    public array $commands = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function queueJson(array $payload): void
    {
        $this->queue[] = [0, json_encode($payload, JSON_THROW_ON_ERROR), ''];
    }

    public function run(array $command): array
    {
        $this->commands[] = $command;

        return array_shift($this->queue) ?? [1, '', ''];
    }
}
