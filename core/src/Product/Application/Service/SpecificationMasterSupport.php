<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Shared normalization helpers for specification master-data services.
 */
final class SpecificationMasterSupport
{
    public static function normalizeCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            throw InvalidProductValue::for('Code', $code);
        }
        if (strlen($normalized) > 50) {
            throw InvalidProductValue::for('Code', $code);
        }

        return $normalized;
    }

    public static function requireName(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '') {
            throw InvalidProductValue::for('Name', $name);
        }

        return $normalized;
    }

    public static function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $normalized = trim($description);

        return $normalized === '' ? null : $normalized;
    }
}
