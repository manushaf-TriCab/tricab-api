<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

use DateTimeImmutable;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

/**
 * Legacy CC number: YYMM-###L-ERR.
 *
 * YYMM comes from the report date, not from the assignment clock.
 * The sequence is yearly. Padding is at least three digits and does not
 * truncate 1000, matching str_pad($sequence, 3, '0', STR_PAD_LEFT).
 */
final class CustomerContactReportNumber
{
    public static function format(
        DateTimeImmutable $reportDate,
        int $sequence,
        string $location,
        string $errorCode,
    ): string {
        if ($sequence < 1) {
            throw InvalidCustomerContactReportValue::for('ccNumber', 'sequence must be positive.');
        }

        $number = $reportDate->format('ym')
            . '-'
            . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT)
            . $location
            . '-'
            . $errorCode;

        if (strlen($number) > 20) {
            throw InvalidCustomerContactReportValue::for('ccNumber', 'is too long.');
        }

        return $number;
    }
}
