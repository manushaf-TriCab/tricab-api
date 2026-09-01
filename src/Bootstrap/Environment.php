<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use Dotenv\Dotenv;
use TriCab\Api\Config\Settings;

final class Environment
{
    /**
     * Load configuration values for {@see Settings::fromEnvironment()}.
     *
     * Precedence (later sources win):
     * 1. Process environment via getenv()
     * 2. Scalar $_SERVER values (excluding HTTP_* request headers)
     * 3. Scalar $_ENV values
     *
     * When a readable `.env` file exists, `Dotenv::createImmutable()->safeLoad()`
     * runs first. Immutable loading does not overwrite variables already present
     * in $_ENV or $_SERVER (phpdotenv's default adapters; putenv-only values are
     * not treated as already set). Newly loaded `.env` values are written into
     * $_ENV/$_SERVER and therefore win through those overlays, preserving local
     * development. Production App Service has no `.env`; Application Settings
     * remain visible via getenv()/$_SERVER.
     *
     * Azure App Service Application Settings are injected into the process
     * environment and often appear in getenv()/$_SERVER even when PHP
     * `variables_order` omits `E` (so $_ENV is empty). This merge does not
     * require `variables_order` to contain `E`.
     *
     * @return array<string, string|null>
     */
    public static function load(string $basePath): array
    {
        if (is_readable($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }

        return self::mergeSources(
            self::processEnvironment(),
            self::scalarStringMap($_SERVER),
            self::scalarStringMap($_ENV),
        );
    }

    public static function settings(string $basePath): Settings
    {
        return Settings::fromEnvironment(self::load($basePath));
    }

    /**
     * Read one config key after .env load. Matches {@see load()} overlays:
     * getenv → $_SERVER → $_ENV (later wins). Immutable Dotenv does not putenv,
     * so calculator DI must not rely on getenv alone.
     */
    public static function value(string $key, ?string $default = null): ?string
    {
        $candidates = [
            getenv($key),
            $_SERVER[$key] ?? null,
            $_ENV[$key] ?? null,
        ];

        $resolved = null;
        foreach ($candidates as $candidate) {
            if ($candidate === false || $candidate === null) {
                continue;
            }
            if (is_string($candidate) || is_int($candidate) || is_float($candidate)) {
                $resolved = (string) $candidate;
            }
        }

        return $resolved ?? $default;
    }

    /**
     * @param array<string, string|null> ...$layers
     * @return array<string, string|null>
     */
    private static function mergeSources(array ...$layers): array
    {
        $merged = [];
        foreach ($layers as $layer) {
            foreach ($layer as $key => $value) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @return array<string, string|null>
     */
    private static function processEnvironment(): array
    {
        return self::scalarStringMap(getenv());
    }

    /**
     * @param array<array-key, mixed> $source
     * @return array<string, string|null>
     */
    private static function scalarStringMap(array $source): array
    {
        $out = [];
        foreach ($source as $key => $value) {
            if (!is_string($key) || $key === '' || str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $normalized = self::normalizeScalar($value);
            if ($normalized === false) {
                continue;
            }

            $out[$key] = $normalized;
        }

        return $out;
    }

    /**
     * @return string|null|false false means skip
     */
    private static function normalizeScalar(mixed $value): string|null|false
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return false;
    }
}
