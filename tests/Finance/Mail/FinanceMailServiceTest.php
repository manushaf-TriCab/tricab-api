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
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailConflict;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * The rules of finance mail, one test each, against the in-memory twins. No real
 * mail is sent by any test: the transport is a recording double.
 */
final class FinanceMailServiceTest extends TestCase
{
    private InMemoryFinanceMailLedger $ledger;

    private RecordingFinanceMailTransport $transport;

    private FinanceMailService $service;

    private int $ids = 0;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingFinanceMailTransport($this->ledger);
        $this->ids = 0;
        $this->service = $this->serviceWith($this->transport);
    }

    private function serviceWith(FinanceMailTransport $transport, ?FinanceMailLedger $ledger = null): FinanceMailService
    {
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ap@tricab.com', 'Accounts Payable', 'AP', true, [
                'AP' => ['read' => true, 'send' => true],
                'TASK' => ['read' => false, 'send' => true],
                'AR' => ['read' => true, 'send' => false],
            ]),
            new FinanceMailbox('m2', 'old@tricab.com', 'Retired', '', false, ['AP' => ['read' => true, 'send' => true]]),
        ]);

        return new FinanceMailService(
            $registry,
            $ledger ?? $this->ledger,
            $transport,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-07 01:02:03.456789', new DateTimeZone('UTC')),
            function (): string {
                return str_pad((string) ++$this->ids, 32, '0', STR_PAD_LEFT);
            },
        );
    }

    /**
     * @param list<string> $to
     * @param array{
     *     application?: string, mailbox?: string, subject?: string, body?: string,
     *     cc?: list<string>, bcc?: list<string>, contentType?: string,
     *     attachments?: list<FinanceMailAttachment>, key?: string|null, actor?: int|null
     * } $over
     */
    private function message(array $to = ['jane@tricab.com'], array $over = []): FinanceMailMessage
    {
        return new FinanceMailMessage(
            application: $over['application'] ?? 'TASK',
            mailbox: $over['mailbox'] ?? 'ap@tricab.com',
            to: $to,
            subject: $over['subject'] ?? 'Reminder',
            body: $over['body'] ?? '<p>Hello</p>',
            cc: $over['cc'] ?? [],
            bcc: $over['bcc'] ?? [],
            contentType: $over['contentType'] ?? 'HTML',
            attachments: $over['attachments'] ?? [],
            idempotencyKey: $over['key'] ?? null,
            actorUserId: $over['actor'] ?? null,
        );
    }

    public function test_a_send_writes_a_pending_row_first_then_sent(): void
    {
        $result = $this->service->send($this->message(over: ['actor' => 7, 'key' => 'k-1']));

        self::assertFalse($result->deduplicated);
        self::assertSame(FinanceMailRules::STATUS_SENT, $result->delivery->status);
        self::assertCount(1, $this->transport->sent);
        // The transport saw a pending row for this very send: the ledger is written first.
        self::assertSame([FinanceMailRules::STATUS_PENDING], $this->transport->ledgerStatusesAtDelivery);

        $rows = $this->ledger->all();
        self::assertCount(1, $rows);
        self::assertSame(FinanceMailRules::STATUS_SENT, $rows[0]->status);
        self::assertSame('2026-10-07 01:02:03.456789', $rows[0]->sentAt?->format('Y-m-d H:i:s.u'));
        self::assertSame(7, $rows[0]->actorUserId);
        self::assertNull($rows[0]->errorMessage);
    }

    public function test_a_send_may_have_no_actor(): void
    {
        $result = $this->service->send($this->message());

        self::assertNull($result->delivery->actorUserId);
        self::assertNull($result->delivery->idempotencyKey);
    }

    public function test_the_ledger_keeps_a_hash_and_names_never_the_content_and_no_bcc(): void
    {
        $this->service->send($this->message(['a@tricab.com'], [
            'body' => 'secret body text',
            'cc' => ['c@tricab.com'],
            'bcc' => ['hidden@tricab.com'],
            'attachments' => [new FinanceMailAttachment('statement.pdf', 'PDF-BYTES')],
        ]));

        $row = $this->ledger->all()[0];
        self::assertSame(hash('sha256', 'secret body text'), $row->bodySha256);
        self::assertSame(['statement.pdf'], $row->attachmentNames);
        self::assertSame(['a@tricab.com'], $row->to);
        self::assertSame(['c@tricab.com'], $row->cc);
        $dump = json_encode($row, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret body text', $dump);
        self::assertStringNotContainsString('PDF-BYTES', $dump);
        self::assertStringNotContainsString('hidden@tricab.com', $dump);
        // The transport still gets the bcc.
        self::assertSame(['hidden@tricab.com'], $this->transport->sent[0]->bcc);
    }

    public function test_a_send_is_refused_without_a_send_grant_and_leaves_no_row(): void
    {
        foreach ([
            'an app with no grant' => ['application' => 'CREDIT'],
            'an app that may only read' => ['application' => 'AR'],
            'an unknown mailbox' => ['mailbox' => 'nobody@tricab.com'],
            'an inactive mailbox' => ['mailbox' => 'old@tricab.com', 'application' => 'AP'],
        ] as $case => $over) {
            try {
                $this->service->send($this->message(over: $over));
                self::fail('Sent for ' . $case);
            } catch (FinanceMailRefused) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame([], $this->ledger->all());
        self::assertSame([], $this->transport->sent);
    }

    public function test_the_mailbox_address_is_matched_in_any_case(): void
    {
        $result = $this->service->send($this->message(over: ['mailbox' => '  AP@Tricab.COM ']));

        self::assertSame('ap@tricab.com', $result->delivery->mailbox);
        self::assertSame('ap@tricab.com', $this->transport->sent[0]->mailbox);
    }

    public function test_recipients_are_lower_cased_and_de_duplicated(): void
    {
        $this->service->send($this->message(['Jane@Tricab.com', 'jane@tricab.com ', 'bob@tricab.com']));

        self::assertSame(['jane@tricab.com', 'bob@tricab.com'], $this->transport->sent[0]->to);
    }

    public function test_at_least_one_valid_address_without_a_display_name_is_required(): void
    {
        foreach ([[], ['not-an-address'], ['Jane Doe <jane@tricab.com>'], ['']] as $to) {
            try {
                $this->service->send($this->message($to));
                self::fail('Accepted ' . json_encode($to));
            } catch (FinanceMailInvalid) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->ledger->all());
    }

    public function test_no_more_than_fifty_recipients_per_list_or_in_all(): void
    {
        $many = static fn (int $n, string $prefix): array => array_map(static fn (int $i): string => "{$prefix}{$i}@tricab.com", range(1, $n));

        $this->service->send($this->message($many(50, 'a')));
        self::assertCount(1, $this->transport->sent);

        $this->expectInvalid(fn () => $this->service->send($this->message($many(51, 'a'))));
        $this->expectInvalid(fn () => $this->service->send($this->message($many(30, 'a'), ['cc' => $many(21, 'c')])));
        $this->expectInvalid(fn () => $this->service->send($this->message(['a@tricab.com'], ['bcc' => $many(51, 'b')])));
        self::assertCount(1, $this->ledger->all());
    }

    public function test_the_subject_is_required_and_at_most_five_hundred_characters(): void
    {
        $this->expectInvalid(fn () => $this->service->send($this->message(over: ['subject' => '   '])));
        $this->expectInvalid(fn () => $this->service->send($this->message(over: ['subject' => str_repeat('é', 501)])));

        $result = $this->service->send($this->message(over: ['subject' => '  ' . str_repeat('é', 500) . '  ']));
        self::assertSame(500, mb_strlen($result->delivery->subject));
    }

    public function test_the_body_is_at_most_one_mebibyte(): void
    {
        $this->expectInvalid(fn () => $this->service->send($this->message(over: ['body' => str_repeat('x', FinanceMailRules::MAX_BODY_BYTES + 1)])));
        $this->service->send($this->message(over: ['body' => str_repeat('x', FinanceMailRules::MAX_BODY_BYTES)]));
        self::assertCount(1, $this->transport->sent);
    }

    public function test_the_content_type_is_html_or_text_in_any_case(): void
    {
        $this->expectInvalid(fn () => $this->service->send($this->message(over: ['contentType' => 'markdown'])));
        $this->service->send($this->message(over: ['contentType' => 'text']));

        self::assertSame('TEXT', $this->transport->sent[0]->contentType);
    }

    public function test_attachments_are_limited_in_number_size_and_name(): void
    {
        $file = static fn (string $name, string $bytes = 'x'): FinanceMailAttachment => new FinanceMailAttachment($name, $bytes);

        $this->expectInvalid(fn () => $this->service->send($this->message(over: ['attachments' => array_map(static fn (int $i): FinanceMailAttachment => $file("f{$i}.txt"), range(1, 11))])));
        $this->expectInvalid(fn () => $this->service->send($this->message(over: ['attachments' => [$file('big.bin', str_repeat('x', FinanceMailRules::MAX_ATTACHMENT_BYTES + 1))]])));
        $this->expectInvalid(fn () => $this->service->send($this->message(over: ['attachments' => [$file('a.bin', str_repeat('x', 2000000)), $file('b.bin', str_repeat('x', 1200000))]])));
        foreach (['', 'dir/file.txt', 'dir\\file.txt', "bad\x00name", str_repeat('n', 256)] as $name) {
            $this->expectInvalid(fn () => $this->service->send($this->message(over: ['attachments' => [$file($name)]])));
        }
        self::assertSame([], $this->ledger->all());

        // Ten files that add up to exactly the limit are fine.
        $tenth = FinanceMailRules::MAX_ATTACHMENT_BYTES - 9 * 314572;
        $this->service->send($this->message(over: ['attachments' => [
            ...array_map(static fn (int $i): FinanceMailAttachment => $file("f{$i}.bin", str_repeat('x', 314572)), range(1, 9)),
            $file('f10.bin', str_repeat('x', $tenth)),
        ]]));
        self::assertCount(1, $this->transport->sent);
    }

    public function test_a_key_already_sent_is_not_sent_again_and_returns_the_earlier_delivery(): void
    {
        $first = $this->service->send($this->message(over: ['key' => 'task-reminder-1']));
        $again = $this->service->send($this->message(['someone-else@tricab.com'], ['key' => '  task-reminder-1 ']));

        self::assertTrue($again->deduplicated);
        self::assertSame($first->delivery->id, $again->delivery->id);
        self::assertCount(1, $this->transport->sent);
        self::assertCount(1, $this->ledger->all());
    }

    public function test_a_key_that_failed_is_a_conflict_so_the_caller_retries_under_a_new_key(): void
    {
        $this->transport->failWith = new FinanceMailTransportFailed('Graph said no.');
        try {
            $this->service->send($this->message(over: ['key' => 'k-fail']));
            self::fail('Expected a transport failure.');
        } catch (FinanceMailTransportFailed) {
            self::addToAssertionCount(1);
        }
        $this->transport->failWith = null;

        try {
            $this->service->send($this->message(over: ['key' => 'k-fail']));
            self::fail('Expected a conflict.');
        } catch (FinanceMailConflict $conflict) {
            self::assertSame(FinanceMailRules::STATUS_FAILED, $conflict->delivery->status);
        }
        self::assertSame([], $this->transport->sent);

        $retry = $this->service->send($this->message(over: ['key' => 'k-fail-2']));
        self::assertFalse($retry->deduplicated);
        self::assertCount(1, $this->transport->sent);
    }

    public function test_a_key_still_pending_is_a_conflict_too(): void
    {
        $this->ledger->recordPending(new FinanceMailDelivery(
            'dead', 'TASK', null, 'ap@tricab.com', ['x@tricab.com'], [], 'S', str_repeat('a', 64), [], 'k-pending',
            FinanceMailRules::STATUS_PENDING, null, new DateTimeImmutable('2026-10-07 00:00:00', new DateTimeZone('UTC')), null,
        ));

        $this->expectException(FinanceMailConflict::class);
        $this->service->send($this->message(over: ['key' => 'k-pending']));
    }

    public function test_a_key_is_unique_per_app_not_across_apps(): void
    {
        $this->service->send($this->message(over: ['key' => 'shared', 'application' => 'TASK']));
        $other = $this->service->send($this->message(over: ['key' => 'shared', 'application' => 'AP']));

        self::assertFalse($other->deduplicated);
        self::assertCount(2, $this->transport->sent);
    }

    public function test_a_key_longer_than_two_hundred_characters_is_refused(): void
    {
        $this->expectInvalid(fn () => $this->service->send($this->message(over: ['key' => str_repeat('k', 201)])));
        $this->service->send($this->message(over: ['key' => str_repeat('k', 200)]));
        self::assertCount(1, $this->transport->sent);
    }

    public function test_two_sends_without_a_key_are_both_sent(): void
    {
        $this->service->send($this->message());
        $this->service->send($this->message());

        self::assertCount(2, $this->transport->sent);
        self::assertCount(2, $this->ledger->all());
    }

    public function test_a_transport_failure_marks_the_row_failed_with_the_error_and_rethrows_with_the_delivery(): void
    {
        $this->transport->failWith = new FinanceMailTransportFailed(str_repeat('e', 700));

        try {
            $this->service->send($this->message(over: ['key' => 'k']));
            self::fail('Expected a transport failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertNotNull($failure->delivery);
            self::assertSame(FinanceMailRules::STATUS_FAILED, $failure->delivery->status);
        }

        $row = $this->ledger->all()[0];
        self::assertSame(FinanceMailRules::STATUS_FAILED, $row->status);
        self::assertSame(500, strlen((string) $row->errorMessage));
        self::assertNull($row->sentAt);
    }

    public function test_any_other_failure_in_the_transport_is_a_failed_row_too(): void
    {
        $this->transport->failWith = new RuntimeException('socket closed');

        try {
            $this->service->send($this->message());
            self::fail('Expected a transport failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertSame('socket closed', $failure->getMessage());
        }
        self::assertSame(FinanceMailRules::STATUS_FAILED, $this->ledger->all()[0]->status);
    }

    public function test_with_mail_switched_off_a_send_leaves_a_suppressed_row_and_sends_nothing(): void
    {
        $service = $this->serviceWith(new DisabledFinanceMailTransport());

        $result = $service->send($this->message(over: ['key' => 'off-1']));

        self::assertFalse($result->deduplicated);
        self::assertTrue($result->delivery->isSuppressed());
        self::assertFalse($result->delivery->isSent());
        $row = $this->ledger->all()[0];
        self::assertSame(FinanceMailRules::STATUS_SUPPRESSED, $row->status);
        self::assertStringContainsString('Mail is switched off', (string) $row->errorMessage);
        self::assertNull($row->sentAt);
        self::assertSame([], $this->transport->sent);
    }

    public function test_a_suppressed_key_is_sent_when_it_is_sent_again_and_reuses_its_row(): void
    {
        $this->serviceWith(new DisabledFinanceMailTransport())->send($this->message(over: ['key' => 'off-2']));
        $suppressedId = $this->ledger->all()[0]->id;

        $result = $this->service->send($this->message(over: ['key' => 'off-2', 'subject' => 'Now it goes']));

        self::assertFalse($result->deduplicated);
        self::assertTrue($result->delivery->isSent());
        self::assertSame($suppressedId, $result->delivery->id);
        self::assertCount(1, $this->ledger->all());
        $row = $this->ledger->all()[0];
        self::assertSame(FinanceMailRules::STATUS_SENT, $row->status);
        self::assertSame('Now it goes', $row->subject);
        self::assertNull($row->errorMessage);
        self::assertSame([FinanceMailRules::STATUS_PENDING], $this->transport->ledgerStatusesAtDelivery);
        self::assertCount(1, $this->transport->sent);
    }

    public function test_a_suppressed_key_sent_again_while_mail_is_still_off_stays_suppressed_and_is_not_a_conflict(): void
    {
        $off = $this->serviceWith(new DisabledFinanceMailTransport());
        $off->send($this->message(over: ['key' => 'off-3']));

        $again = $off->send($this->message(over: ['key' => 'off-3']));

        self::assertTrue($again->delivery->isSuppressed());
        self::assertCount(1, $this->ledger->all());
    }

    public function test_a_suppressed_key_that_then_fails_is_a_conflict_like_any_failed_key(): void
    {
        $this->serviceWith(new DisabledFinanceMailTransport())->send($this->message(over: ['key' => 'off-4']));
        $this->transport->failWith = new FinanceMailTransportFailed('nope');

        try {
            $this->service->send($this->message(over: ['key' => 'off-4']));
            self::fail('Expected a transport failure.');
        } catch (FinanceMailTransportFailed) {
        }

        $this->expectException(FinanceMailConflict::class);
        $this->service->send($this->message(over: ['key' => 'off-4']));
    }

    public function test_a_caller_that_loses_a_race_for_the_key_gets_the_winners_delivery(): void
    {
        $winner = new FinanceMailDelivery(
            'win', 'TASK', null, 'ap@tricab.com', ['x@tricab.com'], [], 'S', str_repeat('a', 64), [], 'raced',
            FinanceMailRules::STATUS_SENT, null, new DateTimeImmutable('2026-10-07 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-10-07 00:00:01', new DateTimeZone('UTC')),
        );
        // The first look finds nothing; by the time this send writes, another caller has.
        $racing = new class ($winner) implements FinanceMailLedger {
            private int $looks = 0;

            public function __construct(private FinanceMailDelivery $winner)
            {
            }

            public function findByIdempotencyKey(string $application, string $key): ?FinanceMailDelivery
            {
                return ++$this->looks === 1 ? null : $this->winner;
            }

            public function findById(string $id): ?FinanceMailDelivery
            {
                return null;
            }

            public function recordPending(FinanceMailDelivery $delivery): bool
            {
                return false;
            }

            public function markSent(string $id, DateTimeImmutable $at): void
            {
            }

            public function markFailed(string $id, string $error): void
            {
            }

            public function markSuppressed(string $id, string $reason): void
            {
            }

            public function reopenSuppressed(FinanceMailDelivery $delivery): bool
            {
                return false;
            }
        };

        $result = $this->serviceWith($this->transport, $racing)->send($this->message(over: ['key' => 'raced']));

        self::assertTrue($result->deduplicated);
        self::assertSame('win', $result->delivery->id);
        self::assertSame([], $this->transport->sent);
    }

    private function expectInvalid(callable $send): void
    {
        try {
            $send();
            self::fail('Expected FinanceMailInvalid.');
        } catch (FinanceMailInvalid) {
            self::addToAssertionCount(1);
        }
    }
}
