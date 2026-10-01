<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

/**
 * Legacy credit routing. The comparison is the GST-inclusive credit total,
 * and the hop happens only when that total is greater than 1000.
 * 1000.00 stays with the raiser's manager. 1000.01 climbs one more level.
 *
 * This is the only climb. If that person is the requester, the request is
 * rejected. There is no further hop, and TriExpense does not supply one:
 * it forbids self-approval instead of walking the reporting line again.
 */
final class CustomerContactCreditEscalation
{
    public const THRESHOLD = '1000.00';

    public static function climbsOneLevel(CustomerContactReportMoney $creditTotal): bool
    {
        return $creditTotal->greaterThan(CustomerContactReportMoney::fromDecimal(self::THRESHOLD, 'creditTotal'));
    }
}
