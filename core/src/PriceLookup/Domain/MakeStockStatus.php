<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

/**
 * Legacy MAKE/STOCK from tstkwh_p.st (wh=7):
 * null or 2301 → MAKE; else STOCK.
 */
final class MakeStockStatus
{
    public const MAKE = 'MAKE';
    public const STOCK = 'STOCK';

    public function fromWarehouseStatus(mixed $st): string
    {
        if ($st === null) {
            return self::MAKE;
        }
        if (is_string($st) && ($st === '' || $st === '2301')) {
            return self::MAKE;
        }
        if (is_int($st) && $st === 2301) {
            return self::MAKE;
        }

        return self::STOCK;
    }
}
