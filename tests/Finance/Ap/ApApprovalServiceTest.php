<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApFailure;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;
use TriCab\Core\Finance\Ap\Service\ApOutboundMail;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * Approvals (T6.11) on the in-memory stores and a transport that sends nothing: who the Approvers are, the request (validation,
 * eligibility, statuses, who is notified, the warnings), the decision (the first wins, the 409 with the batch, the statuses, the
 * outcome mail), the sending switch (decision D80), mail failures, the send grant, idempotency, the mailbox scope and one person
 * preparing and deciding. No mail leaves the process.
 */
final class ApApprovalServiceTest extends TestCase
{
    private ApApprovalWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApApprovalWorld();
        $this->seed($this->world);
    }

    private function seed(ApApprovalWorld $world): void
    {
        $world->mail->invoice('i1', vendor: 'Acme Freight Pty Ltd', invoiceNo: 'INV-1', totalAmount: 110.0);
        $world->mail->invoice('i2', vendor: 'Zed & Co <Ltd>', invoiceNo: 'Z-9', totalAmount: 1234.5, currency: '');
        $world->mail->invoice('other', mailbox: 'melissa@example.test');
        $world->mail->invoice('hand', mailbox: null);
        $world->mail->invoice('binned', deletedAt: ApInvoiceFixtures::utc('2026-10-06 00:00:00'), deletedBy: 'dee@example.test');
        $world->mail->invoice('done', status: ApInvoice::STATUS_COMPLETED);
        $world->mail->invoice('exported', status: ApInvoice::STATUS_EXPORTED);
    }

    private function preparer(): ApActor
    {
        return ApApprovalWorld::preparer();
    }

    private function approver(): ApActor
    {
        return ApApprovalWorld::approver();
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $over
     * @return array{batch: array<string, mixed>, warning: string|null}
     */
    private function request(array $ids = ['i1', 'i2'], array $over = [], ?ApActor $actor = null): array
    {
        return $this->world->service->request($actor ?? $this->preparer(), $over + ['invoice_ids' => $ids, 'note' => 'Please check']);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function decide(string $id, string $decision = 'approve', array $over = [], ?ApActor $actor = null): array
    {
        return $this->world->service->decide($actor ?? $this->approver(), $id, $over + ['decision' => $decision, 'comment' => 'Looks right']);
    }

    private function batchId(string ...$ids): string
    {
        $out = $this->request($ids === [] ? ['i1', 'i2'] : array_values($ids));
        $id = $out['batch']['id'];
        self::assertIsString($id);

        return $id;
    }

    private function approval(string $invoice): string
    {
        $row = $this->world->mail->register->invoices->find($invoice);
        self::assertNotNull($row);

        return $row->approvalStatus;
    }

    private function workflow(string $invoice): string
    {
        $row = $this->world->mail->register->invoices->find($invoice);
        self::assertNotNull($row);

        return $row->status;
    }

    /**
     * @param callable(): mixed $work
     */
    private static function failure(callable $work): ApFailure
    {
        try {
            $work();
        } catch (ApFailure $failure) {
            return $failure;
        }
        self::fail('Expected a failure.');
    }

    private function sent(): int
    {
        return count($this->world->mail->transport->standalone);
    }

    /**
     * @return list<ApAuditEvent>
     */
    private function events(string $batchId): array
    {
        return $this->world->mail->register->audit->forEntity(ApAuditEvent::ENTITY_APPROVAL_BATCH, $batchId);
    }

    // ---- the approvers -------------------------------------------------------------------

    public function test_the_approvers_are_listed_with_an_id_a_name_and_a_lower_case_address_by_name(): void
    {
        self::assertSame([
            ['id' => '21', 'full_name' => 'Ann Approver', 'email' => 'ann@example.test'],
            ['id' => '22', 'full_name' => 'Bob Approver', 'email' => 'bob@example.test'],
        ], $this->world->service->approvers());
    }

    public function test_an_approver_without_a_name_reads_as_their_address_and_nobody_is_an_empty_list(): void
    {
        $this->world->directory->remove(21);
        $this->world->directory->remove(22);
        self::assertSame([], $this->world->service->approvers());

        $this->world->directory->add(23, '', 'Nameless@Example.test');
        self::assertSame([['id' => '23', 'full_name' => 'nameless@example.test', 'email' => 'nameless@example.test']], $this->world->service->approvers());
    }

    // ---- the request ---------------------------------------------------------------------

    public function test_a_request_creates_a_pending_batch_marks_the_invoices_and_leaves_their_workflow_status(): void
    {
        $out = $this->request();

        self::assertNull($out['warning']);
        self::assertArrayHasKey('warning', $out);
        $batch = $out['batch'];
        self::assertSame(ApApprovalWorld::id(1), $batch['id']);
        self::assertSame('Pending', $batch['status']);
        self::assertSame('Please check', $batch['note']);
        self::assertSame('7', $batch['prepared_by']);
        self::assertSame(['id' => '7', 'full_name' => 'Dee Preparer', 'email' => 'dee@example.test'], $batch['prepared_by_user']);
        self::assertNull($batch['decided_by']);
        self::assertNull($batch['decided_by_user']);
        self::assertNull($batch['decided_at']);
        self::assertNull($batch['decision_comment']);
        self::assertSame([], $batch['decisions']);
        self::assertSame('2026-10-09T01:02:03.456789+00:00', $batch['created_date']);
        self::assertSame(['ann@example.test', 'bob@example.test'], $batch['notified_emails']);
        $invoices = $batch['invoices'];
        self::assertIsArray($invoices);
        self::assertSame(['i1', 'i2'], array_column($invoices, 'id'));
        self::assertSame(['Pending approval', 'Pending approval'], array_column($invoices, 'approval_status'));
        self::assertSame('Pending approval', $this->approval('i1'));
        self::assertSame(ApInvoice::STATUS_IN_PROGRESS, $this->workflow('i1'), 'the workflow status is not touched');
    }

    public function test_the_request_mail_goes_from_the_ap_mailbox_to_the_approvers_with_the_source_text_and_the_platform_link(): void
    {
        $id = $this->batchId();

        self::assertCount(1, $this->world->mail->transport->standalone);
        $message = $this->world->mail->transport->standalone[0];
        self::assertSame('ap@example.test', $message->mailbox);
        self::assertSame(['ann@example.test', 'bob@example.test'], $message->to);
        self::assertSame('AP approval request: 2 invoice(s)', $message->subject);
        $cell = "<td style='padding:6px;border-bottom:1px solid #ddd'>";
        $right = "<td style='padding:6px;border-bottom:1px solid #ddd;text-align:right'>";
        self::assertSame(
            '<p>Dee Preparer submitted 2 AP invoice(s) for approval.</p>'
            . '<p>Please check</p>'
            . "<table style='border-collapse:collapse;width:100%'><thead><tr><th align='left'>Vendor</th><th align='left'>Invoice</th><th align='right'>Total</th></tr></thead><tbody>"
            . '<tr>' . $cell . 'Acme Freight Pty Ltd</td>' . $cell . 'INV-1</td>' . $right . 'AUD 110.00</td></tr>'
            . '<tr>' . $cell . 'Zed &amp; Co &lt;Ltd&gt;</td>' . $cell . 'Z-9</td>' . $right . 'AUD 1,234.50</td></tr>'
            . '</tbody></table>'
            . "<p><a href='https://site.example.test/finance/ap/approvals/" . $id . "'>Review this approval request in Invoice Flow</a></p>"
            . '<p>Any active Approver may complete this request. The decision and approver identity will be recorded.</p>',
            $message->body,
        );
        $key = 'ap-approval:' . $id . ':request';
        self::assertSame($key, $message->idempotencyKey);
        $links = $this->world->mail->outbound->of(ApOutboundTarget::approvalBatch($id), 10);
        self::assertCount(1, $links);
        self::assertSame(7, $links[0]->senderUserId);
    }

    public function test_the_request_is_audited_with_the_ids_as_sent_the_recipients_and_the_warning(): void
    {
        $id = $this->batchId('i1', 'i2', 'i1');

        $events = $this->events($id);
        self::assertCount(1, $events);
        self::assertSame('approval_requested', $events[0]->action);
        self::assertSame('approval_batch', $events[0]->entityType);
        self::assertSame(7, $events[0]->actorUserId);
        self::assertSame(['invoice_ids' => ['i1', 'i2', 'i1'], 'notified' => ['ann@example.test', 'bob@example.test'], 'notification_warning' => null], $events[0]->details);
    }

    public function test_an_id_sent_twice_is_one_item_and_the_mail_counts_distinct_invoices(): void
    {
        $out = $this->request(['i2', 'i1', 'i2']);

        $invoices = $out['batch']['invoices'];
        self::assertIsArray($invoices);
        self::assertSame(['i1', 'i2'], array_column($invoices, 'id'), 'shown by vendor');
        self::assertSame('AP approval request: 2 invoice(s)', $this->world->mail->transport->standalone[0]->subject);
        $rows = $this->world->mail->transport->standalone[0]->body;
        self::assertLessThan((int) strpos($rows, 'Acme'), (int) strpos($rows, 'Zed'), 'the mail lists the invoices in the order they were sent');
    }

    #[DataProvider('badInvoiceIds')]
    public function test_the_invoice_list_must_be_a_non_empty_list(mixed $ids): void
    {
        $failure = self::failure(fn () => $this->world->service->request($this->preparer(), ['invoice_ids' => $ids]));

        self::assertInstanceOf(ApInvalid::class, $failure);
        self::assertSame('Select at least one invoice', $failure->getMessage());
        self::assertSame(422, $failure->httpStatus());
        self::assertSame(0, $this->sent());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badInvoiceIds(): array
    {
        return ['missing' => [null], 'empty' => [[]], 'a string' => ['i1'], 'an object' => [['a' => 'i1']], 'zero' => [0]];
    }

    public function test_the_whole_request_is_missing_without_invoice_ids(): void
    {
        $failure = self::failure(fn () => $this->world->service->request($this->preparer(), []));

        self::assertSame('Select at least one invoice', $failure->getMessage());
    }

    public function test_an_unknown_or_out_of_scope_invoice_refuses_the_whole_request_and_creates_nothing(): void
    {
        foreach ([['i1', 'nope'], ['i1', 'other'], ['i1', 7], ['i1', ''], ['i1', ['x']]] as $ids) {
            $failure = self::failure(fn () => $this->world->service->request(ApApprovalWorld::preparer(['ap@example.test']), ['invoice_ids' => $ids]));

            self::assertInstanceOf(ApInvalid::class, $failure);
            self::assertSame('One or more invoices are unavailable', $failure->getMessage());
        }
        self::assertSame([], $this->world->service->list($this->preparer()));
        self::assertSame('Not requested', $this->approval('i1'));
        self::assertSame(0, $this->sent());
    }

    public function test_a_scoped_person_may_include_a_hand_capture_and_their_own_mailbox(): void
    {
        $out = $this->request(['i1', 'hand'], [], ApApprovalWorld::preparer(['ap@example.test']));

        $invoices = $out['batch']['invoices'];
        self::assertIsArray($invoices);
        self::assertEqualsCanonicalizing(['i1', 'hand'], array_column($invoices, 'id'));
    }

    public function test_there_is_no_eligibility_rule_binned_completed_exported_and_pending_invoices_are_accepted(): void
    {
        $first = $this->batchId('binned', 'done', 'exported');
        self::assertSame(['Pending approval', 'Pending approval', 'Pending approval'], [$this->approval('binned'), $this->approval('done'), $this->approval('exported')]);
        self::assertSame(ApInvoice::STATUS_COMPLETED, $this->workflow('done'));
        self::assertSame(ApInvoice::STATUS_EXPORTED, $this->workflow('exported'));

        $second = $this->batchId('done');
        self::assertNotSame($first, $second);
        $this->decide($first, 'reject');
        self::assertSame('Rejected', $this->approval('done'));
        $this->decide($second, 'approve');
        self::assertSame('Approved', $this->approval('done'), 'an invoice in two batches takes the later decision');
        self::assertSame('Rejected', $this->approval('binned'), 'a binned invoice is overwritten too');
        self::assertSame(ApInvoice::STATUS_COMPLETED, $this->workflow('done'), 'the workflow status is never touched');
    }

    // ---- the recipients ------------------------------------------------------------------

    public function test_typed_recipients_replace_the_approvers_and_are_trimmed_lower_cased_and_de_duplicated(): void
    {
        $out = $this->request(['i1'], ['recipients' => ['  Ann@Example.test', 'ann@example.test', 'cfo@example.test']]);

        self::assertSame(['ann@example.test', 'cfo@example.test'], $out['batch']['notified_emails']);
        self::assertSame(['ann@example.test', 'cfo@example.test'], $this->world->mail->transport->standalone[0]->to);
        self::assertSame('cfo@example.test will receive the email but cannot complete the request — they are not Approvers in TriCab Finance.', $out['warning']);
    }

    public function test_recipients_that_are_all_approvers_give_no_warning_and_a_null_recipients_means_everyone(): void
    {
        $out = $this->request(['i1'], ['recipients' => ['bob@example.test']]);
        self::assertNull($out['warning']);
        self::assertSame(['bob@example.test'], $out['batch']['notified_emails']);

        $out = $this->request(['i1'], ['recipients' => null]);
        self::assertSame(['ann@example.test', 'bob@example.test'], $out['batch']['notified_emails']);
    }

    public function test_nobody_notified_being_an_approver_is_the_source_warning(): void
    {
        $out = $this->request(['i1'], ['recipients' => ['x@example.test', 'y@example.test']]);

        self::assertSame('Nobody notified holds Approver access in TriCab Finance, so no one on this list can complete the request.', $out['warning']);
        self::assertSame(1, $this->sent(), 'the mail still goes out');
    }

    public function test_several_outsiders_are_named_with_commas(): void
    {
        $out = $this->request(['i1'], ['recipients' => ['ann@example.test', 'x@example.test', 'y@example.test']]);

        self::assertSame('x@example.test, y@example.test will receive the email but cannot complete the request — they are not Approvers in TriCab Finance.', $out['warning']);
    }

    public function test_with_no_approvers_and_nobody_typed_there_is_nobody_to_notify_and_nothing_is_created(): void
    {
        $this->world->directory->remove(21);
        $this->world->directory->remove(22);
        $message = 'No active Approvers are configured. Assign the Approver role to someone, or enter an address to notify.';

        foreach ([[], ['recipients' => []]] as $over) {
            $failure = self::failure(fn () => $this->request(['i1'], $over));
            self::assertInstanceOf(ApInvalid::class, $failure);
            self::assertSame($message, $failure->getMessage());
        }
        self::assertSame('Not requested', $this->approval('i1'));
        self::assertSame([], $this->world->service->list($this->preparer()));
        self::assertSame(0, $this->sent());

        $out = $this->request(['i1'], ['recipients' => ['cfo@example.test']]);
        self::assertStringStartsWith('Nobody notified holds Approver access', (string) $out['warning']);
    }

    public function test_an_empty_recipient_list_is_refused_even_with_approvers_and_nothing_is_created(): void
    {
        $failure = self::failure(fn () => $this->request(['i1'], ['recipients' => []]));

        self::assertSame('No active Approvers are configured. Assign the Approver role to someone, or enter an address to notify.', $failure->getMessage());
        self::assertSame('Not requested', $this->approval('i1'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function badRecipients(): array
    {
        return [
            'a string' => ['ann@example.test', 'Recipients must be a list of email addresses'],
            'an object' => [['a' => 'ann@example.test'], 'Recipients must be a list of email addresses'],
            'no dot in the domain' => [['missing@domain'], '“missing@domain” is not a valid email address'],
            'no at' => [['nobody'], '“nobody” is not a valid email address'],
            'a display name' => [['Ann <ann@example.test>'], '“Ann <ann@example.test>” is not a valid email address'],
            'blank' => [['  '], '“  ” is not a valid email address'],
            'null item' => [[null], '“” is not a valid email address'],
        ];
    }

    #[DataProvider('badRecipients')]
    public function test_a_bad_recipient_list_is_a_422_with_the_source_words_and_creates_nothing(mixed $recipients, string $message): void
    {
        $failure = self::failure(fn () => $this->request(['i1'], ['recipients' => $recipients]));

        self::assertInstanceOf(ApInvalid::class, $failure);
        self::assertSame($message, $failure->getMessage());
        self::assertSame('Not requested', $this->approval('i1'));
        self::assertSame(0, $this->sent());
    }

    public function test_twenty_addresses_are_allowed_and_twenty_one_are_not_counted_after_repeats_are_dropped(): void
    {
        $twenty = array_map(static fn (int $n): string => 'p' . $n . '@example.test', range(1, 20));
        $out = $this->request(['i1'], ['recipients' => [...$twenty, 'P1@example.test']]);
        $notified = $out['batch']['notified_emails'];
        self::assertIsArray($notified);
        self::assertCount(20, $notified);

        $failure = self::failure(fn () => $this->request(['i2'], ['recipients' => [...$twenty, 'p21@example.test']]));
        self::assertSame('An approval request cannot notify more than 20 addresses', $failure->getMessage());
        self::assertSame('Not requested', $this->approval('i2'));
    }

    // ---- sending off, failure, grant, idempotency ----------------------------------------------------------

    public function test_with_sending_off_the_batch_is_created_nothing_is_mailed_and_the_warning_says_so(): void
    {
        $world = new ApApprovalWorld(false);
        $this->seed($world);
        $this->world = $world;

        $out = $this->request();

        self::assertSame('Approval mail is switched off for Tri-AP, so no one was notified.', $out['warning']);
        self::assertSame('Pending', $out['batch']['status']);
        self::assertSame('Pending approval', $this->approval('i1'));
        self::assertSame([], $out['batch']['notified_emails']);
        self::assertSame([], $world->mail->transport->standalone);
        $id = $out['batch']['id'];
        self::assertIsString($id);
        self::assertNull($world->mail->ledger->findByIdempotencyKey('AP', 'ap-approval:' . $id . ':request'), 'the ledger saw nothing');
        self::assertSame([], $world->mail->outbound->of(ApOutboundTarget::approvalBatch($id), 10));
        self::assertSame('Approval mail is switched off for Tri-AP, so no one was notified.', $this->events($id)[0]->details['notification_warning']);
    }

    public function test_with_sending_off_the_decision_is_recorded_nothing_is_mailed_and_the_warning_says_so(): void
    {
        $world = new ApApprovalWorld(false);
        $this->seed($world);
        $this->world = $world;
        $id = $this->batchId();

        $out = $this->decide($id, 'reject');

        self::assertSame('Rejected', $out['status']);
        self::assertSame('Rejected', $this->approval('i1'));
        self::assertSame('Approval mail is switched off for Tri-AP, so no one was notified.', $out['notification_warning']);
        self::assertSame([], $world->mail->transport->standalone);
        self::assertSame('approval_rejected', $this->events($id)[1]->action);
    }

    public function test_a_mail_that_is_off_at_the_request_is_not_taken_for_the_request_mail_when_the_outcome_goes_out(): void
    {
        $world = new ApApprovalWorld(false);
        $this->seed($world);
        $this->world = $world;
        $id = $this->batchId();

        $this->world->service = $world->serviceFor(true);
        $out = $this->decide($id);

        self::assertNull($out['notification_warning']);
        self::assertSame(['dee@example.test'], $world->mail->transport->standalone[0]->to);
        self::assertSame([], $out['notified_emails'], 'notified_emails is the request mail only');
    }

    public function test_a_failed_request_mail_is_a_warning_not_an_error_and_the_batch_stands(): void
    {
        $this->world->mail->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        $out = $this->request();

        self::assertSame('Approval was created, but notification email failed: Microsoft Graph refused the message with status 500.', $out['warning']);
        self::assertSame('Pending', $out['batch']['status']);
        self::assertSame('Pending approval', $this->approval('i1'));
        self::assertSame(['ann@example.test', 'bob@example.test'], $out['batch']['notified_emails'], 'the failed message is on the history, with its recipients');
        $id = $out['batch']['id'];
        self::assertIsString($id);
        self::assertSame('approval_requested', $this->events($id)[0]->action);
        $recorded = $this->events($id)[0]->details['notification_warning'];
        self::assertIsString($recorded);
        self::assertStringContainsString('notification email failed', $recorded);
    }

    public function test_the_warnings_join_with_a_single_space(): void
    {
        $this->world->mail->transport->failWith = new FinanceMailTransportFailed('Down.');

        $out = $this->request(['i1'], ['recipients' => ['x@example.test']]);

        self::assertSame(
            'Nobody notified holds Approver access in TriCab Finance, so no one on this list can complete the request. Approval was created, but notification email failed: Down.',
            $out['warning'],
        );
    }

    public function test_a_mailbox_without_a_send_grant_is_a_warning_and_nothing_is_sent(): void
    {
        $world = new ApApprovalWorld(true, 'readonly@example.test');
        $this->seed($world);
        $this->world = $world;

        $out = $this->request();

        self::assertSame('Approval was created, but notification email failed: Tri-AP is not allowed to send from readonly@example.test. Ask a finance administrator to grant it that mailbox.', $out['warning']);
        self::assertSame([], $world->mail->transport->standalone);
        self::assertSame('Pending approval', $this->approval('i1'));
    }

    public function test_the_grant_refusal_on_the_outcome_is_a_notification_warning_and_the_decision_stands(): void
    {
        $world = new ApApprovalWorld(true, 'readonly@example.test');
        $this->seed($world);
        $this->world = $world;
        $id = $this->batchId();

        $out = $this->decide($id);

        self::assertSame('The decision was recorded, but the Preparer notification failed: Tri-AP is not allowed to send from readonly@example.test. Ask a finance administrator to grant it that mailbox.', $out['notification_warning']);
        self::assertSame('Approved', $out['status']);
        self::assertSame('Approved', $this->approval('i1'));
    }

    public function test_a_scoped_preparer_still_sends_the_mail_the_mailbox_is_the_systems_not_theirs(): void
    {
        $out = $this->request(['hand'], [], ApApprovalWorld::preparer(['melissa@example.test']));

        self::assertNull($out['warning']);
        self::assertSame('ap@example.test', $this->world->mail->transport->standalone[0]->mailbox);
    }

    public function test_the_same_mail_key_is_never_sent_twice_so_a_mail_already_sent_is_not_sent_again(): void
    {
        $id = $this->batchId();
        // The outcome mail went out already (a request that died after sending): the repeat finds its key and sends nothing.
        $this->world->mail->service->deliver(
            $this->preparer(),
            ApOutboundTarget::approvalBatch($id),
            new ApOutboundMail('ap@example.test', ['dee@example.test'], 'AP approval approved: 2 invoice(s)', '<p>x</p>', '<p>x</p>'),
            'ap-approval:' . $id . ':outcome',
        );
        self::assertSame(2, $this->sent());

        $out = $this->decide($id);

        self::assertNull($out['notification_warning'], 'a mail sent already is not a failure');
        self::assertSame(2, $this->sent());
    }

    // ---- the decision --------------------------------------------------------------------------------------

    public function test_any_approver_completes_a_request_and_their_identity_is_recorded(): void
    {
        $id = $this->batchId();

        $out = $this->decide($id, 'approve', ['comment' => 'Looks right']);

        self::assertSame('Approved', $out['status']);
        self::assertSame('21', $out['decided_by']);
        self::assertSame('2026-10-09T01:02:03.456789+00:00', $out['decided_at']);
        self::assertSame('Looks right', $out['decision_comment']);
        self::assertSame(['id' => '21', 'full_name' => 'Ann Approver', 'email' => 'ann@example.test'], $out['decided_by_user']);
        self::assertSame(['id' => '7', 'full_name' => 'Dee Preparer', 'email' => 'dee@example.test'], $out['prepared_by_user']);
        self::assertNull($out['notification_warning']);
        self::assertArrayHasKey('notification_warning', $out);
        $decisions = $out['decisions'];
        self::assertIsArray($decisions);
        self::assertCount(1, $decisions);
        $decision = $decisions[0];
        self::assertIsArray($decision);
        self::assertSame(['Approved', '21', 'Ann Approver', 'ann@example.test', 'Looks right', $id], [$decision['decision'], $decision['approver_id'], $decision['approver_name'], $decision['approver_email'], $decision['comment'], $decision['batch_id']]);
        $invoices = $out['invoices'];
        self::assertIsArray($invoices);
        self::assertSame(['Approved', 'Approved'], array_column($invoices, 'approval_status'));
        self::assertSame(ApInvoice::STATUS_IN_PROGRESS, $this->workflow('i1'));
        self::assertSame(['ann@example.test', 'bob@example.test'], $out['notified_emails']);
    }

    public function test_the_outcome_mail_goes_to_the_preparer_with_the_source_text(): void
    {
        $id = $this->batchId();

        $this->decide($id, 'approve', ['comment' => 'Looks <right> & fine']);

        self::assertSame(2, $this->sent());
        $message = $this->world->mail->transport->standalone[1];
        self::assertSame('ap@example.test', $message->mailbox);
        self::assertSame(['dee@example.test'], $message->to);
        self::assertSame('AP approval approved: 2 invoice(s)', $message->subject);
        $cell = "<td style='padding:6px;border-bottom:1px solid #ddd'>";
        $right = "<td style='padding:6px;border-bottom:1px solid #ddd;text-align:right'>";
        self::assertSame(
            '<p>Your AP approval request has been <strong>approved</strong>.</p>'
            . '<p>Decision by: Ann Approver</p>'
            . '<p>Comment: Looks &lt;right&gt; &amp; fine</p>'
            . "<table style='border-collapse:collapse;width:100%'><thead><tr><th align='left'>Vendor</th><th align='left'>Invoice</th><th align='right'>Total</th></tr></thead><tbody>"
            . '<tr>' . $cell . 'Acme Freight Pty Ltd</td>' . $cell . 'INV-1</td>' . $right . 'AUD 110.00</td></tr>'
            . '<tr>' . $cell . 'Zed &amp; Co &lt;Ltd&gt;</td>' . $cell . 'Z-9</td>' . $right . 'AUD 1,234.50</td></tr>'
            . '</tbody></table>'
            . "<p><a href='https://site.example.test/finance/ap/approvals/" . $id . "'>View the completed approval request in Invoice Flow</a></p>",
            $message->body,
        );
        self::assertSame('ap-approval:' . $id . ':outcome', $message->idempotencyKey);
        $senders = array_map(static fn ($link): ?int => $link->senderUserId, $this->world->mail->outbound->of(ApOutboundTarget::approvalBatch($id), 10));
        sort($senders);
        self::assertSame([7, 21], $senders, 'each mail is recorded against the batch with its sender');
    }

    public function test_a_rejection_mails_the_preparer_with_the_comment_and_audits_the_preparers_address(): void
    {
        $id = $this->batchId('i1');

        $out = $this->decide($id, 'reject', ['comment' => 'Wrong cost centre']);

        self::assertSame('Rejected', $out['status']);
        self::assertSame('Rejected', $this->approval('i1'));
        $message = $this->world->mail->transport->standalone[1];
        self::assertSame('AP approval rejected: 1 invoice(s)', $message->subject);
        self::assertStringContainsString('has been <strong>rejected</strong>', $message->body);
        self::assertStringContainsString('<p>Comment: Wrong cost centre</p>', $message->body);
        $events = $this->events($id);
        self::assertSame(['approval_requested', 'approval_rejected'], array_map(static fn (ApAuditEvent $e): string => $e->action, $events));
        self::assertSame(
            ['approver_name' => 'Ann Approver', 'approver_email' => 'Ann@Example.test', 'comment' => 'Wrong cost centre', 'preparer_email' => 'dee@example.test', 'notification_warning' => null],
            $events[1]->details,
        );
        self::assertSame(21, $events[1]->actorUserId);
        self::assertSame('approval_batch', $events[1]->entityType);
    }

    public function test_an_approval_is_audited_as_approval_approved(): void
    {
        $id = $this->batchId('i1');

        $this->decide($id, 'approve', ['comment' => null]);

        $events = $this->events($id);
        self::assertSame('approval_approved', $events[1]->action);
        self::assertNull($events[1]->details['comment'], 'the comment as sent');
    }

    public function test_no_comment_reads_as_an_empty_comment_and_the_mail_says_so(): void
    {
        $id = $this->batchId('i1');

        $out = $this->world->service->decide($this->approver(), $id, ['decision' => 'approve']);

        self::assertSame('', $out['decision_comment']);
        self::assertStringContainsString('<p>Comment: No comment was provided.</p>', $this->world->mail->transport->standalone[1]->body);
    }

    public function test_the_first_decision_wins_and_a_second_is_a_409_carrying_the_batch_and_sends_nothing(): void
    {
        $id = $this->batchId();
        $this->decide($id, 'approve', ['comment' => 'First']);
        $mails = $this->sent();
        $events = count($this->events($id));

        foreach (['reject', 'approve'] as $second) {
            $failure = self::failure(fn () => $this->decide($id, $second, ['comment' => 'Second'], ApApprovalWorld::approver(null, 22, 'Bob Approver', 'bob@example.test')));

            self::assertInstanceOf(ApConflict::class, $failure);
            self::assertSame(409, $failure->httpStatus());
            self::assertSame('This request was already approved', $failure->getMessage());
            $batch = $failure->extra['batch'] ?? null;
            self::assertIsArray($batch);
            self::assertSame($id, $batch['id']);
            self::assertSame('Approved', $batch['status']);
            self::assertSame('First', $batch['decision_comment']);
            self::assertSame('21', $batch['decided_by']);
        }
        self::assertSame($mails, $this->sent());
        self::assertCount($events, $this->events($id));
        self::assertSame('Approved', $this->approval('i1'));
        self::assertSame('Approved', $this->approval('i2'));
        $stored = $this->world->service->get($this->approver(), $id);
        self::assertIsArray($stored['decisions']);
        self::assertCount(1, $stored['decisions']);
    }

    public function test_a_second_decision_on_a_rejected_batch_says_rejected(): void
    {
        $id = $this->batchId('i1');
        $this->decide($id, 'reject');

        $failure = self::failure(fn () => $this->decide($id, 'approve'));

        self::assertSame('This request was already rejected', $failure->getMessage());
    }

    #[DataProvider('badDecisions')]
    public function test_the_decision_must_be_approve_or_reject(mixed $word): void
    {
        $id = $this->batchId('i1');

        $failure = self::failure(fn () => $this->world->service->decide($this->approver(), $id, ['decision' => $word]));

        self::assertInstanceOf(ApInvalid::class, $failure);
        self::assertSame('decision must be approve or reject', $failure->getMessage());
        self::assertSame('Pending approval', $this->approval('i1'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badDecisions(): array
    {
        return ['missing' => [null], 'approved' => ['approved'], 'capital' => ['Approve'], 'a number' => [1], 'a list' => [['approve']], 'blank' => ['']];
    }

    public function test_an_unknown_batch_is_a_404(): void
    {
        $failure = self::failure(fn () => $this->decide('nope'));

        self::assertInstanceOf(ApNotFound::class, $failure);
        self::assertSame('Not found', $failure->getMessage());
    }

    public function test_a_decision_on_a_batch_with_an_invoice_out_of_the_approvers_scope_is_a_404_and_changes_nothing(): void
    {
        $id = $this->batchId('i1', 'other');

        $failure = self::failure(fn () => $this->decide($id, 'approve', [], ApApprovalWorld::approver(['ap@example.test'])));

        self::assertInstanceOf(ApNotFound::class, $failure);
        self::assertSame('Not found', $failure->getMessage());
        self::assertSame('Pending approval', $this->approval('i1'));
        self::assertSame('Pending', $this->world->store->find($id)?->status);
        self::assertSame(1, $this->sent(), 'only the request went out');

        $out = $this->decide($id, 'approve', [], ApApprovalWorld::approver(['ap@example.test', 'melissa@example.test']));
        self::assertSame('Approved', $out['status']);
    }

    public function test_a_person_without_the_permission_is_refused_in_the_service_too(): void
    {
        $id = $this->batchId('i1');

        $decide = self::failure(fn () => $this->decide($id, 'approve', [], $this->preparer()));
        self::assertInstanceOf(ApForbidden::class, $decide);
        self::assertSame('Approver permission required', $decide->getMessage());

        $prepare = self::failure(fn () => $this->request(['i1'], [], $this->approver()));
        self::assertInstanceOf(ApForbidden::class, $prepare);
        self::assertSame('Preparer permission required', $prepare->getMessage());
        self::assertSame('Pending', $this->world->store->find($id)?->status);
    }

    public function test_a_preparer_with_no_address_gets_the_source_warning_and_the_decision_stands(): void
    {
        $id = $this->batchId('i1');
        $blank = $this->request(['i2'], [], ApApprovalWorld::preparer(null, ''));
        $blankId = $blank['batch']['id'];
        self::assertIsString($blankId);

        $out = $this->decide($blankId);

        self::assertSame('The decision was recorded, but the original Preparer has no email address.', $out['notification_warning']);
        self::assertSame('Approved', $out['status']);
        self::assertSame(2, $this->sent(), 'the first batch\'s request and the second\'s: no outcome mail');
        self::assertSame('Pending', $this->world->store->find($id)?->status);
    }

    public function test_a_failed_outcome_mail_is_a_notification_warning_the_decision_stands_and_the_history_has_the_failure(): void
    {
        $id = $this->batchId('i1');
        $this->world->mail->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        $out = $this->decide($id);

        self::assertSame('The decision was recorded, but the Preparer notification failed: Microsoft Graph refused the message with status 500.', $out['notification_warning']);
        self::assertSame('Approved', $out['status']);
        self::assertSame('Approved', $this->approval('i1'));
        self::assertSame('Approved', $this->world->store->find($id)?->status);
        self::assertCount(2, $this->world->mail->outbound->of(ApOutboundTarget::approvalBatch($id), 10), 'a Failed link is kept');
        $recorded = $this->events($id)[1]->details['notification_warning'];
        self::assertIsString($recorded);
        self::assertStringContainsString('notification failed', $recorded);
        self::assertSame(['ann@example.test', 'bob@example.test'], $out['notified_emails']);
    }

    public function test_the_same_person_may_prepare_and_decide_the_same_batch(): void
    {
        $admin = ApApprovalWorld::administrator();
        $out = $this->request(['i1'], [], $admin);
        $id = $out['batch']['id'];
        self::assertIsString($id);

        $decided = $this->decide($id, 'approve', [], $admin);

        self::assertSame('Approved', $decided['status']);
        self::assertSame('31', $decided['prepared_by']);
        self::assertSame('31', $decided['decided_by']);
        self::assertSame(['ada@example.test'], $this->world->mail->transport->standalone[1]->to);
        self::assertNull($decided['notification_warning']);
    }

    public function test_a_note_and_names_with_markup_are_escaped_in_the_mail(): void
    {
        $this->request(['i1'], ['note' => '<b>Urgent</b> & "now" it\'s due'], ApApprovalWorld::preparer(null, 'Dee@Example.test'));

        self::assertStringContainsString('<p>&lt;b&gt;Urgent&lt;/b&gt; &amp; &quot;now&quot; it&#x27;s due</p>', $this->world->mail->transport->standalone[0]->body);
    }

    // ---- reading -------------------------------------------------------------------------------------------

    public function test_the_list_is_newest_first_and_each_batch_has_its_people_decision_and_recipients(): void
    {
        $first = $this->batchId('i1');
        $this->world->mail->register->clock->advance('+1 minute');
        $second = $this->batchId('i2');
        $this->decide($first);

        $list = $this->world->service->list($this->preparer());

        self::assertSame([$second, $first], array_column($list, 'id'));
        self::assertSame(['Pending', 'Approved'], array_column($list, 'status'));
        self::assertSame(['ann@example.test', 'bob@example.test'], $list[1]['notified_emails']);
        $decidedBy = $list[1]['decided_by_user'];
        self::assertIsArray($decidedBy);
        self::assertSame('Ann Approver', $decidedBy['full_name']);
        self::assertNull($list[0]['decided_by_user']);
        self::assertSame($list[1], $this->world->service->get($this->preparer(), $first));
    }

    public function test_a_batch_is_hidden_from_a_person_who_cannot_see_all_of_its_invoices(): void
    {
        $visible = $this->batchId('i1', 'hand');
        $hidden = $this->batchId('i1', 'other');
        $scoped = ApApprovalWorld::preparer(['ap@example.test']);

        self::assertSame([$visible], array_column($this->world->service->list($scoped), 'id'));
        self::assertEqualsCanonicalizing([$visible, $hidden], array_column($this->world->service->list($this->preparer()), 'id'));
        self::assertSame($visible, $this->world->service->get($scoped, $visible)['id']);

        $failure = self::failure(fn () => $this->world->service->get($scoped, $hidden));
        self::assertInstanceOf(ApNotFound::class, $failure);
        self::assertSame('Not found', $failure->getMessage());
        self::assertSame('Not found', self::failure(fn () => $this->world->service->get($scoped, 'nope'))->getMessage());
    }

    public function test_a_batch_with_a_binned_invoice_still_shows_it(): void
    {
        $id = $this->batchId('binned');

        $batch = $this->world->service->get($this->preparer(), $id);

        $invoices = $batch['invoices'];
        self::assertIsArray($invoices);
        self::assertSame(['binned'], array_column($invoices, 'id'));
        self::assertIsArray($invoices[0]);
        self::assertSame('dee@example.test', $invoices[0]['deleted_by']);
    }

    public function test_a_batch_whose_invoices_were_all_deleted_shows_no_invoices_and_is_visible_to_everyone(): void
    {
        $id = $this->batchId('i1');
        $this->world->mail->register->invoices->purge('i1', null, null, ApInvoiceFixtures::utc('2026-10-09 00:00:00'));

        $batch = $this->world->service->get(ApApprovalWorld::preparer(['ap@example.test']), $id);

        self::assertSame([], $batch['invoices']);
        self::assertSame($id, $this->world->service->list(ApApprovalWorld::preparer(['ap@example.test']))[0]['id']);
    }

    public function test_the_list_shows_at_most_200_batches(): void
    {
        for ($n = 0; $n < 3; ++$n) {
            $this->batchId('i1');
            $this->world->mail->register->clock->advance('+1 second');
        }
        self::assertCount(3, $this->world->service->list($this->preparer()));
        self::assertSame(200, \TriCab\Core\Finance\Ap\Service\ApApprovalService::LIST_LIMIT);
        self::assertCount(2, $this->world->store->recent(2, null));
    }

    public function test_the_permission_constants_the_routes_use(): void
    {
        self::assertSame('FINANCE.AP.APPROVALS.PREPARE', Module::PERMISSION_AP_APPROVALS_PREPARE);
        self::assertSame('FINANCE.AP.APPROVALS.DECIDE', Module::PERMISSION_AP_APPROVALS_DECIDE);
    }
}
