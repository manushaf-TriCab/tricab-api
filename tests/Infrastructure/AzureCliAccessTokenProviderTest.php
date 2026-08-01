<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenException;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;

final class AzureCliAccessTokenProviderTest extends TestCase
{
    public function test_missing_azure_cli_throws_descriptive_exception(): void
    {
        $provider = new AzureCliAccessTokenProvider('/definitely/missing/az');

        $this->expectException(AzureCliAccessTokenException::class);
        $this->expectExceptionMessage('Azure CLI is unavailable');

        $provider->getAccessToken();
    }

    public function test_settings_recognise_entra_cli_mode(): void
    {
        $settings = Settings::fromEnvironment([
            'DB_AUTH_MODE' => 'entra-cli',
            'USER_REPOSITORY' => 'azure-sql',
            'AZURE_CLI_BINARY' => 'C:/Program Files/Microsoft SDKs/Azure/CLI2/wbin/az.cmd',
        ]);

        self::assertTrue($settings->usesEntraCliAuth());
        self::assertFalse($settings->usesSqlLoginAuth());
        self::assertSame(Settings::DB_AUTH_MODE_ENTRA_CLI, $settings->dbAuthMode);
        self::assertSame(
            'C:/Program Files/Microsoft SDKs/Azure/CLI2/wbin/az.cmd',
            $settings->azureCliBinary,
        );
    }
}
