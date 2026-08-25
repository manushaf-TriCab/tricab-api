<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Bootstrap;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Passkey\FakePasskeyCeremony;
use TriCab\Api\Identity\Passkey\WebauthnLibPasskeyCeremony;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
use TriCab\Api\Tests\TestAppFactory;

final class PasskeyCeremonySelectionTest extends TestCase
{
    public function test_memory_development_tests_use_fake_ceremony(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'memory',
            'PASSKEY_FAKE_CEREMONY' => 'true',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
        ]);

        self::assertInstanceOf(FakePasskeyCeremony::class, ContainerFactory::passkeyCeremonyFor($settings));
    }

    public function test_azure_sql_uses_webauthn_lib_even_if_fake_flag_is_set(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'azure-sql',
            'PASSKEY_FAKE_CEREMONY' => 'true',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
        ]);

        self::assertInstanceOf(WebauthnLibPasskeyCeremony::class, ContainerFactory::passkeyCeremonyFor($settings));
    }

    public function test_development_without_fake_flag_uses_webauthn_lib(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'memory',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
        ]);

        self::assertInstanceOf(WebauthnLibPasskeyCeremony::class, ContainerFactory::passkeyCeremonyFor($settings));
    }

    public function test_rp_config_comes_from_settings_not_request_host(): void
    {
        $app = TestAppFactory::create([
            'PASSKEY_RP_ID' => 'localhost',
            'PASSKEY_ALLOWED_ORIGINS' => 'http://localhost:5173',
        ]);
        $container = $app->getContainer();
        self::assertNotNull($container);
        $rp = $container->get(PasskeyRpConfig::class);
        self::assertInstanceOf(PasskeyRpConfig::class, $rp);
        self::assertSame('localhost', $rp->rpId);
        self::assertSame(['http://localhost:5173'], $rp->allowedOrigins);
        self::assertFalse($rp->allowsOrigin('https://tricab-web.azurewebsites.net'));
        self::assertFalse($rp->allowsOrigin('https://evil.tricab.com'));
    }
}
