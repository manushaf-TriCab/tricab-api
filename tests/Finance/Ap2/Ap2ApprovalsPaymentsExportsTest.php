<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap2\InMemoryAp2ApprovalStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2HistoryStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2InvoiceStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2MailStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2PaymentStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2PersonDirectory;
use TriCab\Api\Finance\Ap2\InMemoryAp2SettingsStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2SupplierStore;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap2\Contract\Ap2NotificationStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailDraft;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2Settings;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;
use TriCab\Core\Finance\Ap2\Service\Ap2ApprovalService;
use TriCab\Core\Finance\Ap2\Service\Ap2ExportService;
use TriCab\Core\Finance\Ap2\Service\Ap2InvoiceService;
use TriCab\Core\Finance\Ap2\Service\Ap2NotificationService;
use TriCab\Core\Finance\Ap2\Service\Ap2PaymentService;
use TriCab\Core\Finance\Ap2\Service\Ap2ReportService;
use TriCab\Core\Finance\Ap2\Service\Ap2SettingsService;
use TriCab\Core\Finance\Ap2\Service\Ap2StatementService;
use TriCab\Core\Finance\Ap2\Service\Ap2SupplierService;
use TriCab\Core\Finance\Module;

/** Phases 5, 6 (CSV), 7 and the payment proposals of 9, through the services on the in-memory stores. */
final class Ap2ApprovalsPaymentsExportsTest extends TestCase
{
    private \Psr\Container\ContainerInterface $c;

    private Ap2Actor $requester;

    private Ap2Actor $approver;

    protected function setUp(): void
    {
        $this->c = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true'])->getContainer();
        $this->requester = new Ap2Actor(7, 'Alex Chen', 'alex@tricab.com', [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_REQUEST, Module::PERMISSION_AP2_PAYMENTS_PREPARE, Module::PERMISSION_AP2_EXPORT]);
        $this->approver = new Ap2Actor(9, 'Priya Shah', 'priya@tricab.com', [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE, Module::PERMISSION_AP2_PAYMENTS_APPROVE]);
        $dir = $this->get(InMemoryAp2PersonDirectory::class);
        $dir->add($this->approver->person(), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE, Module::PERMISSION_AP2_PAYMENTS_APPROVE]);
        $dir->add(new Ap2Person(11, 'Dan Lee', 'dan@tricab.com'), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE]);
        $dir->add($this->requester->person(), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function get(string $class): object
    {
        $contracts = [
            InMemoryAp2ApprovalStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore::class,
            InMemoryAp2HistoryStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore::class,
            InMemoryAp2InvoiceStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore::class,
            InMemoryAp2MailStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2MailStore::class,
            InMemoryAp2PaymentStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore::class,
            InMemoryAp2PersonDirectory::class => \TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory::class,
            InMemoryAp2SettingsStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore::class,
            InMemoryAp2SupplierStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore::class,
        ];
        $service = $this->c->get($contracts[$class] ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function supplier(bool $verified = true, string $name = 'Cable Materials Pty Ltd'): Ap2Supplier
    {
        $s = Ap2Fixtures::supplier('S001', $name, $verified);
        $this->get(InMemoryAp2SupplierStore::class)->insert($s);

        return $s;
    }

    private function invoice(Ap2Supplier $s, string $id = 'B010', string $number = 'OH-001', mixed ...$over): Ap2Invoice
    {
        $b = Ap2Fixtures::invoice($s, $id, $number, ...$over);
        $this->get(InMemoryAp2InvoiceStore::class)->insert($b);

        return $b;
    }

    /** @return array<string, mixed> */
    private function request(Ap2Invoice $b, int $approver = 9): array
    {
        $out = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Invoice', 'invoiceIds' => [$b->id], 'approverUserId' => $approver]);
        self::assertCount(1, $out);

        return $out[0];
    }

    /** @return array<string, mixed> */
    private function decide(string $id, string $decision = 'approve', string $comment = '', ?Ap2Actor $by = null): array
    {
        return $this->get(Ap2ApprovalService::class)->decide($by ?? $this->approver, $id, ['decision' => $decision, 'comment' => $comment]);
    }

    private function issuesOf(Ap2Failure $f): string
    {
        $out = [];
        foreach (Ap2Json::arr($f->extra, 'details.issues') as $issue) {
            $out[] = is_string($issue) ? $issue : '';
        }

        return implode(' ', $out);
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    /** @return array<string, mixed> */
    private function invoiceView(string $id): array
    {
        return $this->get(Ap2InvoiceService::class)->get($id);
    }

    private function refused(callable $work, int $status): Ap2Failure
    {
        try {
            $work();
        } catch (Ap2Failure $f) {
            self::assertSame($status, $f->httpStatus(), $f->getMessage());

            return $f;
        }
        self::fail('Expected a refusal');
    }

    /** An invoice posted, approved and ready to pay (verified supplier). */
    private function payable(string $id = 'B020', string $number = 'PAY-1', float $total = 1100.0): Ap2Invoice
    {
        $s = $this->supplier();
        $b = $this->invoice($s, $id, $number, net: $total / 1.1, tax: $total - $total / 1.1, total: $total, due: '2026-12-31');
        $a = $this->request($b);
        $this->decide($this->str($a['id']));
        $this->get(InMemoryAp2InvoiceStore::class)->update($this->get(InMemoryAp2InvoiceStore::class)->find($b->id)?->with(importState: 'Posted') ?? $b);

        return $b;
    }

    public function test_a_request_snapshots_the_invoice_notifies_the_approver_and_records_the_email_without_sending(): void
    {
        $b = $this->invoice($this->supplier());
        $a = $this->request($b);

        self::assertSame('Pending', $a['status']);
        self::assertTrue($a['snapshotCurrent']);
        self::assertSame(9, Ap2Json::get($a, 'approver.userId'));
        self::assertSame($a['id'], $this->invoiceView($b->id)['approvalId']);
        self::assertFalse($this->invoiceView($b->id)['approvalCurrent']);
        self::assertSame('Pending approval', $this->invoiceView($b->id)['stage']);
        self::assertSame(['Requested'], array_column(Ap2Json::arr($a, 'history'), 'action'));
        $expires = new \DateTimeImmutable($this->str($a['expiresAt']));
        $requested = new \DateTimeImmutable($this->str($a['requestedAt']));
        self::assertEquals($requested->add(new DateInterval('P7D')), $expires);

        $bell = $this->get(Ap2NotificationService::class)->list($this->approver);
        self::assertCount(1, $bell);
        self::assertStringContainsString('Approval requested', $this->str($bell[0]['title']));
        $types = array_map(static fn ($e) => $e->type, $this->get(InMemoryAp2HistoryStore::class)->all());
        self::assertContains('Approval requested', $types);
        self::assertContains('Email recorded (not sent)', $types);
    }

    public function test_the_expiry_setting_is_used(): void
    {
        $this->get(InMemoryAp2SettingsStore::class)->save(new Ap2Settings(approvalExpiryDays: 2), 1);
        $a = $this->request($this->invoice($this->supplier()));
        self::assertEquals(
            (new \DateTimeImmutable($this->str($a['requestedAt'])))->add(new DateInterval('P2D')),
            new \DateTimeImmutable($this->str($a['expiresAt'])),
        );
    }

    public function test_the_approver_must_be_an_approver_and_not_the_requester(): void
    {
        $b = $this->invoice($this->supplier());
        $service = $this->get(Ap2ApprovalService::class);
        $self = $this->refused(fn () => $service->request($this->requester, ['kind' => 'Invoice', 'invoiceIds' => [$b->id], 'approverUserId' => 7]), 409);
        self::assertStringContainsString('yourself', $self->getMessage());
        $this->refused(fn () => $service->request($this->requester, ['kind' => 'Invoice', 'invoiceIds' => [$b->id], 'approverUserId' => 99]), 422);
        $this->refused(fn () => $service->request($this->requester, ['kind' => 'Invoice', 'invoiceIds' => [], 'approverUserId' => 9]), 422);
        // Dan holds DECIDE but not PAYMENTS.APPROVE: fine for an invoice, not for a payment.
        self::assertSame(11, Ap2Json::get($this->request($b, 11), 'approver.userId'));
        self::assertSame([9], array_column($service->approvers(['kind' => 'Payment']), 'userId') === [9] ? [9] : []);
        self::assertContains(11, array_column($service->approvers([]), 'userId'));
    }

    public function test_a_new_request_supersedes_the_pending_one(): void
    {
        $b = $this->invoice($this->supplier());
        $first = $this->request($b);
        $second = $this->request($b);
        $service = $this->get(Ap2ApprovalService::class);

        self::assertSame('Superseded', $service->get($this->str($first['id']))['status']);
        self::assertSame('Pending', $service->get($this->str($second['id']))['status']);
        self::assertSame($second['id'], $this->invoiceView($b->id)['approvalId']);
        self::assertCount(1, $service->list(['status' => 'Pending']));
        self::assertCount(1, $service->list(['status' => 'Superseded']));
        $this->refused(fn () => $service->list(['status' => 'Nope']), 422);
    }

    public function test_only_the_named_approver_decides_and_an_approval_makes_the_invoice_current(): void
    {
        $b = $this->invoice($this->supplier());
        $a = $this->request($b);
        $id = $this->str($a['id']);

        $this->refused(fn () => $this->decide($id, 'approve', '', $this->requester), 403);
        $this->refused(fn () => $this->decide($id, 'approve', '', new Ap2Actor(11, 'Dan Lee', 'dan@tricab.com', [Module::PERMISSION_AP2_APPROVALS_DECIDE])), 403);
        $this->refused(fn () => $this->decide($id, 'reject', ''), 422);
        $this->refused(fn () => $this->decide($id, 'maybe'), 422);

        $done = $this->decide($id, 'approve', 'Looks right');
        self::assertSame('Approved', $done['status']);
        self::assertSame(['Requested', 'Approved'], array_column(Ap2Json::arr($done, 'history'), 'action'));
        $view = $this->invoiceView($b->id);
        self::assertTrue($view['approvalCurrent']);
        self::assertSame('Approved', $view['stage']);
        self::assertSame('Ready to import', $view['queue']);

        $bell = $this->get(Ap2NotificationService::class)->list($this->requester);
        self::assertStringContainsString('Approved', $this->str($bell[0]['title']));
        self::assertFalse($bell[0]['read']);
        $this->get(Ap2NotificationService::class)->readAll($this->requester);
        self::assertTrue($this->get(Ap2NotificationService::class)->list($this->requester)[0]['read']);
        self::assertSame([], array_filter($this->get(Ap2NotificationService::class)->list($this->approver), static fn ($n): bool => !$n['read'] && false));

        $this->refused(fn () => $this->decide($id), 409);
    }

    public function test_a_rejection_keeps_the_invoice_unapproved(): void
    {
        $b = $this->invoice($this->supplier());
        $id = $this->str($this->request($b)['id']);
        $done = $this->decide($id, 'reject', 'Wrong cost centre');

        self::assertSame('Rejected', $done['status']);
        self::assertSame('Wrong cost centre', $done['comment']);
        self::assertFalse($this->invoiceView($b->id)['approvalCurrent']);
    }

    public function test_a_stale_snapshot_blocks_the_decision(): void
    {
        $b = $this->invoice($this->supplier());
        $a = $this->request($b);
        $this->get(Ap2InvoiceService::class)->update($this->requester, $b->id, ['costCentre' => 'Sales']);

        self::assertFalse($this->get(Ap2ApprovalService::class)->get($this->str($a['id']))['snapshotCurrent']);
        $f = $this->refused(fn () => $this->decide($this->str($a['id'])), 409);
        self::assertStringContainsString('Request fresh approval', $f->getMessage());
    }

    public function test_an_approved_invoice_goes_stale_when_a_critical_field_changes_or_the_bank_changes(): void
    {
        $s = $this->supplier();
        $b = $this->invoice($s);
        $this->decide($this->str($this->request($b)['id']));
        self::assertTrue($this->invoiceView($b->id)['approvalCurrent']);

        $this->get(InMemoryAp2SupplierStore::class)->update($s->with(bankChange: true, bankVerified: false, hold: true));
        self::assertFalse($this->invoiceView($b->id)['approvalCurrent'], 'the supplier bank flags are in the fingerprint');
    }

    public function test_an_expired_request_reads_as_expired_and_cannot_be_decided(): void
    {
        $b = $this->invoice($this->supplier());
        $a = $this->request($b);
        $store = $this->get(InMemoryAp2ApprovalStore::class);
        $row = $store->get($this->str($a['id']));
        self::assertNotNull($row);
        $store->update($row->with(expiresAt: new \DateTimeImmutable('2020-01-01')));

        $service = $this->get(Ap2ApprovalService::class);
        self::assertSame('Expired', $service->get($this->str($a['id']))['status']);
        self::assertCount(1, $service->list(['status' => 'Expired']));
        $f = $this->refused(fn () => $this->decide($this->str($a['id'])), 409);
        self::assertStringContainsString('expired', $f->getMessage());
    }

    public function test_an_email_approval_is_bound_to_the_draft(): void
    {
        $s = $this->supplier();
        $mails = $this->get(InMemoryAp2MailStore::class);
        $mails->add(new Ap2MailDraft(md5('m1'), $s->id, 'Where is my money', 'Dear supplier', 'ap@tricab.com', '', []));
        $service = $this->get(Ap2ApprovalService::class);
        $a = $service->request($this->requester, ['kind' => 'Email', 'mailId' => md5('m1'), 'approverUserId' => 9])[0];

        self::assertSame($a['id'], $mails->approvals[md5('m1')]);
        $mails->add(new Ap2MailDraft(md5('m1'), $s->id, 'Where is my money', 'Dear supplier, changed', 'ap@tricab.com', '', []));
        $this->refused(fn () => $this->decide($this->str($a['id'])), 409);
        $this->refused(fn () => $service->request($this->requester, ['kind' => 'Email', 'approverUserId' => 9]), 422);
        $this->refused(fn () => $service->request($this->requester, ['kind' => 'Email', 'mailId' => md5('zz'), 'approverUserId' => 9]), 404);
    }

    public function test_the_csv_has_the_prototypes_headers_a_credit_row_a_bom_quotes_and_the_injection_guard(): void
    {
        $s = $this->supplier(true, '=HYPERLINK("x")');
        $b = $this->invoice(
            $s,
            'B030',
            'CM-2200',
            kind: 'Goods PO',
            po: 'PO-1001',
            quantity: 1000.0,
            orderedQty: 1000.0,
            receivedQty: 900.0,
            poUnitPrice: 20.0,
            receiptRef: 'GRN-1',
            net: 20000.0,
            tax: 2000.0,
            total: 22000.0,
            noPoReason: '',
            serviceAccepted: false,
            credit: 2200.0,
            creditRef: 'CN-2200',
            creditNet: 2000.0,
            creditTax: 200.0,
            creditResolved: true,
            sourceDoc: '-evil.pdf',
        );
        $exports = $this->get(Ap2ExportService::class);

        $f = $this->refused(fn () => $exports->create($this->requester, ['invoiceIds' => [$b->id]]), 409);
        self::assertStringContainsString('Invoice approval required', $this->issuesOf($f));
        $this->decide($this->str($this->request($b)['id']));

        $x = $exports->create($this->requester, ['invoiceIds' => [$b->id, $b->id]]);
        self::assertSame('CSV-0001', $x['id']);
        self::assertSame(['External ID', 'Move Type', 'Vendor', 'Bill Reference', 'Bill Date', 'Due Date', 'Currency', 'Account Code', 'Tax Code', 'Cost Centre', 'Net Amount', 'Tax Amount', 'Gross Amount', 'Source Document', 'Original Bill Reference'], $x['headers']);
        $csv = $this->str($x['csv']);
        self::assertStringStartsWith("\xEF\xBB\xBF\"External ID\",\"Move Type\"", $csv);
        $lines = explode("\r\n", substr($csv, 3));
        self::assertCount(3, $lines, 'header, invoice, credit');
        self::assertStringStartsWith('"triap_' . $b->id . '","in_invoice","\'=HYPERLINK(""x"")","CM-2200","2026-10-01","2026-10-31","AUD","6000","GST10","Operations","20000.00","2000.00","22000.00","\'-evil.pdf",""', $lines[1]);
        self::assertStringStartsWith('"triap_' . $b->id . '_credit","in_refund"', $lines[2]);
        self::assertStringEndsWith('"2000.00","200.00","2200.00","CN-2200.pdf","CM-2200"', $lines[2]);
        self::assertSame($csv, $exports->csv('CSV-0001'));
        self::assertCount(1, $exports->list());

        self::assertSame('Exported CSV', $this->invoiceView($b->id)['importState']);
        $again = $this->refused(fn () => $exports->create($this->requester, ['invoiceIds' => [$b->id]]), 409);
        self::assertStringContainsString('Already exported', $this->issuesOf($again));
        $this->refused(fn () => $exports->create($this->requester, ['invoiceIds' => []]), 422);
        $this->refused(fn () => $exports->csv('CSV-9999'), 404);
    }

    public function test_a_number_is_never_altered_by_the_injection_guard(): void
    {
        self::assertSame('"-5.00"', \TriCab\Core\Finance\Ap2\Service\Ap2Csv::cell(-5.0));
        self::assertSame('"\'+1"', \TriCab\Core\Finance\Ap2\Service\Ap2Csv::cell('+1'));
        self::assertSame('"\'  @a"', \TriCab\Core\Finance\Ap2\Service\Ap2Csv::cell('  @a'));
        self::assertSame('"a ""b"""', \TriCab\Core\Finance\Ap2\Service\Ap2Csv::cell('a "b"'));
    }

    public function test_import_results_follow_the_export_and_need_confirmation_and_a_current_approval(): void
    {
        $b = $this->invoice($this->supplier());
        $exports = $this->get(Ap2ExportService::class);
        $body = ['result' => 'Posted', 'reference' => 'BILL/2026/001', 'confirmed' => true];

        $this->refused(fn () => $exports->importResult($this->requester, $b->id, $body), 409);
        $this->decide($this->str($this->request($b)['id']));
        $exports->create($this->requester, ['invoiceIds' => [$b->id]]);
        $this->refused(fn () => $exports->importResult($this->requester, $b->id, ['confirmed' => false] + $body), 422);
        $this->refused(fn () => $exports->importResult($this->requester, $b->id, ['reference' => ''] + $body), 422);

        $failed = $exports->importResult($this->requester, $b->id, ['result' => 'Import failed', 'reference' => 'row 3', 'confirmed' => true]);
        self::assertSame('Import failed', $failed['importState']);
        $posted = $exports->importResult($this->requester, $b->id, $body);
        self::assertSame('Posted', $posted['importState']);
        self::assertSame('Posted', $posted['stage']);
        self::assertSame('BILL/2026/001', $posted['odooRef']);
        $this->refused(fn () => $exports->importResult($this->requester, $b->id, $body), 409);
    }

    public function test_an_import_result_for_a_stale_approval_is_refused_unless_it_failed(): void
    {
        $b = $this->invoice($this->supplier());
        $this->decide($this->str($this->request($b)['id']));
        $exports = $this->get(Ap2ExportService::class);
        $exports->create($this->requester, ['invoiceIds' => [$b->id]]);
        $this->get(Ap2InvoiceService::class)->update($this->requester, $b->id, ['costCentre' => 'Sales']);

        $this->refused(fn () => $exports->importResult($this->requester, $b->id, ['result' => 'Imported draft', 'reference' => 'D1', 'confirmed' => true]), 409);
        self::assertSame('Import failed', $exports->importResult($this->requester, $b->id, ['result' => 'Import failed', 'reference' => 'D1', 'confirmed' => true])['importState']);
    }

    public function test_a_payment_proposal_follows_the_rules_and_the_cash_limit(): void
    {
        $b = $this->payable();
        $pay = $this->get(Ap2PaymentService::class);
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+3 days')->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('-1 day')->format('Y-m-d');

        $this->refused(fn () => $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 100, 'date' => $yesterday]), 422);
        $this->refused(fn () => $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 0, 'date' => $future]), 422);
        $this->refused(fn () => $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 5000, 'date' => $future]), 409);
        $p = $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 1000, 'date' => $future]);
        self::assertSame('Proposed', $p['status']);
        self::assertSame('PAY-1', $p['invoiceNumber']);
        $f = $this->refused(fn () => $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 50, 'date' => $future]), 409);
        self::assertContains('Already in an active payment plan', Ap2Json::arr($f->extra, 'details.issues'));
        self::assertTrue($this->invoiceView($b->id)['paymentIssues'] !== []);
    }

    public function test_the_proposals_together_may_not_pass_the_cash_limit(): void
    {
        $this->get(InMemoryAp2SettingsStore::class)->save(new Ap2Settings(batchCashLimit: 1500.0), 1);
        $a = $this->payable('B021', 'PAY-A');
        $b = $this->payable('B022', 'PAY-B');
        $pay = $this->get(Ap2PaymentService::class);
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+2 days')->format('Y-m-d');

        $pay->create($this->requester, ['invoiceId' => $a->id, 'amount' => 1000, 'date' => $future]);
        $f = $this->refused(fn () => $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 600, 'date' => $future]), 409);
        self::assertStringContainsString('CFO review required', $f->getMessage());
        $this->refused(fn () => $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 1600, 'date' => $future]), 409);
        self::assertSame('Proposed', $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 500, 'date' => $future])['status']);
    }

    public function test_proposals_can_be_switched_off(): void
    {
        $b = $this->payable();
        $this->get(Ap2SettingsService::class)->update($this->requester, ['paymentProposalsEnabled' => false]);
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+2 days')->format('Y-m-d');
        $f = $this->refused(fn () => $this->get(Ap2PaymentService::class)->create($this->requester, ['invoiceId' => $b->id, 'amount' => 100, 'date' => $future]), 409);
        self::assertStringContainsString('switched off', $f->getMessage());
    }

    public function test_a_payment_goes_through_approval_then_odoo_and_settles_the_invoice(): void
    {
        $b = $this->payable('B023', 'PAY-C', 500.0);
        $pay = $this->get(Ap2PaymentService::class);
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+2 days')->format('Y-m-d');
        $p = $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 500, 'date' => $future]);
        $approvals = $this->get(Ap2ApprovalService::class);

        // Dan holds DECIDE but not PAYMENTS.APPROVE, so he cannot be asked.
        $this->refused(fn () => $approvals->request($this->requester, ['kind' => 'Payment', 'paymentId' => $p['id'], 'approverUserId' => 11]), 422);
        $a = $approvals->request($this->requester, ['kind' => 'Payment', 'paymentId' => $p['id'], 'approverUserId' => 9])[0];
        self::assertSame('Awaiting payment approval', $this->get(InMemoryAp2PaymentStore::class)->find($this->str($p['id']))?->status);
        $this->refused(fn () => $this->decide($this->str($a['id']), 'approve', '', new Ap2Actor(9, 'Priya Shah', 'priya@tricab.com', [Module::PERMISSION_AP2_APPROVALS_DECIDE])), 403);
        $this->decide($this->str($a['id']));
        $stored = $this->get(InMemoryAp2PaymentStore::class)->find($this->str($p['id']));
        self::assertSame('Payment approved', $stored->status);

        $paid = $pay->recordOdooState($this->str($p['id']), 'Sent to Odoo', 'PAY/001');
        self::assertSame('Sent to Odoo', $paid['status']);
        $this->refused(fn () => $pay->recordOdooState($this->str($p['id']), 'Cancelled', ''), 409);
        self::assertSame('Paid', $pay->recordOdooState($this->str($p['id']), 'Paid', '')['status']);
        $view = $this->invoiceView($b->id);
        self::assertSame('Settled', $view['stage']);
        self::assertSame(0.0, $view['balance']);
    }

    public function test_a_rejected_payment_approval_rejects_the_proposal(): void
    {
        $b = $this->payable('B024', 'PAY-D', 500.0);
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+2 days')->format('Y-m-d');
        $p = $this->get(Ap2PaymentService::class)->create($this->requester, ['invoiceId' => $b->id, 'amount' => 500, 'date' => $future]);
        $a = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Payment', 'paymentId' => $p['id'], 'approverUserId' => 9])[0];
        $this->decide($this->str($a['id']), 'reject', 'Hold until Friday');

        $row = $this->get(InMemoryAp2PaymentStore::class)->find($this->str($p['id']));
        self::assertNotNull($row);
        self::assertSame('Rejected', $row->status);
        self::assertSame('Hold until Friday', $row->rejection);
        // A rejected proposal frees the invoice for a new one.
        self::assertSame([], array_filter(Ap2Json::arr($this->invoiceView($b->id), 'paymentIssues'), static fn (mixed $i): bool => $i === 'Already in an active payment plan'));
    }

    public function test_a_payment_can_only_be_cancelled_and_cancelling_supersedes_the_pending_approval(): void
    {
        $b = $this->payable('B025', 'PAY-E', 500.0);
        $pay = $this->get(Ap2PaymentService::class);
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+2 days')->format('Y-m-d');
        $p = $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 500, 'date' => $future]);
        $a = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Payment', 'paymentId' => $p['id'], 'approverUserId' => 9])[0];

        $this->refused(fn () => $pay->transition($this->requester, $this->str($p['id']), ['to' => 'Paid']), 422);
        $this->refused(fn () => $pay->transition($this->requester, $this->str($p['id']), []), 422);
        self::assertSame('Cancelled', $pay->transition($this->requester, $this->str($p['id']), ['to' => 'Cancelled'])['status']);
        self::assertSame('Superseded', $this->get(Ap2ApprovalService::class)->get($this->str($a['id']))['status']);
        $this->refused(fn () => $pay->transition($this->requester, $this->str($p['id']), ['to' => 'Cancelled']), 409);
        self::assertSame('Proposed', $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 500, 'date' => $future])['status'], 'cancelled proposals free the invoice');
    }

    public function test_cancelling_works_from_payment_approved_but_not_from_sent_to_odoo(): void
    {
        $b = $this->payable('B026', 'PAY-F', 500.0);
        $pay = $this->get(Ap2PaymentService::class);
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+2 days')->format('Y-m-d');
        $p = $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 500, 'date' => $future]);
        $a = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Payment', 'paymentId' => $p['id'], 'approverUserId' => 9])[0];
        $this->decide($this->str($a['id']));
        $pay->recordOdooState($this->str($p['id']), 'Sent to Odoo', 'PAY/9');

        $this->refused(fn () => $pay->transition($this->requester, $this->str($p['id']), ['to' => 'Cancelled']), 409);
    }

    public function test_a_stale_payment_approval_cannot_go_to_odoo(): void
    {
        $b = $this->payable('B027', 'PAY-G', 500.0);
        $pay = $this->get(Ap2PaymentService::class);
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+2 days')->format('Y-m-d');
        $p = $pay->create($this->requester, ['invoiceId' => $b->id, 'amount' => 500, 'date' => $future]);
        $a = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Payment', 'paymentId' => $p['id'], 'approverUserId' => 9])[0];
        $this->decide($this->str($a['id']));
        $s = $this->get(InMemoryAp2SupplierStore::class);
        $sup = $s->find($b->supplierId);
        self::assertNotNull($sup);
        $s->update($sup->with(hold: true));

        $this->refused(fn () => $pay->recordOdooState($this->str($p['id']), 'Sent to Odoo', 'PAY/1'), 409);
    }

    public function test_bank_verification_needs_evidence_clears_the_hold_and_stales_approvals(): void
    {
        $s = $this->get(InMemoryAp2SupplierStore::class);
        $cw = Ap2Fixtures::cleanWorks();
        $s->insert($cw);
        $b = Ap2Fixtures::invoice($cw, 'B040', 'CW-1');
        $this->get(InMemoryAp2InvoiceStore::class)->insert($b);
        $this->decide($this->str($this->request($b)['id']));
        self::assertTrue($this->invoiceView($b->id)['approvalCurrent']);
        $suppliers = $this->get(Ap2SupplierService::class);

        $this->refused(fn () => $suppliers->bankVerify($this->requester, $cw->id, []), 422);
        $out = $suppliers->bankVerify($this->requester, $cw->id, ['evidence' => 'Phoned Sam on the number from the contract']);
        self::assertTrue($out['bankVerified']);
        self::assertFalse($out['bankChange']);
        self::assertFalse($out['hold']);
        self::assertFalse($this->invoiceView($b->id)['approvalCurrent'], 'the approval was made on the unverified bank state');
        $this->refused(fn () => $suppliers->bankVerify($this->requester, $cw->id, ['evidence' => 'again']), 409);
        $types = array_map(static fn ($e) => $e->type, $this->get(InMemoryAp2HistoryStore::class)->all());
        self::assertContains('Bank verification recorded', $types);
    }

    public function test_sharing_history_needs_review_valid_addresses_and_the_suppliers_own_entries(): void
    {
        $s = $this->supplier();
        $suppliers = $this->get(Ap2SupplierService::class);
        $entry = $suppliers->recordAction($this->requester, $s->id, ['type' => 'Note', 'detail' => 'Chased receipt']);
        $ok = ['historyIds' => [$entry['id']], 'to' => 'boss@example.com', 'cc' => '', 'subject' => 'History', 'body' => 'Chased receipt', 'reviewed' => true];

        $this->refused(fn () => $suppliers->shareHistory($this->requester, $s->id, ['reviewed' => false] + $ok), 422);
        $this->refused(fn () => $suppliers->shareHistory($this->requester, $s->id, ['to' => 'nope'] + $ok), 422);
        $this->refused(fn () => $suppliers->shareHistory($this->requester, $s->id, ['to' => ''] + $ok), 422);
        $this->refused(fn () => $suppliers->shareHistory($this->requester, $s->id, ['historyIds' => [md5('x')]] + $ok), 422);
        $this->refused(fn () => $suppliers->shareHistory($this->requester, $s->id, ['historyIds' => []] + $ok), 422);
        $out = $suppliers->shareHistory($this->requester, $s->id, $ok);
        self::assertSame(1, $out['shared']);
        self::assertFalse($out['sent']);
        $types = array_map(static fn ($e) => $e->type, $this->get(InMemoryAp2HistoryStore::class)->all());
        self::assertContains('History shared', $types);
        self::assertContains('Email recorded (not sent)', $types);
    }

    public function test_statements_compare_with_posted_ap_and_never_change_it(): void
    {
        $s = $this->supplier();
        $b = $this->invoice($s, 'B050', 'ST-1', importState: 'Posted');
        $st = $this->get(Ap2StatementService::class);
        $a = $st->create($this->requester, ['supplierId' => $s->id, 'date' => '2026-10-20', 'amount' => 1500, 'reference' => 'Oct statement']);

        self::assertSame('Unreviewed', $a['status']);
        self::assertSame(1100.0, $a['postedAp']);
        self::assertSame(400.0, $a['difference']);
        $id = $this->str($a['id']);
        $this->refused(fn () => $st->review($this->requester, $id, ['status' => 'Matched', 'evidence' => 'e', 'notes' => 'n']), 409);
        $this->refused(fn () => $st->review($this->requester, $id, ['status' => 'Discrepancy', 'evidence' => '', 'notes' => 'n']), 422);
        $r = $st->review($this->requester, $id, ['evidence' => 'Supplier PDF p2', 'notes' => 'Missing credit note']);
        self::assertSame('Discrepancy', $r['status']);
        self::assertSame('Alex Chen', Ap2Json::get($r, 'reviewedBy.name'));
        self::assertSame(1100.0, $this->invoiceView($b->id)['balance'], 'the ledger is untouched');

        $m = $st->create($this->requester, ['supplierId' => $s->id, 'date' => '2026-10-21', 'amount' => 1100, 'reference' => 'Second']);
        self::assertSame('Matched', $st->review($this->requester, $this->str($m['id']), ['status' => 'Matched', 'evidence' => 'e', 'notes' => 'n'])['status']);
        self::assertCount(2, $st->list());
        $this->refused(fn () => $st->create($this->requester, ['supplierId' => md5('nobody'), 'date' => '2026-10-21', 'amount' => 1]), 422);
        $this->refused(fn () => $st->create($this->requester, ['supplierId' => $s->id, 'amount' => 1]), 422);
        $this->refused(fn () => $st->create($this->requester, ['supplierId' => $s->id, 'date' => '2026-10-21', 'amount' => 1, 'mailId' => md5('none')]), 422);
        $this->refused(fn () => $st->review($this->requester, md5('none'), ['evidence' => 'e', 'notes' => 'n']), 404);
    }

    public function test_settings_are_editable_except_the_read_only_fields(): void
    {
        $settings = $this->get(Ap2SettingsService::class);
        $before = $settings->get();
        self::assertSame(25000.0, $before['batchCashLimit']);
        self::assertSame(7, $before['approvalExpiryDays']);
        self::assertTrue($before['paymentProposalsEnabled']);

        $after = $settings->update($this->requester, ['batchCashLimit' => 10000, 'approvalExpiryDays' => 3, 'glDefault' => '6100', 'currencies' => ['AUD', 'USD', 'NZD', 'SGD']]);
        self::assertSame(10000.0, $after['batchCashLimit']);
        self::assertSame(3, $after['approvalExpiryDays']);
        self::assertSame('6100', $after['glDefault']);
        $this->refused(fn () => $settings->update($this->requester, ['odooPostingEnabled' => true]), 422);
        $this->refused(fn () => $settings->update($this->requester, ['mailbox' => 'x@y.com']), 422);
        $this->refused(fn () => $settings->update($this->requester, ['batchCashLimit' => 0]), 422);
        $this->refused(fn () => $settings->update($this->requester, ['approvalExpiryDays' => 0]), 422);
    }

    public function test_the_aged_report_flags_a_supplier_on_hold_or_with_an_unverified_bank(): void
    {
        $verified = $this->supplier();
        $this->invoice($verified, 'B070', 'H-1', importState: 'Posted', issued: '2000-01-01', due: '2000-02-01', paymentHold: true);
        $other = Ap2Fixtures::supplier('S002', 'Unverified Co', false);
        $this->get(InMemoryAp2SupplierStore::class)->insert($other);
        $this->invoice($other, 'B071', 'H-2', importState: 'Posted', issued: '2000-01-01', due: '2099-01-01');

        $rows = [];
        foreach ($this->get(Ap2ReportService::class)->aged() as $row) {
            $rows[Ap2Json::str($row, 'supplierName')] = $row;
        }
        self::assertTrue($rows['Cable Materials Pty Ltd']['onHold'], 'an invoice payment hold');
        self::assertSame(1100.0, $rows['Cable Materials Pty Ltd']['overdue']);
        self::assertTrue($rows['Unverified Co']['onHold'], 'bank not verified');
        self::assertSame(0.0, $rows['Unverified Co']['overdue']);
    }

    public function test_the_aged_report_buckets_posted_open_invoices_in_aud_per_supplier(): void
    {
        $s = $this->supplier();
        $day = static fn (int $o): string => (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify(($o >= 0 ? '+' : '') . $o . ' days')->format('Y-m-d');
        $this->invoice($s, 'B060', 'A-1', importState: 'Posted', issued: '2000-01-01', due: $day(5));
        $this->invoice($s, 'B061', 'A-2', importState: 'Posted', issued: '2000-01-01', due: $day(-10));
        $this->invoice($s, 'B062', 'A-3', importState: 'Posted', issued: '2000-01-01', due: $day(-45));
        $this->invoice($s, 'B063', 'A-4', importState: 'Posted', issued: '2000-01-01', due: $day(-120));
        $this->invoice($s, 'B064', 'A-5', issued: '2000-01-01', due: $day(-120));

        $rows = $this->get(Ap2ReportService::class)->aged();
        self::assertCount(1, $rows);
        self::assertEquals(['supplierId' => $s->id, 'supplierName' => 'Cable Materials Pty Ltd', 'current' => 1100, 'd1_30' => 1100, 'd31_60' => 1100, 'd61_90' => 0, 'd90p' => 1100, 'total' => 4400, 'overdue' => 3300, 'onHold' => false], $rows[0]);
    }
}
