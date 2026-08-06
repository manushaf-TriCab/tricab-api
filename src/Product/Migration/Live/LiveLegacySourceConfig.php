<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

/**
 * Read-only live source configuration. Never logs password fields.
 *
 * Accepts either an explicit PDO DSN (`LEGACY_WP_DSN` / `LEGACY_TC_DSN`) or
 * host parts (`LEGACY_WP_HOST` + `LEGACY_WP_DATABASE`, same for tc).
 */
final class LiveLegacySourceConfig
{
    public function __construct(
        public readonly ?string $wordpressDsn,
        public readonly ?string $wordpressUser,
        public readonly ?string $wordpressPassword,
        public readonly string $wordpressTablePrefix,
        public readonly ?string $wordpressSslMode,
        public readonly bool $waiveWordpress,
        public readonly ?string $tcDsn,
        public readonly ?string $tcUser,
        public readonly ?string $tcPassword,
        public readonly ?string $tcSslMode,
        public readonly bool $waiveTc,
        public readonly ?string $filesRoot,
        public readonly bool $waiveFiles,
        public readonly bool $phase12PilotApproved = true,
    ) {
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env): self
    {
        return new self(
            wordpressDsn: self::resolveDsn($env, 'LEGACY_WP'),
            wordpressUser: self::nonEmpty($env['LEGACY_WP_USER'] ?? null),
            wordpressPassword: self::nonEmpty($env['LEGACY_WP_PASSWORD'] ?? null),
            wordpressTablePrefix: self::nonEmpty($env['LEGACY_WP_TABLE_PREFIX'] ?? null) ?? 'wp_',
            wordpressSslMode: self::normalizeSslMode($env['LEGACY_WP_SSL_MODE'] ?? null),
            waiveWordpress: self::bool($env['LEGACY_SOURCE_WAIVE_WP'] ?? null),
            tcDsn: self::resolveDsn($env, 'LEGACY_TC'),
            tcUser: self::nonEmpty($env['LEGACY_TC_USER'] ?? null),
            tcPassword: self::nonEmpty($env['LEGACY_TC_PASSWORD'] ?? null),
            tcSslMode: self::normalizeSslMode($env['LEGACY_TC_SSL_MODE'] ?? null),
            waiveTc: self::bool($env['LEGACY_SOURCE_WAIVE_TC'] ?? null),
            filesRoot: self::nonEmpty($env['LEGACY_FILES_ROOT'] ?? null),
            waiveFiles: self::bool($env['LEGACY_SOURCE_WAIVE_FILES'] ?? null),
            phase12PilotApproved: self::bool($env['PHASE12_PILOT_APPROVED'] ?? 'true', true),
        );
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function resolveDsn(array $env, string $prefix): ?string
    {
        $explicit = self::nonEmpty($env[$prefix . '_DSN'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $host = self::nonEmpty($env[$prefix . '_HOST'] ?? null);
        $database = self::nonEmpty($env[$prefix . '_DATABASE'] ?? null);
        if ($host === null || $database === null) {
            return null;
        }

        $port = self::nonEmpty($env[$prefix . '_PORT'] ?? null) ?? '3306';

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
        if ($mode === null) {
            return null;
        }

        return strtolower($mode);
    }

    public function wordpressConfigured(): bool
    {
        return $this->wordpressDsn !== null && $this->wordpressUser !== null && $this->wordpressPassword !== null;
    }

    public function tcConfigured(): bool
    {
        return $this->tcDsn !== null && $this->tcUser !== null && $this->tcPassword !== null;
    }

    public function filesConfigured(): bool
    {
        return $this->filesRoot !== null && is_dir($this->filesRoot) && is_readable($this->filesRoot);
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'wordpressConfigured' => $this->wordpressConfigured(),
            'wordpressTablePrefix' => $this->wordpressTablePrefix,
            'wordpressSslMode' => $this->wordpressSslMode,
            'waiveWordpress' => $this->waiveWordpress,
            'tcConfigured' => $this->tcConfigured(),
            'tcSslMode' => $this->tcSslMode,
            'waiveTc' => $this->waiveTc,
            'filesConfigured' => $this->filesConfigured(),
            'filesRootSet' => $this->filesRoot !== null,
            'waiveFiles' => $this->waiveFiles,
            'phase12PilotApproved' => $this->phase12PilotApproved,
        ];
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
