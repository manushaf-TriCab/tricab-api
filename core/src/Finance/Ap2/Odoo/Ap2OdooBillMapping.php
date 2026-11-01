<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * Odoo bill state → AP2 invoice (Phase 9 read-back). Odoo is the source of truth for posting and payment:
 *
 * | Odoo `state` / `payment_state`           | importState     | paid                          | settled          |
 * |------------------------------------------|-----------------|-------------------------------|------------------|
 * | draft                                    | Imported draft  | unchanged                     | no               |
 * | posted, not_paid                         | Posted          | 0                             | no               |
 * | posted, partial                          | Posted          | total − credit − residual     | no               |
 * | posted, in_payment / paid                | Posted          | total − credit − residual     | when 0 owed      |
 * | posted, reversed                         | Posted          | total − credit (and an issue) | yes              |
 * | cancel                                   | Import failed   | unchanged (and an issue)      | no               |
 *
 * The residual is in the bill's currency, as our amounts are; a bill whose currency differs from ours is reported,
 * not converted. A posted bill's stage is Posted, paid or not (settlement is its balance); for a draft or cancelled
 * bill the stage is left to the rules ('').
 */
final class Ap2OdooBillMapping
{
    private const CENT = 0.005;

    public static function map(Ap2OdooTrackedBill $ours, Ap2OdooBillState $odoo): Ap2OdooBillUpdate
    {
        $issues = [];
        $owed = round($ours->total - $ours->credit, 2);
        $paid = $ours->paid;
        $odooRef = $odoo->name !== '' && $odoo->name !== '/' ? $odoo->name : '';

        if ($odoo->currency !== '' && $odoo->currency !== $ours->currency) {
            $issues[] = 'Odoo holds ' . $ours->number . ' in ' . $odoo->currency . ', not ' . $ours->currency . '; the payment state was not read';

            return self::update($ours, $odoo, $ours->importState, $odooRef, $paid, $issues);
        }

        switch ($odoo->state) {
            case 'draft':
                $importState = 'Imported draft';
                break;
            case 'cancel':
                $importState = 'Import failed';
                $issues[] = 'The bill ' . ($odooRef !== '' ? $odooRef . ' ' : '') . 'was cancelled in Odoo';
                break;
            case 'posted':
                $importState = 'Posted';
                if (abs(round($odoo->amountTotal, 2) - round($ours->total, 2)) >= self::CENT) {
                    $issues[] = sprintf('Odoo total %s %.2f differs from %.2f', $odoo->currency, $odoo->amountTotal, $ours->total);
                }
                if ($odoo->paymentState === 'reversed') {
                    $paid = $owed;
                    $issues[] = 'The bill was reversed in Odoo (credit note): nothing is owed';
                } elseif ($odoo->paymentState === 'not_paid') {
                    $paid = 0.0;
                } else {
                    // partial, in_payment, paid: what Odoo still shows owed is what is open here.
                    $paid = max(0.0, min($owed, round($owed - $odoo->amountResidual, 2)));
                }
                if ($odoo->paymentState === 'blocked') {
                    $issues[] = 'Payment of the bill is blocked in Odoo';
                }
                break;
            default:
                $importState = $ours->importState;
                $issues[] = 'Odoo reports an unknown bill state "' . $odoo->state . '"';
        }

        return self::update($ours, $odoo, $importState, $odooRef, $paid, $issues);
    }

    /** @param list<string> $issues */
    private static function update(Ap2OdooTrackedBill $ours, Ap2OdooBillState $odoo, string $importState, string $odooRef, float $paid, array $issues): Ap2OdooBillUpdate
    {
        $paid = round($paid, 2);
        $owed = round($ours->total - $ours->credit, 2);
        $settled = $importState === 'Posted' && $owed - $paid < self::CENT;
        $stage = $importState === 'Posted' ? 'Posted' : '';
        $changed = $importState !== $ours->importState
            || abs($paid - round($ours->paid, 2)) >= self::CENT
            || ($odooRef !== '' && $odooRef !== $ours->odooRef)
            || $issues !== [];

        return new Ap2OdooBillUpdate($ours->invoiceId, $odoo->moveId, $importState, $odooRef, $paid, $stage, $settled, $issues, $changed);
    }
}
