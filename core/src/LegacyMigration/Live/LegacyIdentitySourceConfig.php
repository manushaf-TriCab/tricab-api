<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

/**
 * Identity-migration source flags. Reuses Product Migration DSN resolution
 * without changing Product Migration behaviour.
 */
final class LegacyIdentitySourceConfig
{
    public function __construct(
        public readonly LiveLegacySourceConfig $legacy,
        public readonly bool $wordpressReadOnly,
        public readonly bool $tcReadOnly,
        public readonly string $expectedWordpressDatabase,
        public readonly string $expectedTcDatabase,
        public readonly LegacyMigrationDevelopmentOverride $developmentOverride = new LegacyMigrationDevelopmentOverride('production', false),
    ) {
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $legacy = LiveLegacySourceConfig::fromEnvironment($env);

        return new self(
            legacy: $legacy,
            wordpressReadOnly: self::bool($env['LEGACY_WP_READ_ONLY'] ?? null),
            tcReadOnly: self::bool($env['LEGACY_TC_READ_ONLY'] ?? null),
            expectedWordpressDatabase: self::databaseName($env, 'LEGACY_WP', $legacy->wordpressDsn, 'tricab'),
            expectedTcDatabase: self::databaseName($env, 'LEGACY_TC', $legacy->tcDsn, 'tc'),
            developmentOverride: LegacyMigrationDevelopmentOverride::fromEnvironment($env),
        );
    }

    public function liveExtractionConfigured(): bool
    {
        return $this->legacy->wordpressConfigured() && $this->legacy->tcConfigured();
    }

    public function readOnlyFlagsPresent(): bool
    {
        return $this->wordpressReadOnly && $this->tcReadOnly;
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'wordpressConfigured' => $this->legacy->wordpressConfigured(),
            'tcConfigured' => $this->legacy->tcConfigured(),
            'wordpressReadOnlyConfigured' => $this->wordpressReadOnly,
            'tcReadOnlyConfigured' => $this->tcReadOnly,
            'wordpressSslMode' => $this->legacy->wordpressSslMode,
            'tcSslMode' => $this->legacy->tcSslMode,
            'wordpressTablePrefix' => $this->legacy->wordpressTablePrefix,
            'expectedWordpressDatabase' => $this->expectedWordpressDatabase,
            'expectedTcDatabase' => $this->expectedTcDatabase,
            'liveExtractionConfigured' => $this->liveExtractionConfigured(),
            'developmentOverride' => $this->developmentOverride->toPublicArray(),
        ];
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function databaseName(array $env, string $prefix, ?string $dsn, string $fallback): string
    {
        $explicit = self::nonEmpty($env[$prefix . '_DATABASE'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        if ($dsn !== null && preg_match('/dbname=([^;]+)/i', $dsn, $matches) === 1) {
            return $matches[1];
        }

        return $fallback;
    }

    private static function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function bool(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
