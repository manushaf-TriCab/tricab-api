<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalRef;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;
use TriCab\Core\Finance\Module;

/** The prototype's seed scenarios (`Tri-AP-AWS/site/data.js`), as domain objects. Today is 2026-10-26. */
final class Ap2Fixtures
{
    public const TODAY = '2026-10-26';

    public static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-26 01:00:00 UTC');
    }

    public static function supplier(string $id = 'S001', string $name = 'Cable Materials Pty Ltd', bool $verified = true, bool $change = false, bool $hold = false): Ap2Supplier
    {
        return new Ap2Supplier(md5($id), $name, self::at(), self::at(), entity: 'Tricab Australia', bankVerified: $verified, bankChange: $change, hold: $hold);
    }

    /** S007 CleanWorks: bank details changed, on hold, not verified. */
    public static function cleanWorks(): Ap2Supplier
    {
        return self::supplier('S007', 'CleanWorks', false, true, true);
    }

    /** A clean, verified, fully coded No-PO invoice of AUD 1,100 (the prototype's B010 shape). */
    public static function invoice(Ap2Supplier $s, string $id = 'B010', string $number = 'OH-001', mixed ...$over): Ap2Invoice
    {
        return (new Ap2Invoice(md5($id), $s->id, self::at(), self::at()))->with(...array_merge([
            'number' => $number,
            'entity' => 'Tricab Australia',
            'net' => 1000.0,
            'tax' => 100.0,
            'total' => 1100.0,
            'issued' => '2026-10-01',
            'due' => '2026-10-31',
            'kind' => 'No PO',
            'noPoReason' => 'Approved office supplies purchase',
            'serviceAccepted' => true,
            'verified' => true,
            'gl' => '6000',
            'taxCode' => 'GST10',
            'costCentre' => 'Operations',
            'sourceFingerprint' => 'sample-' . $id,
        ], $over));
    }

    /** CM-2200: 1000 units invoiced at 20.00, 900 received. */
    public static function cm2200(Ap2Supplier $s, mixed ...$over): Ap2Invoice
    {
        return self::invoice($s, 'B001', 'CM-2200', ...array_merge([
            'net' => 20000.0, 'tax' => 2000.0, 'total' => 22000.0, 'kind' => 'Goods PO', 'po' => 'PO-1001', 'quantity' => 1000.0,
            'orderedQty' => 1000.0, 'receivedQty' => 900.0, 'poUnitPrice' => 20.0, 'receiptRef' => 'GRN-1001', 'noPoReason' => '',
            'serviceAccepted' => false,
        ], $over));
    }

    /** CM-2200 with credit note CN-2200 (2000 + 200) for the 100-unit shortfall. */
    public static function cm2200WithCredit(Ap2Supplier $s, mixed ...$over): Ap2Invoice
    {
        return self::cm2200($s, ...array_merge(['credit' => 2200.0, 'creditRef' => 'CN-2200', 'creditNet' => 2000.0, 'creditTax' => 200.0, 'creditResolved' => true], $over));
    }

    /**
     * What the rules say, with an optional approval and register.
     *
     * @param list<Ap2Invoice> $others
     */
    public static function assess(Ap2Invoice $b, ?Ap2Supplier $s, array $others = [], ?Ap2ApprovalRef $approval = null, bool $payment = false): \TriCab\Core\Finance\Ap2\Domain\Ap2Assessment
    {
        return Ap2InvoiceRules::assess($b, $s, $others, $approval, $payment, self::TODAY);
    }

    /** An approved invoice approval bound to the invoice as it is now. */
    public static function approved(Ap2Invoice $b, ?Ap2Supplier $s): Ap2ApprovalRef
    {
        return new Ap2ApprovalRef('a1', 'Invoice', 'Approved', Ap2InvoiceRules::fingerprint($b, $s));
    }

    /** @param list<string> $permissions */
    public static function actor(array $permissions = []): Ap2Actor
    {
        return new Ap2Actor(7, 'Alex Chen', 'alex.chen@tricab.com', $permissions === [] ? [Module::PERMISSION_AP2_ACCESS, ...Module::AP2_PERMISSIONS] : $permissions);
    }
}
