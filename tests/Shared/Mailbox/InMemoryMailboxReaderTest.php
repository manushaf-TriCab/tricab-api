<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Shared\Mailbox;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Shared\Mailbox\DisabledMailboxReader;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

/**
 * The twin must behave as Graph does where the finance rules depend on it: newest first,
 * the window inclusive, the cap applied, a missing item a 404-style failure. And the
 * "off" reader must refuse rather than answer "no mail".
 */
final class InMemoryMailboxReaderTest extends TestCase
{
    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private static function message(string $id, string $received): MailboxMessage
    {
        return new MailboxMessage(
            new MailboxMessageSummary($id, 'Subject ' . $id, 'sender@example.test', 'Sender', self::at($received), false, 'preview'),
            MailboxMessage::BODY_TEXT,
            'body of ' . $id,
        );
    }

    private function mailbox(): InMemoryMailboxReader
    {
        $reader = new InMemoryMailboxReader();
        $reader->add('AP@Example.test', self::message('m1', '2026-10-01T00:00:00Z'));
        $reader->add('ap@example.test', self::message('m2', '2026-10-03T12:00:00Z'), [
            new MailboxAttachment(new MailboxAttachmentSummary('a1', 'one.pdf', 'application/pdf', 3), 'abc'),
        ]);
        $reader->add('ap@example.test', self::message('m3', '2026-10-08T00:00:00Z'));
        $reader->add('ar@example.test', self::message('other', '2026-10-03T00:00:00Z'));

        return $reader;
    }

    public function test_messages_come_newest_first_inside_an_inclusive_window_for_one_mailbox(): void
    {
        $messages = $this->mailbox()->listMessages('ap@example.test', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 500);

        self::assertSame(['m3', 'm2', 'm1'], array_map(static fn (MailboxMessageSummary $m): string => $m->id, $messages));
        $narrow = $this->mailbox()->listMessages('ap@example.test', self::at('2026-10-02T00:00:00Z'), self::at('2026-10-07T00:00:00Z'), 500);
        self::assertSame(['m2'], array_map(static fn (MailboxMessageSummary $m): string => $m->id, $narrow));
    }

    public function test_the_cap_keeps_the_newest(): void
    {
        $messages = $this->mailbox()->listMessages('ap@example.test', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 2);

        self::assertSame(['m3', 'm2'], array_map(static fn (MailboxMessageSummary $m): string => $m->id, $messages));
    }

    public function test_a_message_its_attachments_and_one_attachment(): void
    {
        $reader = $this->mailbox();

        self::assertSame('body of m2', $reader->getMessage('AP@example.test', 'm2')->body);
        self::assertSame(['one.pdf'], array_map(static fn (MailboxAttachmentSummary $a): string => $a->name, $reader->listAttachments('ap@example.test', 'm2')));
        self::assertSame('abc', $reader->getAttachment('ap@example.test', 'm2', 'a1')->content);
        self::assertSame([], $reader->listAttachments('ap@example.test', 'm1'));
    }

    public function test_something_that_is_not_there_is_not_found(): void
    {
        $reader = $this->mailbox();

        foreach ([
            static fn () => $reader->getMessage('ap@example.test', 'nope'),
            static fn () => $reader->getMessage('ar@example.test', 'm2'),
            static fn () => $reader->getAttachment('ap@example.test', 'm2', 'nope'),
            static fn () => $reader->listAttachments('unknown@example.test', 'm2'),
        ] as $read) {
            try {
                $read();
                self::fail('Expected a not-found failure.');
            } catch (MailboxItemNotFound) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_the_disabled_reader_refuses_every_read_instead_of_returning_no_mail(): void
    {
        $reader = new DisabledMailboxReader();
        $reads = [
            static fn () => $reader->listMessages('ap@example.test', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-02T00:00:00Z'), 10),
            static fn () => $reader->getMessage('ap@example.test', 'm1'),
            static fn () => $reader->listAttachments('ap@example.test', 'm1'),
            static fn () => $reader->getAttachment('ap@example.test', 'm1', 'a1'),
        ];

        foreach ($reads as $read) {
            try {
                $read();
                self::fail('A disabled reader must refuse.');
            } catch (MailboxReadUnavailable $failure) {
                self::assertSame(DisabledMailboxReader::REASON, $failure->getMessage());
            }
        }
    }
}
