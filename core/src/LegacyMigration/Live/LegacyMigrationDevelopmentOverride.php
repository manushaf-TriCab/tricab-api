<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

use InvalidArgumentException;

/**
 * Temporary development-only policy for write-capable legacy MySQL credentials.
 * Must never activate outside APP_ENV=development.
 */
final class LegacyMigrationDevelopmentOverride
{
    public const SAFETY_SELECT_ONLY = 'SELECT_ONLY';
    public const SAFETY_DEVELOPMENT_OVERRIDE = 'DEVELOPMENT_OVERRIDE';
    public const SAFETY_BLOCKED = 'BLOCKED';

    public function __construct(
        public readonly string $appEnv,
        public readonly bool $overrideRequested,
    ) {
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $appEnv = strtolower(trim((string) ($env['APP_ENV'] ?? 'production')));
        $overrideRequested = self::bool($env['LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER'] ?? null);
        if ($overrideRequested && $appEnv !== 'development') {
            throw new InvalidArgumentException(
                'Legacy migration write-capable reader override is permitted only in development.',
            );
        }

        return new self($appEnv, $overrideRequested);
    }

    public function isGloballyEnabled(): bool
    {
        return $this->appEnv === 'development' && $this->overrideRequested;
    }

    public function allowsWriteCapableReader(bool $readOnlyConfigured): bool
    {
        return $this->isGloballyEnabled() && $readOnlyConfigured;
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'appEnv' => $this->appEnv,
            'overrideRequested' => $this->overrideRequested,
            'developmentOverrideActive' => $this->isGloballyEnabled(),
        ];
    }

    private static function bool(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
