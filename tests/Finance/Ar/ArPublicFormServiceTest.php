<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Ar\InMemoryArRateLimiter;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArCreditNote;
use TriCab\Core\Finance\Ar\Domain\ArGone;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArRateLimited;
use TriCab\Core\Finance\Ar\Service\ArPublicFormService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * The sales reps' public form (T5.10): every refusal and its words, one answer for an unknown id and a wrong token,
 * expiry as a 410, single use, the record update, an answer with no token, the team notice and its failure never
 * failing the submit, and the limiter. Nothing here sends mail; every address is invented.
 */
final class ArPublicFormServiceTest extends TestCase
{
    private const TOKEN = 'good-token-0123456789';

    private InMemoryArStore $store;

    private RecordingReplyMailTransport $transport;

    private InMemoryFinanceMailLedger $ledger;

    private FixedClock $clock;

    private int $now = 1_700_000_000;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->store = new InMemoryArStore();
        $this->transport = new RecordingReplyMailTransport();
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->clock = FixedClock::utc('2026-10-08 02:03:04');
        $this->store->insert(ArFixtures::record('r1', followUpStatus: 'needs_review', status: 'reviewed'));
        $this->store->insertCreditNote($this->note('n1'));
    }

    private function note(string $id, mixed ...$over): ArCreditNote
    {
        $at = new DateTimeImmutable('2026-10-08 01:00:00', new \DateTimeZone('UTC'));
        $note = new ArCreditNote(
            $id, 'r1', 'Acme Marine Pty Ltd', 'ap@acme.example.test', 'Riley Rep', 'rep@example.test', '', 0.0, '', "Please credit INV-1\nThanks",
            hash('sha256', self::TOKEN), $at->modify('+72 hours'), ArCreditNote::FORM_SENT, $at, null, null, $at, $at,
        );

        return $over === [] ? $note : $note->with(...$over);
    }

    private function service(bool $sending = true, ?InMemoryArRateLimiter $limiter = null, ?FinanceMailTransportFailed $failMail = null): ArPublicFormService
    {
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ar@example.test', 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
        ]);
        $this->transport->failWith = $failMail;
        $mail = new FinanceMailService($registry, $this->ledger, $this->transport, fn (): DateTimeImmutable => $this->clock->now());

        return new ArPublicFormService(
            $this->store,
            $this->store,
            $limiter ?? new InMemoryArRateLimiter(fn (): int => $this->now),
            $mail,
            new ArTime($this->clock, 'Australia/Melbourne'),
            'AR@Example.test',
            $sending,
            function (string $line): void {
                $this->log[] = $line;
            },
        );
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function body(array $over = []): array
    {
        return $over + ['id' => 'n1', 'token' => self::TOKEN, 'invoice_number' => ' INV-77 ', 'credit_amount' => '12.5', 'reason' => ' Damaged ', 'sales_rep_name' => ' Riley R '];
    }

    private static function length(mixed $value): int
    {
        self::assertIsString($value);

        return strlen($value);
    }

    // ---- show ---------------------------------------------------------------------------

    public function test_the_form_shows_what_the_rep_needs_and_no_token(): void
    {
        $view = $this->service()->show('203.0.113.9', ['id' => 'n1', 'token' => self::TOKEN]);

        self::assertSame(
            ['id', 'customer_name', 'customer_email', 'sales_rep_name', 'original_request', 'invoice_number', 'credit_amount', 'reason', 'status'],
            array_keys($view),
        );
        self::assertSame(['n1', 'Acme Marine Pty Ltd', 'form_sent'], [$view['id'], $view['customer_name'], $view['status']]);
        self::assertStringNotContainsString(self::TOKEN, json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function test_missing_parts_are_a_422_in_the_sources_words(): void
    {
        foreach ([[], ['id' => 'n1'], ['token' => self::TOKEN], ['id' => '', 'token' => ''], ['id' => ['n1'], 'token' => self::TOKEN]] as $query) {
            try {
                $this->service()->show('203.0.113.9', $query);
                self::fail('Expected a 422.');
            } catch (ArInvalid $invalid) {
                self::assertSame('Missing form ID or security token', $invalid->getMessage());
            }
        }
    }

    public function test_an_unknown_id_and_a_wrong_token_are_the_same_404(): void
    {
        $answers = [];
        foreach ([['id' => 'ghost', 'token' => self::TOKEN], ['id' => 'n1', 'token' => 'wrong-token'], ['id' => 'n1', 'token' => str_repeat('x', 500)], ['id' => str_repeat('n', 200), 'token' => self::TOKEN]] as $query) {
            try {
                $this->service()->show('203.0.113.9', $query);
                self::fail('Expected a 404.');
            } catch (ArNotFound $notFound) {
                $answers[] = [$notFound->httpStatus(), $notFound->errorCode(), $notFound->getMessage()];
            }
        }
        self::assertSame(array_fill(0, 4, [404, 'FINANCE_AR_NOT_FOUND', 'Invalid form link']), $answers, 'a caller learns nothing about which ids exist');
    }

    public function test_an_expired_link_is_a_410_that_says_so(): void
    {
        $this->clock->advance('+73 hours');

        try {
            $this->service()->show('203.0.113.9', ['id' => 'n1', 'token' => self::TOKEN]);
            self::fail('Expected a 410.');
        } catch (ArGone $gone) {
            self::assertSame([410, 'FINANCE_AR_GONE', 'This credit note form link has expired'], [$gone->httpStatus(), $gone->errorCode(), $gone->getMessage()]);
        }
    }

    public function test_an_expired_wrong_token_is_still_just_an_invalid_link(): void
    {
        $this->clock->advance('+73 hours');

        $this->expectException(ArNotFound::class);
        $this->service()->show('203.0.113.9', ['id' => 'n1', 'token' => 'wrong-token']);
    }

    public function test_a_used_form_still_shows_with_its_status_so_the_page_can_say_so(): void
    {
        $this->service()->submit('203.0.113.9', $this->body());

        self::assertSame('submitted', $this->service()->show('203.0.113.9', ['id' => 'n1', 'token' => self::TOKEN])['status']);
    }

    // ---- submit -------------------------------------------------------------------------

    public function test_submit_stores_the_values_trimmed_once_and_answers_without_a_token(): void
    {
        $answer = $this->service()->submit('203.0.113.9', $this->body());

        self::assertTrue($answer['success']);
        $record = $answer['record'];
        self::assertIsArray($record);
        self::assertSame(['id', 'status', 'invoice_number', 'credit_amount', 'reason', 'sales_rep_name', 'submitted_date'], array_keys($record));
        self::assertSame(['n1', 'submitted', 'INV-77', 12.5, 'Damaged', 'Riley R'], [$record['id'], $record['status'], $record['invoice_number'], $record['credit_amount'], $record['reason'], $record['sales_rep_name']]);
        self::assertStringNotContainsString(self::TOKEN, json_encode($answer, JSON_THROW_ON_ERROR));
        $stored = $this->store->findCreditNote('n1') ?? self::fail('No request.');
        self::assertSame(['submitted', 'INV-77', 12.5, 'Damaged', 'Riley R'], [$stored->status, $stored->invoiceNumber, $stored->creditAmount, $stored->reason, $stored->salesRepName]);
        self::assertNotNull($stored->submittedDate);
    }

    public function test_the_register_item_is_updated_as_the_source_did(): void
    {
        $this->service()->submit('203.0.113.9', $this->body());

        $record = $this->store->find('r1') ?? self::fail('No record.');
        self::assertSame(['in_progress', 'Credit note form submitted by Riley R for invoice INV-77 ($12.50)'], [$record->followUpStatus, $record->finalOutcome]);
    }

    public function test_a_blank_name_keeps_the_requests_and_a_blank_one_there_falls_back_to_the_address_in_the_outcome(): void
    {
        $this->store->insertCreditNote($this->note('n2', salesRepName: '', tokenHash: hash('sha256', 'other-token')));

        $this->service()->submit('203.0.113.9', ['id' => 'n2', 'token' => 'other-token', 'invoice_number' => 'X', 'credit_amount' => 1, 'sales_rep_name' => '']);

        self::assertSame('Credit note form submitted by rep@example.test for invoice X ($1.00)', $this->store->find('r1')?->finalOutcome);
        self::assertSame('Riley Rep', $this->store->findCreditNote('n1')?->salesRepName, 'another request is untouched');
    }

    public function test_a_request_with_no_record_still_submits(): void
    {
        $this->store->insertCreditNote($this->note('n3', emailRecordId: null, tokenHash: hash('sha256', 'third')));

        $answer = $this->service()->submit('203.0.113.9', ['id' => 'n3', 'token' => 'third', 'credit_amount' => 5]);

        self::assertTrue($answer['success']);
        self::assertSame('', $this->store->find('r1')?->finalOutcome, 'the other record is untouched');
    }

    public function test_it_is_single_use_and_the_second_submit_is_a_409_that_changes_nothing(): void
    {
        $this->service()->submit('203.0.113.9', $this->body());

        try {
            $this->service()->submit('203.0.113.9', $this->body(['invoice_number' => 'CHANGED', 'credit_amount' => 999]));
            self::fail('Expected a 409.');
        } catch (ArConflict $conflict) {
            self::assertSame([409, 'This form has already been submitted'], [$conflict->httpStatus(), $conflict->getMessage()]);
        }
        self::assertSame(['INV-77', 12.5], [$this->store->findCreditNote('n1')?->invoiceNumber, $this->store->findCreditNote('n1')?->creditAmount]);
    }

    public function test_the_loser_of_a_race_is_a_409_even_when_it_read_the_request_as_open(): void
    {
        // Another submit lands between this one's read and its conditional update.
        $racing = new class() extends InMemoryArStore {
            public bool $stealOnSubmit = true;

            public function submitCreditNote(string $id, string $invoiceNumber, float $creditAmount, string $reason, string $salesRepName, DateTimeImmutable $at): bool
            {
                if ($this->stealOnSubmit) {
                    $this->stealOnSubmit = false;
                    parent::submitCreditNote($id, 'WINNER', 1.0, '', 'W', $at);
                }

                return parent::submitCreditNote($id, $invoiceNumber, $creditAmount, $reason, $salesRepName, $at);
            }
        };
        $racing->insert(ArFixtures::record('r1'));
        $racing->insertCreditNote($this->note('n1'));
        $registry = new InMemoryFinanceMailboxRegistry([]);
        $loser = new ArPublicFormService($racing, $racing, new InMemoryArRateLimiter(fn (): int => $this->now), new FinanceMailService($registry, $this->ledger, $this->transport), new ArTime($this->clock, 'Australia/Melbourne'), 'ar@example.test', false);

        try {
            $loser->submit('203.0.113.9', $this->body());
            self::fail('Expected a 409.');
        } catch (ArConflict) {
            // expected
        }
        self::assertSame('WINNER', $racing->findCreditNote('n1')?->invoiceNumber);
    }

    /**
     * @return array<string, array{0: string, 1: string}> the status the request is in, which the form refuses
     */
    public static function closedStatuses(): array
    {
        return ['submitted' => ['submitted', 'x'], 'under review' => ['under_review', 'x'], 'approved' => ['approved', 'x'], 'rejected' => ['rejected', 'x'], 'completed' => ['completed', 'x']];
    }

    #[DataProvider('closedStatuses')]
    public function test_a_request_that_is_no_longer_open_refuses_the_form(string $status): void
    {
        $this->store->insertCreditNote($this->note('n9', status: $status, tokenHash: hash('sha256', 'closed')));

        $this->expectException(ArConflict::class);
        $this->service()->submit('203.0.113.9', ['id' => 'n9', 'token' => 'closed', 'credit_amount' => 1]);
    }

    public function test_awaiting_sales_response_is_open(): void
    {
        $this->store->insertCreditNote($this->note('n8', status: ArCreditNote::AWAITING_SALES_RESPONSE, tokenHash: hash('sha256', 'waiting')));

        $answer = $this->service()->submit('203.0.113.9', ['id' => 'n8', 'token' => 'waiting', 'credit_amount' => 1]);
        $record = $answer['record'];
        self::assertIsArray($record);
        self::assertSame('submitted', $record['status']);
    }

    public function test_an_expired_link_cannot_be_submitted_and_writes_nothing(): void
    {
        $this->clock->advance('+73 hours');

        try {
            $this->service()->submit('203.0.113.9', $this->body());
            self::fail('Expected a 410.');
        } catch (ArGone) {
            // expected
        }
        self::assertSame('form_sent', $this->store->findCreditNote('n1')?->status);
        self::assertSame('', $this->store->find('r1')?->finalOutcome);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badAmounts(): array
    {
        return ['text' => [['credit_amount' => 'lots']], 'negative' => [['credit_amount' => '-5']], 'too big' => [['credit_amount' => 1.0E13]], 'infinite' => [['credit_amount' => 'INF']], 'a list' => [['credit_amount' => [1]]]];
    }

    /**
     * @param array<string, mixed> $over
     */
    #[DataProvider('badAmounts')]
    public function test_a_bad_amount_is_a_422_and_leaves_the_form_open(array $over): void
    {
        try {
            $this->service()->submit('203.0.113.9', $this->body($over));
            self::fail('Expected a 422.');
        } catch (ArInvalid $invalid) {
            self::assertSame('credit_amount must be a number', $invalid->getMessage());
        }
        self::assertSame('form_sent', $this->store->findCreditNote('n1')?->status);
    }

    public function test_long_text_is_cut_to_its_bound_and_an_empty_amount_is_zero(): void
    {
        $answer = $this->service()->submit('203.0.113.9', $this->body(['invoice_number' => str_repeat('i', 300), 'reason' => str_repeat('r', 9000), 'sales_rep_name' => str_repeat('n', 900), 'credit_amount' => '']));

        $record = $answer['record'];
        self::assertIsArray($record);
        self::assertSame([100, 5000, 400, 0.0], [self::length($record['invoice_number']), self::length($record['reason']), self::length($record['sales_rep_name']), $record['credit_amount']]);
    }

    // ---- the team notice -----------------------------------------------------------------

    public function test_the_team_is_told_by_a_mail_to_the_ar_mailbox_itself_with_every_value_escaped(): void
    {
        $this->service()->submit('203.0.113.9', $this->body(['invoice_number' => '<b>INV</b>', 'reason' => "<script>x</script>\nline two", 'sales_rep_name' => 'A & B']));

        self::assertCount(1, $this->transport->standalone);
        $mail = $this->transport->standalone[0];
        self::assertSame(['AR', 'ar@example.test', ['ar@example.test'], null], [$mail->application, $mail->mailbox, $mail->to, $mail->actorUserId]);
        self::assertSame('Credit note form submitted — Acme Marine Pty Ltd', $mail->subject);
        self::assertSame('ar-credit-note-submitted:n1', $mail->idempotencyKey);
        self::assertStringContainsString('&lt;b&gt;INV&lt;/b&gt;', $mail->body);
        self::assertStringContainsString('A &amp; B', $mail->body);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;<br/>line two', $mail->body);
        self::assertStringContainsString('$12.50', $mail->body);
        self::assertStringNotContainsString('<script', $mail->body);
        self::assertStringNotContainsString(self::TOKEN, $mail->body);
    }

    public function test_with_sending_off_no_notice_is_sent_and_the_submit_still_works(): void
    {
        $answer = $this->service(sending: false)->submit('203.0.113.9', $this->body());

        self::assertTrue($answer['success']);
        self::assertSame([], $this->transport->standalone);
        self::assertSame([], $this->ledger->all());
    }

    public function test_a_failed_notice_never_fails_the_reps_submit_and_is_logged_without_content(): void
    {
        $answer = $this->service(failMail: new FinanceMailTransportFailed('Graph said no about Acme Marine'))->submit('203.0.113.9', $this->body());

        self::assertTrue($answer['success']);
        self::assertSame('submitted', $this->store->findCreditNote('n1')?->status);
        self::assertCount(1, $this->log);
        self::assertStringNotContainsString('Acme', $this->log[0]);
        self::assertStringContainsString(FinanceMailTransportFailed::class, $this->log[0]);
    }

    public function test_an_unexpected_exception_in_the_notice_is_swallowed_too(): void
    {
        $this->transport->failWith = new RuntimeException('boom');
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'ar@example.test', 'AR', 'AR', true, ['AR' => ['read' => true, 'send' => true]])]);
        $service = new ArPublicFormService($this->store, $this->store, new InMemoryArRateLimiter(fn (): int => $this->now), new FinanceMailService($registry, $this->ledger, $this->transport), new ArTime($this->clock, 'Australia/Melbourne'), 'ar@example.test', true, function (string $line): void {
            $this->log[] = $line;
        });

        self::assertTrue($service->submit('203.0.113.9', $this->body())['success']);
    }

    // ---- the limiter ---------------------------------------------------------------------

    public function test_an_address_over_its_limit_is_a_429_and_a_new_window_resets_it(): void
    {
        $limiter = new InMemoryArRateLimiter(fn (): int => $this->now);
        $service = $this->service(limiter: $limiter);
        for ($i = 0; $i < ArPublicFormService::IP_LIMIT; ++$i) {
            try {
                $service->show('203.0.113.9', ['id' => 'ghost' . $i, 'token' => 'x']);
            } catch (ArNotFound) {
                // each is a different form, so only the address limit counts
            }
        }

        try {
            $service->show('203.0.113.9', ['id' => 'n1', 'token' => self::TOKEN]);
            self::fail('Expected a 429.');
        } catch (ArRateLimited $limited) {
            self::assertSame([429, 'FINANCE_AR_RATE_LIMITED', 'Too many attempts. Try again later.'], [$limited->httpStatus(), $limited->errorCode(), $limited->getMessage()]);
        }
        self::assertSame('form_sent', $service->show('198.51.100.7', ['id' => 'n1', 'token' => self::TOKEN])['status'], 'another address is unaffected');

        $this->now += ArPublicFormService::WINDOW_SECONDS + 1;
        self::assertSame('form_sent', $service->show('203.0.113.9', ['id' => 'n1', 'token' => self::TOKEN])['status']);
    }

    public function test_one_form_hammered_from_many_addresses_is_a_429(): void
    {
        $service = $this->service();
        for ($i = 0; $i < ArPublicFormService::FORM_LIMIT; ++$i) {
            try {
                $service->show('10.0.0.' . $i, ['id' => 'n1', 'token' => 'guess-' . $i]);
            } catch (ArNotFound) {
                // wrong token
            }
        }

        $this->expectException(ArRateLimited::class);
        $service->show('10.9.9.9', ['id' => 'n1', 'token' => self::TOKEN]);
    }

    public function test_a_missing_address_is_counted_as_one_and_the_limit_applies_before_any_lookup(): void
    {
        $service = $this->service();
        for ($i = 0; $i < ArPublicFormService::IP_LIMIT; ++$i) {
            try {
                $service->show(null, []);
            } catch (ArInvalid) {
                // missing parts
            }
        }

        $this->expectException(ArRateLimited::class);
        $service->show('', []);
    }
}
