<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Live;

use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteMisconfiguredException;

/**
 * Dedicated write configuration. Never falls back to LEGACY_TC read-only credentials.
 */
final class LegacyQuoteWriteConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly ?string $dsn,
        public readonly ?string $user,
        public readonly ?string $password,
        public readonly ?string $sslMode,
        public readonly ?string $tableSuffix,
        public readonly bool $tableSuffixConfigured,
    ) {
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $enabled = self::bool($env['QUOTES_ORDERS_LEGACY_WRITE_ENABLED'] ?? null, false);
        $suffixConfigured = array_key_exists('QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX', $env);
        $suffixRaw = $suffixConfigured ? (string) ($env['QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX'] ?? '') : null;

        $config = new self(
            enabled: $enabled,
            dsn: self::resolveDsn($env),
            user: self::nonEmpty($env['QUOTES_ORDERS_LEGACY_WRITE_USER'] ?? null),
            password: self::nonEmpty($env['QUOTES_ORDERS_LEGACY_WRITE_PASSWORD'] ?? null),
            sslMode: self::normalizeSslMode($env['QUOTES_ORDERS_LEGACY_WRITE_SSL_MODE'] ?? null),
            tableSuffix: $suffixRaw,
            tableSuffixConfigured: $suffixConfigured,
        );

        if ($enabled) {
            $config->assertExecutable();
        }

        return $config;
    }

    public function assertExecutable(): void
    {
        if (!$this->enabled) {
            return;
        }
        if ($this->dsn === null || $this->user === null || $this->password === null) {
            throw QuoteWriteMisconfiguredException::missingCredentials();
        }
        if (!$this->tableSuffixConfigured) {
            throw QuoteWriteMisconfiguredException::missingTableSuffix();
        }
        $suffix = (string) $this->tableSuffix;
        if ($suffix !== '' && $suffix !== '_dev') {
            throw QuoteWriteMisconfiguredException::invalidTableSuffix($suffix);
        }
    }

    public function isConfigured(): bool
    {
        return $this->dsn !== null && $this->user !== null && $this->password !== null;
    }

    public function resolvedTableSuffix(): string
    {
        return (string) ($this->tableSuffix ?? '_dev');
    }

    public function headerTable(): string
    {
        return 'tc.tqoth' . $this->resolvedTableSuffix();
    }

    public function lineTable(): string
    {
        return 'tc.tqoti' . $this->resolvedTableSuffix();
    }

    public function extendedTable(): string
    {
        return 'tc.tqote' . $this->resolvedTableSuffix();
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'configured' => $this->isConfigured(),
            'sslMode' => $this->sslMode,
            'tableSuffixConfigured' => $this->tableSuffixConfigured,
            'tableSuffix' => $this->tableSuffixConfigured ? $this->tableSuffix : null,
            'headerTable' => $this->enabled && $this->tableSuffixConfigured ? $this->headerTable() : null,
            // Never expose DSN user/password.
        ];
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function resolveDsn(array $env): ?string
    {
        $explicit = self::nonEmpty($env['QUOTES_ORDERS_LEGACY_WRITE_DSN'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $host = self::nonEmpty($env['QUOTES_ORDERS_LEGACY_WRITE_HOST'] ?? null);
        $database = self::nonEmpty($env['QUOTES_ORDERS_LEGACY_WRITE_DATABASE'] ?? null);
        if ($host === null || $database === null) {
            return null;
        }

        $port = self::nonEmpty($env['QUOTES_ORDERS_LEGACY_WRITE_PORT'] ?? null) ?? '3306';

        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database,
        );
    }

    private static function normalizeSslMode(?string $value): ?string
    {
        $mode = self::nonEmpty($value);

        return $mode === null ? null : strtolower($mode);
    }

    private static function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function bool(?string $value, bool $default = false): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
