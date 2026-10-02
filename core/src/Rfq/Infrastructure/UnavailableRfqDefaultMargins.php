<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqDefaultMargins;

/** Where the 4js mirror is not configured: no customer default margins, lines keep their own. */
final class UnavailableRfqDefaultMargins implements RfqDefaultMargins
{
    public function forCustomer(int $customerId, array $styles): array
    {
        return [];
    }
}
