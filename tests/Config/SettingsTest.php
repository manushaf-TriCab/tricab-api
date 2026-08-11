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
}
