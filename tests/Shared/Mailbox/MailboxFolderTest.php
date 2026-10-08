<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Shared\Mailbox;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Shared\Mailbox\DisabledMailboxReader;
use TriCab\Api\Shared\Mailbox\GraphMailboxReader;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

/**
 * The optional folder on the shared reader (T5.6, Q2), on hand-made fixtures: the Graph URL, the twin's filter, the
 * finance wrapper's pass-through and audit, and that no folder behaves as it always did.
 */
final class MailboxFolderTest extends TestCase
{
    private const MAILBOX = 'ar@example.test';

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private static function message(string $id, string $received): MailboxMessage
    {
        return new MailboxMessage(
            new MailboxMessageSummary($id, 'Subject ' . $id, 'sender@sample.local', 'Sender', self::at($received), false, 'preview'),
            MailboxMessage::BODY_TEXT,
            'body of ' . $id,
        );
    }

    private function twin(): InMemoryMailboxReader
    {
        $reader = new InMemoryMailboxReader();
        $reader->add(self::MAILBOX, self::message('in1', '2026-10-01T00:00:00Z'));
        $reader->add(self::MAILBOX, self::message('in2', '2026-10-02T00:00:00Z'), [], 'INBOX');
        $reader->add(self::MAILBOX, self::message('sent1', '2026-10-03T00:00:00Z'), [], 'sentitems');
        $reader->add(self::MAILBOX, self::message('del1', '2026-10-04T00:00:00Z'), [], 'deleteditems');

        return $reader;
    }

    /**
     * @param list<MailboxMessageSummary> $messages
     * @return list<string>
     */
    private static function ids(array $messages): array
    {
        return array_map(static fn (MailboxMessageSummary $m): string => $m->id, $messages);
    }

    public function test_the_twin_lists_one_folder_and_the_inbox_is_the_default_folder_of_a_message(): void
    {
        $from = self::at('2026-09-30T00:00:00Z');
        $to = self::at('2026-10-10T00:00:00Z');

        self::assertSame(['in2', 'in1'], self::ids($this->twin()->listMessagesInFolder(self::MAILBOX, 'Inbox', $from, $to, 500)));
        self::assertSame(['sent1'], self::ids($this->twin()->listMessagesInFolder(self::MAILBOX, 'sentitems', $from, $to, 500)));
    }

    public function test_the_twin_without_a_folder_still_returns_every_folder(): void
    {
        $all = $this->twin()->listMessages(self::MAILBOX, self::at('2026-09-30T00:00:00Z'), self::at('2026-10-10T00:00:00Z'), 500);

        self::assertSame(['del1', 'sent1', 'in2', 'in1'], self::ids($all));
    }

    public function test_the_twin_applies_the_cap_and_refuses_an_unknown_folder(): void
    {
        $capped = $this->twin()->listMessagesInFolder(self::MAILBOX, 'inbox', self::at('2026-09-30T00:00:00Z'), self::at('2026-10-10T00:00:00Z'), 1);
        self::assertSame(['in2'], self::ids($capped));

        $this->expectException(InvalidArgumentException::class);
        $this->twin()->listMessagesInFolder(self::MAILBOX, 'AAMkAGI2-a-folder-id', self::at('2026-09-30T00:00:00Z'), self::at('2026-10-10T00:00:00Z'), 5);
    }

    public function test_graph_reads_the_inbox_through_its_folder_url_with_immutable_ids(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'],
            ['status' => 200, 'body' => '{"value":[{"id":"imm-1","subject":"Remittance","from":{"emailAddress":{"address":"Pay@Sample.Local","name":"Pay"}},"receivedDateTime":"2026-10-02T01:00:00Z","hasAttachments":true,"bodyPreview":"p"}]}'],
        ];
        $reader = new GraphMailboxReader($http, 'tenant-1', 'client-1', 'dummy-secret', pauseBetweenRetries: false);

        $messages = $reader->listMessagesInFolder(self::MAILBOX, 'INBOX', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 500);

        self::assertSame(['imm-1'], self::ids($messages));
        $gets = array_values(array_filter($http->calls, static fn (array $call): bool => $call['kind'] === 'get'));
        self::assertCount(1, $gets);
        self::assertStringStartsWith('https://graph.microsoft.com/v1.0/users/ar%40example.test/mailFolders/inbox/messages?', $gets[0]['url']);
        self::assertSame('IdType="ImmutableId"', $gets[0]['headers']['Prefer']);
    }

    public function test_graph_without_a_folder_reads_the_whole_mailbox_as_before(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'],
            ['status' => 200, 'body' => '{"value":[]}'],
        ];
        $reader = new GraphMailboxReader($http, 'tenant-1', 'client-1', 'dummy-secret', pauseBetweenRetries: false);

        $reader->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 500);

        $gets = array_values(array_filter($http->calls, static fn (array $call): bool => $call['kind'] === 'get'));
        self::assertStringStartsWith('https://graph.microsoft.com/v1.0/users/ar%40example.test/messages?', $gets[0]['url']);
        self::assertStringNotContainsString('mailFolders', $gets[0]['url']);
    }

    public function test_graph_refuses_a_folder_that_is_not_a_well_known_name_and_makes_no_request(): void
    {
        $http = new FakeJsonHttpClient();
        $reader = new GraphMailboxReader($http, 'tenant-1', 'client-1', 'dummy-secret', pauseBetweenRetries: false);

        try {
            $reader->listMessagesInFolder(self::MAILBOX, 'inbox/../../users', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 5);
            self::fail('The folder must be refused.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $http->calls);
        }
    }

    public function test_the_disabled_reader_refuses_a_folder_read_too(): void
    {
        $this->expectException(MailboxReadUnavailable::class);
        (new DisabledMailboxReader())->listMessagesInFolder(self::MAILBOX, 'inbox', self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 5);
    }

    private function service(MailboxReader $reader, InMemoryFinanceAuditLog $audit): FinanceMailboxReadService
    {
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('0000000000000000000000000000000b', self::MAILBOX, 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
        ]);

        return new FinanceMailboxReadService($registry, $reader, new InMemoryFinanceAccessScopeReader(), $audit, FixedClock::utc('2026-10-07T00:00:00Z'));
    }

    public function test_the_finance_wrapper_passes_the_folder_through_and_audits_it(): void
    {
        $audit = new InMemoryFinanceAuditLog();
        $service = $this->service($this->twin(), $audit);
        $from = self::at('2026-09-30T00:00:00Z');
        $to = self::at('2026-10-10T00:00:00Z');

        $inbox = $service->listMessages('AR', self::MAILBOX, $from, $to, null, null, 'Inbox');
        $all = $service->listMessages('AR', self::MAILBOX, $from, $to);

        self::assertSame(['in2', 'in1'], self::ids($inbox));
        self::assertCount(4, $all);
        self::assertSame('inbox', $audit->events[0]->details['folder']);
        self::assertArrayNotHasKey('folder', $audit->events[1]->details);
    }

    public function test_the_finance_wrapper_refuses_an_unknown_folder_and_a_reader_that_cannot_list_one(): void
    {
        $audit = new InMemoryFinanceAuditLog();
        $service = $this->service($this->twin(), $audit);
        try {
            $service->listMessages('AR', self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-02T00:00:00Z'), null, null, 'my folder');
            self::fail('The folder must be refused.');
        } catch (FinanceMailInvalid) {
            self::assertSame([], $audit->events);
        }

        $plain = new class () implements MailboxReader {
            public function listMessages(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
            {
                return [];
            }

            public function getMessage(string $mailbox, string $messageId): MailboxMessage
            {
                throw new MailboxReadUnavailable('not used');
            }

            public function listAttachments(string $mailbox, string $messageId): array
            {
                return [];
            }

            public function getAttachment(string $mailbox, string $messageId, string $attachmentId): \TriCab\Core\Shared\Mailbox\MailboxAttachment
            {
                throw new MailboxReadUnavailable('not used');
            }
        };
        $this->expectException(MailboxReadUnavailable::class);
        $this->service($plain, $audit)->listMessages('AR', self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-02T00:00:00Z'), null, null, 'inbox');
    }
}
