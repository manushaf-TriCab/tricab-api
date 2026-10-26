<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalRef;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;

/** The ported prototype rules, one by one, on the prototype's own seed scenarios. */
final class Ap2InvoiceRulesTest extends TestCase
{
    // ---- matching ----------------------------------------------------------------------------------------------

    public function test_cm_2200_short_receipt_without_a_credit_does_not_match(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::cm2200($s);

        self::assertSame('Quantity shortfall / credit mismatch', Ap2InvoiceRules::matching($b));
        self::assertContains('Quantity shortfall / credit mismatch', Ap2Fixtures::assess($b, $s)->validation);
    }

    public function test_cm_2200_matches_once_credit_note_cn_2200_covers_the_100_unit_shortfall(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::cm2200WithCredit($s);

        self::assertSame('Matched', Ap2InvoiceRules::matching($b));
        self::assertSame([], Ap2Fixtures::assess($b, $s)->validation);
        self::assertSame(19800.0, $b->balance(), 'the credit reduces what is owed');
    }

    public function test_the_credit_must_equal_the_shortfall_at_the_po_price_and_be_marked_resolved(): void
    {
        $s = Ap2Fixtures::supplier();
        // 100 short x 20.00 = 2,000; a credit net of 1,900 does not cover it.
        self::assertSame('Quantity shortfall / credit mismatch', Ap2InvoiceRules::matching(Ap2Fixtures::cm2200WithCredit($s, creditNet: 1900.0, creditTax: 190.0, credit: 2090.0)));
        // Right amount, but nobody has confirmed the credit resolves the variance.
        self::assertSame('Quantity shortfall / credit mismatch', Ap2InvoiceRules::matching(Ap2Fixtures::cm2200WithCredit($s, creditResolved: false)));
        // Full receipt needs no credit.
        self::assertSame('Matched', Ap2InvoiceRules::matching(Ap2Fixtures::cm2200($s, receivedQty: 1000.0)));
    }

    public function test_goods_po_other_failures_in_the_prototypes_order(): void
    {
        $s = Ap2Fixtures::supplier();
        self::assertSame('Missing PO / receipt evidence', Ap2InvoiceRules::matching(Ap2Fixtures::cm2200($s, po: '')));
        self::assertSame('Missing PO / receipt evidence', Ap2InvoiceRules::matching(Ap2Fixtures::cm2200($s, receiptRef: '')));
        self::assertSame('Invoiced quantity exceeds PO', Ap2InvoiceRules::matching(Ap2Fixtures::cm2200($s, orderedQty: 999.0)));
        self::assertSame('PO price / net amount variance', Ap2InvoiceRules::matching(Ap2Fixtures::cm2200($s, poUnitPrice: 20.5, receivedQty: 1000.0)));
    }

    public function test_service_po_no_po_and_recurring(): void
    {
        $s = Ap2Fixtures::supplier();
        $service = Ap2Fixtures::invoice($s, kind: 'Service PO', po: 'PO-1002', receiptRef: 'ACC-1', serviceAccepted: true);
        self::assertSame('Matched', Ap2InvoiceRules::matching($service));
        self::assertSame('Service acceptance required', Ap2InvoiceRules::matching($service->with(serviceAccepted: false)));
        self::assertSame('Service acceptance required', Ap2InvoiceRules::matching($service->with(po: '')));
        self::assertSame('Service acceptance required', Ap2InvoiceRules::matching($service->with(receiptRef: '')));

        $noPo = Ap2Fixtures::invoice($s);
        self::assertSame('Matched', Ap2InvoiceRules::matching($noPo));
        self::assertSame('No-PO reason / business acceptance required', Ap2InvoiceRules::matching($noPo->with(noPoReason: '   ')));
        self::assertSame('No-PO reason / business acceptance required', Ap2InvoiceRules::matching($noPo->with(serviceAccepted: false)));

        $recurring = Ap2Fixtures::invoice($s, kind: 'Recurring', receiptRef: 'PERIOD-OCT', serviceAccepted: true);
        self::assertSame('Matched', Ap2InvoiceRules::matching($recurring));
        self::assertSame('Recurring contract / period check required', Ap2InvoiceRules::matching($recurring->with(receiptRef: '')));
    }

    // ---- validation --------------------------------------------------------------------------------------------

    public function test_a_clean_invoice_has_no_issues(): void
    {
        $s = Ap2Fixtures::supplier();

        self::assertSame([], Ap2Fixtures::assess(Ap2Fixtures::invoice($s), $s)->validation);
    }

    public function test_all_four_currencies_are_accepted_and_others_are_flagged(): void
    {
        $s = Ap2Fixtures::supplier();
        foreach (['AUD', 'USD', 'NZD', 'SGD'] as $currency) {
            self::assertNotContains('Review unsupported currency', Ap2Fixtures::assess(Ap2Fixtures::invoice($s, currency: $currency), $s)->validation, $currency);
        }
        self::assertContains('Review unsupported currency', Ap2Fixtures::assess(Ap2Fixtures::invoice($s, currency: 'EUR'), $s)->validation);
    }

    public function test_each_prototype_message(): void
    {
        $s = Ap2Fixtures::supplier();
        $base = Ap2Fixtures::invoice($s);
        $cases = [
            'Missing required identity / dates' => [$base->with(number: ''), $s],
            'Net + tax differs from total' => [$base->with(total: 1099.0), $s],
            'Invalid credit / paid allocation' => [$base->with(credit: 2000.0, creditRef: 'CN', creditNet: 1818.18, creditTax: 181.82), $s],
            'Missing credit note reference' => [$base->with(credit: 100.0, creditNet: 90.91, creditTax: 9.09), $s],
            'Credit net + tax must equal gross credit' => [$base->with(credit: 100.0, creditRef: 'CN-1', creditNet: 50.0, creditTax: 10.0), $s],
            'Check dates / quantities' => [$base->with(due: '2026-09-01'), $s],
            'Human field verification required' => [$base->with(verified: false), $s],
            'Accounting coding incomplete' => [$base->with(taxCode: ''), $s],
        ];
        foreach ($cases as $message => [$invoice, $supplier]) {
            self::assertContains($message, Ap2Fixtures::assess($invoice, $supplier)->validation, $message);
        }
        self::assertContains('Missing required identity / dates', Ap2Fixtures::assess($base, null)->validation, 'no supplier');
        self::assertContains('Check dates / quantities', Ap2Fixtures::assess($base->with(quantity: 0.0), $s)->validation);
        self::assertContains('Invalid credit / paid allocation', Ap2Fixtures::assess($base->with(paid: 1200.0), $s)->validation);
        self::assertContains('Net + tax differs from total', Ap2Fixtures::assess($base->with(net: 0.0, tax: 0.0, total: 0.0), $s)->validation);
    }

    public function test_a_one_cent_rounding_difference_is_tolerated(): void
    {
        $s = Ap2Fixtures::supplier();

        self::assertNotContains('Net + tax differs from total', Ap2Fixtures::assess(Ap2Fixtures::invoice($s, total: 1100.01), $s)->validation);
    }

    // ---- duplicates --------------------------------------------------------------------------------------------

    public function test_the_b003_b005_pair_are_duplicates_of_each_other_until_one_is_excluded(): void
    {
        $s = Ap2Fixtures::supplier('S003', 'Office Hub');
        $b003 = Ap2Fixtures::invoice($s, 'B003', 'OH-004');
        $b005 = Ap2Fixtures::invoice($s, 'B005', 'oh 004'); // same number once normalised
        $register = [$b003, $b005];

        $a003 = Ap2Fixtures::assess($b003, $s, $register);
        $a005 = Ap2Fixtures::assess($b005, $s, $register);
        self::assertSame([$b005->id], $a003->duplicateOf);
        self::assertSame([$b003->id], $a005->duplicateOf);
        self::assertContains('Suspected duplicate', $a003->validation);
        self::assertSame('Duplicate', $a003->queue);

        $excluded = $b005->with(excluded: true);
        $after = [$b003, $excluded];
        self::assertSame([], Ap2Fixtures::assess($b003, $s, $after)->duplicateOf, 'the survivor is clear');
        self::assertSame([], Ap2Fixtures::assess($b003, $s, $after)->validation);
        $gone = Ap2Fixtures::assess($excluded, $s, $after);
        self::assertContains('Excluded duplicate', $gone->validation);
        self::assertSame('Excluded', $gone->queue);
    }

    public function test_same_source_document_is_a_duplicate_even_with_another_number(): void
    {
        $s = Ap2Fixtures::supplier();
        $a = Ap2Fixtures::invoice($s, 'B001', 'X-1', sourceFingerprint: 'doc-9');
        $b = Ap2Fixtures::invoice($s, 'B002', 'Y-2', sourceFingerprint: 'doc-9');

        self::assertSame([$b->id], Ap2Fixtures::assess($a, $s, [$a, $b])->duplicateOf);
    }

    public function test_other_suppliers_and_entities_are_not_duplicates_and_blanks_never_match(): void
    {
        $s = Ap2Fixtures::supplier();
        $other = Ap2Fixtures::supplier('S002', 'Metro Freight');
        $a = Ap2Fixtures::invoice($s, 'B001', 'A-1');

        self::assertSame([], Ap2InvoiceRules::duplicates($a, [Ap2Fixtures::invoice($other, 'B002', 'A-1')]));
        self::assertSame([], Ap2InvoiceRules::duplicates($a, [Ap2Fixtures::invoice($s, 'B003', 'A-1', entity: 'Tricab NZ')]));
        // Two invoices with no number and no source document are not "the same".
        $blank = Ap2Fixtures::invoice($s, 'B004', '', sourceFingerprint: '');
        self::assertSame([], Ap2InvoiceRules::duplicates($blank, [Ap2Fixtures::invoice($s, 'B005', '!!', sourceFingerprint: '')]));
    }

    // ---- fingerprint and approval currency ---------------------------------------------------------------------

    public function test_the_fingerprint_changes_with_each_critical_field_and_the_banks_two_flags(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::cm2200WithCredit($s);
        $base = Ap2InvoiceRules::fingerprint($b, $s);
        $changes = [
            'supplierId' => 'x', 'entity' => 'Other', 'number' => 'N2', 'net' => 1.0, 'tax' => 1.0, 'total' => 1.0, 'currency' => 'USD',
            'po' => 'PO-2', 'kind' => 'Service PO', 'quantity' => 5.0, 'receivedQty' => 1.0, 'receiptRef' => 'R2', 'serviceAccepted' => true,
            'noPoReason' => 'why', 'gl' => '7000', 'taxCode' => 'FRE', 'costCentre' => 'Sales', 'credit' => 1.0, 'creditRef' => 'CN-9',
            'creditNet' => 1.0, 'creditTax' => 1.0, 'verified' => false, 'creditResolved' => false, 'issued' => '2026-09-01', 'due' => '2026-12-01',
        ];
        self::assertCount(25, $changes, '25 invoice fields plus the supplier bank state make the 27');
        foreach ($changes as $field => $value) {
            self::assertNotSame($base, Ap2InvoiceRules::fingerprint($b->with(...[$field => $value]), $s), $field);
        }
        self::assertNotSame($base, Ap2InvoiceRules::fingerprint($b, $s->with(bankVerified: false)), 'bankVerified');
        self::assertNotSame($base, Ap2InvoiceRules::fingerprint($b, $s->with(bankChange: true)), 'bankChange');
    }

    public function test_the_fingerprint_ignores_what_an_approval_does_not_cover(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::cm2200WithCredit($s);
        $base = Ap2InvoiceRules::fingerprint($b, $s);

        self::assertSame($base, Ap2InvoiceRules::fingerprint($b->with(paymentHold: true, stage: 'Approved', orderedQty: 5.0, poUnitPrice: 9.0, sourceDoc: 'z.pdf'), $s->with(hold: true)));
    }

    public function test_an_approval_is_current_only_while_the_invoice_still_hashes_to_its_snapshot(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::invoice($s);
        $approval = Ap2Fixtures::approved($b, $s);

        self::assertTrue(Ap2InvoiceRules::approvalCurrent($b, $s, $approval));
        self::assertFalse(Ap2InvoiceRules::approvalCurrent($b->with(gl: '7000'), $s, $approval), 'edited after approval');
        self::assertFalse(Ap2InvoiceRules::approvalCurrent($b, $s, null));
        self::assertFalse(Ap2InvoiceRules::approvalCurrent($b, $s, new Ap2ApprovalRef('a', 'Email', 'Approved', $approval->snapshot)));
        foreach (['Pending', 'Rejected', 'Superseded', 'Expired'] as $status) {
            self::assertFalse(Ap2InvoiceRules::approvalCurrent($b, $s, new Ap2ApprovalRef('a', 'Invoice', $status, $approval->snapshot)), $status);
        }
        self::assertFalse(Ap2InvoiceRules::approvalCurrent($b, $s, new Ap2ApprovalRef('a', 'Invoice', 'Approved', null)));
    }

    public function test_s007_a_supplier_bank_change_makes_an_existing_approval_stale(): void
    {
        $verified = Ap2Fixtures::supplier('S007', 'CleanWorks', true, false, false);
        $b = Ap2Fixtures::invoice($verified, 'B008', 'CW-3002', kind: 'Recurring', receiptRef: 'ACC', noPoReason: '', importState: 'Posted');
        $approval = Ap2Fixtures::approved($b, $verified);
        self::assertTrue(Ap2Fixtures::assess($b, $verified, [], $approval)->approvalCurrent);

        $changed = Ap2Fixtures::cleanWorks();
        $after = Ap2Fixtures::assess($b, $changed, [], $approval);
        self::assertFalse($after->approvalCurrent, 'the supplier asked to change bank details');
        self::assertContains('Invoice approval required / stale', $after->paymentIssues);
    }

    // ---- queue, export, payment -------------------------------------------------------------------------------

    public function test_queue_derivation_walks_the_prototypes_order(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::invoice($s);

        self::assertSame('Needs review', Ap2Fixtures::assess($b->with(verified: false), $s)->queue);
        self::assertSame('Approval', Ap2Fixtures::assess($b, $s)->queue);
        self::assertSame('Ready to import', Ap2Fixtures::assess($b, $s, [], Ap2Fixtures::approved($b, $s))->queue);
        $posted = $b->with(importState: 'Posted');
        self::assertSame('Open AP', Ap2Fixtures::assess($posted, $s, [], Ap2Fixtures::approved($posted, $s))->queue);
        $settled = $posted->with(paid: 1100.0);
        self::assertSame('Settled', Ap2Fixtures::assess($settled, $s, [], Ap2Fixtures::approved($settled, $s))->queue);
    }

    public function test_csv_eligibility_and_its_reasons(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::invoice($s);

        $ok = Ap2Fixtures::assess($b, $s, [], Ap2Fixtures::approved($b, $s));
        self::assertTrue($ok->csvEligible);
        self::assertSame([], $ok->exportIssues);

        $unapproved = Ap2Fixtures::assess($b, $s);
        self::assertFalse($unapproved->csvEligible);
        self::assertSame(['Invoice approval required / stale'], $unapproved->exportIssues);

        $invalid = Ap2Fixtures::assess($b->with(verified: false), $s, [], Ap2Fixtures::approved($b->with(verified: false), $s));
        self::assertSame(['Unresolved invoice checks: Human field verification required'], $invalid->exportIssues);

        foreach (['Exported CSV', 'Imported draft'] as $state) {
            $exported = $b->with(importState: $state);
            $a = Ap2Fixtures::assess($exported, $s, [], Ap2Fixtures::approved($exported, $s));
            self::assertFalse($a->csvEligible, $state);
            self::assertSame(['Already exported to CSV / imported as a draft'], $a->exportIssues);
        }
        $posted = $b->with(importState: 'Posted');
        self::assertSame(['Already posted in Odoo'], Ap2Fixtures::assess($posted, $s, [], Ap2Fixtures::approved($posted, $s))->exportIssues);
    }

    public function test_s007_payment_issues_for_a_supplier_with_a_pending_bank_change(): void
    {
        $s = Ap2Fixtures::cleanWorks();
        $b = Ap2Fixtures::invoice($s, 'B008', 'CW-3002', kind: 'Recurring', receiptRef: 'ACC', noPoReason: '', importState: 'Posted');
        $a = Ap2Fixtures::assess($b, $s, [], Ap2Fixtures::approved($b, $s));

        self::assertSame(['Payment hold', 'Bank independently verified? No'], $a->paymentIssues);
    }

    public function test_payment_issues_cover_every_prototype_message(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::invoice($s);
        $issues = Ap2Fixtures::assess($b->with(verified: false, paymentHold: true), $s, [], null, true)->paymentIssues;

        self::assertSame([
            'Unresolved invoice checks: Human field verification required',
            'Not posted in Odoo',
            'Invoice approval required / stale',
            'Payment hold',
            'Already in an active payment plan',
        ], $issues);
        self::assertContains('No open balance', Ap2Fixtures::assess($b->with(importState: 'Posted', paid: 1100.0), $s)->paymentIssues);
        self::assertContains('Duplicate / excluded', Ap2Fixtures::assess($b->with(excluded: true), $s)->paymentIssues);

        $clean = $b->with(importState: 'Posted');
        self::assertSame([], Ap2Fixtures::assess($clean, $s, [], Ap2Fixtures::approved($clean, $s))->paymentIssues);
    }

    public function test_posted_invoices_are_locked_and_overdue_days_count_from_the_due_date(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::invoice($s, due: '2026-10-16');

        self::assertFalse(Ap2Fixtures::assess($b, $s)->locked);
        self::assertTrue(Ap2Fixtures::assess($b->with(importState: 'Posted'), $s)->locked);
        self::assertSame(10, Ap2Fixtures::assess($b, $s)->daysOverdue);
        self::assertSame(0, Ap2Fixtures::assess($b->with(due: '2026-10-26'), $s)->daysOverdue);
        self::assertSame(0, Ap2Fixtures::assess($b->with(due: '2026-11-30'), $s)->daysOverdue);
    }

    public function test_the_stage_follows_the_rules(): void
    {
        $s = Ap2Fixtures::supplier();
        $b = Ap2Fixtures::cm2200($s);
        $a = Ap2Fixtures::assess($b, $s);
        self::assertSame('Awaiting matching', Ap2InvoiceRules::stage($b, $a->validation, false));
        self::assertSame('Needs verification', Ap2InvoiceRules::stage($b->with(verified: false), Ap2Fixtures::assess($b->with(verified: false), $s)->validation, false));

        $clean = Ap2Fixtures::invoice($s);
        self::assertSame('Pending approval', Ap2InvoiceRules::stage($clean, [], false));
        self::assertSame('Approved', Ap2InvoiceRules::stage($clean, [], true));
        self::assertSame('Excluded', Ap2InvoiceRules::stage($clean->with(excluded: true), [], true));
        $posted = $clean->with(importState: 'Posted');
        self::assertSame('Posted', Ap2InvoiceRules::stage($posted, [], true));
        self::assertSame('Partially settled', Ap2InvoiceRules::stage($posted->with(paid: 100.0), [], true));
        self::assertSame('Settled', Ap2InvoiceRules::stage($posted->with(paid: 1100.0), [], true));
    }
}
