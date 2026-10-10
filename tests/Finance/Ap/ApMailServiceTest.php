<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApOutboundMessage;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;
use TriCab\Core\Finance\Ap\Domain\ApUnavailable;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Ap\Service\ApMailOutcome;
use TriCab\Core\Finance\Ap\Service\ApMailService;
use TriCab\Core\Finance\Ap\Service\ApOutboundMail;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * The composer (T6.10) on the in-memory stores and a transport that sends nothing: the mailboxes a person may send from, the
 * history, the vendor email and the reply with their address rules, attachments (the invoice document counts towards the 3 MB),
 * threading conditions, idempotency, the sending switch, the failed and suppressed outcomes that must never read as sent, the
 * audit events, and the `deliver` method the approvals task calls. No mail leaves the process.
 */
final class ApMailServiceTest extends TestCase
{
    private ApMailWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApMailWorld();
        $this->world->invoice('i1', '%PDF-1.4 the invoice', attachment: 'Acme INV-1.pdf', vendorEmail: 'billing@vendor.example.test');
        $this->world->email('e1', graphMessageId: 'graph-1');
    }

    private function actor(): ApActor
    {
        return $this->world->actor();
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function vendor(array $over = [], string $invoice = 'i1'): array
    {
        return $this->world->service->sendVendorEmail($this->actor(), $invoice, $over + ['to' => 'Billing@Vendor.example.test', 'subject' => 'Question', 'body' => 'Where is INV-1?']);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function reply(array $over = [], string $email = 'e1'): array
    {
        return $this->world->service->sendReply($this->actor(), $email, $over + ['to' => 'accounts@acme.example.test', 'subject' => 'Re: Where is our payment?', 'body' => 'It is in the next run.']);
    }

    /**
     * @return array{name: string, content_type: string, data: string}
     */
    private static function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_type' => 'text/plain', 'data' => base64_encode($content)];
    }

    // ---- send mailboxes ----------------------------------------------------------------

    public function test_the_send_mailboxes_are_the_granted_active_ones_with_the_configured_one_first(): void
    {
        $out = $this->world->service->sendMailboxes($this->actor());

        self::assertSame('ap@example.test', $out['default'], 'the configured mailbox is lower-cased');
        self::assertSame([
            ['email' => 'ap@example.test', 'label' => 'Accounts Payable'],
            ['email' => 'melissa@example.test', 'label' => 'melissa@example.test'],
        ], $out['mailboxes'], 'a read-only grant, an inactive mailbox and another app\'s grant are left out; a nameless one is labelled by its address');
    }

    public function test_the_send_mailboxes_are_limited_to_the_persons_scope(): void
    {
        $out = $this->world->service->sendMailboxes($this->world->actor(['melissa@example.test']));

        self::assertSame([['email' => 'melissa@example.test', 'label' => 'melissa@example.test']], $out['mailboxes']);
        self::assertSame('ap@example.test', $out['default']);
    }

    // ---- the sending switch ------------------------------------------------------------

    public function test_with_sending_off_both_sends_are_refused_before_anything_is_read_or_written(): void
    {
        $service = $this->world->serviceFor(false);

        foreach ([
            fn () => $service->sendVendorEmail($this->actor(), 'i1', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b']),
            fn () => $service->sendReply($this->actor(), 'e1', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b']),
            fn () => $service->sendVendorEmail($this->actor(), 'ghost', []),
        ] as $send) {
            try {
                $send();
                self::fail('Expected the switch to refuse.');
            } catch (ApUnavailable $off) {
                self::assertSame('Sending is switched off', $off->getMessage());
                self::assertSame(503, $off->httpStatus());
            }
        }
        self::assertSame([], $this->world->ledger->all());
        self::assertSame([], $this->world->outbound->of(ApOutboundTarget::invoice('i1'), 10));
        self::assertSame([], $this->world->transport->standalone);
        self::assertSame([], $this->world->transport->replies);
        self::assertFalse($service->sendingEnabled());
        // Reading is not blocked.
        self::assertSame([], $service->invoiceHistory($this->actor(), 'i1'));
        self::assertSame('ap@example.test', $service->sendMailboxes($this->actor())['default']);
    }

    // ---- vendor email ------------------------------------------------------------------

    public function test_a_vendor_email_goes_out_as_the_sources_html_and_answers_the_outbound_row(): void
    {
        $row = $this->vendor(['cc' => 'Nick@TriCab.com; nick@tricab.com, ops@example.test billing@vendor.example.test', 'body' => "Hi <b>there</b> & \"co\" it's\nline two"]);

        self::assertCount(1, $this->world->transport->standalone);
        $sent = $this->world->transport->standalone[0];
        self::assertSame('ap@example.test', $sent->mailbox, 'the default from is the configured mailbox');
        self::assertSame(['billing@vendor.example.test'], $sent->to);
        self::assertSame(['nick@tricab.com', 'ops@example.test'], $sent->cc, 'cc is lower-cased, de-duplicated, and drops the recipient');
        self::assertSame('<div style="font-family:Arial,sans-serif;white-space:pre-wrap">Hi &lt;b&gt;there&lt;/b&gt; &amp; &quot;co&quot; it&#x27;s' . "\n" . 'line two</div>', $sent->body);
        self::assertSame('AP', $sent->application);
        self::assertSame(7, $sent->actorUserId);
        self::assertSame([], $sent->attachments);

        self::assertSame(
            ['id', 'invoice_id', 'email_id', 'approval_batch_id', 'sender_user_id', 'from_mailbox', 'to_addresses', 'cc_addresses', 'subject', 'body', 'attach_invoice', 'graph_message_id', 'status', 'error', 'sent_at', 'created_date', 'updated_date'],
            array_keys($row),
        );
        self::assertSame(['i1', null, null, '7', 'ap@example.test', ['billing@vendor.example.test'], ['nick@tricab.com', 'ops@example.test'], 'Question'], [$row['invoice_id'], $row['email_id'], $row['approval_batch_id'], $row['sender_user_id'], $row['from_mailbox'], $row['to_addresses'], $row['cc_addresses'], $row['subject']]);
        self::assertSame("Hi <b>there</b> & \"co\" it's\nline two", $row['body'], 'the stored body is the plain text as typed');
        self::assertSame([false, null, 'Sent', null, '2026-10-09T02:03:04.000000+00:00'], [$row['attach_invoice'], $row['graph_message_id'], $row['status'], $row['error'], $row['sent_at']]);
        self::assertArrayNotHasKey('threaded', $row);
    }

    public function test_the_vendor_email_writes_its_audit_event_after_success_only(): void
    {
        $this->vendor(['cc' => ['x@example.test'], 'attachments' => [self::file('note.txt', 'hello')]]);

        $events = $this->world->register->audit->forEntity('invoice', 'i1');
        self::assertCount(1, $events);
        self::assertSame('vendor_email_sent', $events[0]->action);
        self::assertSame(['to' => 'billing@vendor.example.test', 'cc' => ['x@example.test'], 'from' => 'ap@example.test', 'subject' => 'Question', 'attached' => false, 'files' => ['note.txt']], $events[0]->details);
        self::assertSame([7, 'Dee Preparer'], [$events[0]->actorUserId, $events[0]->actorName]);
    }

    public function test_attach_invoice_sends_the_document_first_and_records_the_flag(): void
    {
        $row = $this->vendor(['attach_invoice' => true, 'attachments' => [self::file('extra.txt', 'more')]]);

        $files = $this->world->transport->standalone[0]->attachments;
        self::assertSame(['Acme INV-1.pdf', 'extra.txt'], array_map(static fn ($f): string => $f->name, $files));
        self::assertSame('%PDF-1.4 the invoice', $files[0]->content);
        self::assertSame('application/pdf', $files[0]->contentType);
        self::assertTrue($row['attach_invoice']);
        $event = $this->world->register->audit->forEntity('invoice', 'i1')[0];
        self::assertSame([true, ['extra.txt']], [$event->details['attached'], $event->details['files']], 'the files are the typed ones');
    }

    public function test_attach_invoice_with_no_document_is_a_422_and_nothing_is_written(): void
    {
        $this->world->invoice('i2');

        try {
            $this->vendor(['attach_invoice' => 'true'], 'i2');
            self::fail('Expected a refusal.');
        } catch (ApInvalid $invalid) {
            self::assertSame('The invoice attachment could not be found', $invalid->getMessage());
            self::assertSame(422, $invalid->httpStatus());
        }
        self::assertSame([], $this->world->ledger->all());
        self::assertSame([], $this->world->transport->standalone);
    }

    public function test_attach_invoice_text_for_no_is_read_as_no(): void
    {
        $this->world->invoice('i2');

        $this->vendor(['attach_invoice' => 'false'], 'i2');

        self::assertSame([], $this->world->transport->standalone[0]->attachments);
    }

    public function test_the_invoice_document_counts_towards_the_three_megabyte_limit(): void
    {
        $big = str_repeat('x', 3 * 1024 * 1024 - 100);
        $this->world->invoice('i2', str_repeat('p', 200), attachment: 'big.pdf');

        // The typed files alone fit; with the document they do not (fault R9).
        $this->vendor(['attachments' => [self::file('a.bin', $big)]], 'i2');
        self::assertCount(1, $this->world->transport->standalone);

        try {
            $this->vendor(['attach_invoice' => true, 'attachments' => [self::file('a.bin', $big)]], 'i2');
            self::fail('Expected the total to be refused.');
        } catch (ApInvalid $invalid) {
            self::assertSame('Attachments come to more than 3 MB in total. Send a link instead, or attach fewer files.', $invalid->getMessage());
        }
        self::assertCount(1, $this->world->transport->standalone, 'nothing more was sent');
    }

    public function test_exactly_three_megabytes_is_accepted_and_one_byte_more_is_not(): void
    {
        $this->vendor(['attachments' => [self::file('exact.bin', str_repeat('x', 3 * 1024 * 1024))]]);
        self::assertCount(1, $this->world->transport->standalone);

        $this->expectException(ApInvalid::class);
        $this->expectExceptionMessage('Attachments come to more than 3 MB in total. Send a link instead, or attach fewer files.');
        $this->vendor(['attachments' => [self::file('over.bin', str_repeat('x', 3 * 1024 * 1024 + 1))]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'no recipient' => [['to' => ''], 'A valid vendor email is required'];
        yield 'no at sign' => [['to' => 'vendor'], 'A valid vendor email is required'];
        yield 'a list for the recipient' => [['to' => ['a@b.example.test']], 'A valid vendor email is required'];
        yield 'a recipient with a name' => [['to' => 'Vera <v@b.example.test>'], 'A valid vendor email is required'];
        yield 'one bad cc' => [['cc' => 'ok@example.test bad'], 'Invalid CC address: bad'];
        yield 'two bad cc in order' => [['cc' => ['zed', 'ok@example.test', 'a@b']], 'Invalid CC address: zed, a@b'];
        yield 'no subject' => [['subject' => '  '], 'Subject and message are required'];
        yield 'no body' => [['body' => " \n"], 'Subject and message are required'];
        yield 'attachments not a list' => [['attachments' => ['name' => 'x']], 'Attachments must be a list'];
        yield 'attachment not an object' => [['attachments' => ['text']], 'Each attachment must be an object'];
        yield 'attachment without a name' => [['attachments' => [['data' => 'aGk=']]], 'Every attachment needs a file name'];
        yield 'attachment with a path as its name' => [['attachments' => [['name' => '../x.txt', 'data' => 'aGk=']]], '../x.txt is not a usable file name'];
        yield 'attachment not base64' => [['attachments' => [['name' => 'a.txt', 'data' => 'not base64!']]], 'a.txt could not be read'];
        yield 'base64 with a newline' => [['attachments' => [['name' => 'a.txt', 'data' => "aGk=\n"]]], 'a.txt could not be read'];
        yield 'empty attachment' => [['attachments' => [['name' => 'a.txt', 'data' => '']]], 'a.txt is empty'];
        yield 'eleven attachments' => [['attachments' => array_fill(0, 11, ['name' => 'a.txt', 'data' => 'aGk='])], 'Attach at most 10 files'];
        yield 'bad idempotency key' => [['idempotency_key' => ['x']], 'idempotency_key must be text of at most 100 characters'];
        yield 'long idempotency key' => [['idempotency_key' => str_repeat('k', 101)], 'idempotency_key must be text of at most 100 characters'];
    }

    /**
     * @param array<string, mixed> $over
     */
    #[DataProvider('refusals')]
    public function test_every_refusal_is_a_422_with_the_sources_words_and_sends_nothing(array $over, string $message): void
    {
        try {
            $this->vendor($over);
            self::fail('Expected a refusal.');
        } catch (ApInvalid $invalid) {
            self::assertSame($message, $invalid->getMessage());
            self::assertSame('FINANCE_AP_INPUT_INVALID', $invalid->errorCode());
        }
        self::assertSame([], $this->world->ledger->all());
        self::assertSame([], $this->world->transport->standalone);
        self::assertSame([], $this->world->register->audit->forEntity('invoice', 'i1'));
    }

    public function test_the_recipient_is_checked_before_the_cc_and_the_cc_before_the_subject_and_the_sender(): void
    {
        $all = ['to' => 'nope', 'cc' => 'bad', 'subject' => '', 'from' => 'nobody@example.test'];
        $steps = [
            [$all, ApInvalid::class, 'A valid vendor email is required'],
            [['to' => 'a@b.example.test'] + $all, ApInvalid::class, 'Invalid CC address: bad'],
            [['to' => 'a@b.example.test', 'cc' => ''] + $all, ApInvalid::class, 'Subject and message are required'],
            [['to' => 'a@b.example.test', 'cc' => '', 'subject' => 's'] + $all, ApForbidden::class, 'Tri-AP is not allowed to send from nobody@example.test. Ask a finance administrator to grant it that mailbox.'],
        ];
        foreach ($steps as [$over, $class, $message]) {
            try {
                $this->vendor($over);
                self::fail('Expected ' . $class);
            } catch (ApInvalid | ApForbidden $refusal) {
                self::assertInstanceOf($class, $refusal);
                self::assertSame($message, $refusal->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mailboxesItMayNotSendFrom(): iterable
    {
        yield 'a read-only grant' => ['readonly@example.test'];
        yield 'an inactive mailbox' => ['old@example.test'];
        yield 'another app\'s grant' => ['task@example.test'];
        yield 'an unknown address' => ['nobody@example.test'];
    }

    #[DataProvider('mailboxesItMayNotSendFrom')]
    public function test_a_mailbox_without_a_send_grant_is_a_403_and_nothing_is_sent(string $from): void
    {
        foreach ([fn () => $this->vendor(['from' => strtoupper($from)]), fn () => $this->reply(['from' => $from])] as $send) {
            try {
                $send();
                self::fail('Expected a refusal.');
            } catch (ApForbidden $forbidden) {
                self::assertSame('Tri-AP is not allowed to send from ' . $from . '. Ask a finance administrator to grant it that mailbox.', $forbidden->getMessage());
                self::assertSame(403, $forbidden->httpStatus());
            }
        }
        self::assertSame([], $this->world->ledger->all());
        self::assertSame([], $this->world->transport->standalone);
    }

    public function test_a_mailbox_outside_the_persons_scope_is_refused_even_with_a_grant(): void
    {
        $this->world->invoice('i3', mailbox: 'melissa@example.test');
        $actor = $this->world->actor(['melissa@example.test']);

        try {
            $this->world->service->sendVendorEmail($actor, 'i3', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b', 'from' => 'ap@example.test']);
            self::fail('Expected a refusal.');
        } catch (ApForbidden) {
        }
        // The default from is the configured mailbox, which this person may not use either.
        try {
            $this->world->service->sendVendorEmail($actor, 'i3', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b']);
            self::fail('Expected a refusal.');
        } catch (ApForbidden) {
        }

        $this->world->service->sendVendorEmail($actor, 'i3', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b', 'from' => 'melissa@example.test']);
        self::assertSame('melissa@example.test', $this->world->transport->standalone[0]->mailbox);
    }

    public function test_an_invoice_out_of_scope_or_missing_is_not_found_and_a_hand_capture_is_visible_to_everyone(): void
    {
        $this->world->invoice('hand', null, mailbox: null);
        $scoped = $this->world->actor(['melissa@example.test']);

        foreach ([['ghost', $this->actor()], ['i1', $scoped]] as [$id, $actor]) {
            try {
                $this->world->service->sendVendorEmail($actor, $id, ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b']);
                self::fail('Expected not found.');
            } catch (ApNotFound $missing) {
                self::assertSame('Not found', $missing->getMessage());
            }
            $this->expectNotFoundOf(fn () => $this->world->service->invoiceHistory($actor, $id));
        }
        $this->world->service->sendVendorEmail($scoped, 'hand', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b', 'from' => 'melissa@example.test']);
        self::assertCount(1, $this->world->transport->standalone);
    }

    private function expectNotFoundOf(callable $call): void
    {
        try {
            $call();
            self::fail('Expected not found.');
        } catch (ApNotFound) {
            self::addToAssertionCount(1);
        }
    }

    public function test_a_binned_invoice_can_still_be_mailed(): void
    {
        $this->world->invoice('bin', null, deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00'), deletedBy: 'x');

        $this->vendor([], 'bin');

        self::assertCount(1, $this->world->transport->standalone);
    }

    // ---- reply -------------------------------------------------------------------------

    public function test_a_reply_to_the_sender_from_the_emails_mailbox_is_threaded_and_audited(): void
    {
        $row = $this->reply(['cc' => 'boss@example.test']);

        self::assertCount(1, $this->world->transport->replies);
        self::assertSame([], $this->world->transport->standalone);
        self::assertSame('graph-1', $this->world->transport->replies[0]['replyTo']);
        self::assertTrue($row['threaded']);
        self::assertSame(['e1', null, 'Sent', 'Re: Where is our payment?', ['boss@example.test']], [$row['email_id'], $row['invoice_id'], $row['status'], $row['subject'], $row['cc_addresses']]);
        self::assertSame(['accounts@acme.example.test'], $row['to_addresses']);
        self::assertSame('ap@example.test', $row['from_mailbox'], 'the default from is the email\'s own mailbox');

        $event = $this->world->register->audit->forEntity('email', 'e1')[0];
        self::assertSame('email_reply_sent', $event->action);
        self::assertSame(['to' => 'accounts@acme.example.test', 'cc' => ['boss@example.test'], 'subject' => 'Re: Where is our payment?', 'threaded' => true], $event->details);
    }

    public function test_a_typed_in_recipient_other_than_the_sender_is_not_threaded(): void
    {
        $row = $this->reply(['to' => 'someone.else@acme.example.test']);

        self::assertSame([], $this->world->transport->replies, 'Graph would send a threaded reply to the original sender and ignore this');
        self::assertCount(1, $this->world->transport->standalone);
        self::assertSame(['someone.else@acme.example.test'], $this->world->transport->standalone[0]->to);
        self::assertFalse($row['threaded']);
        self::assertFalse($this->world->register->audit->forEntity('email', 'e1')[0]->details['threaded']);
    }

    public function test_another_from_mailbox_is_standalone_with_its_attachment(): void
    {
        $row = $this->reply(['from' => 'melissa@example.test', 'attachments' => [self::file('r.txt', 'remit')]]);

        self::assertSame([], $this->world->transport->replies, 'a Graph id belongs to the mailbox it was read from');
        $sent = $this->world->transport->standalone[0];
        self::assertSame(['melissa@example.test', ['r.txt']], [$sent->mailbox, array_map(static fn ($f): string => $f->name, $sent->attachments)]);
        self::assertFalse($row['threaded']);
    }

    public function test_when_the_transport_falls_back_the_answer_says_it_was_not_threaded(): void
    {
        $this->world->transport->threads = false;

        $row = $this->reply();

        self::assertCount(1, $this->world->transport->replies);
        self::assertFalse($row['threaded']);
        self::assertSame('Sent', $row['status']);
    }

    public function test_an_email_with_no_graph_id_is_never_threaded(): void
    {
        $this->world->email('e2', graphMessageId: '');

        $this->reply([], 'e2');

        self::assertSame([], $this->world->transport->replies);
        self::assertCount(1, $this->world->transport->standalone);
    }

    public function test_the_reply_needs_a_typed_recipient_and_gets_no_automatic_re_prefix(): void
    {
        try {
            $this->reply(['to' => '']);
            self::fail('Expected a refusal.');
        } catch (ApInvalid $invalid) {
            self::assertSame('A valid recipient is required', $invalid->getMessage());
        }
        $this->reply(['subject' => 'Plain subject']);
        self::assertSame('Plain subject', $this->world->transport->replies[0]['message']->subject);
    }

    public function test_an_email_out_of_scope_or_missing_is_not_found_for_the_reply_the_history_and_the_scope_ignores_case(): void
    {
        $this->world->email('e3', mailbox: 'Melissa@Example.test');
        $scoped = $this->world->actor(['melissa@example.test']);

        $this->expectNotFoundOf(fn () => $this->world->service->sendReply($scoped, 'e1', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b']));
        $this->expectNotFoundOf(fn () => $this->world->service->sendReply($this->actor(), 'ghost', []));
        $this->expectNotFoundOf(fn () => $this->world->service->emailHistory($scoped, 'e1'));

        // Fault R11: a capital in the stored mailbox does not hide the email from a scoped person.
        self::assertSame([], $this->world->service->emailHistory($scoped, 'e3'));
        $this->world->service->sendReply($scoped, 'e3', ['to' => 'accounts@acme.example.test', 'subject' => 's', 'body' => 'b', 'from' => 'melissa@example.test']);
        self::assertCount(1, $this->world->transport->replies, 'the lower-case from is the email\'s own mailbox in any case');
    }

    // ---- idempotency, failure, suppression ---------------------------------------------

    public function test_the_same_key_twice_is_one_send_one_link_and_one_audit_event(): void
    {
        $first = $this->vendor(['idempotency_key' => 'click-1']);
        $second = $this->vendor(['idempotency_key' => 'click-1']);

        self::assertCount(1, $this->world->transport->standalone);
        self::assertCount(1, $this->world->ledger->all());
        self::assertSame($first['id'], $second['id']);
        self::assertCount(1, $this->world->outbound->of(ApOutboundTarget::invoice('i1'), 10));
        self::assertCount(1, $this->world->register->audit->forEntity('invoice', 'i1'));

        $this->reply(['idempotency_key' => 'click-1']);
        $this->reply(['idempotency_key' => 'click-1']);
        self::assertCount(1, $this->world->transport->replies, 'the key is scoped to the record and the kind of mail');
        self::assertCount(1, $this->world->transport->standalone);
    }

    public function test_without_a_key_each_request_is_its_own_send(): void
    {
        $this->vendor();
        $this->vendor();

        self::assertCount(2, $this->world->transport->standalone);
        $keys = array_map(static fn ($d): ?string => $d->idempotencyKey, $this->world->ledger->all());
        self::assertCount(2, array_unique($keys));
        self::assertStringStartsWith('ap-vendor:', (string) $keys[0]);
    }

    public function test_a_failed_send_is_a_502_never_sent_and_the_history_reads_failed(): void
    {
        $this->world->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        try {
            $this->vendor();
            self::fail('Expected a failure.');
        } catch (ApUpstream $failed) {
            self::assertSame('Failed to send email: Microsoft Graph refused the message with status 500.', $failed->getMessage());
            self::assertSame(502, $failed->httpStatus());
            self::assertSame('send', $failed->extra['reason']);
        }
        self::assertSame([], $this->world->register->audit->forEntity('invoice', 'i1'), 'no audit event for a failure');

        $history = $this->world->service->invoiceHistory($this->actor(), 'i1');
        self::assertCount(1, $history);
        self::assertSame(['Failed', 'Microsoft Graph refused the message with status 500.', null], [$history[0]['status'], $history[0]['error'], $history[0]['sent_at']]);

        $this->world->transport->failWith = new FinanceMailTransportFailed('Down.');
        try {
            $this->reply();
            self::fail('Expected a failure.');
        } catch (ApUpstream) {
        }
        self::assertSame('Failed', $this->world->service->emailHistory($this->actor(), 'e1')[0]['status']);
        self::assertSame([], $this->world->register->audit->forEntity('email', 'e1'));
    }

    public function test_a_key_that_failed_cannot_be_reused_and_is_a_409(): void
    {
        $this->world->transport->failWith = new FinanceMailTransportFailed('Down.');
        try {
            $this->vendor(['idempotency_key' => 'k']);
        } catch (ApUpstream) {
        }
        $this->world->transport->failWith = null;

        try {
            $this->vendor(['idempotency_key' => 'k']);
            self::fail('Expected a conflict.');
        } catch (ApConflict $conflict) {
            self::assertStringContainsString('Retry under a new key', $conflict->getMessage());
            self::assertSame(409, $conflict->httpStatus());
        }
        self::assertSame([], $this->world->transport->standalone);

        $this->vendor(['idempotency_key' => 'k2']);
        self::assertCount(1, $this->world->transport->standalone);
    }

    public function test_with_mail_switched_off_the_send_is_suppressed_reported_failed_and_not_audited(): void
    {
        $service = $this->world->serviceFor(true, new DisabledFinanceMailTransport());

        try {
            $service->sendVendorEmail($this->actor(), 'i1', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b']);
            self::fail('Expected a failure.');
        } catch (ApUpstream $off) {
            self::assertSame('Failed to send email: Mail is switched off', $off->getMessage());
        }
        try {
            $service->sendReply($this->actor(), 'e1', ['to' => 'accounts@acme.example.test', 'subject' => 's', 'body' => 'b']);
            self::fail('Expected a failure.');
        } catch (ApUpstream) {
        }

        $history = $service->invoiceHistory($this->actor(), 'i1');
        self::assertSame(['Failed', 'Mail is switched off', null], [$history[0]['status'], $history[0]['error'], $history[0]['sent_at']]);
        self::assertSame('suppressed', $this->world->ledger->all()[0]->status);
        self::assertSame([], $this->world->register->audit->forEntity('invoice', 'i1'));
        self::assertSame([], $this->world->register->audit->forEntity('email', 'e1'));
    }

    // ---- history -----------------------------------------------------------------------

    public function test_history_is_newest_first_limited_and_its_own(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->world->register->clock->advance('+1 minute');
            $this->vendor(['subject' => 'Mail ' . $i]);
        }
        $this->world->invoice('i2');
        $this->vendor(['subject' => 'Other invoice'], 'i2');
        $this->reply();

        $rows = $this->world->service->invoiceHistory($this->actor(), 'i1');

        self::assertSame(['Mail 3', 'Mail 2', 'Mail 1'], array_column($rows, 'subject'), 'ordered by created date, newest first');
        self::assertSame(['Other invoice'], array_column($this->world->service->invoiceHistory($this->actor(), 'i2'), 'subject'));
        self::assertSame(['Re: Where is our payment?'], array_column($this->world->service->emailHistory($this->actor(), 'e1'), 'subject'));
        self::assertSame('Sent', $rows[0]['status']);
    }

    public function test_the_histories_stop_at_100_and_50_rows(): void
    {
        $delivery = $this->vendor();
        $deliveryId = $this->world->outbound->of(ApOutboundTarget::invoice('i1'), 1)[0]->deliveryId;
        self::assertSame('Sent', $delivery['status']);
        for ($i = 0; $i < 120; $i++) {
            $this->world->register->clock->advance('+1 second');
            $this->world->outbound->add(new ApOutboundMessage('x' . $i, ApOutboundTarget::invoice('i1'), $deliveryId, 7, 'b', false, $this->world->register->clock->now()));
            $this->world->outbound->add(new ApOutboundMessage('y' . $i, ApOutboundTarget::email('e1'), $deliveryId, 7, 'b', false, $this->world->register->clock->now()));
        }

        self::assertCount(100, $this->world->service->invoiceHistory($this->actor(), 'i1'));
        self::assertCount(50, $this->world->service->emailHistory($this->actor(), 'e1'));
    }

    public function test_a_row_whose_ledger_entry_is_gone_is_left_out_and_pending_reads_sending(): void
    {
        $this->world->outbound->add(new ApOutboundMessage('gone', ApOutboundTarget::invoice('i1'), 'no-such-delivery', 7, 'b', false, $this->world->register->clock->now()));
        $this->vendor();

        self::assertCount(1, $this->world->service->invoiceHistory($this->actor(), 'i1'));

        $this->world->register->clock->advance('+1 minute');
        $pending = new FinanceMailDelivery('p1', 'AP', 7, 'ap@example.test', ['a@b.example.test'], [], 'Pending one', str_repeat('0', 64), [], null, 'pending', null, $this->world->register->clock->now(), null);
        $this->world->ledger->recordPending($pending);
        $this->world->outbound->add(new ApOutboundMessage('pend', ApOutboundTarget::invoice('i1'), 'p1', 7, 'b', false, $this->world->register->clock->now()));
        $rows = $this->world->service->invoiceHistory($this->actor(), 'i1');
        self::assertSame(['Sending', null, null], [$rows[0]['status'], $rows[0]['error'], $rows[0]['sent_at']]);
    }

    // ---- deliver, for the approvals task -----------------------------------------------

    public function test_deliver_records_approval_mail_against_the_batch_and_it_is_in_neither_history(): void
    {
        $mail = new ApOutboundMail('ap@example.test', ['approver@example.test'], 'Approval needed', '<p>Please approve</p>', '<p>Please approve</p>', ['prep@example.test']);

        $outcome = $this->world->service->deliver($this->actor(), ApOutboundTarget::approvalBatch('batch-1'), $mail, 'ap-approval:batch-1:request');

        self::assertFalse($outcome->deduplicated);
        self::assertSame('Sent', ApMailService::present($outcome->link, $outcome->delivery)['status']);
        self::assertSame(['batch-1', null, null], [ApMailService::present($outcome->link, $outcome->delivery)['approval_batch_id'], ApMailService::present($outcome->link, $outcome->delivery)['invoice_id'], ApMailService::present($outcome->link, $outcome->delivery)['email_id']]);
        self::assertSame('<p>Please approve</p>', $this->world->transport->standalone[0]->body);
        self::assertSame('ap-approval:batch-1:request', $outcome->delivery->idempotencyKey);
        self::assertSame([], $this->world->service->invoiceHistory($this->actor(), 'i1'));
        self::assertSame([], $this->world->service->emailHistory($this->actor(), 'e1'));
        self::assertCount(1, $this->world->outbound->of(ApOutboundTarget::approvalBatch('batch-1'), 10));
        self::assertSame([], $this->world->register->audit->forEntity('approval_batch', 'batch-1'), 'no audit event unless the caller passes one');
    }

    public function test_deliver_runs_the_callers_audit_event_with_the_link_and_not_for_a_repeated_key(): void
    {
        $events = 0;
        $audit = function (ApMailOutcome $sent) use (&$events): ApAuditEvent {
            $events++;

            return new ApAuditEvent('approval_requested', 'approval_batch', 'batch-2', ['notified' => $sent->delivery->to], $this->world->register->clock->now(), 7, 'Dee Preparer');
        };
        $mail = new ApOutboundMail('ap@example.test', ['approver@example.test'], 'Approval needed', 'x', 'x');

        $first = $this->world->service->deliver($this->actor(), ApOutboundTarget::approvalBatch('batch-2'), $mail, 'k-2', $audit);
        $again = $this->world->service->deliver($this->actor(), ApOutboundTarget::approvalBatch('batch-2'), $mail, 'k-2', $audit);

        self::assertSame(1, $events);
        self::assertTrue($again->deduplicated);
        self::assertSame($first->link->id, $again->link->id);
        self::assertCount(1, $this->world->transport->standalone);
        self::assertSame(['notified' => ['approver@example.test']], $this->world->register->audit->forEntity('approval_batch', 'batch-2')[0]->details);
    }

    public function test_deliver_obeys_the_switch_and_the_grant(): void
    {
        $mail = new ApOutboundMail('ap@example.test', ['approver@example.test'], 'Approval needed', 'x', 'x');
        $off = $this->world->serviceFor(false);

        try {
            $off->deliver($this->actor(), ApOutboundTarget::approvalBatch('b'), $mail, 'k');
            self::fail('Expected the switch to refuse.');
        } catch (ApUnavailable) {
        }
        self::assertFalse($off->sendingEnabled());

        try {
            $this->world->service->deliver($this->actor(), ApOutboundTarget::approvalBatch('b'), new ApOutboundMail('readonly@example.test', ['approver@example.test'], 's', 'x', 'x'), 'k');
            self::fail('Expected the grant to refuse.');
        } catch (ApForbidden $forbidden) {
            self::assertStringContainsString('readonly@example.test', $forbidden->getMessage());
        }
        self::assertSame([], $this->world->ledger->all());
        self::assertSame([], $this->world->transport->standalone);
        self::assertSame([], $this->world->outbound->of(ApOutboundTarget::approvalBatch('b'), 10));
    }

    public function test_deliver_turns_the_shared_senders_refusals_into_typed_failures(): void
    {
        try {
            $this->world->service->deliver($this->actor(), ApOutboundTarget::approvalBatch('b'), new ApOutboundMail('ap@example.test', ['not an address'], 's', 'x', 'x'), 'k');
            self::fail('Expected a refusal.');
        } catch (ApInvalid $invalid) {
            self::assertSame('Invalid email address in to.', $invalid->getMessage());
        }
        try {
            $this->world->service->deliver($this->actor(), ApOutboundTarget::approvalBatch('b'), new ApOutboundMail('ap@example.test', [], 's', 'x', 'x'), 'k');
            self::fail('Expected a refusal.');
        } catch (ApInvalid $invalid) {
            self::assertSame('At least one to address is required.', $invalid->getMessage());
        }
    }
}
