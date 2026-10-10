<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\InMemoryApAuditStore;
use TriCab\Api\Finance\Ap\InMemoryApEmailStore;
use TriCab\Api\Finance\Ap\InMemoryApInvoiceStore;
use TriCab\Api\Finance\Ap\InMemoryApReferenceStore;
use TriCab\Api\Finance\Ap\InMemoryApScanStore;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Ar\FlakyMailboxReader;
use TriCab\Core\Finance\Ap\ApAiService;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceQuery;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;
use TriCab\Core\Finance\Ap\Service\ApEmailService;
use TriCab\Core\Finance\Ap\Service\ApInvoiceGuard;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;
use TriCab\Core\Finance\Ap\Service\ApScanService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService;
use TriCab\Core\Shared\Mailbox\FolderMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;

/**
 * Tri-AP's scan (T6.7) over the in-memory stores, a fake mailbox and a queued AI client, with a fixed clock (2026-10-09 01:02:03
 * UTC, which is 9 Oct in Melbourne). `ap@example.test` and `ap2@example.test` are granted to Tri-AP for reading;
 * `closed@example.test` is in the registry without a read grant. Every address is invented; nothing reaches Graph or a model.
 */
final class ApScanWorld
{
    public const MAILBOX = 'ap@example.test';
    public const SECOND = 'ap2@example.test';
    public const CLOSED = 'closed@example.test';

    public FixedClock $clock;

    public InMemoryApInvoiceStore $invoices;

    public InMemoryApEmailStore $emails;

    public InMemoryApAuditStore $audit;

    public InMemoryApScanStore $scans;

    public InMemoryApReferenceStore $references;

    public InMemoryFinanceAttachmentStore $fileStore;

    public InMemoryMailboxReader $mailbox;

    public FlakyMailboxReader $reader;

    public ListFailingMailboxReader $listing;

    public InMemoryFinanceAuditLog $mailAudit;

    public InMemoryFinanceAccessScopeReader $scopes;

    public QueuedApAiClient $ai;

    public ApEmailService $emailService;

    public ApAttachmentService $attachments;

    public ApInvoiceService $invoiceService;

    public ApScanService $service;

    /** @var list<string> */
    public array $log = [];

    /**
     * @param list<string> $internalDomains
     */
    public function __construct(bool $suggest = true, int $maxScanMessages = 0, int $listCap = 500, array $internalDomains = [], int $minBytes = 25600)
    {
        $this->clock = FixedClock::utc('2026-10-09 01:02:03.456789');
        $this->invoices = new InMemoryApInvoiceStore();
        $this->emails = new InMemoryApEmailStore($this->invoices);
        $this->audit = new InMemoryApAuditStore();
        $this->scans = new InMemoryApScanStore();
        $this->references = new InMemoryApReferenceStore();
        $this->fileStore = new InMemoryFinanceAttachmentStore();
        $this->mailbox = new InMemoryMailboxReader();
        $this->mailAudit = new InMemoryFinanceAuditLog();
        $this->scopes = new InMemoryFinanceAccessScopeReader();
        $this->ai = new QueuedApAiClient();
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('0000000000000000000000000000000b', self::SECOND, 'Accounts Payable 2', 'AP', true, ['AP' => ['read' => true, 'send' => false]]),
            new FinanceMailbox('0000000000000000000000000000000a', self::MAILBOX, 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('0000000000000000000000000000000c', self::CLOSED, 'Closed', 'AP', true, ['AP' => ['read' => false, 'send' => false]]),
        ]);
        $this->reader = new FlakyMailboxReader(new FinanceMailboxReadService($registry, $this->mailbox, $this->scopes, $this->mailAudit, $this->clock));

        $this->listing = new ListFailingMailboxReader($this->reader);
        $time = new ApTime($this->clock, 'Australia/Melbourne');
        $files = new FinanceAttachmentService($this->fileStore);
        $this->attachments = new ApAttachmentService($files, $this->fileStore, new ApInvoiceGuard($this->invoices));
        $this->invoiceService = new ApInvoiceService($this->invoices, $this->audit, $this->attachments, $time, new InMemoryFinanceAuditLog());
        $this->emailService = new ApEmailService($this->emails, $this->invoices, $this->audit, $time);
        $this->service = new ApScanService(
            $this->scans,
            $registry,
            $this->listing,
            $this->emailService,
            $this->emails,
            $this->invoiceService,
            $this->invoices,
            $this->attachments,
            new ApAiService($this->ai, $suggest, 0.80, function (string $line): void {
                $this->log[] = $line;
            }),
            $this->references,
            $time,
            $minBytes,
            $maxScanMessages,
            $internalDomains,
            $listCap,
            function (string $line): void {
                $this->log[] = $line;
            },
        );
    }

    public static function utc(string $when): DateTimeImmutable
    {
        return ApEmailWorld::utc($when);
    }

    /**
     * A person who may scan: user 7, Dee Preparer, optionally limited to some mailboxes.
     *
     * @param list<string>|null $mailboxes
     */
    public static function actor(?array $mailboxes = null): ApActor
    {
        return ApInvoiceFixtures::actor($mailboxes);
    }

    /**
     * Puts a message in the fake mailbox and returns its Graph id.
     *
     * @param list<MailboxAttachment> $attachments
     */
    public function message(string $id, array $attachments = [], string $mailbox = self::MAILBOX, string $received = '2026-10-05 01:00:00', string $subject = 'Invoice please', string $from = 'billing@vendor.example.test', string $folder = FolderMailboxReader::INBOX): string
    {
        $this->mailbox->add($mailbox, new MailboxMessage(
            new MailboxMessageSummary($id, $subject, $from, 'Vera Vendor', self::utc($received), $attachments !== [], 'Preview of ' . $id),
            MailboxMessage::BODY_TEXT,
            'Body',
        ), $attachments, $folder);

        return $id;
    }

    /** A PDF (a PDF is exempt from the size floor and from the inline rule). */
    public static function pdf(string $id, string $name, string $content): MailboxAttachment
    {
        return ApEmailWorld::file($id, $name, 'application/pdf', $content);
    }

    /** An attachment of a declared size, whatever its bytes (to try the size floor). */
    public static function sized(string $id, string $name, string $type, int $size, string $content = 'bytes', bool $inline = false): MailboxAttachment
    {
        return new MailboxAttachment(new MailboxAttachmentSummary($id, $name, $type, $size, $inline), $content);
    }

    /**
     * Runs `process` and returns its answer.
     *
     * @return array<string, mixed>
     */
    public function process(string $messageId, string $mailbox = self::MAILBOX, ?string $runId = null, bool $force = false, ?ApActor $actor = null): array
    {
        $body = ['mode' => 'process', 'messageId' => $messageId, 'mailbox' => $mailbox];
        if ($runId !== null) {
            $body['scanRunId'] = $runId;
        }
        if ($force) {
            $body['force'] = true;
        }

        return $this->service->handle($actor ?? self::actor(), $body);
    }

    /**
     * Runs `list` for the 5 Oct 2026 and returns its answer.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public function list(array $over = [], ?ApActor $actor = null): array
    {
        return $this->service->handle($actor ?? self::actor(), array_merge(['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05'], $over));
    }

    public function email(string $messageId, string $mailbox = self::MAILBOX): ApEmail
    {
        return $this->emails->findByMessage($mailbox, $messageId) ?? throw new \LogicException('No email ' . $messageId);
    }

    /** The invoice captured from the file of this name (the fixed clock gives every invoice one creation time, so order is not a test's to rely on). */
    public function invoiceNamed(string $attachment, bool $binned = false): ApInvoice
    {
        foreach ($this->allInvoices($binned) as $invoice) {
            if ($invoice->attachment === $attachment) {
                return $invoice;
            }
        }

        throw new \LogicException('No invoice from ' . $attachment);
    }

    /** @return list<apinvoice> oldest first */
    public function allInvoices(bool $binned = false): array
    {
        return $this->invoices->page(new ApInvoiceQuery($binned, null, null, null, null, null, 'created_date', false, 1000, '2026-10-09'));
    }
}
