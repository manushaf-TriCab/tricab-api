<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;

final class SettingsTest extends TestCase
{
    public function test_unknown_user_repository_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported USER_REPOSITORY "sqlite"');

        Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'sqlite',
        ]);
    }

    public function test_production_with_memory_repository_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('USER_REPOSITORY=memory is not allowed when APP_ENV=production.');

        Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'USER_REPOSITORY' => 'memory',
        ]);
    }

    public function test_production_with_memory_requires_explicit_allow_flag_for_tests(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'USER_REPOSITORY' => 'memory',
            'ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION' => 'true',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => 'token',
            'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
        ]);

        self::assertFalse($settings->usesAzureSqlUserRepository());
    }

    public function test_production_default_is_azure_sql_when_unset(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => 'token',
            'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
        ]);

        self::assertSame(Settings::USER_REPOSITORY_AZURE_SQL, $settings->userRepository);
        self::assertTrue($settings->usesAzureSqlUserRepository());
    }

    public function test_development_default_is_memory_when_unset(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
        ]);

        self::assertSame(Settings::USER_REPOSITORY_MEMORY, $settings->userRepository);
        self::assertFalse($settings->usesAzureSqlUserRepository());
    }

    public function test_production_with_azure_sql_repository_is_allowed(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'USER_REPOSITORY' => 'azure-sql',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => 'token',
            'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
        ]);

        self::assertTrue($settings->usesAzureSqlUserRepository());
    }

    public function test_user_repository_value_is_case_insensitive(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'AZURE-SQL',
        ]);

        self::assertTrue($settings->usesAzureSqlUserRepository());
    }

    public function test_dev_memory_product_file_delivery_only_in_development_memory_mode(): void
    {
        $enabled = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'BLOB_AUTH_MODE' => 'memory',
        ]);
        self::assertTrue($enabled->isDevMemoryProductFileDeliveryEnabled());

        $disabledDev = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'BLOB_AUTH_MODE' => 'disabled',
        ]);
        self::assertTrue($disabledDev->isDevMemoryProductFileDeliveryEnabled());

        $production = Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'USER_REPOSITORY' => 'memory',
            'ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION' => 'true',
            'BLOB_AUTH_MODE' => 'disabled',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => 'token',
            'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
        ]);
        self::assertFalse($production->isDevMemoryProductFileDeliveryEnabled());
    }

    public function test_performance_logging_defaults_on_in_development_off_in_production(): void
    {
        $development = Settings::fromEnvironment([
            'APP_ENV' => 'development',
        ]);
        self::assertTrue($development->performanceLogging);
        self::assertFalse($development->performanceSqlVerbose);

        $production = Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'USER_REPOSITORY' => 'azure-sql',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => 'token',
            'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
        ]);
        self::assertFalse($production->performanceLogging);
        self::assertFalse($production->performanceSqlVerbose);
    }

    public function test_unknown_cache_driver_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported APP_CACHE_DRIVER');

        Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'APP_CACHE_DRIVER' => 'redis',
        ]);
    }

    public function test_explicit_array_cache_driver(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'APP_CACHE_DRIVER' => 'array',
        ]);
        self::assertSame(Settings::CACHE_DRIVER_ARRAY, $settings->appCacheDriver);
    }

    public function test_file_cache_driver_and_path(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'APP_CACHE_DRIVER' => 'file',
            'APP_CACHE_PATH' => 'var/cache/app',
        ]);
        self::assertSame(Settings::CACHE_DRIVER_FILE, $settings->appCacheDriver);
        self::assertSame('var/cache/app', $settings->appCachePath);
    }

    public function test_serverless_resume_defaults(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
        ]);

        self::assertSame(20, $settings->dbLoginTimeoutSeconds);
        self::assertTrue($settings->dbConnectRetryEnabled);
        self::assertSame(2, $settings->dbConnectRetryMaxAttempts);
        self::assertSame(1500, $settings->dbConnectRetryDelayMs);
    }

    public function test_serverless_resume_settings_are_bounded(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'DB_LOGIN_TIMEOUT_SECONDS' => '90',
            'DB_CONNECT_RETRY_MAX_ATTEMPTS' => '9',
            'DB_CONNECT_RETRY_DELAY_MS' => '50',
            'DB_CONNECT_RETRY_ENABLED' => 'false',
        ]);

        self::assertSame(60, $settings->dbLoginTimeoutSeconds);
        self::assertFalse($settings->dbConnectRetryEnabled);
        self::assertSame(2, $settings->dbConnectRetryMaxAttempts);
        self::assertSame(200, $settings->dbConnectRetryDelayMs);

        $low = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'DB_LOGIN_TIMEOUT_SECONDS' => '1',
            'DB_CONNECT_RETRY_MAX_ATTEMPTS' => '1',
            'DB_CONNECT_RETRY_DELAY_MS' => '4000',
        ]);
        self::assertSame(5, $low->dbLoginTimeoutSeconds);
        self::assertSame(1, $low->dbConnectRetryMaxAttempts);
        self::assertSame(4000, $low->dbConnectRetryDelayMs);
    }

    public function test_foundry_is_disabled_by_default(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
        ]);

        self::assertFalse($settings->foundryEnabled);
        self::assertFalse($settings->isFoundryConfigured());
        self::assertSame(Settings::FOUNDRY_AUTH_MODE_MANAGED_IDENTITY, $settings->foundryAuthMode);
        self::assertSame(30, $settings->foundryTimeoutSeconds);
        self::assertSame(5, $settings->foundryMaxToolCalls);
    }

    public function test_enabled_foundry_requires_endpoint_and_deployment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FOUNDRY_ENDPOINT and FOUNDRY_MODEL_DEPLOYMENT are required');

        Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'FOUNDRY_ENABLED' => 'true',
        ]);
    }

    public function test_development_foundry_api_key_configuration_is_allowed(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'FOUNDRY_ENABLED' => 'true',
            'FOUNDRY_ENDPOINT' => 'https://example.services.ai.azure.com/',
            'FOUNDRY_MODEL_DEPLOYMENT' => 'product-assistant',
            'FOUNDRY_AUTH_MODE' => 'api-key',
            'FOUNDRY_API_KEY' => 'development-key',
        ]);

        self::assertTrue($settings->isFoundryConfigured());
        self::assertSame(
            'https://example.services.ai.azure.com',
            $settings->foundryEndpoint,
        );
    }

    public function test_enabled_foundry_rejects_untrusted_endpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS root endpoint on services.ai.azure.com');

        Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'FOUNDRY_ENABLED' => 'true',
            'FOUNDRY_ENDPOINT' => 'http://example.com/foundry',
            'FOUNDRY_MODEL_DEPLOYMENT' => 'product-assistant',
        ]);
    }

    public function test_production_foundry_rejects_api_key_authentication(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FOUNDRY_AUTH_MODE=api-key is not allowed');

        Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'USER_REPOSITORY' => 'azure-sql',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => 'token',
            'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
            'FOUNDRY_ENABLED' => 'true',
            'FOUNDRY_ENDPOINT' => 'https://example.services.ai.azure.com',
            'FOUNDRY_MODEL_DEPLOYMENT' => 'product-assistant',
            'FOUNDRY_AUTH_MODE' => 'api-key',
            'FOUNDRY_API_KEY' => 'development-key',
        ]);
    }
}
