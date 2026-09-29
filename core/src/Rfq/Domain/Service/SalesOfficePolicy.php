<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

/**
 * The 4js sales office a customer's territory implies: the source's
 * `sales_office_for_territory` (`matcher/db.py`), table for table. Anything
 * the table does not name, including no territory at all, is Melbourne, as
 * in the source.
 */
final class SalesOfficePolicy
{
    public const BY_TERRITORY = [
        'WA' => 'PER',
        'SA' => 'ADE',
        'NZSTH' => 'CHR',
        'TAS' => 'HOB',
        'NZNTH' => 'AUC',
        'NZ' => 'AUC',
        'VIC' => 'MEL',
        'NSW' => 'SYD',
        'QLD' => 'BRI',
    ];

    public const DEFAULT = 'MEL';

    public static function forTerritory(?string $territory): string
    {
        return self::BY_TERRITORY[strtoupper(trim((string) $territory))] ?? self::DEFAULT;
    }
}
