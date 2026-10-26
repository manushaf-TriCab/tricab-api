<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Api\Finance\Ap\AzureSqlApSupport;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/** What the Tri-AP2 SQL stores share, on top of Tri-AP's: money, calendar days and people. */
abstract class AzureSqlAp2Support extends AzureSqlApSupport
{
    protected static function amount(mixed $value): float
    {
        return is_scalar($value) ? (float) $value : 0.0;
    }

    protected static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    protected static function quantity(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    protected static function day(mixed $value): ?string
    {
        $text = self::textOrNull($value);

        return $text === null || $text === '' ? null : substr($text, 0, 10);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected static function person(array $row, string $prefix): ?Ap2Person
    {
        $userId = AzureSqlValueMapper::nullableInt($row[$prefix . '_user_id'] ?? null);

        return $userId === null ? null : new Ap2Person($userId, self::text($row[$prefix . '_name'] ?? ''), self::text($row[$prefix . '_email'] ?? ''));
    }
}
