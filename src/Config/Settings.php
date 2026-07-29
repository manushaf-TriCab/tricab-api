<?php

declare(strict_types=1);

namespace TriCab\Api\Config;

final readonly class Settings
{
    public const USER_REPOSITORY_MEMORY = 'memory';
    public const USER_REPOSITORY_AZURE_SQL = 'azure-sql';

    public function __construct(
        public string $appEnv,
        public string $appName,
        public bool $appDebug,
        public bool $devAuthEnabled,
        public string $devAuthDefaultTenant,
        public string $devAuthDefaultSubject,
        public string $devAuthDefaultEmail,
        public string $entraTenantId,
        public string $entraClientId,
        public string $entraAudience,
        public string $userRepository,
        public string $dbDriver,
        public string $dbHost,
        public int $dbPort,
        public string $dbDatabase,
        public string $dbUsername,
        public string $dbPassword,
        public bool $dbEncrypt,
        public bool $dbTrustServerCertificate,
    ) {
    }

    public function isDevelopment(): bool
    {
        return $this->appEnv === 'development';
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }

    public function isDevelopmentAuthActive(): bool
    {
        return $this->isDevelopment() && $this->devAuthEnabled;
    }

    public function usesAzureSqlUserRepository(): bool
    {
        return $this->userRepository === self::USER_REPOSITORY_AZURE_SQL;
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $userRepository = strtolower(self::string($env, 'USER_REPOSITORY', self::USER_REPOSITORY_MEMORY));
        if (
            $userRepository !== self::USER_REPOSITORY_MEMORY
            && $userRepository !== self::USER_REPOSITORY_AZURE_SQL
        ) {
            $userRepository = self::USER_REPOSITORY_MEMORY;
        }

        return new self(
            appEnv: self::string($env, 'APP_ENV', 'production'),
            appName: self::string($env, 'APP_NAME', 'tricab-api'),
            appDebug: self::bool($env, 'APP_DEBUG', false),
            devAuthEnabled: self::bool($env, 'DEV_AUTH_ENABLED', false),
            devAuthDefaultTenant: self::string($env, 'DEV_AUTH_DEFAULT_TENANT', 'development-tenant'),
            devAuthDefaultSubject: self::string($env, 'DEV_AUTH_DEFAULT_SUBJECT', 'development-thilan'),
            devAuthDefaultEmail: self::string($env, 'DEV_AUTH_DEFAULT_EMAIL', 'thilanh@tricab.com'),
            entraTenantId: self::string($env, 'ENTRA_TENANT_ID', ''),
            entraClientId: self::string($env, 'ENTRA_CLIENT_ID', ''),
            entraAudience: self::string($env, 'ENTRA_AUDIENCE', ''),
            userRepository: $userRepository,
            dbDriver: self::string($env, 'DB_DRIVER', 'sqlsrv'),
            dbHost: self::string($env, 'DB_HOST', ''),
            dbPort: self::int($env, 'DB_PORT', 1433),
            dbDatabase: self::string($env, 'DB_DATABASE', ''),
            dbUsername: self::string($env, 'DB_USERNAME', ''),
            dbPassword: self::string($env, 'DB_PASSWORD', ''),
            dbEncrypt: self::bool($env, 'DB_ENCRYPT', true),
            dbTrustServerCertificate: self::bool($env, 'DB_TRUST_SERVER_CERTIFICATE', false),
        );
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function string(array $env, string $key, string $default): string
    {
        $value = $env[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function bool(array $env, string $key, bool $default): bool
    {
        $value = $env[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function int(array $env, string $key, int $default): int
    {
        $value = $env[$key] ?? null;

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}
