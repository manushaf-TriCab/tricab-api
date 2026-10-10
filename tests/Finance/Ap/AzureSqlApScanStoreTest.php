<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\AzureSqlApAuditStore;
use TriCab\Api\Finance\Ap\AzureSqlApEmailStore;
use TriCab\Api\Finance\Ap\AzureSqlApInvoiceStore;
use TriCab\Api\Finance\Ap\AzureSqlApReferenceStore;
use TriCab\Api\Finance\Ap\AzureSqlApScanStore;
use TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore;
use TriCab\Api\Finance\Audit\AzureSqlFinanceAuditLog;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ap\ApAiService;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApScanRun;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;
use TriCab\Core\Finance\Ap\Service\ApEmailService;
use TriCab\Core\Finance\Ap\Service\ApInvoiceGuard;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;
use TriCab\Core\Finance\Ap\Service\ApScanService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;

/**
 * The scan runs over `create-finance-invoice-schema.sql`, against a real local SQL Server ({@see LocalFinanceSqlServer});
 * skipped without one. What the twin cannot prove: the filtered unique index that is the claim (ONE open scan for the whole
 * app), the expired claim failed first in the same transaction, counters added in one statement, six-digit times and a person
 * id past 2^53, the overlap filter, and the whole pipeline on the real stores, including an invoice rolled back when the file
 * row race is lost.
 */
#[Group('local-sql-server')]
final class AzureSqlApScanStoreTest extends TestCase
{
    private PDO $pdo;

    private PdoSqlConnection $connection;

    private AzureSqlApScanStore $store;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlApScanStore($this->connection);
    }

    private static function at(string $when): DateTimeImmutable
    {
        return ApScanWorld::utc($when);
    }

    private function newRun(string $id, string $createdAt = '2026-10-07 01:00:00.123456', ?string $from = '2026-10-05', ?string $to = '2026-10-06', int $claimMinutes = 30, ?int $userId = 9007199254740993, string $status = ApScanRun::LISTING): ApScanRun
    {
        $at = self::at($createdAt);

        return new ApScanRun($id, $at, $at, $userId, 'Dee Preparer', $from, $to, ['ap@example.test', 'ap2@example.test'], $status, $at->modify('+' . $claimMinutes . ' minutes'));
    }

    private function begin(ApScanRun $run): void
    {
        $this->store->begin($run, $run->createdAt);
    }

    private function runOf(string $id): ApScanRun
    {
        return $this->store->find($id) ?? self::fail('No run ' . $id);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    public function test_a_run_round_trips_with_six_digit_time_the_mailboxes_and_a_person_id_past_two_to_the_53(): void
    {
        $this->begin($this->newRun('r1'));

        $found = $this->runOf('r1');

        self::assertSame('2026-10-07 01:00:00.123456', $found->createdAt->format('Y-m-d H:i:s.u'));
        self::assertSame([ApScanRun::LISTING, '2026-10-05', '2026-10-06', 9007199254740993, 'Dee Preparer', ['ap@example.test', 'ap2@example.test']], [$found->status, $found->fromDate, $found->toDate, $found->requestedByUserId, $found->requestedBy, $found->mailboxes]);
        self::assertSame('2026-10-07 01:30:00.123456', $found->claimExpiresAt?->format('Y-m-d H:i:s.u'));
        self::assertSame([0, 0, 0, 0, 0, 0], [$found->totalMessages, $found->processedMessages, $found->invoiceEmails, $found->otherEmails, $found->duplicateInvoices, $found->errors]);
        self::assertNull($this->store->find('missing'));
    }

    public function test_only_one_scan_may_be_open_for_the_whole_app_and_finishing_frees_the_claim(): void
    {
        $this->begin($this->newRun('r1'));

        try {
            $this->begin($this->newRun('r2', '2026-10-07 01:05:00'));
            self::fail('Expected the claim to hold');
        } catch (ApConflict $conflict) {
            self::assertSame('Another scan is in progress. Wait for it to finish, or stop it, before starting a new one.', $conflict->getMessage());
        }
        self::assertNull($this->store->find('r2'));

        $this->store->markRunning('r1', 4, 1, self::at('2026-10-07 01:40:00'), self::at('2026-10-07 01:10:00'));
        try {
            $this->begin($this->newRun('r2', '2026-10-07 01:11:00'));
            self::fail('A Running scan holds the claim too');
        } catch (ApConflict) {
        }

        $this->store->finish('r1', ApScanRun::COMPLETE, self::at('2026-10-07 01:20:00'));
        $this->begin($this->newRun('r2', '2026-10-07 01:21:00'));
        $this->store->finish('r2', ApScanRun::FAILED, self::at('2026-10-07 01:22:00'), 2);
        $this->begin($this->newRun('r3', '2026-10-07 01:23:00'));

        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[invoice_scan_runs] WHERE [status] IN (N'Listing', N'Running')"));
    }

    public function test_an_expired_claim_is_failed_and_the_new_run_takes_over(): void
    {
        $this->begin($this->newRun('dead', '2026-10-07 01:00:00'));
        try {
            $this->store->begin($this->newRun('early', '2026-10-07 01:29:59'), self::at('2026-10-07 01:29:59'));
            self::fail('The claim is still held a second before it expires');
        } catch (ApConflict) {
        }
        self::assertSame(ApScanRun::LISTING, $this->runOf('dead')->status);

        $this->store->begin($this->newRun('fresh', '2026-10-07 01:30:00'), self::at('2026-10-07 01:30:00'));

        self::assertSame(ApScanRun::FAILED, $this->runOf('dead')->status);
        self::assertNull($this->runOf('dead')->claimExpiresAt);
        self::assertSame(ApScanRun::LISTING, $this->runOf('fresh')->status);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[invoice_scan_runs] WHERE [status] IN (N'Listing', N'Running')"));
    }

    public function test_counters_are_added_in_one_statement_and_only_an_open_run_has_its_claim_moved(): void
    {
        $this->begin($this->newRun('r1'));
        $this->store->markRunning('r1', 7, 1, self::at('2026-10-07 01:35:00'), self::at('2026-10-07 01:01:00'));

        $this->store->bump('r1', ['processed_messages' => 1, 'invoice_emails' => 1, 'duplicate_invoices' => 2], self::at('2026-10-07 02:00:00'), self::at('2026-10-07 01:02:00'));
        $this->store->bump('r1', ['processed_messages' => 1, 'other_emails' => 1, 'errors' => 3], self::at('2026-10-07 02:05:00'), self::at('2026-10-07 01:03:00'));
        $this->store->bump('missing', ['processed_messages' => 1], self::at('2026-10-07 02:05:00'), self::at('2026-10-07 01:03:00'));

        $run = $this->runOf('r1');
        self::assertSame([ApScanRun::RUNNING, 7, 2, 1, 1, 2, 4], [$run->status, $run->totalMessages, $run->processedMessages, $run->invoiceEmails, $run->otherEmails, $run->duplicateInvoices, $run->errors]);
        self::assertSame('2026-10-07 02:05:00', $run->claimExpiresAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-07 01:03:00', $run->updatedAt->format('Y-m-d H:i:s'));

        $this->store->finish('r1', ApScanRun::COMPLETE, self::at('2026-10-07 01:10:00'));
        $this->store->bump('r1', ['processed_messages' => 1], self::at('2026-10-07 03:00:00'), self::at('2026-10-07 01:11:00'));
        $closed = $this->runOf('r1');
        self::assertSame([3, 4, ApScanRun::COMPLETE], [$closed->processedMessages, $closed->errors, $closed->status]);
        self::assertNull($closed->claimExpiresAt, 'a closed run does not get its claim back');
    }

    public function test_finish_keeps_the_error_count_unless_given_one(): void
    {
        $this->begin($this->newRun('r1'));
        $this->store->bump('r1', ['errors' => 5], self::at('2026-10-07 02:00:00'), self::at('2026-10-07 01:02:00'));

        $this->store->finish('r1', ApScanRun::COMPLETE, self::at('2026-10-07 01:10:00'));
        self::assertSame(5, $this->runOf('r1')->errors);

        $this->store->finish('r1', ApScanRun::FAILED, self::at('2026-10-07 01:11:00'), 2);
        self::assertSame([ApScanRun::FAILED, 2], [$this->runOf('r1')->status, $this->runOf('r1')->errors]);
    }

    public function test_overlapping_runs_are_the_ones_that_read_something_and_did_not_fail(): void
    {
        $this->seedHistory();

        $ids = array_map(static fn (ApScanRun $r): string => $r->id, $this->store->overlapping('2026-09-28', '2026-09-29'));
        self::assertSame(['open', 'mid', 'old'], $ids, 'newest first; a run that read nothing or failed is left out; an open one counts');

        self::assertSame(['mid'], array_map(static fn (ApScanRun $r): string => $r->id, $this->store->overlapping('2026-10-02', '2026-10-09')));
        self::assertSame([], $this->store->overlapping('2026-11-01', '2026-11-02'));
        self::assertSame(['open'], array_map(static fn (ApScanRun $r): string => $r->id, $this->store->overlapping('2026-09-28', '2026-09-29', 1)));
        self::assertSame(['mid', 'old'], array_map(static fn (ApScanRun $r): string => $r->id, $this->store->overlapping('2026-09-30', '2026-09-30')));
    }

    private function seedHistory(): void
    {
        $this->begin($this->newRun('old', '2026-10-01 01:00:00', '2026-09-01', '2026-09-30'));
        $this->store->markRunning('old', 5, 0, self::at('2026-10-01 01:30:00'), self::at('2026-10-01 01:00:00'));
        $this->store->finish('old', ApScanRun::COMPLETE, self::at('2026-10-01 01:05:00'));
        $this->begin($this->newRun('mid', '2026-10-02 01:00:00', '2026-09-25', '2026-10-03'));
        $this->store->markRunning('mid', 2, 0, self::at('2026-10-02 01:30:00'), self::at('2026-10-02 01:00:00'));
        $this->store->finish('mid', ApScanRun::COMPLETE, self::at('2026-10-02 01:05:00'));
        $this->begin($this->newRun('empty', '2026-10-03 01:00:00', '2026-09-28', '2026-09-29'));
        $this->store->finish('empty', ApScanRun::COMPLETE, self::at('2026-10-03 01:05:00'));
        $this->begin($this->newRun('failed', '2026-10-04 01:00:00', '2026-09-28', '2026-09-29'));
        $this->store->markRunning('failed', 3, 0, self::at('2026-10-04 01:30:00'), self::at('2026-10-04 01:00:00'));
        $this->store->finish('failed', ApScanRun::FAILED, self::at('2026-10-04 01:05:00'));
        $this->begin($this->newRun('open', '2026-10-05 01:00:00', '2026-09-28', '2026-09-29'));
        $this->store->markRunning('open', 9, 0, self::at('2026-10-05 01:30:00'), self::at('2026-10-05 01:00:00'));
    }

    // ---- the whole pipeline on the real stores ----------------------------------------------

    /**
     * @param (callable(AzureSqlApEmailStore): ApEmailStore)|null $wrapEmails
     * @return array{0: ApScanService, 1: InMemoryMailboxReader, 2: QueuedApAiClient, 3: ApEmailService, 4: AzureSqlApEmailStore}
     */
    private function pipeline(?callable $wrapEmails = null): array
    {
        $clock = FixedClock::utc('2026-10-09 01:02:03.456789');
        $time = new ApTime($clock, 'Australia/Melbourne');
        $invoices = new AzureSqlApInvoiceStore($this->connection);
        $emails = new AzureSqlApEmailStore($this->connection);
        $store = $wrapEmails === null ? $emails : $wrapEmails($emails);
        $fileStore = new AzureSqlFinanceAttachmentStore($this->connection, new InMemoryProductBlobStore('', 'finance-attachments'), 'finance-attachments');
        $attachments = new ApAttachmentService(new FinanceAttachmentService($fileStore), $fileStore, new ApInvoiceGuard($invoices));
        $invoiceService = new ApInvoiceService($invoices, new AzureSqlApAuditStore($this->connection), $attachments, $time, new AzureSqlFinanceAuditLog($this->connection));
        $emailService = new ApEmailService($store, $invoices, new AzureSqlApAuditStore($this->connection), $time);
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('0000000000000000000000000000000a', 'ap@example.test', 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => false]])]);
        $mailbox = new InMemoryMailboxReader();
        $ai = new QueuedApAiClient();
        $reader = new FinanceMailboxReadService($registry, $mailbox, new InMemoryFinanceAccessScopeReader(), new InMemoryFinanceAuditLog(), $clock);
        $service = new ApScanService(
            $this->store,
            $registry,
            $reader,
            $emailService,
            $store,
            $invoiceService,
            $invoices,
            $attachments,
            new ApAiService($ai, true, 0.80, static function (string $line): void {
            }),
            new AzureSqlApReferenceStore($this->connection),
            $time,
            log: static function (string $line): void {
            },
        );

        return [$service, $mailbox, $ai, $emailService, $emails];
    }

    private function putMessage(InMemoryMailboxReader $mailbox, string $id, string $pdf): void
    {
        $mailbox->add('ap@example.test', new MailboxMessage(
            new MailboxMessageSummary($id, 'Invoice please', 'billing@vendor.example.test', 'Vera Vendor', self::at('2026-10-05 01:00:00'), true, 'Preview'),
            MailboxMessage::BODY_TEXT,
            'Body',
        ), [ApEmailWorld::file('att-1', 'inv.pdf', 'application/pdf', $pdf)]);
    }

    public function test_a_scan_on_the_real_stores_files_one_invoice_and_a_second_pass_adds_nothing(): void
    {
        [$service, $mailbox, , , $emails] = $this->pipeline();
        $this->putMessage($mailbox, 'msg-1', '%PDF real');
        $actor = ApInvoiceFixtures::actor();

        $listed = $service->handle($actor, ['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);
        $run = $listed['scanRunId'];
        self::assertIsString($run);
        $first = $service->handle($actor, ['mode' => 'process', 'messageId' => 'msg-1', 'mailbox' => 'ap@example.test', 'scanRunId' => $run]);
        $second = $service->handle($actor, ['mode' => 'process', 'messageId' => 'msg-1', 'mailbox' => 'ap@example.test', 'scanRunId' => $run]);
        $closed = $service->handle($actor, ['mode' => 'complete', 'scanRunId' => $run]);

        self::assertSame(['captured' => 1, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $first);
        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $second);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_emails]'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_invoices]'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_email_attachments]'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments] WHERE [record_id] IS NOT NULL'));
        self::assertSame(2, $this->scalar('SELECT [processed_messages] FROM [finance].[invoice_scan_runs]'));
        self::assertSame(['Complete', 2, 2, 0], [$closed['status'], $closed['processed_messages'], $closed['invoice_emails'], $closed['errors']]);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[invoice_scan_runs] WHERE [status] IN (N'Listing', N'Running')"));
        $email = $emails->findByMessage('ap@example.test', 'MSG-1') ?? self::fail('The email is found in any case');
        self::assertSame('Thanks, we will check.', $email->proposedResponse, 'a Graph id is found in any case (R29)');
        $row = $emails->attachmentsOf($email->id)[0];
        self::assertSame(hash('sha256', '%PDF real'), $row->contentHash);
    }

    public function test_an_invoice_is_rolled_back_when_its_file_row_race_is_lost(): void
    {
        $racing = null;
        [$service, $mailbox] = $this->pipeline(static function (AzureSqlApEmailStore $emails) use (&$racing): RacingApEmailStore {
            $racing = new RacingApEmailStore($emails);
            $racing->failNextAttachmentSave = true;

            return $racing;
        });
        $this->putMessage($mailbox, 'msg-1', '%PDF race');

        $answer = $service->handle(ApInvoiceFixtures::actor(), ['mode' => 'process', 'messageId' => 'msg-1', 'mailbox' => 'ap@example.test']);

        self::assertSame(['captured' => 0, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Other'], $answer);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_invoices]'), 'the loser files no invoice');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]'), 'and takes its kept file back');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_emails]'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[invoice_email_attachments]'));
    }
}
