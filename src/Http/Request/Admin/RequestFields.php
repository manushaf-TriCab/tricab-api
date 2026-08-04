<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

/**
 * Small typed accessors for validated JSON request bodies (array<string, mixed>),
 * mirroring QueryParams' conventions for the Admin request DTOs.
 */
final class RequestFields
{
    /**
     * @param array<string, mixed> $body
     */
    public static function requireString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s is required.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function stringOrNull(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }

        $value = $body[$key];
        if (!is_string($value)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must be a string.', $key));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function stringOrDefault(array $body, string $key, string $default): string
    {
        return self::stringOrNull($body, $key) ?? $default;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function intOrNull(array $body, string $key): ?int
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }

        $value = $body[$key];
        if (!is_int($value) && !(is_string($value) && is_numeric($value))) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must be an integer.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requireBool(array $body, string $key): bool
    {
        $value = $body[$key] ?? null;
        if (!is_bool($value)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s is required and must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function boolOrDefault(array $body, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return $default;
        }

        $value = $body[$key];
        if (!is_bool($value)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must be a boolean.', $key));
        }

        return $value;
    }
}
