<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArCreditNote;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArUnavailable;
use TriCab\Core\Finance\Ar\Service\ArCreditNoteService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * Credit-note requests (T5.10) on the twins and a transport that sends nothing: create with its hashed token, the
 * link shown once, the escaped mail and the reported failures, the 503s before any write, list and get without a
 * token, the PATCH whitelist, and resend replacing the token. Every address is invented.
 */
final class ArCreditNoteServiceTest extends TestCase
{
    private const ORIGIN = 'https://tricab.example.test';

    private InMemoryArStore $store;

    private InMemoryFinanceMailLedger $ledger;

    private InMemoryFinanceAccessScopeReader $scopes;

    private RecordingReplyMailTransport $transport;

    private FixedClock $clock;

    private ArActor $actor;

    private int $ids = 0;

    private int $tokens = 0;

    protected function setUp(): void
    {
        $this->store = new InMemoryArStore();
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->scopes = new InMemoryFinanceAccessScopeReader();
        $this->transport = new RecordingReplyMailTransport();
        $this->clock = FixedClock::utc('2026-10-08 02:03:04');
        $this->actor = new ArActor(7, 'Olive Operator', 'olive@sample.local', []);
        $this->store->insert(ArFixtures::record('r1', followUpStatus: 'needs_review', status: 'new'));
    }

    private function service(bool $sending = true, string $origin = self::ORIGIN, ?FinanceMailTransport $transport = null, bool $granted = true): ArCreditNoteService
    {
        $n = 0;
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ar@example.test', 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => $granted]]),
        ]);
        $mail = new FinanceMailService(
            $registry,
            $this->ledger,
            $transport ?? $this->transport,
            fn (): DateTimeImmutable => $this->clock->now(),
            static function () use (&$n): string {
                return 'm' . str_pad((string) ++$n, 31, '0', STR_PAD_LEFT);
            },
        );

        return new ArCreditNoteService(
            $this->store,
            $this->store,
            $mail,
            $this->scopes,
            new ArTime($this->clock, 'Australia/Melbourne'),
            'AR@Example.test',
            $origin,
            72,
            $sending,
            fn (): string => 'n' . str_pad((string) ++$this->ids, 31, '0', STR_PAD_LEFT),
            fn (): string => 'token-' . ++$this->tokens . '-abcDEF_123',
        );
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function create(array $over = []): array
    {
        return $this->service()->create($this->actor, $over + [
            'customer_name' => 'Acme Marine Pty Ltd', 'sales_rep_email' => 'Rep@Example.test', 'sales_rep_name' => 'Riley Rep',
            'invoice_number' => 'INV-1', 'credit_amount' => '12.5', 'reason' => 'Damaged goods', 'original_request' => "Please credit INV-1\nThanks",
            'email_record_id' => 'r1',
        ]);
    }

    private function stored(int $n): ArCreditNote
    {
        return $this->store->findCreditNote(self::id($n)) ?? self::fail('No request ' . $n);
    }

    private static function id(int $n): string
    {
        return 'n' . str_pad((string) $n, 31, '0', STR_PAD_LEFT);
    }

    // ---- create --------------------------------------------------------------------------

    public function test_create_stores_the_hash_and_never_the_token_and_shows_the_link_once(): void
    {
        $answer = $this->create();

        $note = $this->stored(1);
        self::assertSame(hash('sha256', 'token-1-abcDEF_123'), $note->tokenHash);
        self::assertStringNotContainsString('token-1', json_encode($note, JSON_THROW_ON_ERROR), 'the token is held nowhere');
        self::assertSame(self::ORIGIN . '/finance/ar/credit-note-form?id=' . self::id(1) . '&token=token-1-abcDEF_123', $answer['form_url']);
        self::assertTrue($answer['success']);
        self::assertTrue($answer['mail_sent']);
        self::assertNull($answer['mail_error']);
        $view = $answer['credit_note_request'];
        self::assertIsArray($view);
        self::assertArrayNotHasKey('token', $view);
        self::assertArrayNotHasKey('token_hash', $view);
        self::assertStringNotContainsString('token-1', json_encode($view, JSON_THROW_ON_ERROR));
        self::assertSame(['form_sent', 'rep@example.test', 12.5, 'r1', 'Acme Marine Pty Ltd'], [$view['status'], $view['sales_rep_email'], $view['credit_amount'], $view['email_record_id'], $view['customer_name']]);
        self::assertSame('2026-10-11T02:03:04.000000+00:00', $view['token_expires_at'], 'seventy-two hours');
        self::assertSame($view['created_date'], $view['form_sent_date']);
        self::assertSame('', $view['submitted_date']);
    }

    public function test_the_mail_goes_to_the_rep_from_the_ar_mailbox_with_the_link_and_every_value_escaped(): void
    {
        $this->create(['customer_name' => '<b>Acme</b> & Co', 'sales_rep_name' => '"><script>x</script>', 'original_request' => "<img src=x onerror=alert(1)>\nSecond line"]);

        self::assertCount(1, $this->transport->standalone);
        $mail = $this->transport->standalone[0];
        self::assertSame(['AR', 'ar@example.test', ['rep@example.test']], [$mail->application, $mail->mailbox, $mail->to]);
        self::assertSame('Credit Note Request — <b>Acme</b> & Co', $mail->subject, 'a subject is text, not HTML');
        self::assertSame(7, $mail->actorUserId);
        self::assertStringStartsWith('ar-credit-note-link:' . substr(hash('sha256', 'token-1-abcDEF_123'), 0, 16), (string) $mail->idempotencyKey);
        self::assertStringContainsString('&lt;b&gt;Acme&lt;/b&gt; &amp; Co', $mail->body);
        self::assertStringContainsString('Hi &quot;&gt;&lt;script&gt;x&lt;/script&gt;,', $mail->body);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;<br/>Second line', $mail->body);
        self::assertStringNotContainsString('<script', $mail->body);
        self::assertStringNotContainsString('<img', $mail->body);
        self::assertStringContainsString('href="' . self::ORIGIN . '/finance/ar/credit-note-form?id=' . self::id(1) . '&amp;token=token-1-abcDEF_123"', $mail->body);
        self::assertStringContainsString('expire in 72 hours', $mail->body);
    }

    public function test_a_subject_with_line_breaks_stays_one_line_and_a_nameless_rep_is_there(): void
    {
        $this->create(['customer_name' => "Acme\r\nBcc: x@evil.test", 'sales_rep_name' => '']);

        self::assertSame('Credit Note Request — Acme Bcc: x@evil.test', $this->transport->standalone[0]->subject);
        self::assertStringContainsString('Hi there,', $this->transport->standalone[0]->body);
    }

    public function test_the_register_item_moves_on_as_the_source_did(): void
    {
        $this->create();

        $record = $this->store->find('r1') ?? self::fail('No record.');
        self::assertSame(['reviewed', 'rep@example.test', self::id(1), 'in_progress'], [$record->status, $record->assignedSalesRepEmail, $record->creditNoteRequestId, $record->followUpStatus]);
    }

    public function test_a_request_without_a_record_is_fine_and_an_unknown_record_is_a_404(): void
    {
        $answer = $this->create(['email_record_id' => '']);
        $view = $answer['credit_note_request'];
        self::assertIsArray($view);
        self::assertSame('', $view['email_record_id']);

        $this->expectException(ArNotFound::class);
        $this->expectExceptionMessage('Record not found');
        $this->create(['email_record_id' => 'ghost']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badCreates(): array
    {
        return [
            'no customer' => [['customer_name' => ' '], 'customer_name and sales_rep_email are required'],
            'no rep' => [['sales_rep_email' => ''], 'customer_name and sales_rep_email are required'],
            'a bad rep address' => [['sales_rep_email' => 'not an address'], 'Invalid email address: not an address'],
            'a negative amount' => [['credit_amount' => -1], 'credit_amount must be a number'],
            'a non-number amount' => [['credit_amount' => 'lots'], 'credit_amount must be a number'],
            'an array as the amount' => [['credit_amount' => [1]], 'credit_amount must be a number'],
        ];
    }

    /**
     * @param array<string, mixed> $over
     */
    #[DataProvider('badCreates')]
    public function test_bad_input_is_refused_in_the_sources_words_and_writes_nothing(array $over, string $message): void
    {
        try {
            $this->create($over);
            self::fail('Expected a 422.');
        } catch (ArInvalid $invalid) {
            self::assertSame($message, $invalid->getMessage());
        }
        self::assertSame([], $this->store->pageCreditNotes(null, null, null, 10, 0)[0]);
        self::assertSame([], $this->transport->standalone);
    }

    public function test_an_amount_is_kept_to_the_cent_and_empty_is_zero(): void
    {
        self::assertSame(12.35, ArCreditNoteService::amount('12.345'));
        self::assertSame(0.0, ArCreditNoteService::amount(null));
        self::assertSame(0.0, ArCreditNoteService::amount(''));
        self::assertSame(7.0, ArCreditNoteService::amount(7));
    }

    public function test_with_sending_off_or_no_public_origin_create_is_a_503_before_anything_is_written(): void
    {
        foreach ([[false, self::ORIGIN, 'Sending is switched off'], [true, '', 'The public web address for credit note forms is not set (FINANCE_AR_PUBLIC_ORIGIN)']] as [$sending, $origin, $message]) {
            try {
                $this->service($sending, $origin)->create($this->actor, ['customer_name' => 'A', 'sales_rep_email' => 'r@example.test', 'email_record_id' => 'r1']);
                self::fail('Expected a 503.');
            } catch (ArUnavailable $off) {
                self::assertSame([503, 'FINANCE_AR_UNAVAILABLE', $message], [$off->httpStatus(), $off->errorCode(), $off->getMessage()]);
            }
        }
        self::assertSame([], $this->store->pageCreditNotes(null, null, null, 10, 0)[0]);
        self::assertSame('new', $this->store->find('r1')?->status);
        self::assertSame([], $this->ledger->all());
    }

    // ---- create: a mail that did not go is reported -------------------------------------

    public function test_a_failed_mail_is_reported_and_the_request_still_exists(): void
    {
        $this->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        $answer = $this->create();

        self::assertTrue($answer['success']);
        self::assertFalse($answer['mail_sent']);
        self::assertSame('Microsoft Graph refused the message with status 500.', $answer['mail_error']);
        self::assertNotNull($this->store->findCreditNote(self::id(1)));
        self::assertNotSame('', $answer['form_url'], 'the link is still shown, so it can be passed on by hand');
    }

    public function test_mail_switched_off_and_a_missing_grant_are_reported_in_words(): void
    {
        $off = $this->service(transport: new DisabledFinanceMailTransport())->create($this->actor, ['customer_name' => 'A', 'sales_rep_email' => 'r@example.test']);
        self::assertSame([false, 'Mail is switched off'], [$off['mail_sent'], $off['mail_error']]);

        $noGrant = $this->service(granted: false)->create($this->actor, ['customer_name' => 'B', 'sales_rep_email' => 'r@example.test']);
        self::assertFalse($noGrant['mail_sent']);
        self::assertSame('AR is not allowed to send from the mailbox ar@example.test.', $noGrant['mail_error']);
    }

    public function test_a_persons_mailbox_scope_that_excludes_the_ar_mailbox_is_reported_not_bypassed(): void
    {
        $this->scopes->grant(7, 'AR', FinanceAccessScope::TYPE_MAILBOX, 'melissa@example.test');

        $answer = $this->create();

        self::assertFalse($answer['mail_sent']);
        self::assertSame('Tri-AR is not allowed to send from ar@example.test. Ask a finance administrator to grant it that mailbox.', $answer['mail_error']);
        self::assertSame([], $this->transport->standalone);
    }

    // ---- list and get --------------------------------------------------------------------

    public function test_list_and_get_never_carry_a_token_and_the_list_is_newest_first_with_a_bounded_page(): void
    {
        $this->create(['customer_name' => 'First']);
        $this->clock->advance('+1 hour');
        $this->create(['customer_name' => 'Second', 'sales_rep_email' => 'other@example.test']);

        $list = $this->service()->list([]);

        self::assertSame([2, 100, 0], [$list['total'], $list['limit'], $list['offset']]);
        $rows = ArFixtures::rows($list['records']);
        self::assertSame(['Second', 'First'], array_column($rows, 'customer_name'));
        foreach ([...$rows, $this->service()->get(self::id(1))] as $row) {
            $text = json_encode($row, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('token-', $text);
            self::assertStringNotContainsString(hash('sha256', 'token-1-abcDEF_123'), $text);
            self::assertArrayNotHasKey('token', $row);
        }
        self::assertSame(1000, $this->service()->list(['limit' => '5000'])['limit'], 'a larger page is answered as 1,000');
    }

    public function test_list_filters(): void
    {
        $this->create(['customer_name' => '100%_Real [Co]', 'sales_rep_email' => 'a@example.test']);
        $this->create(['customer_name' => 'Plain Co', 'sales_rep_email' => 'b@example.test']);
        $this->service()->update(self::id(2), ['status' => 'approved']);
        $service = $this->service();

        self::assertSame(1, $service->list(['customer_name' => '100%_'])['total'], 'wildcards are literal');
        self::assertSame(1, $service->list(['customer_name' => '%'])['total'], 'a lone % matches only a name that has one');
        self::assertSame(1, $service->list(['status' => 'approved'])['total']);
        self::assertSame(1, $service->list(['sales_rep_email' => 'A@Example.test'])['total'], 'the address matches without regard to case');
        self::assertSame(2, $service->list(['customer_name' => 'co'])['total'], 'contains, without regard to case');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badQueries(): array
    {
        return ['a status that does not exist' => [['status' => 'nonsense']], 'a limit that is not a number' => [['limit' => 'many']], 'a limit below 1' => [['limit' => '0']], 'a negative offset' => [['offset' => '-1']]];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('badQueries')]
    public function test_a_bad_status_limit_or_offset_is_a_422(array $query): void
    {
        $this->expectException(ArInvalid::class);
        $this->service()->list($query);
    }

    public function test_an_unknown_request_is_a_404_in_the_sources_words(): void
    {
        foreach ([fn () => $this->service()->get('nope'), fn () => $this->service()->update('nope', []), fn () => $this->service()->resend($this->actor, 'nope')] as $call) {
            try {
                $call();
                self::fail('Expected a 404.');
            } catch (ArNotFound $notFound) {
                self::assertSame('Credit note request not found', $notFound->getMessage());
            }
        }
    }

    // ---- patch ---------------------------------------------------------------------------

    public function test_patch_changes_the_status_and_the_editable_fields_and_nothing_else(): void
    {
        $this->create();
        $before = $this->stored(1);

        $view = $this->service()->update(self::id(1), [
            'status' => 'under_review', 'invoice_number' => 'INV-9', 'credit_amount' => 99.999, 'reason' => 'Changed', 'sales_rep_email' => 'NEW@example.test',
            'token' => 'stolen', 'token_hash' => str_repeat('a', 64), 'token_expires_at' => '2099-01-01', 'email_record_id' => 'other', 'id' => 'zzz',
            'form_sent_date' => '2000-01-01', 'submitted_date' => '2000-01-01', 'reviewed_at' => '2000-01-01', 'created_date' => '2000-01-01',
        ]);

        $after = $this->stored(1);
        self::assertSame(['under_review', 'INV-9', 100.0, 'Changed', 'new@example.test'], [$after->status, $after->invoiceNumber, $after->creditAmount, $after->reason, $after->salesRepEmail]);
        self::assertSame([$before->tokenHash, $before->tokenExpiresAt, $before->emailRecordId, $before->id, $before->formSentDate, $before->submittedDate, $before->createdAt], [$after->tokenHash, $after->tokenExpiresAt, $after->emailRecordId, $after->id, $after->formSentDate, $after->submittedDate, $after->createdAt], 'nothing outside the whitelist is written');
        self::assertSame('2026-10-08T02:03:04.000000+00:00', $view['reviewed_at'], 'reviewed_at is stamped by the move to under_review');
    }

    public function test_reviewed_at_is_stamped_once_and_only_for_a_reviewing_status(): void
    {
        $this->create();
        $service = $this->service();

        $service->update(self::id(1), ['status' => 'awaiting_sales_response']);
        $untouched = $this->stored(1)->reviewedAt;
        self::assertNull($untouched);
        $service->update(self::id(1), ['status' => 'approved']);
        $first = $this->stored(1)->reviewedAt;
        self::assertInstanceOf(DateTimeImmutable::class, $first);
        $this->clock->advance('+1 day');
        $service->update(self::id(1), ['status' => 'completed']);
        $later = $this->stored(1)->reviewedAt;
        self::assertEquals($first, $later, 'a later review does not move it');
    }

    public function test_patch_refuses_a_bad_status_and_writes_nothing_when_nothing_is_editable(): void
    {
        $this->create();
        $service = $this->service();

        try {
            $service->update(self::id(1), ['status' => 'overdue']);
            self::fail('Expected a 422.');
        } catch (ArInvalid $invalid) {
            self::assertSame('Invalid status: overdue', $invalid->getMessage());
        }
        $updated = $this->stored(1)->updatedAt;
        $this->clock->advance('+1 hour');
        $service->update(self::id(1), ['token' => 'x', 'nonsense' => 1]);
        $after = $this->stored(1)->updatedAt;
        self::assertEquals($updated, $after, 'an edit with nothing editable writes nothing');
        $this->expectException(ArInvalid::class);
        $service->update(self::id(1), ['customer_name' => '  ']);
    }

    // ---- resend --------------------------------------------------------------------------

    public function test_resend_replaces_the_token_so_the_old_one_stops_working_and_mails_the_new_link(): void
    {
        $this->create();
        $oldHash = $this->stored(1)->tokenHash;
        $this->clock->advance('+30 hours');

        $answer = $this->service()->resend($this->actor, self::id(1));

        $note = $this->stored(1);
        self::assertNotSame($oldHash, $note->tokenHash);
        self::assertSame(hash('sha256', 'token-2-abcDEF_123'), $note->tokenHash);
        self::assertSame('2026-10-12T08:03:04+00:00', $note->tokenExpiresAt->format('c'), 'a new 72 hours from the resend');
        self::assertIsString($answer['form_url']);
        self::assertStringContainsString('token=token-2-abcDEF_123', $answer['form_url']);
        self::assertTrue($answer['mail_sent']);
        self::assertCount(2, $this->transport->standalone);
        self::assertStringContainsString('token=token-2-abcDEF_123', $this->transport->standalone[1]->body);
        self::assertNotSame($this->transport->standalone[0]->idempotencyKey, $this->transport->standalone[1]->idempotencyKey, 'a second mail is not deduplicated away');
    }

    public function test_a_request_that_is_no_longer_open_cannot_be_resent(): void
    {
        $this->create();
        $this->service()->update(self::id(1), ['status' => 'submitted']);

        try {
            $this->service()->resend($this->actor, self::id(1));
            self::fail('Expected a 409.');
        } catch (ArConflict $conflict) {
            self::assertSame('This request has already been submitted', $conflict->getMessage());
        }
        self::assertSame(hash('sha256', 'token-1-abcDEF_123'), $this->store->findCreditNote(self::id(1))?->tokenHash, 'the old token is untouched');
        self::assertCount(1, $this->transport->standalone);
    }

    public function test_resend_is_refused_with_sending_off_before_anything_changes(): void
    {
        $this->create();

        $this->expectException(ArUnavailable::class);
        $this->service(sending: false)->resend($this->actor, self::id(1));
    }

    public function test_the_metrics_count_requests_by_status(): void
    {
        $this->create();
        $this->create(['customer_name' => 'Two']);
        $this->service()->update(self::id(2), ['status' => 'approved']);

        self::assertEquals(['form_sent' => 1, 'approved' => 1], $this->store->metrics(null, null, '2026-10-08')['credit_notes']);
    }

    public function test_helpers_cut_text_to_its_bound_and_ignore_what_is_not_text(): void
    {
        self::assertSame(str_repeat('é', 5), ArCreditNoteService::text('  ' . str_repeat('é', 9), 5));
        self::assertSame('', ArCreditNoteService::text(['x'], 10));
        self::assertSame('', ArCreditNoteService::text(null, 10));
        self::assertSame('1', ArCreditNoteService::text(1, 10));
        self::assertEquals(new DateTimeImmutable('2026-10-08 02:03:04', new DateTimeZone('UTC')), $this->clock->now());
    }
}
