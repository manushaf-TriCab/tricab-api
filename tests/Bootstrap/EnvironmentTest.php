<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Bootstrap;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Config\Settings;

final class EnvironmentTest extends TestCase
{
    private const UNSET = "\0unset";

    /** @var array<string, array{env: mixed, server: mixed, process: string|false}> */
    private array $originals = [];

    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach (array_keys($this->originals) as $key) {
            $this->restoreKey($key);
        }
        $this->originals = [];

        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                rmdir($path);
            }
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    public function test_values_in_env_superglobal_are_returned(): void
    {
        $key = 'TEST_TRI_ENV';
        $this->clearKey($key);
        $_ENV[$key] = 'env-value';

        $loaded = Environment::load($this->emptyBasePath());

        self::assertSame('env-value', $loaded[$key]);
    }

    public function test_values_only_in_server_superglobal_are_returned(): void
    {
        $key = 'TEST_TRI_ENV_SERVER_ONLY';
        $this->clearKey($key);
        $_SERVER[$key] = 'server-value';

        $loaded = Environment::load($this->emptyBasePath());

        self::assertSame('server-value', $loaded[$key]);
        self::assertArrayNotHasKey($key, $_ENV);
    }

    public function test_values_only_in_process_environment_are_returned(): void
    {
        $key = 'TEST_TRI_ENV_PROCESS_ONLY';
        $this->clearKey($key);
        putenv($key . '=from-process');
        unset($_ENV[$key], $_SERVER[$key]);

        $loaded = Environment::load($this->emptyBasePath());

        self::assertSame('from-process', $loaded[$key]);
        self::assertArrayNotHasKey($key, $_ENV);
        self::assertArrayNotHasKey($key, $_SERVER);
    }

    public function test_env_wins_over_server_and_process(): void
    {
        $key = 'TEST_TRI_ENV_PRECEDENCE';
        $this->clearKey($key);
        putenv($key . '=from-process');
        $_SERVER[$key] = 'from-server';
        $_ENV[$key] = 'from-env';

        $loaded = Environment::load($this->emptyBasePath());

        self::assertSame('from-env', $loaded[$key]);
    }

    public function test_server_wins_over_process_when_env_is_absent(): void
    {
        $key = 'TEST_TRI_ENV_PRECEDENCE_SERVER';
        $this->clearKey($key);
        putenv($key . '=from-process');
        $_SERVER[$key] = 'from-server';
        unset($_ENV[$key]);

        $loaded = Environment::load($this->emptyBasePath());

        self::assertSame('from-server', $loaded[$key]);
    }

    public function test_missing_dotenv_file_does_not_fail(): void
    {
        $dir = $this->emptyBasePath();
        self::assertFileDoesNotExist($dir . '/.env');

        $loaded = Environment::load($dir);

        self::assertArrayNotHasKey('TEST_TRI_ENV_DOTENV_ABSENT', $loaded);
    }

    public function test_readable_dotenv_file_is_loaded_when_not_already_set(): void
    {
        $key = 'TEST_TRI_ENV_DOTENV';
        $this->clearKey($key);

        $dir = $this->emptyBasePath();
        $envFile = $dir . '/.env';
        file_put_contents($envFile, $key . "=from-dotenv\n");
        $this->tempPaths[] = $envFile;

        $loaded = Environment::load($dir);

        self::assertSame('from-dotenv', $loaded[$key]);
    }

    public function test_immutable_dotenv_does_not_override_existing_env_value(): void
    {
        $key = 'TEST_TRI_ENV_DOTENV_IMMUTABLE';
        $this->clearKey($key);
        $_ENV[$key] = 'from-env';

        $dir = $this->emptyBasePath();
        $envFile = $dir . '/.env';
        file_put_contents($envFile, $key . "=from-dotenv\n");
        $this->tempPaths[] = $envFile;

        $loaded = Environment::load($dir);

        self::assertSame('from-env', $loaded[$key]);
    }

    public function test_immutable_dotenv_does_not_override_existing_server_value(): void
    {
        $key = 'TEST_TRI_ENV_DOTENV_IMMUTABLE_SERVER';
        $this->clearKey($key);
        $_SERVER[$key] = 'from-server';
        unset($_ENV[$key]);

        $dir = $this->emptyBasePath();
        $envFile = $dir . '/.env';
        file_put_contents($envFile, $key . "=from-dotenv\n");
        $this->tempPaths[] = $envFile;

        $loaded = Environment::load($dir);

        self::assertSame('from-server', $loaded[$key]);
    }

    public function test_request_headers_are_not_copied_from_server(): void
    {
        $key = 'HTTP_X_TEST_TRI_ENV';
        $this->remember($key);
        $_SERVER[$key] = 'should-not-appear';

        $loaded = Environment::load($this->emptyBasePath());

        self::assertArrayNotHasKey($key, $loaded);
    }

    public function test_production_keys_resolve_from_process_environment(): void
    {
        $values = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DEV_AUTH_ENABLED' => 'false',
            'USER_REPOSITORY' => 'azure-sql',
            'DB_DRIVER' => 'sqlsrv',
            'DB_AUTH_MODE' => 'sql-login',
            'DB_HOST' => 'test-only.example.invalid',
            'DB_PORT' => '1433',
            'DB_DATABASE' => 'test-only-db',
            'DB_ENCRYPT' => 'yes',
            'DB_TRUST_SERVER_CERTIFICATE' => 'false',
            'ENTRA_TENANT_ID' => '00000000-0000-0000-0000-000000000000',
            'ENTRA_CLIENT_ID' => '00000000-0000-0000-0000-000000000001',
            'ENTRA_AUDIENCE' => 'api://test-only',
            'ENTRA_REQUIRED_SCOPE' => 'access_as_user',
            'CORS_ALLOWED_ORIGINS' => 'https://test-only.example.invalid',
            'GUEST_JWT_SECRET' => 'test-only-guest-jwt-secret-32bytes!',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'BLOB_AUTH_MODE' => 'disabled',
        ];

        foreach (array_keys($values) as $key) {
            $this->clearKey($key);
        }
        foreach ($values as $key => $value) {
            putenv($key . '=' . $value);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $loaded = Environment::load($this->emptyBasePath());

        $fromProcess = [];
        foreach ($values as $key => $value) {
            self::assertSame($value, $loaded[$key] ?? null, $key . ' did not resolve from process environment');
            $fromProcess[$key] = $loaded[$key];
        }

        $settings = Settings::fromEnvironment($fromProcess);
        self::assertSame('production', $settings->appEnv);
        self::assertFalse($settings->appDebug);
        self::assertFalse($settings->devAuthEnabled);
        self::assertSame(Settings::USER_REPOSITORY_AZURE_SQL, $settings->userRepository);
        self::assertSame('sqlsrv', $settings->dbDriver);
        self::assertSame(Settings::DB_AUTH_MODE_SQL_LOGIN, $settings->dbAuthMode);
        self::assertSame('test-only.example.invalid', $settings->dbHost);
        self::assertSame(1433, $settings->dbPort);
        self::assertSame('test-only-db', $settings->dbDatabase);
        self::assertTrue($settings->dbEncrypt);
        self::assertFalse($settings->dbTrustServerCertificate);
        self::assertSame('00000000-0000-0000-0000-000000000000', $settings->entraTenantId);
        self::assertSame('00000000-0000-0000-0000-000000000001', $settings->entraClientId);
        self::assertSame('api://test-only', $settings->entraAudience);
        self::assertSame('access_as_user', $settings->entraRequiredScope);
        self::assertSame(['https://test-only.example.invalid'], $settings->corsAllowedOrigins);
        self::assertSame(Settings::GUEST_EMAIL_PROVIDER_POSTMARK, $settings->guestEmailProvider);
        self::assertSame(Settings::BLOB_AUTH_MODE_DISABLED, $settings->blobAuthMode);
    }

    private function emptyBasePath(): string
    {
        $dir = sys_get_temp_dir() . '/tricab-env-test-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $this->tempPaths[] = $dir;

        return $dir;
    }

    private function clearKey(string $key): void
    {
        $this->remember($key);
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function remember(string $key): void
    {
        if (isset($this->originals[$key])) {
            return;
        }

        $this->originals[$key] = [
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : self::UNSET,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : self::UNSET,
            'process' => getenv($key),
        ];
    }

    private function restoreKey(string $key): void
    {
        $original = $this->originals[$key];

        if ($original['env'] === self::UNSET) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $original['env'];
        }

        if ($original['server'] === self::UNSET) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original['server'];
        }

        if ($original['process'] === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $original['process']);
        }
    }
}
