<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Tri-AP2's invoice rules, ported from the prototype (`Tri-AP-AWS/site/app.js`: `validation`, `matching`,
 * `duplicates`, `fingerprint`, `invoiceApproved`, `invoiceQueue`, `csvEligible`, `paymentIssues`) with its messages
 * kept word for word. Pure functions: no store, no clock (callers pass today's date).
 *
 * Deliberate differences from the prototype, each one a bug it would otherwise have:
 *  - currency: AUD, USD, NZD and SGD are accepted, not AUD only;
 *  - duplicates: two invoices with an empty source fingerprint, or a number with no letters or digits, are not
 *    duplicates of each other (the prototype compared '' to '');
 *  - the fingerprint is a SHA-256 of the same 27 values, so it fits a column and an approval can store it.
 */
final class Ap2InvoiceRules
{
    private const TOLERANCE = 0.01;

    /**
     * All the computed fields of one invoice.
     *
     * @param iterable<Ap2Invoice> $others the other invoices to compare for duplicates (the whole register, or at least this supplier's)
     * @param string               $today  `yyyy-MM-dd`, the Sydney calendar day
     */
    public static function assess(
        Ap2Invoice $b,
        ?Ap2Supplier $supplier,
        iterable $others,
        ?Ap2ApprovalRef $approval,
        bool $activePayment,
        string $today,
    ): Ap2Assessment {
        $duplicates = self::duplicates($b, $others);
        $validation = self::validation($b, $supplier, $duplicates);
        $current = self::approvalCurrent($b, $supplier, $approval);
        $exportIssues = self::exportIssues($b, $validation, $current);

        return new Ap2Assessment(
            $validation,
            self::matching($b),
            array_map(static fn (Ap2Invoice $x): string => $x->id, $duplicates),
            self::queue($b, $validation, $duplicates, $current),
            self::fingerprint($b, $supplier),
            $current,
            $exportIssues === [],
            $exportIssues,
            self::paymentIssues($b, $supplier, $validation, $duplicates, $current, $activePayment),
            $b->balance(),
            $b->isPosted(),
            self::daysOverdue($b, $today),
        );
    }

    /** The prototype's `normal()`: lower case, letters and digits only. */
    public static function normaliseNumber(string $number): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($number)) ?? '';
    }

    /**
     * Other live invoices of the same supplier and entity that carry the same normalised number or the same source
     * document. An excluded invoice is never a duplicate of anything (it was retired for that reason).
     *
     * @param iterable<Ap2Invoice> $others
     * @return list<Ap2Invoice>
     */
    public static function duplicates(Ap2Invoice $b, iterable $others): array
    {
        $number = self::normaliseNumber($b->number);
        $found = [];
        foreach ($others as $x) {
            if ($x->id === $b->id || $x->excluded || $x->supplierId !== $b->supplierId || $x->entity !== $b->entity) {
                continue;
            }
            $sameNumber = $number !== '' && self::normaliseNumber($x->number) === $number;
            $sameSource = $b->sourceFingerprint !== '' && $x->sourceFingerprint === $b->sourceFingerprint;
            if ($sameNumber || $sameSource) {
                $found[] = $x;
            }
        }

        return $found;
    }

    /**
     * Matching to PO, receipt and service evidence: 'Matched' or the one reason it is not.
     *
     * Goods PO is a three-way match (ordered, price, received). When less arrived than was invoiced, the shortfall
     * must be covered by a credit (`creditResolved`) whose net equals the shortfall at the PO unit price: CM-2200
     * invoices 1000, receives 900, and is matched by credit note CN-2200 for 100 x 20.00 = 2,000 net.
     */
    public static function matching(Ap2Invoice $b): string
    {
        if ($b->kind === 'Goods PO') {
            if ($b->po === '' || $b->receiptRef === '') {
                return 'Missing PO / receipt evidence';
            }
            if ($b->quantity > $b->orderedQty) {
                return 'Invoiced quantity exceeds PO';
            }
            if (abs($b->net - $b->quantity * $b->poUnitPrice) > self::TOLERANCE) {
                return 'PO price / net amount variance';
            }
            if ($b->receivedQty < $b->quantity
                && (!$b->creditResolved || abs(($b->quantity - $b->receivedQty) * $b->poUnitPrice - $b->creditNet) > self::TOLERANCE)) {
                return 'Quantity shortfall / credit mismatch';
            }

            return 'Matched';
        }
        if ($b->kind === 'Service PO') {
            return $b->po !== '' && $b->serviceAccepted && $b->receiptRef !== '' ? 'Matched' : 'Service acceptance required';
        }
        if ($b->kind === 'No PO') {
            return trim($b->noPoReason) !== '' && $b->serviceAccepted ? 'Matched' : 'No-PO reason / business acceptance required';
        }

        return $b->receiptRef !== '' && $b->serviceAccepted ? 'Matched' : 'Recurring contract / period check required';
    }

    /**
     * The prototype's `validation()`: every reason the invoice cannot go forward, empty when it can.
     *
     * @param list<Ap2Invoice> $duplicates the result of {@see self::duplicates()}
     * @return list<string>
     */
    public static function validation(Ap2Invoice $b, ?Ap2Supplier $supplier, array $duplicates): array
    {
        $errors = [];
        if ($b->excluded) {
            $errors[] = 'Excluded duplicate';
        }
        if ($duplicates !== []) {
            $errors[] = 'Suspected duplicate';
        }
        if ($supplier === null || $b->entity === '' || $b->number === '' || ($b->issued ?? '') === '' || ($b->due ?? '') === '') {
            $errors[] = 'Missing required identity / dates';
        }
        if (!in_array($b->currency, Ap2Invoice::CURRENCIES, true)) {
            $errors[] = 'Review unsupported currency';
        }
        if ($b->total <= 0 || $b->net < 0 || $b->tax < 0 || abs($b->net + $b->tax - $b->total) > self::TOLERANCE) {
            $errors[] = 'Net + tax differs from total';
        }
        if ($b->credit < 0 || $b->credit > $b->total || $b->paid < 0 || $b->paid > $b->total - $b->credit) {
            $errors[] = 'Invalid credit / paid allocation';
        }
        if ($b->credit > 0 && $b->creditRef === '') {
            $errors[] = 'Missing credit note reference';
        }
        if ($b->credit > 0 && abs($b->creditNet + $b->creditTax - $b->credit) > self::TOLERANCE) {
            $errors[] = 'Credit net + tax must equal gross credit';
        }
        if (($b->due ?? '') < ($b->issued ?? '') || $b->quantity <= 0 || $b->receivedQty < 0) {
            $errors[] = 'Check dates / quantities';
        }
        if (!$b->verified) {
            $errors[] = 'Human field verification required';
        }
        if ($b->gl === '' || $b->taxCode === '' || $b->costCentre === '') {
            $errors[] = 'Accounting coding incomplete';
        }
        $matching = self::matching($b);
        if ($matching !== 'Matched') {
            $errors[] = $matching;
        }

        return $errors;
    }

    /**
     * What an approval is bound to: the 27 values the prototype hashed (25 invoice fields and the supplier's two bank
     * flags). Change any of them and an approval made on the old values no longer matches. Not in it: `paymentHold`,
     * the owner, the stage, and the Odoo-sourced `orderedQty` / `poUnitPrice`.
     */
    public static function fingerprint(Ap2Invoice $b, ?Ap2Supplier $supplier): string
    {
        $values = [
            $b->supplierId, $b->entity, $b->number,
            self::cents($b->net), self::cents($b->tax), self::cents($b->total), $b->currency,
            $b->po, $b->kind, self::qty($b->quantity), self::qty($b->receivedQty), $b->receiptRef,
            $b->serviceAccepted, $b->noPoReason, $b->gl, $b->taxCode, $b->costCentre,
            self::cents($b->credit), $b->creditRef, self::cents($b->creditNet), self::cents($b->creditTax),
            $b->verified, $b->creditResolved, $b->issued, $b->due,
            $supplier !== null && $supplier->bankVerified, $supplier !== null && $supplier->bankChange,
        ];

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The prototype's `invoiceApproved()`: an Approved invoice approval whose snapshot is still the invoice's fingerprint. */
    public static function approvalCurrent(Ap2Invoice $b, ?Ap2Supplier $supplier, ?Ap2ApprovalRef $approval): bool
    {
        return $approval !== null
            && $approval->kind === 'Invoice'
            && $approval->status === 'Approved'
            && $approval->snapshot !== null
            && hash_equals($approval->snapshot, self::fingerprint($b, $supplier));
    }

    /**
     * The prototype's `invoiceQueue()`.
     *
     * @param list<string>      $validation
     * @param list<Ap2Invoice>  $duplicates
     */
    public static function queue(Ap2Invoice $b, array $validation, array $duplicates, bool $approvalCurrent): string
    {
        if ($b->excluded) {
            return 'Excluded';
        }
        if ($duplicates !== []) {
            return 'Duplicate';
        }
        if ($validation !== []) {
            return 'Needs review';
        }
        if (!$approvalCurrent) {
            return 'Approval';
        }
        if (!$b->isPosted()) {
            return 'Ready to import';
        }

        return $b->balance() > 0 ? 'Open AP' : 'Settled';
    }

    /**
     * The stored stage a save leaves the invoice in. The prototype set it on every review save (Needs verification, or
     * Pending approval when clean); Tri-AP2 derives it so it cannot be dragged around by hand.
     *
     * @param list<string> $validation
     */
    public static function stage(Ap2Invoice $b, array $validation, bool $approvalCurrent): string
    {
        if ($b->excluded) {
            return 'Excluded';
        }
        if ($b->isPosted()) {
            if ($b->balance() <= 0) {
                return 'Settled';
            }

            return $b->paid > 0 ? 'Partially settled' : 'Posted';
        }
        if ($validation !== []) {
            $matching = self::matching($b);

            return $matching !== 'Matched' && $validation === [$matching] ? 'Awaiting matching' : 'Needs verification';
        }

        return $approvalCurrent ? 'Approved' : 'Pending approval';
    }

    /**
     * The prototype's `csvEligible()` as reasons: valid, currently approved, not posted, not already exported or
     * imported as a draft. Empty means the invoice may go in an Odoo CSV.
     *
     * @param list<string> $validation
     * @return list<string>
     */
    public static function exportIssues(Ap2Invoice $b, array $validation, bool $approvalCurrent): array
    {
        $issues = [];
        if ($validation !== []) {
            $issues[] = 'Unresolved invoice checks: ' . implode('; ', $validation);
        }
        if (!$approvalCurrent) {
            $issues[] = 'Invoice approval required / stale';
        }
        if ($b->isPosted()) {
            $issues[] = 'Already posted in Odoo';
        }
        if (in_array($b->importState, ['Exported CSV', 'Imported draft'], true)) {
            $issues[] = 'Already exported to CSV / imported as a draft';
        }

        return $issues;
    }

    /**
     * The prototype's `paymentIssues()`.
     *
     * @param list<string>     $validation
     * @param list<Ap2Invoice> $duplicates
     * @return list<string>
     */
    public static function paymentIssues(
        Ap2Invoice $b,
        ?Ap2Supplier $supplier,
        array $validation,
        array $duplicates,
        bool $approvalCurrent,
        bool $activePayment,
    ): array {
        $issues = [];
        if ($validation !== []) {
            $issues[] = 'Unresolved invoice checks: ' . implode('; ', $validation);
        }
        if (!$b->isPosted()) {
            $issues[] = 'Not posted in Odoo';
        }
        if (!$approvalCurrent) {
            $issues[] = 'Invoice approval required / stale';
        }
        if (($supplier !== null && $supplier->hold) || $b->paymentHold) {
            $issues[] = 'Payment hold';
        }
        if ($supplier === null || !$supplier->bankVerified || $supplier->bankChange) {
            $issues[] = 'Bank independently verified? No';
        }
        if ($b->excluded || $duplicates !== []) {
            $issues[] = 'Duplicate / excluded';
        }
        if ($b->balance() <= 0) {
            $issues[] = 'No open balance';
        }
        if ($activePayment) {
            $issues[] = 'Already in an active payment plan';
        }

        return $issues;
    }

    /** Whole days past due (never negative): the prototype's `days()`. */
    public static function daysOverdue(Ap2Invoice $b, string $today): int
    {
        if ($b->due === null || $b->due === '') {
            return 0;
        }
        $utc = new DateTimeZone('UTC');
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $b->due, $utc);
        $now = DateTimeImmutable::createFromFormat('!Y-m-d', $today, $utc);
        if ($due === false || $now === false) {
            return 0;
        }

        return max(0, (int) $due->diff($now)->format('%r%a'));
    }

    private static function cents(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
