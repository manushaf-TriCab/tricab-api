<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\InMemoryApAuditStore;
use TriCab\Api\Finance\Ap\InMemoryApEmailStore;
use TriCab\Api\Finance\Ap\InMemoryApInvoiceStore;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Ar\FlakyMailboxReader;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailAttachment;
use TriCab\Core\Finance\Ap\Service\ApEmailService;
use TriCab\Core\Finance\Ap\Service\ApMessageService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/**
 * The AP Mailbox's services over the in-memory stores and a fake mailbox, with a fixed clock (2026-10-09 01:02:03 UTC, which
 * is 9 Oct in Melbourne). `ap@example.test` is granted to Tri-AP for reading; `closed@example.test` is not.
 */
final class ApEmailWorld
{
    public const MAILBOX = 'ap@example.test';

    public InMemoryApInvoiceStore $invoices;

    public InMemoryApEmailStore $emails;

    public InMemoryApAuditStore $audit;

    public InMemoryMailboxReader $mailbox;

    public FlakyMailboxReader $reader;

    public InMemoryFinanceAuditLog $mailAudit;

    public InMemoryFinanceAccessScopeReader $scopes;

    public FixedClock $clock;

    public ApEmailService $service;

    public ApMessageService $messages;

    public function __construct()
    {
        $this->clock = FixedClock::utc('2026-10-09 01:02:03.456789');
        $this->invoices = new InMemoryApInvoiceStore();
        $this->emails = new InMemoryApEmailStore($this->invoices);
        $this->audit = new InMemoryApAuditStore();
        $this->mailbox = new InMemoryMailboxReader();
        $this->mailAudit = new InMemoryFinanceAuditLog();
        $this->scopes = new InMemoryFinanceAccessScopeReader();
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('0000000000000000000000000000000a', self::MAILBOX, 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('0000000000000000000000000000000c', 'closed@example.test', 'Closed', 'AP', true, ['AP' => ['read' => false, 'send' => false]]),
        ]);
        $this->reader = new FlakyMailboxReader(new FinanceMailboxReadService($registry, $this->mailbox, $this->scopes, $this->mailAudit, $this->clock));
        $this->service = new ApEmailService($this->emails, $this->invoices, $this->audit, new ApTime($this->clock, 'Australia/Melbourne'));
        $this->messages = new ApMessageService($this->service, $this->reader);
    }

    public static function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    /** Stores an email; pass any property by name. */
    public function email(string $id, mixed ...$over): ApEmail
    {
        $email = new ApEmail(
            $id,
            'msg-' . $id,
            self::MAILBOX,
            self::utc('2026-10-05 02:00:00'),
            self::utc('2026-10-05 02:00:00'),
            receivedAt: self::utc('2026-10-05 01:00:00'),
            senderName: 'Vera Vendor',
            senderEmail: 'billing@vendor.example.test',
            subject: 'Invoice ' . $id,
            bodyPreview: 'Preview ' . $id,
            hasAttachments: true,
        );
        $email = $over === [] ? $email : $email->with(...$over);
        $this->emails->insert($email);

        return $email;
    }

    public function attachment(string $id, string $emailId, mixed ...$over): ApEmailAttachment
    {
        $row = new ApEmailAttachment($id, $emailId, self::utc('2026-10-05 02:00:00'), self::utc('2026-10-05 02:00:00'), 'att-' . $id, $id . '.pdf', 'application/pdf', 100);
        $row = $over === [] ? $row : $row->with(...$over);
        $this->emails->saveAttachment($row);

        return $row;
    }

    /**
     * Puts a live message in the fake mailbox.
     *
     * @param MailboxMessage::BODY_* $type
     * @param list<MailboxAttachment> $attachments
     */
    public function message(string $graphId, string $body = '<p>Hello</p>', string $type = MailboxMessage::BODY_HTML, array $attachments = [], string $subject = 'Live subject'): void
    {
        $this->mailbox->add(self::MAILBOX, new MailboxMessage(
            new MailboxMessageSummary($graphId, $subject, 'billing@vendor.example.test', 'Vera Vendor', self::utc('2026-10-05 01:00:00'), $attachments !== [], 'preview'),
            $type,
            $body,
            [new MailboxRecipient('ap@example.test', 'AP')],
            [new MailboxRecipient('boss@example.test', '')],
        ), $attachments);
    }

    public static function file(string $id, string $name, string $type, string $content, bool $inline = false): MailboxAttachment
    {
        return new MailboxAttachment(new MailboxAttachmentSummary($id, $name, $type, strlen($content), $inline), $content);
    }
}
