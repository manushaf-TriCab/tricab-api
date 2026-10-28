<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\Credit;

use TriCab\Core\CustomerContactReport\Domain\Credit\CustomerContactReportDecimal as Dec;
use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

/**
 * Server-owned credit arithmetic. The browser may preview these figures; it
 * never supplies them. Rules are the live ones (js/cc.js, js/cc-orders.js):
 *
 *   Returned goods  value ex GST = sum of the ticked lines' credit net, or the
 *                   typed value when no line is ticked.
 *                   restocking   = 30% of the value when the fee applies.
 *                   sub total    = value - restocking.
 *                   GST          = each line's credit net x its GST rate (the
 *                   invoice's, else 15% for NZD or Christchurch, else 10%).
 *                   GST is taken on the value before restocking, as live does.
 *   Overcharged     value ex GST = the typed amount, which must exceed zero.
 *                   No restocking. GST = amount x rate unless add GST is No.
 *
 * Credit total = sub total + GST.
 */
final class CustomerContactReportCreditCalculator
{
    public const RESTOCKING_RATE = '0.30';

    public const RATE_AU = '0.10';

    public const RATE_NZ = '0.15';

    /**
     * @param list<CustomerContactReportLine> $lines
     */
    public static function calculate(
        string $creditType,
        array $lines,
        ?string $typedValueExGst,
        bool $restockingFeeApplies,
        bool $addGst,
        string $location,
        string $currency = '',
    ): CustomerContactReportCreditFigures {
        $typed = $typedValueExGst === null || trim($typedValueExGst) === ''
            ? null
            : self::amount($typedValueExGst, 'valueExGst');
        $defaultRate = (strtoupper($currency) === 'NZD' || $location === 'C') ? self::RATE_NZ : self::RATE_AU;

        if ($creditType === CustomerContactReportCreditType::OVERCHARGED) {
            if ($typed === null || Dec::cmp($typed, '0', 2) <= 0) {
                throw InvalidCustomerContactReportValue::for('valueExGst', 'must be greater than zero for an overcharge credit.');
            }
            $rate = self::linesRate($lines) ?? $defaultRate;
            $gst = $addGst ? Dec::money(Dec::mul($typed, $rate, 8)) : '0.00';

            return self::figures($typed, '0.00', $gst);
        }

        $hasLines = $lines !== [];
        $value = '0.00';
        $gstRaw = '0';
        if ($hasLines) {
            foreach ($lines as $line) {
                $value = Dec::add($value, $line->creditNet, 2);
                $gstRaw = Dec::add($gstRaw, Dec::mul($line->creditNet, $line->gstRate ?? $defaultRate, 8), 8);
            }
        } elseif ($typed !== null) {
            $value = $typed;
            $gstRaw = Dec::mul($typed, $defaultRate, 8);
        }
        if (Dec::cmp($value, '0', 2) <= 0) {
            throw InvalidCustomerContactReportValue::for('valueExGst', 'must be greater than zero for a returned goods credit.');
        }
        $restocking = $restockingFeeApplies ? Dec::money(Dec::mul($value, self::RESTOCKING_RATE, 8)) : '0.00';

        return self::figures($value, $restocking, Dec::money($gstRaw));
    }

    public static function amount(string $raw, string $field): string
    {
        $value = Dec::normalise($raw);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
            throw InvalidCustomerContactReportValue::for($field, 'must be a non-negative amount with at most two decimal places.');
        }
        if (strlen(explode('.', $value)[0]) > 16) {
            throw InvalidCustomerContactReportValue::for($field, 'exceeds DECIMAL(18,2).');
        }

        return Dec::add($value, '0', 2);
    }

    /**
     * The invoice-implied GST rate, rounded to two places, when lines carry one.
     *
     * @param list<CustomerContactReportLine> $lines
     */
    private static function linesRate(array $lines): ?string
    {
        foreach ($lines as $line) {
            if ($line->gstRate !== null && Dec::cmp($line->gstRate, '0', 4) > 0) {
                return $line->gstRate;
            }
        }

        return null;
    }

    private static function figures(string $value, string $restocking, string $gst): CustomerContactReportCreditFigures
    {
        $sub = Dec::sub($value, $restocking, 2);

        return new CustomerContactReportCreditFigures(
            valueExGst: Dec::add($value, '0', 2),
            restockingAmount: $restocking,
            subTotal: $sub,
            gst: $gst,
            creditTotal: Dec::add($sub, $gst, 2),
        );
    }
}
