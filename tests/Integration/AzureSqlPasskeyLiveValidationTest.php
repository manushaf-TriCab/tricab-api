<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Passkey\WebauthnLibPasskeyCeremony;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Contract\PasskeyCeremony;

/**
 * Live Azure SQL passkey adapter/schema checks. Skipped unless RUN_AZURE_INTEGRATION_TESTS=true.
 *
 * @group azure-sql
 */
#[Group('azure-sql')]
final class AzureSqlPasskeyLiveValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $enabled = getenv('RUN_AZURE_INTEGRATION_TESTS') === 'true'
            || getenv('RUN_AZURE_SQL_INTEGRATION_TESTS') === 'true';

        if (!$enabled) {
            self::markTestSkipped('Set RUN_AZURE_INTEGRATION_TESTS=true to run Azure SQL integration tests.');
        }

        if (!extension_loaded('pdo_sqlsrv')) {
            self::markTestSkipped('pdo_sqlsrv extension is not installed.');
        }
    }

    public function test_azure_sql_selects_webauthn_lib_and_passkey_tables_exist(): void
    {
        $basePath = dirname(__DIR__, 2);
        $env = Environment::load($basePath);
        $settings = Settings::fromEnvironment(array_merge($env, [
            'USER_REPOSITORY' => Settings::USER_REPOSITORY_AZURE_SQL,
            'PASSKEY_FAKE_CEREMONY' => 'false',
        ]));

        self::assertFalse($settings->isProduction());
        self::assertTrue($settings->usesAzureSqlUserRepository());
        self::assertFalse($settings->usesFakePasskeyCeremony());
        self::assertInstanceOf(
            WebauthnLibPasskeyCeremony::class,
            ContainerFactory::passkeyCeremonyFor($settings),
        );

        $container = ContainerFactory::create($settings);
        $ceremony = $container->get(PasskeyCeremony::class);
        self::assertInstanceOf(WebauthnLibPasskeyCeremony::class, $ceremony);
        self::assertSame('WebauthnLibPasskeyCeremony', (new ReflectionClass($ceremony))->getShortName());

        /** @var SqlConnection $db */
        $db = $container->get(SqlConnection::class);
        $envRows = $db->fetchAll('SELECT @@SERVERNAME AS [serverName], DB_NAME() AS [dbName]');
        $server = (string) ($envRows[0]['serverName'] ?? '');
        $database = (string) ($envRows[0]['dbName'] ?? '');
        self::assertStringContainsString('tricab-mel', strtolower($server));
        self::assertSame('tc', strtolower($database));

        foreach ([
            'UserWebAuthnHandles',
            'UserPasskeys',
            'GuestPasskeySetupTokens',
            'PasskeyChallenges',
            'PasskeyRegistrationSessions',
        ] as $table) {
            $rows = $db->fetchAll(
                "SELECT CASE WHEN OBJECT_ID(N'[identity].[{$table}]', N'U') IS NULL THEN 0 ELSE 1 END AS [TableExists]",
            );
            self::assertSame(1, (int) ($rows[0]['TableExists'] ?? 0), $table . ' is missing');
        }
    }
}
