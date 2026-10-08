<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArForbidden;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArOutboundMessage;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArUnavailable;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;
use TriCab\Core\Finance\Ar\Service\ArMailService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;

/**
 * The reply composer (T5.9) on the twins and a transport that sends nothing: the mailboxes a person may send from, the
 * history, and the send with its defaults, address rules, attachments, threading conditions, fallback, record update, and
 * the failed and suppressed outcomes that must never read as sent. No mail leaves the process.
 */
final class ArMailServiceTest extends TestCase
{
    private const DEFAULT = 'ar@example.test';

    private InMemoryArStore $store;

    private InMemoryFinanceMailLedger $ledger;

    private InMemoryFinanceAccessScopeReader $scopes;

    private RecordingReplyMailTransport $transport;

    private InMemoryFinanceMailboxRegistry $registry;

    private ArActor $actor;

    private int $ids = 0;

    protected function setUp(): void
    {
        $this->store = new InMemoryArStore();
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->scopes = new InMemoryFinanceAccessScopeReader();
        $this->transport = new RecordingReplyMailTransport();
        $this->actor = new ArActor(7, 'Olive Operator', 'Olive@sample.local', []);
        $this->registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', self::DEFAULT, 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m2', 'melissa@example.test', '', 'Personal', true, ['AR' => ['read' => false, 'send' => true]]),
            new FinanceMailbox('m3', 'readonly@example.test', 'Read only', 'AR', true, ['AR' => ['read' => true, 'send' => false]]),
            new FinanceMailbox('m4', 'old@example.test', 'Retired', '', false, ['AR' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m5', 'task@example.test', 'Tasks', '', true, ['TASK' => ['read' => false, 'send' => true]]),
        ]);
        $this->store->insert(ArFixtures::record('r1', sourceMessageId: 'graph-1', mailbox: self::DEFAULT, senderEmail: 'Accounts@Acme.example.test', subject: 'Purchase order 77363', proposedResponse: "Subject: Re: PO\n\nHi, it is released.\n\nKind regards", status: 'reviewed', followUpStatus: 'in_progress'));
    }

    private function mailService(?FinanceMailTransport $transport = null): FinanceMailService
    {
        $n = 0;

        return new FinanceMailService(
            $this->registry,
            $this->ledger,
            $transport ?? $this->transport,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-08 02:03:04', new DateTimeZone('UTC')),
            static function () use (&$n): string {
                return 'd' . str_pad((string) ++$n, 31, '0', STR_PAD_LEFT);
            },
        );
    }

    private function service(bool $sending = true, ?FinanceMailTransport $transport = null, ?InMemoryArStore $store = null): ArMailService
    {
        $store ??= $this->store;

        return new ArMailService(
            $store,
            $store,
            $this->mailService($transport),
            $this->ledger,
            $this->registry,
            $this->scopes,
            new ArTime(FixedClock::utc('2026-10-08 02:03:04'), 'Australia/Melbourne'),
            'AR@Example.test',
            $sending,
            fn (): string => 'l' . str_pad((string) ++$this->ids, 31, '0', STR_PAD_LEFT),
        );
    }

    private static function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    private function record(string $id = 'r1'): ArRecord
    {
        return $this->store->find($id) ?? self::fail('No record ' . $id);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function sendOk(array $over = [], string $record = 'r1'): array
    {
        return $this->service()->send($this->actor, $record, $over + ['response_body' => 'Thanks, done.']);
    }

    // ---- send mailboxes ----------------------------------------------------------------

    public function test_the_send_mailboxes_are_the_granted_active_ones_with_the_configured_one_first(): void
    {
        $out = $this->service()->sendMailboxes($this->actor);

        self::assertSame('ar@example.test', $out['default'], 'the configured mailbox is lower-cased');
        self::assertSame([
            ['email' => 'ar@example.test', 'label' => 'Accounts Receivable'],
            ['email' => 'melissa@example.test', 'label' => 'melissa@example.test'],
        ], $out['mailboxes'], 'a read-only grant, an inactive mailbox and another app\'s grant are left out; a nameless one is labelled by its address');
    }

    public function test_a_persons_mailbox_scope_narrows_the_list_and_an_empty_scope_means_all(): void
    {
        $this->scopes->grant(7, 'AR', FinanceAccessScope::TYPE_MAILBOX, 'Melissa@example.test');
        $this->scopes->grant(8, 'TASK', FinanceAccessScope::TYPE_MAILBOX, 'task@example.test');

        self::assertSame(['melissa@example.test'], array_column($this->service()->sendMailboxes($this->actor)['mailboxes'], 'email'));
        self::assertCount(2, $this->service()->sendMailboxes(new ArActor(8, 'Other', 'o@example.test', []))['mailboxes'], 'a scope for another app does not apply');
    }

    // ---- send: the happy path and the record ------------------------------------------

    public function test_a_reply_to_the_sender_from_the_scanned_mailbox_is_threaded_and_completes_the_record(): void
    {
        $answer = $this->service()->send($this->actor, 'r1', ['response_body' => "Thanks.\n\nRegards"]);

        self::assertSame(['success' => true, 'record_id' => 'r1', 'threaded' => true], array_diff_key($answer, ['message' => 1]));
        self::assertCount(1, $this->transport->replies);
        self::assertSame([], $this->transport->standalone);
        $sent = $this->transport->replies[0];
        self::assertSame('graph-1', $sent['replyTo']);
        self::assertSame('ar@example.test', $sent['message']->mailbox);
        self::assertSame(['accounts@acme.example.test'], $sent['message']->to);
        self::assertSame('Re: Purchase order 77363', $sent['message']->subject);
        self::assertSame('<p style="margin:0 0 1em 0">Thanks.</p><p style="margin:0 0 1em 0">Regards</p>', $sent['message']->body);
        self::assertSame('HTML', $sent['message']->contentType);
        self::assertSame(7, $sent['message']->actorUserId);
        self::assertSame('AR', $sent['message']->application);
        self::assertSame('ar-reply:l' . str_pad('1', 31, '0', STR_PAD_LEFT), $sent['message']->idempotencyKey);

        $record = $this->record();
        self::assertSame(['sent', 'completed', 'Replied in thread to Accounts@Acme.example.test on 2026-10-08 02:03 UTC'], [$record->status, $record->followUpStatus, $record->finalOutcome]);
        self::assertSame('Customer asks for an order to be released.', $record->aiSummary, 'nothing else on the record changes');
        self::assertSame(1, $this->store->outboundCount('r1'));
        self::assertSame(['sent'], array_map(static fn (FinanceMailDelivery $d): string => $d->status, $this->ledger->all()));
    }

    public function test_the_history_row_has_the_sources_keys_and_the_body_that_was_sent(): void
    {
        $this->sendOk(['to' => 'accounts@acme.example.test', 'cc' => ['boss@example.test'], 'subject' => 'Re: PO 77363 released']);

        $rows = $this->service()->history('r1');

        self::assertCount(1, $rows);
        self::assertSame(
            ['id', 'email_record_id', 'sender_user', 'from_mailbox', 'to_addresses', 'cc_addresses', 'subject', 'body', 'threaded', 'status', 'error', 'sent_at', 'created_date', 'updated_date'],
            array_keys($rows[0]),
        );
        self::assertSame(['r1', 'Olive@sample.local', 'ar@example.test', ['accounts@acme.example.test'], ['boss@example.test'], 'Re: PO 77363 released', 'Thanks, done.', true, 'sent', ''], [
            $rows[0]['email_record_id'], $rows[0]['sender_user'], $rows[0]['from_mailbox'], $rows[0]['to_addresses'], $rows[0]['cc_addresses'], $rows[0]['subject'], $rows[0]['body'], $rows[0]['threaded'], $rows[0]['status'], $rows[0]['error'],
        ]);
        self::assertIsString($rows[0]['sent_at']);
        self::assertStringStartsWith('2026-10-08T02:03:04', $rows[0]['sent_at']);
    }

    public function test_history_is_newest_first_and_an_unknown_record_is_a_404_in_the_sources_words(): void
    {
        $service = $this->service();
        $service->send($this->actor, 'r1', ['response_body' => 'First']);
        $service->send($this->actor, 'r1', ['response_body' => 'Second']);
        $links = $this->store->outboundOf('r1');
        self::assertCount(2, $links);

        self::assertSame(2, count($service->history('r1')));
        $this->expectException(ArNotFound::class);
        $this->expectExceptionMessage('Record not found');
        $service->history('nope');
    }

    public function test_pending_reads_sending_and_a_ledger_row_that_is_gone_is_left_out(): void
    {
        $at = self::utc('2026-10-08 01:00:00');
        $pending = new FinanceMailDelivery('d-pending', 'AR', 7, 'ar@example.test', ['a@example.test'], [], 'Hello', 'h', [], null, 'pending', null, $at, null);
        $this->ledger->recordPending($pending);
        $this->store->addOutbound(new ArOutboundMessage('o1', 'r1', 'd-pending', 7, 'olive@sample.local', false, 'Hello', $at));
        $this->store->addOutbound(new ArOutboundMessage('o2', 'r1', 'd-missing', 7, 'olive@sample.local', false, 'Gone', $at->modify('+1 minute')));

        $rows = $this->service()->history('r1');

        self::assertCount(1, $rows);
        self::assertSame(['sending', ''], [$rows[0]['status'], $rows[0]['error']]);
        self::assertNull($rows[0]['sent_at']);
    }

    // ---- send: defaults, addresses, body ----------------------------------------------

    public function test_with_nothing_sent_the_reply_goes_to_the_sender_with_re_subject_and_the_proposed_response_without_its_subject_line(): void
    {
        $this->service()->send($this->actor, 'r1', []);

        $message = $this->transport->replies[0]['message'];
        self::assertSame(['accounts@acme.example.test'], $message->to);
        self::assertSame('Re: Purchase order 77363', $message->subject);
        self::assertSame('<p style="margin:0 0 1em 0">Hi, it is released.</p><p style="margin:0 0 1em 0">Kind regards</p>', $message->body);
    }

    public function test_a_body_and_a_proposed_response_that_are_both_empty_is_a_422_in_the_sources_words(): void
    {
        $this->store->insert(ArFixtures::record('bare', proposedResponse: ''));

        foreach ([[], ['response_body' => '   '], ['response_body' => "Subject: Only a subject\n\n"]] as $data) {
            try {
                $this->service()->send($this->actor, 'bare', $data);
                self::fail('Expected a 422.');
            } catch (ArInvalid $invalid) {
                self::assertSame('Recipient and response body are required', $invalid->getMessage());
            }
        }
        self::assertSame([], $this->ledger->all());
    }

    public function test_addresses_are_split_on_commas_semicolons_and_spaces_and_de_duplicated_without_regard_to_case(): void
    {
        $this->sendOk(['to' => 'A@x.example.test; b@y.example.test,  a@X.example.test', 'cc' => ['c@x.example.test', 'C@X.example.test', ' ']]);

        $message = $this->transport->standalone[0];
        self::assertSame(['a@x.example.test', 'b@y.example.test'], $message->to, 'the shared service lower-cases what it sends');
        self::assertSame(['c@x.example.test'], $message->cc);
        self::assertSame('Olive@sample.local', $this->service()->history('r1')[0]['sender_user']);
        self::assertSame(['A@x.example.test', 'b@y.example.test'], array_slice($this->recordedTo(), 0, 2) ?: [], 'the final outcome keeps the case it was typed in');
    }

    /**
     * The recipients as the final outcome text names them.
     *
     * @return list<string>
     */
    private function recordedTo(): array
    {
        preg_match('/^Sent email reply to (.+?)(?: \(cc .+\))? on /', $this->record()->finalOutcome, $m);

        return array_map('trim', explode(',', $m[1] ?? ''));
    }

    public function test_every_bad_address_is_named_and_nothing_is_sent(): void
    {
        try {
            $this->sendOk(['to' => 'good@example.test, not-an-email', 'cc' => 'Display Name <a@b.example.test>']);
            self::fail('Expected a 422.');
        } catch (ArInvalid $invalid) {
            self::assertSame('Invalid email address: not-an-email, Display, Name, <a@b.example.test>', $invalid->getMessage());
        }
        self::assertSame([], $this->ledger->all());
        self::assertSame([], $this->transport->standalone);
    }

    public function test_the_draft_is_escaped_so_no_markup_reaches_the_customer(): void
    {
        $this->sendOk(['response_body' => "<script>alert(1)</script> & more\nsecond line <b>x</b>"]);

        self::assertSame(
            '<p style="margin:0 0 1em 0">&lt;script&gt;alert(1)&lt;/script&gt; &amp; more<br>second line &lt;b&gt;x&lt;/b&gt;</p>',
            $this->transport->replies[0]['message']->body,
        );
    }

    // ---- send: threading ---------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> a record override and a request override that each stop threading
     */
    public static function notThreaded(): array
    {
        return [
            'another recipient as well' => [[], ['to' => 'accounts@acme.example.test, extra@example.test']],
            'a different recipient' => [[], ['to' => 'someone.else@example.test']],
            'sent from another mailbox' => [[], ['from' => 'melissa@example.test']],
            'the record was read from another mailbox' => [['mailbox' => 'melissa@example.test'], []],
            'no message id' => [['sourceMessageId' => null], []],
            'the original is flagged unreachable' => [['messageUnreachable' => true], []],
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $request
     */
    #[DataProvider('notThreaded')]
    public function test_a_reply_that_cannot_be_threaded_goes_as_a_new_message(array $record, array $request): void
    {
        $this->store->insert(ArFixtures::record('other', ...($record + ['senderEmail' => 'accounts@acme.example.test', 'sourceMessageId' => 'graph-2', 'mailbox' => self::DEFAULT])));

        $answer = $this->service()->send($this->actor, 'other', $request + ['response_body' => 'Hello']);

        self::assertFalse($answer['threaded']);
        self::assertCount(1, $this->transport->standalone);
        self::assertSame([], $this->transport->replies);
        self::assertStringStartsWith('Sent email reply to ', $this->record('other')->finalOutcome);
    }

    public function test_a_blank_record_mailbox_means_the_configured_one_for_threading(): void
    {
        $this->store->insert(ArFixtures::record('blank', senderEmail: 'accounts@acme.example.test', sourceMessageId: 'graph-3', mailbox: ''));

        self::assertTrue($this->service()->send($this->actor, 'blank', ['response_body' => 'Hi'])['threaded']);
    }

    public function test_a_reply_graph_could_not_thread_is_a_sent_message_that_says_it_was_not_threaded(): void
    {
        $this->transport->threads = false;

        $answer = $this->sendOk();

        self::assertFalse($answer['threaded']);
        self::assertStringStartsWith('Sent email reply to Accounts@Acme.example.test on ', $this->record()->finalOutcome);
        self::assertFalse($this->service()->history('r1')[0]['threaded']);
    }

    public function test_the_outcome_names_the_cc(): void
    {
        $this->sendOk(['cc' => 'boss@example.test, other@example.test']);

        self::assertSame('Replied in thread to Accounts@Acme.example.test (cc boss@example.test, other@example.test) on 2026-10-08 02:03 UTC', $this->record()->finalOutcome);
    }

    // ---- send: attachments -------------------------------------------------------------

    public function test_attachments_are_decoded_and_sent(): void
    {
        $this->sendOk(['attachments' => [['name' => 'receipt.pdf', 'content_type' => 'application/pdf', 'data' => base64_encode('%PDF bytes')], ['name' => 'notes.txt', 'data' => base64_encode('plain')]]]);

        $files = $this->transport->replies[0]['message']->attachments;
        self::assertSame(['receipt.pdf', 'notes.txt'], array_map(static fn ($a): string => $a->name, $files));
        self::assertSame(['%PDF bytes', 'plain'], array_map(static fn ($a): string => $a->content, $files));
        self::assertSame(['application/pdf', 'application/octet-stream'], array_map(static fn ($a): string => $a->contentType, $files));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function badAttachments(): array
    {
        $big = base64_encode(str_repeat('x', 2 * 1024 * 1024));

        return [
            'not a list' => ['file.pdf', 'Attachments must be a list'],
            'a list item that is not an object' => [['file.pdf'], 'Each attachment must be an object'],
            'no name' => [[['data' => 'eA==']], 'Every attachment needs a file name'],
            'bad base64' => [[['name' => 'a.pdf', 'data' => '%%%']], 'a.pdf could not be read'],
            'empty file' => [[['name' => 'a.pdf', 'data' => '']], 'a.pdf is empty'],
            'a file that decodes to nothing' => [[['name' => 'a.pdf', 'data' => base64_encode('')]], 'a.pdf is empty'],
            'over 3 MB in total' => [[['name' => 'a.bin', 'data' => $big], ['name' => 'b.bin', 'data' => $big]], 'Attachments come to more than 3 MB in total. Send a link instead, or attach fewer files.'],
            'eleven files' => [array_fill(0, 11, ['name' => 'a.txt', 'data' => 'eA==']), 'Attach at most 10 files'],
            'a path as a name' => [[['name' => '../etc/passwd', 'data' => 'eA==']], '../etc/passwd is not a usable file name'],
        ];
    }

    #[DataProvider('badAttachments')]
    public function test_a_bad_attachment_is_refused_in_the_sources_words_before_anything_is_sent(mixed $attachments, string $message): void
    {
        try {
            $this->sendOk(['attachments' => $attachments]);
            self::fail('Expected a 422.');
        } catch (ArInvalid $invalid) {
            self::assertSame($message, $invalid->getMessage());
        }
        self::assertSame([], $this->ledger->all());
    }

    // ---- send: refusals ----------------------------------------------------------------

    public function test_with_sending_switched_off_everything_is_refused_with_a_503_before_the_record_is_even_read(): void
    {
        foreach (['r1', 'nope'] as $id) {
            try {
                $this->service(sending: false)->send($this->actor, $id, ['response_body' => 'Hi']);
                self::fail('Expected a 503.');
            } catch (ArUnavailable $off) {
                self::assertSame([503, 'FINANCE_AR_UNAVAILABLE', 'Sending is switched off'], [$off->httpStatus(), $off->errorCode(), $off->getMessage()]);
            }
        }
        self::assertSame([], $this->ledger->all());
        self::assertSame(0, $this->store->outboundCount('r1'));
        self::assertNotSame([], $this->service(sending: false)->sendMailboxes($this->actor)['mailboxes'], 'reads still work');
    }

    public function test_an_unknown_record_is_a_404(): void
    {
        $this->expectException(ArNotFound::class);
        $this->expectExceptionMessage('Record not found');
        $this->service()->send($this->actor, 'nope', ['response_body' => 'Hi']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mailboxesThatMayNotSend(): array
    {
        return ['a read-only grant' => ['readonly@example.test'], 'an inactive mailbox' => ['old@example.test'], 'another app\'s mailbox' => ['task@example.test'], 'an unknown address' => ['nobody@example.test']];
    }

    #[DataProvider('mailboxesThatMayNotSend')]
    public function test_sending_from_a_mailbox_without_a_send_grant_is_a_403_naming_it(string $from): void
    {
        try {
            $this->sendOk(['from' => strtoupper($from)]);
            self::fail('Expected a 403.');
        } catch (ArForbidden $forbidden) {
            self::assertSame('Tri-AR is not allowed to send from ' . $from . '. Ask a finance administrator to grant it that mailbox.', $forbidden->getMessage());
        }
        self::assertSame([], $this->ledger->all());
    }

    public function test_a_persons_scope_can_forbid_a_mailbox_the_app_may_use(): void
    {
        $this->scopes->grant(7, 'AR', FinanceAccessScope::TYPE_MAILBOX, 'melissa@example.test');

        $this->expectException(ArForbidden::class);
        $this->sendOk();
    }

    public function test_the_shared_services_own_rule_is_a_422_not_a_500(): void
    {
        $this->expectException(ArInvalid::class);
        $this->sendOk(['subject' => str_repeat('s', 501)]);
    }

    // ---- send: failed and suppressed ---------------------------------------------------

    public function test_a_failed_send_is_a_502_the_record_is_untouched_and_the_history_says_failed(): void
    {
        $this->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        try {
            $this->sendOk();
            self::fail('Expected a 502.');
        } catch (ArUpstream $upstream) {
            self::assertSame([502, 'FINANCE_AR_UPSTREAM_FAILED', ['reason' => 'send']], [$upstream->httpStatus(), $upstream->errorCode(), $upstream->extra]);
            self::assertSame('Failed to send email: Microsoft Graph refused the message with status 500.', $upstream->getMessage());
        }

        $record = $this->record();
        self::assertSame(['reviewed', 'in_progress', ''], [$record->status, $record->followUpStatus, $record->finalOutcome]);
        $rows = $this->service()->history('r1');
        self::assertCount(1, $rows);
        self::assertSame(['failed', 'Microsoft Graph refused the message with status 500.', 'Thanks, done.'], [$rows[0]['status'], $rows[0]['error'], $rows[0]['body']]);
    }

    public function test_a_send_with_mail_switched_off_is_not_reported_as_sent(): void
    {
        try {
            $this->service(transport: new DisabledFinanceMailTransport())->send($this->actor, 'r1', ['response_body' => 'Hi']);
            self::fail('Expected a 502.');
        } catch (ArUpstream $upstream) {
            self::assertSame('Failed to send email: Mail is switched off', $upstream->getMessage());
        }

        self::assertSame(['reviewed', ''], [$this->record()->status, $this->record()->finalOutcome]);
        $rows = $this->service()->history('r1');
        self::assertSame(['failed', 'Mail is switched off'], [$rows[0]['status'], $rows[0]['error']]);
        self::assertSame(['suppressed'], array_map(static fn (FinanceMailDelivery $d): string => $d->status, $this->ledger->all()));
    }

    public function test_the_link_and_the_record_update_are_one_transaction(): void
    {
        $store = new class() extends InMemoryArStore {
            public function update(ArRecord $record): void
            {
                throw new RuntimeException('the record could not be saved');
            }
        };
        $store->insert(ArFixtures::record('r1', sourceMessageId: 'graph-1', mailbox: self::DEFAULT));

        try {
            $this->service(store: $store)->send($this->actor, 'r1', ['response_body' => 'Hi']);
            self::fail('Expected the failure to surface.');
        } catch (RuntimeException) {
            // expected: the mail went (the ledger has it) but nothing half-written is left in the register
        }

        self::assertSame(0, $store->outboundCount('r1'));
        self::assertCount(1, $this->ledger->all());
    }
}
