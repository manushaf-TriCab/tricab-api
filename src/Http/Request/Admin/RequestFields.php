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

    /**
     * @param array<string, mixed> $body
     */
    public static function boolOrNull(array $body, string $key): ?bool
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }

        $value = $body[$key];
        if (!is_bool($value)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * Reads a JSON array of strings (list<string>). Missing/null yields an
     * empty list unless $required is true.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public static function listOfStrings(array $body, string $key, bool $required = false): array
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            if ($required) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s is required.', $key));
            }

            return [];
        }

        $value = $body[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must be an array of strings.', $key));
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must contain only non-empty strings.', $key));
            }
            $result[] = trim($item);
        }

        return $result;
    }

    /**
     * Reads a JSON array of objects (list<array<string, mixed>>). Missing/null
     * yields an empty list unless $required is true.
     *
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    public static function listOfArrays(array $body, string $key, bool $required = false): array
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            if ($required) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s is required.', $key));
            }

            return [];
        }

        $value = $body[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must be an array of objects.', $key));
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s items must be objects.', $key));
            }
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * Reads a JSON object of string => string (e.g. code-rule segment values).
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    public static function stringMap(array $body, string $key): array
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return [];
        }

        $value = $body[$key];
        if (!is_array($value)) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must be an object.', $key));
        }

        $result = [];
        foreach ($value as $mapKey => $mapValue) {
            if (!is_string($mapKey) || !is_string($mapValue)) {
                throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', sprintf('%s must map strings to strings.', $key));
            }
            $result[$mapKey] = $mapValue;
        }

        return $result;
    }

    /**
     * Reads a required array item value as a non-empty string.
     *
     * @param array<string, mixed> $item
     */
    public static function requireItemString(array $item, string $key, string $context): string
    {
        $value = $item[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                sprintf('%s.%s is required.', $context, $key),
            );
        }

        return trim($value);
    }
}
