<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Authentication\EntraTokenValidationNotConfigured;
use TriCab\Api\Identity\Authentication\MicrosoftEntraJwtValidator;

final class MicrosoftEntraJwtValidatorTest extends TestCase
{
    public function test_placeholder_refuses_to_validate_tokens(): void
    {
        $validator = new MicrosoftEntraJwtValidator();

        $this->expectException(EntraTokenValidationNotConfigured::class);
        $this->expectExceptionMessage('Microsoft Entra JWT validation is not configured.');

        $validator->validate('header.payload.signature');
    }

    public function test_settings_require_development_env_for_dev_auth(): void
    {
        $production = Settings::fromEnvironment([
            'APP_ENV' => 'production',
            'DEV_AUTH_ENABLED' => 'true',
        ]);
        $development = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'DEV_AUTH_ENABLED' => 'true',
        ]);

        self::assertFalse($production->isDevelopmentAuthActive());
        self::assertTrue($development->isDevelopmentAuthActive());
    }
}
