<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Shared\Mailbox\DisabledMailboxReader;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArForbidden;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;
use TriCab\Core\Finance\Ar\Service\ArOriginalMessageService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/**
 * The original-message route's use case (T5.6) over the in-memory mailbox, registry and store: the source's route 8
 * with its 404s and 502, the Q1 flag, and the read grant behind it. Nothing reaches Graph.
 */
final class ArOriginalMessageServiceTest extends TestCase
{
    private const MAILBOX = 'ar@example.test';

    private InMemoryArStore $store;

    private InMemoryMailboxReader $mailbox;

    private ArActor $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryArStore();
        $this->mailbox = new InMemoryMailboxReader();
        $this->actor = new ArActor(7, 'Rae Reviewer', 'rae@sample.local', []);
    }

    private function service(MailboxReader $reader, bool $granted = true): ArOriginalMessageService
    {
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('0000000000000000000000000000000b', self::MAILBOX, 'Accounts Receivable', 'AR', true, ['AR' => ['read' => $granted, 'send' => true]]),
        ]);
        $read = new FinanceMailboxReadService($registry, $reader, new InMemoryFinanceAccessScopeReader(), new InMemoryFinanceAuditLog(), FixedClock::utc('2026-10-07T00:00:00Z'));

        return new ArOriginalMessageService($this->store, $read, self::MAILBOX);
    }

    /**
     * @param MailboxMessage::BODY_* $type
     */
    private function message(string $id, string $body = '<p>Remittance table</p>', string $type = MailboxMessage::BODY_HTML, string $subject = 'Payment advice'): MailboxMessage
    {
        return new MailboxMessage(
            new MailboxMessageSummary($id, $subject, 'pay@customer.example.test', 'Pat Payer', new DateTimeImmutable('2026-10-02T01:02:03Z', new DateTimeZone('UTC')), true, 'preview'),
            $type,
            $body,
            [new MailboxRecipient('ar@example.test', 'AR')],
            [new MailboxRecipient('boss@customer.example.test', '')],
        );
    }

    public function test_the_original_message_comes_back_in_the_sources_keys_unmodified_and_marked_untrusted(): void
    {
        $this->mailbox->add(self::MAILBOX, $this->message('imm-1', '<script>alert(1)</script><table><tr><td>INV-1</td></tr></table>'));
        $this->store->insert(ArFixtures::record('r1', mailbox: self::MAILBOX, sourceMessageId: 'imm-1'));

        $out = $this->service($this->mailbox)->original($this->actor, 'r1');

        self::assertSame('Payment advice', $out['subject']);
        self::assertSame(['name' => 'Pat Payer', 'address' => 'pay@customer.example.test'], $out['from']);
        self::assertSame([['name' => 'AR', 'address' => 'ar@example.test']], $out['to']);
        self::assertSame([['name' => '', 'address' => 'boss@customer.example.test']], $out['cc']);
        self::assertSame('2026-10-02T01:02:03Z', $out['received_at']);
        self::assertSame('html', $out['content_type']);
        self::assertSame('<script>alert(1)</script><table><tr><td>INV-1</td></tr></table>', $out['content'], 'untrusted content is returned as it came');
        self::assertTrue($out['untrusted']);
    }

    public function test_an_empty_graph_body_falls_back_to_the_stored_text_and_subject(): void
    {
        $this->mailbox->add(self::MAILBOX, $this->message('imm-1', '', MailboxMessage::BODY_TEXT, ''));
        $this->store->insert(ArFixtures::record('r1', mailbox: self::MAILBOX, sourceMessageId: 'imm-1', body: 'Stored plain text', subject: 'Stored subject'));

        $out = $this->service($this->mailbox)->original($this->actor, 'r1');

        self::assertSame('Stored plain text', $out['content']);
        self::assertSame('Stored subject', $out['subject']);
        self::assertSame('text', $out['content_type']);
    }

    public function test_the_record_is_read_from_its_own_mailbox(): void
    {
        $this->mailbox->add(self::MAILBOX, $this->message('imm-1'));
        $this->store->insert(ArFixtures::record('r1', sourceMessageId: 'imm-1', mailbox: ''));

        self::assertSame('html', $this->service($this->mailbox)->original($this->actor, 'r1')['content_type'], 'a blank mailbox falls back to the configured one');
    }

    public function test_a_missing_record_and_a_record_without_a_message_are_404s_in_the_sources_words(): void
    {
        $service = $this->service($this->mailbox);
        $this->store->insert(ArFixtures::record('r1', mailbox: self::MAILBOX, sourceMessageId: null));

        foreach (['missing' => 'Record not found', 'r1' => 'This record did not come from the mailbox'] as $id => $message) {
            try {
                $service->original($this->actor, $id);
                self::fail('Expected a 404.');
            } catch (ArNotFound $notFound) {
                self::assertSame($message, $notFound->getMessage());
            }
        }
    }

    public function test_a_record_flagged_unreachable_is_a_404_and_the_mailbox_is_not_asked(): void
    {
        $this->store->insert(ArFixtures::record('r1', mailbox: self::MAILBOX, sourceMessageId: null, messageUnreachable: true));
        $this->store->insert(ArFixtures::record('r2', mailbox: self::MAILBOX, sourceMessageId: 'imm-2', messageUnreachable: true));

        foreach (['r1', 'r2'] as $id) {
            try {
                $this->service($this->mailbox)->original($this->actor, $id);
                self::fail('Expected a 404.');
            } catch (ArNotFound $notFound) {
                self::assertSame('The original message is no longer reachable', $notFound->getMessage());
            }
        }
        self::assertSame(0, $this->mailbox->reads);
    }

    public function test_no_read_grant_is_a_typed_403(): void
    {
        $this->mailbox->add(self::MAILBOX, $this->message('imm-1'));
        $this->store->insert(ArFixtures::record('r1', mailbox: self::MAILBOX, sourceMessageId: 'imm-1'));

        $this->expectException(ArForbidden::class);
        $this->service($this->mailbox, granted: false)->original($this->actor, 'r1');
    }

    public function test_a_message_gone_from_the_mailbox_is_a_404(): void
    {
        $this->store->insert(ArFixtures::record('r1', mailbox: self::MAILBOX, sourceMessageId: 'imm-gone'));

        try {
            $this->service($this->mailbox)->original($this->actor, 'r1');
            self::fail('Expected a 404.');
        } catch (ArNotFound $notFound) {
            self::assertSame('The original message is no longer in the mailbox', $notFound->getMessage());
        }
    }

    public function test_graph_being_down_is_a_typed_502_and_off_is_not_an_empty_message(): void
    {
        $this->store->insert(ArFixtures::record('r1', mailbox: self::MAILBOX, sourceMessageId: 'imm-1'));

        try {
            $this->service(new DisabledMailboxReader())->original($this->actor, 'r1');
            self::fail('Expected a 502.');
        } catch (ArUpstream $upstream) {
            self::assertSame(502, $upstream->httpStatus());
            self::assertSame('FINANCE_AR_UPSTREAM_FAILED', $upstream->errorCode());
            self::assertSame(['reason' => 'mailbox'], $upstream->extra);
            self::assertStringStartsWith('Could not fetch the message from Outlook: ', $upstream->getMessage());
        }
    }
}
