<?php

declare(strict_types=1);

namespace TriCab\Api\Config;

use InvalidArgumentException;

final readonly class Settings
{
    public const USER_REPOSITORY_MEMORY = 'memory';
    public const USER_REPOSITORY_AZURE_SQL = 'azure-sql';

    public const DB_AUTH_MODE_SQL_LOGIN = 'sql-login';
    public const DB_AUTH_MODE_ENTRA_CLI = 'entra-cli';

    public const GUEST_EMAIL_PROVIDER_POSTMARK = 'postmark';
    public const GUEST_EMAIL_PROVIDER_DEVELOPMENT = 'development';
    public const GUEST_EMAIL_PROVIDER_MICROSOFT_GRAPH = 'microsoft-graph';

    public const DEFAULT_GUEST_JWT_TTL_SECONDS = 86400;
    public const MAX_GUEST_JWT_TTL_SECONDS = 86400;
    public const MIN_GUEST_JWT_SECRET_BYTES = 32;

    /**
     * @param list<string> $corsAllowedOrigins
     */
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
        public string $entraRequiredScope,
        public string $entraSubjectClaim,
        public array $corsAllowedOrigins,
        public string $userRepository,
        public string $dbAuthMode,
        public string $dbDriver,
        public string $dbHost,
        public int $dbPort,
        public string $dbDatabase,
        public string $dbUsername,
        public string $dbPassword,
        public bool $dbEncrypt,
        public bool $dbTrustServerCertificate,
        public string $guestEmailProvider,
        public bool $guestOtpDevReveal,
        public string $postmarkServerToken,
        public string $postmarkFromEmail,
        public string $postmarkFromName,
        public string $postmarkMessageStream,
        public string $graphTenantId,
        public string $graphClientId,
        public string $graphClientSecret,
        public string $rfqMailbox,
        public string $guestOtpEmailLogoUrl,
        public string $guestJwtIssuer,
        public string $guestJwtAudience,
        public string $guestJwtSecret,
        public int $guestJwtTtlSeconds,
        public int $guestJwtClockSkewSeconds,
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

    public function usesEntraCliAuth(): bool
    {
        return $this->dbAuthMode === self::DB_AUTH_MODE_ENTRA_CLI;
    }

    public function usesSqlLoginAuth(): bool
    {
        return $this->dbAuthMode === self::DB_AUTH_MODE_SQL_LOGIN;
    }

    public function isEntraJwtConfigured(): bool
    {
        return $this->entraTenantId !== '' && $this->entraAudience !== '';
    }

    public function usesPostmarkGuestEmail(): bool
    {
        return $this->guestEmailProvider === self::GUEST_EMAIL_PROVIDER_POSTMARK;
    }

    public function usesDevelopmentGuestEmail(): bool
    {
        return $this->guestEmailProvider === self::GUEST_EMAIL_PROVIDER_DEVELOPMENT;
    }

    public function usesMicrosoftGraphGuestEmail(): bool
    {
        return $this->guestEmailProvider === self::GUEST_EMAIL_PROVIDER_MICROSOFT_GRAPH;
    }

    public function isGuestJwtConfigured(): bool
    {
        return $this->guestJwtIssuer !== ''
            && $this->guestJwtAudience !== ''
            && strlen($this->guestJwtSecret) >= self::MIN_GUEST_JWT_SECRET_BYTES;
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $appEnv = self::string($env, 'APP_ENV', 'production');

        // Default differs by environment: production must not silently use in-memory.
        $defaultUserRepository = $appEnv === 'production'
            ? self::USER_REPOSITORY_AZURE_SQL
            : self::USER_REPOSITORY_MEMORY;
        $userRepositoryRaw = self::string($env, 'USER_REPOSITORY', $defaultUserRepository);
        $userRepository = strtolower($userRepositoryRaw);
        if (
            $userRepository !== self::USER_REPOSITORY_MEMORY
            && $userRepository !== self::USER_REPOSITORY_AZURE_SQL
        ) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported USER_REPOSITORY "%s". Supported values: %s, %s.',
                $userRepositoryRaw,
                self::USER_REPOSITORY_MEMORY,
                self::USER_REPOSITORY_AZURE_SQL,
            ));
        }

        if ($appEnv === 'production' && $userRepository === self::USER_REPOSITORY_MEMORY) {
            $allowMemoryInProduction = strtolower(
                self::string($env, 'ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION', 'false'),
            ) === 'true';
            if (!$allowMemoryInProduction) {
                throw new InvalidArgumentException(
                    'USER_REPOSITORY=memory is not allowed when APP_ENV=production.',
                );
            }
        }

        $dbAuthMode = strtolower(self::string($env, 'DB_AUTH_MODE', self::DB_AUTH_MODE_SQL_LOGIN));
        if (
            $dbAuthMode !== self::DB_AUTH_MODE_SQL_LOGIN
            && $dbAuthMode !== self::DB_AUTH_MODE_ENTRA_CLI
        ) {
            $dbAuthMode = self::DB_AUTH_MODE_SQL_LOGIN;
        }

        $subjectClaim = strtolower(self::string($env, 'ENTRA_SUBJECT_CLAIM', 'oid'));
        if ($subjectClaim !== 'oid' && $subjectClaim !== 'sub') {
            $subjectClaim = 'oid';
        }

        $guestEmailProvider = strtolower(
            self::string(
                $env,
                'GUEST_EMAIL_PROVIDER',
                $appEnv === 'production'
                    ? self::GUEST_EMAIL_PROVIDER_POSTMARK
                    : self::GUEST_EMAIL_PROVIDER_DEVELOPMENT,
            ),
        );
        if (
            $guestEmailProvider !== self::GUEST_EMAIL_PROVIDER_POSTMARK
            && $guestEmailProvider !== self::GUEST_EMAIL_PROVIDER_DEVELOPMENT
            && $guestEmailProvider !== self::GUEST_EMAIL_PROVIDER_MICROSOFT_GRAPH
        ) {
            $guestEmailProvider = $appEnv === 'production'
                ? self::GUEST_EMAIL_PROVIDER_POSTMARK
                : self::GUEST_EMAIL_PROVIDER_DEVELOPMENT;
        }

        $guestOtpDevReveal = self::bool($env, 'GUEST_OTP_DEV_REVEAL', false);
        $postmarkServerToken = self::string($env, 'POSTMARK_SERVER_TOKEN', '');
        $postmarkFromEmail = self::string($env, 'POSTMARK_FROM_EMAIL', '');
        $postmarkFromName = self::string($env, 'POSTMARK_FROM_NAME', 'TriCab');
        $postmarkMessageStream = self::string($env, 'POSTMARK_MESSAGE_STREAM', 'outbound');

        $graphTenantId = self::string($env, 'GRAPH_TENANT_ID', '');
        $graphClientId = self::string($env, 'GRAPH_CLIENT_ID', '');
        $graphClientSecret = self::string($env, 'GRAPH_CLIENT_SECRET', '');
        $rfqMailbox = self::string($env, 'RFQ_MAILBOX', '');
        $guestOtpEmailLogoUrl = self::string($env, 'GUEST_OTP_EMAIL_LOGO_URL', '');

        $guestJwtIssuer = self::string($env, 'GUEST_JWT_ISSUER', 'https://api.tricab.local');
        $guestJwtAudience = self::string($env, 'GUEST_JWT_AUDIENCE', 'tricab-api');
        $guestJwtSecret = self::string($env, 'GUEST_JWT_SECRET', '');
        $guestJwtTtlSeconds = self::int($env, 'GUEST_JWT_TTL_SECONDS', self::DEFAULT_GUEST_JWT_TTL_SECONDS);
        $guestJwtClockSkewSeconds = self::int($env, 'GUEST_JWT_CLOCK_SKEW_SECONDS', 30);

        if ($guestJwtTtlSeconds < 1 || $guestJwtTtlSeconds > self::MAX_GUEST_JWT_TTL_SECONDS) {
            throw new InvalidArgumentException(
                'GUEST_JWT_TTL_SECONDS must be between 1 and ' . self::MAX_GUEST_JWT_TTL_SECONDS . '.',
            );
        }

        if ($guestJwtClockSkewSeconds < 0 || $guestJwtClockSkewSeconds > 300) {
            throw new InvalidArgumentException('GUEST_JWT_CLOCK_SKEW_SECONDS must be between 0 and 300.');
        }

        if ($appEnv === 'production' && $guestEmailProvider === self::GUEST_EMAIL_PROVIDER_DEVELOPMENT) {
            throw new InvalidArgumentException(
                'GUEST_EMAIL_PROVIDER=development is not allowed when APP_ENV=production.',
            );
        }

        if ($appEnv === 'production' && $guestOtpDevReveal) {
            throw new InvalidArgumentException(
                'GUEST_OTP_DEV_REVEAL cannot be enabled when APP_ENV=production.',
            );
        }

        if ($guestJwtSecret !== '' && strlen($guestJwtSecret) < self::MIN_GUEST_JWT_SECRET_BYTES) {
            throw new InvalidArgumentException(
                'GUEST_JWT_SECRET must be at least 32 bytes when provided.',
            );
        }

        return new self(
            appEnv: $appEnv,
            appName: self::string($env, 'APP_NAME', 'tricab-api'),
            appDebug: self::bool($env, 'APP_DEBUG', false),
            devAuthEnabled: self::bool($env, 'DEV_AUTH_ENABLED', false),
            devAuthDefaultTenant: self::string($env, 'DEV_AUTH_DEFAULT_TENANT', 'development-tenant'),
            devAuthDefaultSubject: self::string($env, 'DEV_AUTH_DEFAULT_SUBJECT', 'development-thilan'),
            devAuthDefaultEmail: self::string($env, 'DEV_AUTH_DEFAULT_EMAIL', 'thilanh@tricab.com'),
            entraTenantId: self::string($env, 'ENTRA_TENANT_ID', ''),
            entraClientId: self::string($env, 'ENTRA_CLIENT_ID', ''),
            entraAudience: self::string($env, 'ENTRA_AUDIENCE', ''),
            entraRequiredScope: self::string($env, 'ENTRA_REQUIRED_SCOPE', 'access_as_user'),
            entraSubjectClaim: $subjectClaim,
            corsAllowedOrigins: self::csvList($env, 'CORS_ALLOWED_ORIGINS', 'http://localhost:5173'),
            userRepository: $userRepository,
            dbAuthMode: $dbAuthMode,
            dbDriver: self::string($env, 'DB_DRIVER', 'sqlsrv'),
            dbHost: self::string($env, 'DB_HOST', ''),
            dbPort: self::int($env, 'DB_PORT', 1433),
            dbDatabase: self::string($env, 'DB_DATABASE', ''),
            dbUsername: self::string($env, 'DB_USERNAME', ''),
            dbPassword: self::string($env, 'DB_PASSWORD', ''),
            dbEncrypt: self::bool($env, 'DB_ENCRYPT', true),
            dbTrustServerCertificate: self::bool($env, 'DB_TRUST_SERVER_CERTIFICATE', false),
            guestEmailProvider: $guestEmailProvider,
            guestOtpDevReveal: $guestOtpDevReveal,
            postmarkServerToken: $postmarkServerToken,
            postmarkFromEmail: $postmarkFromEmail,
            postmarkFromName: $postmarkFromName,
            postmarkMessageStream: $postmarkMessageStream,
            graphTenantId: $graphTenantId,
            graphClientId: $graphClientId,
            graphClientSecret: $graphClientSecret,
            rfqMailbox: $rfqMailbox,
            guestOtpEmailLogoUrl: $guestOtpEmailLogoUrl,
            guestJwtIssuer: $guestJwtIssuer,
            guestJwtAudience: $guestJwtAudience,
            guestJwtSecret: $guestJwtSecret,
            guestJwtTtlSeconds: $guestJwtTtlSeconds,
            guestJwtClockSkewSeconds: $guestJwtClockSkewSeconds,
        );
    }

    /**
     * @param array<string, string|null> $env
     * @return list<string>
     */
    private static function csvList(array $env, string $key, string $default): array
    {
        $raw = self::string($env, $key, $default);
        $parts = array_map('trim', explode(',', $raw));
        $filtered = array_values(array_filter($parts, static fn (string $value): bool => $value !== ''));

        return $filtered;
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
