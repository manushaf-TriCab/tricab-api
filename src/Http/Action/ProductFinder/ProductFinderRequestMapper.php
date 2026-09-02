<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\ProductFinder;

use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;

/**
 * HTTP body to `ProductFinderQuery`.
 *
 * Absent and blank are both "not stated" — the web form sends `""` for an
 * untouched field, and a zero-length string coerced to 0 would silently become
 * a stated cable type 0 ("Switchboard"). Follows
 * `CableCalculatorRequestMapper`, which reads the same kind of body.
 */
final class ProductFinderRequestMapper
{
    /**
     * @param array<array-key, mixed> $body
     */
    public static function fromHttpBody(array $body): ProductFinderQuery
    {
        return new ProductFinderQuery(
            text: self::string($body, 'text'),
            cableType: self::int($body, 'cableType'),
            style: self::string($body, 'style'),
            coreCount: self::int($body, 'coreCount'),
            crossSectionMm2: self::float($body, 'crossSection'),
            loadAmps: self::float($body, 'loadAmps'),
            lengthM: self::float($body, 'lengthM'),
            voltage: self::int($body, 'voltage'),
            phase: self::int($body, 'phase'),
            install: self::int($body, 'install'),
            ambient: self::int($body, 'ambient'),
            strategy: self::string($body, 'strategy'),
            region: self::string($body, 'region'),
            allowAi: self::bool($body, 'allowAi', true),
        );
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function string(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function int(array $body, string $key): ?int
    {
        $value = $body[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function float(array $body, string $key): ?float
    {
        $value = $body[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function bool(array $body, string $key, bool $default): bool
    {
        $value = $body[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalised = strtolower(trim($value));
            if ($normalised !== '') {
                return in_array($normalised, ['1', 'true', 'yes', 'on'], true);
            }
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return $default;
    }
}
