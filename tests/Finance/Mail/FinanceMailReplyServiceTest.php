<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * How the shared mail service uses a reply-capable transport (T5.9): a message with a reply target goes through
 * `deliverReply`, a plain transport gets a standalone message, and the ledger and the result are the same either way.
 */
final class FinanceMailReplyServiceTest extends TestCase
{
    private InMemoryFinanceMailLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryFinanceMailLedger();
    }

    private function service(FinanceMailTransport $transport): FinanceMailService
    {
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ar@example.test', 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
        ]);
        $n = 0;

        return new FinanceMailService(
            $registry,
            $this->ledger,
            $transport,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-08 01:02:03', new DateTimeZone('UTC')),
            static function () use (&$n): string {
                return str_pad((string) ++$n, 32, '0', STR_PAD_LEFT);
            },
        );
    }

    private function message(?string $replyTo, string $key = 'k1'): FinanceMailMessage
    {
        return new FinanceMailMessage('AR', 'AR@example.test', ['Pat@Customer.example.test'], 'Re: Hi', '<p>x</p>', idempotencyKey: $key, replyToMessageId: $replyTo);
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return array_map(static fn ($row): string => $row->status, $this->ledger->all());
    }

    public function test_a_reply_target_reaches_the_reply_method_with_the_normalised_message_and_the_result_says_threaded(): void
    {
        $transport = new RecordingReplyMailTransport();

        $result = $this->service($transport)->send($this->message('AAMk-1'));

        self::assertTrue($result->threaded);
        self::assertSame([], $transport->standalone);
        self::assertCount(1, $transport->replies);
        self::assertSame('AAMk-1', $transport->replies[0]['replyTo']);
        self::assertSame('ar@example.test', $transport->replies[0]['message']->mailbox, 'the mailbox is normalised');
        self::assertSame(['pat@customer.example.test'], $transport->replies[0]['message']->to);
        self::assertSame('AAMk-1', $transport->replies[0]['message']->replyToMessageId, 'normalising keeps the target');
        self::assertSame('sent', $result->delivery->status);
        self::assertSame(['sent'], $this->statuses());
    }

    public function test_a_reply_that_went_as_a_new_message_is_a_sent_row_that_is_not_threaded(): void
    {
        $transport = new RecordingReplyMailTransport();
        $transport->threads = false;

        $result = $this->service($transport)->send($this->message('AAMk-gone'));

        self::assertFalse($result->threaded);
        self::assertSame('sent', $result->delivery->status);
    }

    public function test_no_reply_target_is_a_plain_send_even_on_a_reply_capable_transport(): void
    {
        $transport = new RecordingReplyMailTransport();

        $result = $this->service($transport)->send($this->message(null));

        self::assertFalse($result->threaded);
        self::assertCount(1, $transport->standalone);
        self::assertSame([], $transport->replies);
    }

    public function test_a_transport_that_cannot_thread_gets_a_standalone_message(): void
    {
        $transport = new RecordingFinanceMailTransport($this->ledger);

        $result = $this->service($transport)->send($this->message('AAMk-1'));

        self::assertFalse($result->threaded);
        self::assertCount(1, $transport->sent);
    }

    public function test_the_pending_row_is_written_before_the_reply_and_a_failure_marks_it_failed(): void
    {
        $transport = new RecordingReplyMailTransport();
        $transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        try {
            $this->service($transport)->send($this->message('AAMk-1'));
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertNotNull($failure->delivery);
            self::assertSame('failed', $failure->delivery->status);
        }

        self::assertSame(['failed'], $this->statuses());
    }

    public function test_a_switched_off_transport_suppresses_a_reply_and_the_row_says_so(): void
    {
        $result = $this->service(new DisabledFinanceMailTransport())->send($this->message('AAMk-1'));

        self::assertTrue($result->delivery->isSuppressed());
        self::assertFalse($result->threaded);
        self::assertSame(['suppressed'], $this->statuses());
    }

    public function test_a_repeated_key_returns_the_earlier_delivery_without_sending_again(): void
    {
        $transport = new RecordingReplyMailTransport();
        $service = $this->service($transport);
        $service->send($this->message('AAMk-1'));

        $again = $service->send($this->message('AAMk-1'));

        self::assertTrue($again->deduplicated);
        self::assertCount(1, $transport->replies);
    }

    public function test_an_unexpected_exception_from_a_reply_is_a_failed_send_with_the_ledger_marked(): void
    {
        $transport = new RecordingReplyMailTransport();
        $transport->failWith = new RuntimeException('boom');

        try {
            $this->service($transport)->send($this->message('AAMk-1'));
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed) {
            // expected
        }

        self::assertSame(['failed'], $this->statuses());
    }
}
