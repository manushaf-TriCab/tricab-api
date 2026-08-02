<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Shared SQL Server value coercion helpers for Azure SQL repositories.
 */
final class AzureSqlValueMapper
{
    public static function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    public static function stringValue(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::stringValue($value);

        return $normalized === '' ? null : $normalized;
    }

    public static function requireDateTime(mixed $value, string $field): DateTimeImmutable
    {
        $parsed = self::nullableDateTime($value);
        if ($parsed === null) {
            throw new RuntimeException(sprintf('Missing or invalid datetime for %s.', $field));
        }

        return $parsed;
    }

    public static function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'));
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $raw, new DateTimeZone('UTC'));
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('UTC'));
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        try {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public static function formatDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function binaryValue(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if ($contents === false) {
                throw new RuntimeException('Unable to read binary column.');
            }

            return $contents;
        }

        if (!is_string($value)) {
            throw new RuntimeException('Expected binary string column.');
        }

        if (strlen($value) === 32) {
            return $value;
        }

        $hex = strtolower(ltrim($value, '0x'));
        if (strlen($hex) === 64 && ctype_xdigit($hex)) {
            $binary = hex2bin($hex);
            if ($binary === false) {
                throw new RuntimeException('Unable to decode binary hex column.');
            }

            return $binary;
        }

        throw new RuntimeException('Unexpected binary column length.');
    }
}
