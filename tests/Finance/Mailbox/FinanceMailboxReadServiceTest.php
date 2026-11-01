<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mailbox;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Shared\Mailbox\DisabledMailboxReader;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

/**
 * The finance rules around a mailbox read, over the in-memory twins: no Graph, no
 * mailbox, no network. Each rule is the portal's (`mail_messages_list`,
 * `_mailbox_capability`, `_mail_actor`, `_parse_mail_window`).
 */
final class FinanceMailboxReadServiceTest extends TestCase
{
    private const SECRET_SUBJECT = 'Confidential subject of a supplier';

    private InMemoryMailboxReader $reader;

    private InMemoryFinanceAuditLog $audit;

    private InMemoryFinanceAccessScopeReader $scopes;

    private FinanceMailboxReadService $service;

    protected function setUp(): void
    {
        $this->reader = new InMemoryMailboxReader();
        $this->reader->add('ap@example.test', self::message('m1', '2026-10-05T01:00:00Z'), [
            new MailboxAttachment(new MailboxAttachmentSummary('a1', 'invoice.pdf', 'application/pdf', 5), 'bytes'),
        ]);
        $this->reader->add('ap@example.test', self::message('m2', '2026-10-06T01:00:00Z'));
        $this->reader->add('ar@example.test', self::message('r1', '2026-10-06T02:00:00Z'));
        $this->reader->add('send-only@example.test', self::message('s1', '2026-10-06T03:00:00Z'));
        $this->reader->add('old@example.test', self::message('o1', '2026-10-06T04:00:00Z'));

        $this->audit = new InMemoryFinanceAuditLog();
        $this->scopes = new InMemoryFinanceAccessScopeReader();
        $this->service = $this->serviceOver($this->reader);
    }

    private function serviceOver(MailboxReader $reader, ?FinanceAuditLog $audit = null): FinanceMailboxReadService
    {
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('0000000000000000000000000000000a', 'ap@example.test', 'Accounts Payable', 'AP2', true, ['AP2' => ['read' => true, 'send' => true], 'TASK' => ['read' => false, 'send' => true]]),
            new FinanceMailbox('0000000000000000000000000000000b', 'ar@example.test', 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('0000000000000000000000000000000c', 'send-only@example.test', 'Send only', '', true, ['AP2' => ['read' => false, 'send' => true]]),
            new FinanceMailbox('0000000000000000000000000000000d', 'old@example.test', 'Retired', '', false, ['AP2' => ['read' => true, 'send' => true]]),
        ]);

        return new FinanceMailboxReadService($registry, $reader, $this->scopes, $audit ?? $this->audit, FixedClock::utc('2026-10-07T00:00:00Z'));
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private static function message(string $id, string $received): MailboxMessage
    {
        return new MailboxMessage(
            new MailboxMessageSummary($id, self::SECRET_SUBJECT, 'supplier@example.test', 'Supplier', self::at($received), true, 'preview'),
            MailboxMessage::BODY_HTML,
            '<p>private body</p>',
        );
    }

    /**
     * @param list<MailboxMessageSummary> $messages
     * @return list<string>
     */
    private function ids(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            $ids[] = $message->id;
        }

        return $ids;
    }

    public function test_an_app_with_a_read_grant_reads_the_window_newest_first(): void
    {
        $messages = $this->service->listMessages('AP2', 'AP@example.test', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-07T00:00:00Z'));

        self::assertSame(['m2', 'm1'], $this->ids($messages));
    }

    public function test_a_send_grant_is_not_a_read_grant(): void
    {
        $this->expectException(FinanceMailRefused::class);
        $this->expectExceptionMessage('AP2 is not allowed to read the mailbox send-only@example.test');
        $this->service->listMessages('AP2', 'send-only@example.test', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-02T00:00:00Z'));
    }

    public function test_an_app_with_no_grant_an_inactive_mailbox_and_an_unknown_one_are_refused_alike(): void
    {
        foreach ([['TASK', 'ap@example.test'], ['AP2', 'ar@example.test'], ['AP2', 'old@example.test'], ['AP2', 'nobody@example.test'], ['AP2', '  ']] as [$app, $mailbox]) {
            try {
                $this->service->listMessages($app, $mailbox, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-02T00:00:00Z'));
                self::fail("$app reading $mailbox must be refused");
            } catch (FinanceMailRefused $refusal) {
                self::assertStringContainsString('is not allowed to read the mailbox', $refusal->getMessage());
            }
        }
        self::assertSame(0, $this->reader->reads, 'a refused read never reaches the reader');
        self::assertSame([], $this->audit->events);
    }

    public function test_an_unknown_application_is_invalid(): void
    {
        $this->expectException(FinanceMailInvalid::class);
        $this->service->listMessages('NOPE', 'ap@example.test', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-02T00:00:00Z'));
    }

    public function test_a_window_may_not_exceed_31_days_and_may_not_run_backwards(): void
    {
        $exactly = $this->service->listMessages('AP2', 'ap@example.test', self::at('2026-09-06T00:00:00Z'), self::at('2026-10-07T00:00:00Z'));
        self::assertCount(2, $exactly, '31 days exactly is allowed');

        foreach ([
            [self::at('2026-09-05T23:59:59Z'), self::at('2026-10-07T00:00:00Z'), '31 days'],
            [self::at('2026-10-07T00:00:00Z'), self::at('2026-10-06T00:00:00Z'), 'must not be after'],
        ] as [$from, $to, $needle]) {
            try {
                $this->service->listMessages('AP2', 'ap@example.test', $from, $to);
                self::fail('The window must be refused.');
            } catch (FinanceMailInvalid $invalid) {
                self::assertStringContainsString($needle, $invalid->getMessage());
            }
        }
    }

    public function test_the_message_count_is_clamped_to_500_and_never_below_one(): void
    {
        $reader = new class () implements MailboxReader {
            /** @var list<int> */
            public array $limits = [];

            public function listMessages(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
            {
                $this->limits[] = $maxMessages;

                return [];
            }

            public function getMessage(string $mailbox, string $messageId): MailboxMessage
            {
                throw new RuntimeException('not used');
            }

            public function listAttachments(string $mailbox, string $messageId): array
            {
                throw new RuntimeException('not used');
            }

            public function getAttachment(string $mailbox, string $messageId, string $attachmentId): MailboxAttachment
            {
                throw new RuntimeException('not used');
            }
        };
        $service = $this->serviceOver($reader);
        $from = self::at('2026-10-01T00:00:00Z');
        $to = self::at('2026-10-02T00:00:00Z');

        foreach ([null, 0, 5000, 500, 250, -3, 1] as $asked) {
            $service->listMessages('AP2', 'ap@example.test', $from, $to, $asked);
        }

        self::assertSame([500, 500, 500, 500, 250, 1, 1], $reader->limits);
    }

    public function test_one_message_its_attachments_and_one_attachment_are_read_through_the_grant(): void
    {
        self::assertSame('<p>private body</p>', $this->service->getMessage('AP2', 'ap@example.test', 'm1')->body);
        self::assertSame(['invoice.pdf'], array_map(static fn (MailboxAttachmentSummary $a): string => $a->name, $this->service->listAttachments('AP2', 'ap@example.test', 'm1')));
        self::assertSame('bytes', $this->service->getAttachment('AP2', 'ap@example.test', 'm1', 'a1')->content);
    }

    public function test_message_ids_are_required_and_at_most_1000_characters(): void
    {
        foreach (['', '   ', str_repeat('x', 1001)] as $id) {
            try {
                $this->service->getMessage('AP2', 'ap@example.test', $id);
                self::fail('A bad id must be refused.');
            } catch (FinanceMailInvalid) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(FinanceMailInvalid::class);
        $this->service->getAttachment('AP2', 'ap@example.test', 'm1', '');
    }

    public function test_a_missing_message_is_the_readers_not_found(): void
    {
        $this->expectException(MailboxItemNotFound::class);
        $this->service->getMessage('AP2', 'ap@example.test', 'does-not-exist');
    }

    public function test_a_person_whose_mailbox_scope_leaves_it_out_is_refused_and_an_unscoped_person_is_not(): void
    {
        $this->scopes->grant(7, 'AP2', FinanceAccessScope::TYPE_MAILBOX, 'other@example.test');
        $from = self::at('2026-10-01T00:00:00Z');
        $to = self::at('2026-10-07T00:00:00Z');

        self::assertNotEmpty($this->service->listMessages('AP2', 'ap@example.test', $from, $to, null, 8), 'no scope rows: unrestricted, as in the portal');
        self::assertNotEmpty($this->service->listMessages('AP2', 'ap@example.test', $from, $to, null, null), 'a job has no person and no scope');

        $this->expectException(FinanceMailRefused::class);
        $this->service->listMessages('AP2', 'ap@example.test', $from, $to, null, 7);
    }

    public function test_a_scope_that_names_the_mailbox_allows_it_in_any_case(): void
    {
        $this->scopes->grant(7, 'AP2', FinanceAccessScope::TYPE_MAILBOX, 'AP@Example.test');

        self::assertCount(1, $this->service->listAttachments('AP2', 'ap@example.test', 'm1', 7));
    }

    public function test_every_read_leaves_an_audit_row_with_facts_and_no_content(): void
    {
        $this->service->listMessages('AP2', 'ap@example.test', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-07T00:00:00Z'), null, 7);
        $this->service->getMessage('AP2', 'ap@example.test', 'm1', 7);
        $this->service->listAttachments('AP2', 'ap@example.test', 'm1', 7);
        $this->service->getAttachment('AP2', 'ap@example.test', 'm1', 'a1');

        $events = $this->audit->events;
        self::assertSame(
            [
                FinanceMailboxReadService::ACTION_MESSAGES_LISTED,
                FinanceMailboxReadService::ACTION_MESSAGE_READ,
                FinanceMailboxReadService::ACTION_ATTACHMENTS_LISTED,
                FinanceMailboxReadService::ACTION_ATTACHMENT_READ,
            ],
            array_map(static fn (FinanceAuditEvent $e): string => $e->action, $events),
        );
        self::assertSame(2, $events[0]->details['count']);
        self::assertSame('2026-10-01T00:00:00Z', $events[0]->details['from']);
        self::assertSame(hash('sha256', 'm1'), $events[1]->details['messageIdSha256']);
        self::assertSame(1, $events[2]->details['count']);
        self::assertSame(5, $events[3]->details['bytes']);
        self::assertSame(7, $events[0]->actorUserId);
        self::assertNull($events[3]->actorUserId, 'a job has no actor');
        foreach ($events as $event) {
            self::assertSame('mailbox', $event->entityType);
            self::assertSame('0000000000000000000000000000000a', $event->entityId);
            self::assertSame('AP2', $event->application);
            self::assertSame('2026-10-07T00:00:00+00:00', $event->at->format('c'));
            $text = json_encode($event->details, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::SECRET_SUBJECT, $text);
            self::assertStringNotContainsString('private body', $text);
            self::assertStringNotContainsString('invoice.pdf', $text);
            self::assertStringNotContainsString('"m1"', $text, 'ids are kept only as a hash');
        }
    }

    public function test_a_read_whose_audit_row_cannot_be_written_returns_no_mail(): void
    {
        $failing = new class () implements FinanceAuditLog {
            public function record(FinanceAuditEvent $event): void
            {
                throw new RuntimeException('audit table unavailable');
            }
        };
        $service = $this->serviceOver($this->reader, $failing);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit table unavailable');
        $service->getMessage('AP2', 'ap@example.test', 'm1');
    }

    public function test_a_failed_read_leaves_no_audit_row(): void
    {
        try {
            $this->service->getMessage('AP2', 'ap@example.test', 'does-not-exist');
        } catch (MailboxItemNotFound) {
            // expected
        }

        self::assertSame([], $this->audit->events);
    }

    public function test_with_reading_switched_off_every_call_refuses_and_no_audit_row_is_written(): void
    {
        $service = $this->serviceOver(new DisabledMailboxReader());

        $this->expectException(MailboxReadUnavailable::class);
        try {
            $service->listMessages('AP2', 'ap@example.test', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-02T00:00:00Z'));
        } finally {
            self::assertSame([], $this->audit->events);
        }
    }
}
