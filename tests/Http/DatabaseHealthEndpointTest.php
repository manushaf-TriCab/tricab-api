<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\AzureSqlConnectionFactory;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;

final class DatabaseHealthEndpointTest extends TestCase
{
    public function test_health_database_reports_unavailable_without_pdo_sqlsrv_when_azure_sql_selected(): void
    {
        if (extension_loaded('pdo_sqlsrv')) {
            self::markTestSkipped('pdo_sqlsrv is installed; unavailable-path assertion not applicable.');
        }

        $app = \TriCab\Api\Tests\TestAppFactory::create([
            'USER_REPOSITORY' => 'azure-sql',
            'DB_HOST' => 'tricab-mel.database.windows.net',
            'DB_DATABASE' => 'tc',
            'DB_USERNAME' => 'example',
            'DB_PASSWORD' => 'example',
        ]);

        $response = $app->handle(\TriCab\Api\Tests\TestAppFactory::request('GET', '/health/database'));
        $payload = \TriCab\Api\Tests\TestAppFactory::json($response);

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('DATABASE_UNAVAILABLE', $error['code']);
        self::assertSame('The database is currently unavailable.', $error['message']);
    }

    public function test_connection_factory_requires_pdo_sqlsrv(): void
    {
        if (extension_loaded('pdo_sqlsrv')) {
            self::markTestSkipped('pdo_sqlsrv is installed.');
        }

        $settings = Settings::fromEnvironment([
            'USER_REPOSITORY' => 'azure-sql',
            'DB_HOST' => 'tricab-mel.database.windows.net',
            'DB_DATABASE' => 'tc',
        ]);

        $this->expectException(DatabaseUnavailable::class);
        (new AzureSqlConnectionFactory($settings))->create();
    }
}
