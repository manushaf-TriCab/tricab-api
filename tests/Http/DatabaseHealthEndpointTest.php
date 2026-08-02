<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\DatabaseAuthFailed;
use TriCab\Api\Tests\TestAppFactory;

final class DatabaseHealthEndpointTest extends TestCase
{
    public function test_health_database_skips_for_memory_repository(): void
    {
        $app = TestAppFactory::create([
            'USER_REPOSITORY' => 'memory',
        ]);

        $response = $app->handle(TestAppFactory::request('GET', '/health/database'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['database']);
        /** @var array<string, mixed> $database */
        $database = $payload['database'];
        self::assertSame('skipped', $database['status']);
    }

    public function test_health_database_reports_auth_failed_when_entra_cli_unavailable(): void
    {
        $app = TestAppFactory::create([
            'USER_REPOSITORY' => 'azure-sql',
            'DB_AUTH_MODE' => 'entra-cli',
            'DB_HOST' => 'tricab-mel.database.windows.net',
            'DB_DATABASE' => 'tc',
            'GUEST_JWT_SECRET' => str_repeat('t', 64),
        ]);

        // Force provider binary to a missing executable via container replacement is hard;
        // instead exercise the factory directly with a missing az binary.
        $settings = Settings::fromEnvironment([
            'USER_REPOSITORY' => 'azure-sql',
            'DB_AUTH_MODE' => 'entra-cli',
            'DB_HOST' => 'tricab-mel.database.windows.net',
            'DB_DATABASE' => 'tc',
            'GUEST_JWT_SECRET' => str_repeat('t', 64),
        ]);
        $factory = new AzureSqlPdoFactory(
            $settings,
            new AzureCliAccessTokenProvider('/path/to/missing-az-binary'),
        );

        try {
            $factory->create();
            self::fail('Expected DatabaseAuthFailed');
        } catch (DatabaseAuthFailed $exception) {
            self::assertSame('Unable to authenticate with Azure SQL.', $exception->getMessage());
        }

        // Endpoint should map auth failure safely when az cannot authenticate.
        // If az is installed and logged in, this may succeed — skip then.
        $response = $app->handle(TestAppFactory::request('GET', '/health/database'));
        $payload = TestAppFactory::json($response);

        if (($payload['success'] ?? false) === true) {
            self::markTestSkipped('Azure CLI is available and authenticated in this environment.');
        }

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertContains($error['code'], ['DATABASE_AUTH_FAILED', 'DATABASE_UNAVAILABLE']);
        self::assertIsString($error['message']);
        self::assertStringNotContainsString('eyJ', (string) $error['message']);
    }

    public function test_sql_login_mode_requires_credentials(): void
    {
        $settings = Settings::fromEnvironment([
            'USER_REPOSITORY' => 'azure-sql',
            'DB_AUTH_MODE' => 'sql-login',
            'DB_HOST' => 'tricab-mel.database.windows.net',
            'DB_DATABASE' => 'tc',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
        ]);

        $factory = new AzureSqlPdoFactory($settings, new AzureCliAccessTokenProvider());

        $this->expectException(DatabaseAuthFailed::class);
        $factory->create();
    }
}
