<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\ArSampleFiles;
use TriCab\Api\Finance\Ar\AzureSqlArAssigneeDirectory;
use TriCab\Api\Finance\Ar\AzureSqlArCreditNoteStore;
use TriCab\Api\Finance\Ar\AzureSqlArOptionStore;
use TriCab\Api\Finance\Ar\AzureSqlArOutboundStore;
use TriCab\Api\Finance\Ar\AzureSqlArRecordStore;
use TriCab\Api\Finance\Ar\AzureSqlArScanStore;
use TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailLedger;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Service\ArAttachmentService;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArRecordGuard;
use TriCab\Core\Finance\Ar\Service\ArRecordService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;

/**
 * The Tri-AR sample seed (T5.12) run for real on a disposable local SQL Server, twice, and then read through the real
 * stores and services the way the pages will: the register and its tiles, the files and their bytes, the assignees,
 * the credit note requests and their hashed tokens, the sent mail and the scan runs. Skipped without a local server.
 */
#[Group('local-sql-server')]
final class AzureSqlArSampleSeedTest extends TestCase
{
    private PDO $pdo;

    private PdoSqlConnection $connection;

    private ArRecordService $records;

    private ArTime $time;

    protected function setUp(): void
    {
        $pdo = LocalArSampleServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalArSampleServer::reset($pdo);
        LocalArSampleServer::run($pdo, 'seed-finance-mailboxes-local.sql');
        LocalArSampleServer::run($pdo, 'seed-finance-ar-sample-local.sql');
        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->time = new ArTime(FixedClock::utc(gmdate('Y-m-d H:i:s')), 'Australia/Melbourne');
        $this->records = new ArRecordService(
            new AzureSqlArRecordStore($this->connection),
            new ArOptionService(new AzureSqlArOptionStore($this->connection), $this->time),
            new AzureSqlArAssigneeDirectory($this->connection),
            $this->time,
            $this->files(),
        );
    }

    private function files(): AzureSqlFinanceAttachmentStore
    {
        $blobs = new InMemoryProductBlobStore('', 'finance-attachments');
        ArSampleFiles::load($blobs, 'finance-attachments');

        return new AzureSqlFinanceAttachmentStore($this->connection, $blobs, 'finance-attachments');
    }

    /**
     * @return list<string>
     */
    private function column(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $values[] = self::str($value);
        }

        return $values;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    private static function str(mixed $value): string
    {
        self::assertTrue(is_scalar($value) || $value === null);

        return trim((string) $value);
    }

    private static function int(mixed $value): int
    {
        self::assertTrue(is_numeric($value));

        return (int) $value;
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $tables = ['ar_email_records', 'ar_email_attachments', 'ar_remittance_lines', 'ar_outbound_messages', 'ar_credit_note_requests', 'ar_scan_runs', 'attachments', 'mail_deliveries'];
        $before = array_map(fn (string $t): int => $this->scalar("SELECT COUNT(*) FROM [finance].[{$t}]"), $tables);
        $users = $this->scalar('SELECT COUNT(*) FROM [identity].[Users]');

        LocalArSampleServer::run($this->pdo, 'seed-finance-ar-sample-local.sql');

        self::assertSame($before, array_map(fn (string $t): int => $this->scalar("SELECT COUNT(*) FROM [finance].[{$t}]"), $tables));
        self::assertSame($users, $this->scalar('SELECT COUNT(*) FROM [identity].[Users]'));
        self::assertSame([16, 5, 9, 2, 3, 3, 5, 2], $before);
        self::assertSame(5, $users);
    }

    public function test_the_register_lists_fifteen_and_the_bin_holds_one(): void
    {
        $live = $this->records->list(['limit' => '100']);
        $bin = $this->records->list(['deleted' => '1']);

        self::assertSame(15, $live['total']);
        self::assertSame(1, $bin['total']);
        $binned = ArFixtures::rows($bin['records'])[0];
        self::assertSame('Old thread - please ignore', $binned['subject']);
        self::assertSame('sample.admin@sample.local', $binned['deleted_by']);
        // A list row carries no body.
        self::assertArrayNotHasKey('body', ArFixtures::rows($live['records'])[0]);
    }

    public function test_the_tiles_agree_with_the_list_and_two_records_are_overdue_on_any_day(): void
    {
        $metrics = $this->records->metrics(null, null);

        self::assertSame(15, $metrics['total_emails']);
        self::assertSame([5, 5, 2, 3], [$metrics['needs_review'], $metrics['in_progress'], $metrics['overdue_items'], $metrics['completed']]);
        self::assertSame(1, $metrics['in_bin']);
        $overdue = ArFixtures::rows($this->records->list(['follow_up_status' => 'overdue'])['records']);
        self::assertSame(['Please release order SO-20418', 'Overdue account - payment plan'], array_column($overdue, 'subject'), 'newest message first, the register\'s default sort');
        foreach ($overdue as $row) {
            self::assertSame('overdue', $row['follow_up_status']);
        }
        self::assertEquals(['form_sent' => 1, 'submitted' => 1, 'completed' => 1], $metrics['credit_note_counts']);
    }

    public function test_every_category_and_method_the_sample_uses_is_one_of_the_seeded_options(): void
    {
        $options = new ArOptionService(new AzureSqlArOptionStore($this->connection), $this->time);
        $lists = $options->lists();
        $categories = array_column(ArFixtures::rows($lists['category']), 'value');
        $methods = array_column(ArFixtures::rows($lists['method']), 'value');

        foreach ($this->column('SELECT DISTINCT [follow_up_category] FROM [finance].[ar_email_records]') as $category) {
            self::assertContains($category, $categories);
        }
        foreach ($this->column('SELECT DISTINCT [proposed_response_type] FROM [finance].[ar_email_records]') as $method) {
            self::assertContains($method, $methods);
        }
    }

    public function test_the_remittance_record_shows_its_file_and_lines_and_the_file_comes_back_with_the_hash_the_row_holds(): void
    {
        $record = $this->records->list(['search' => 'payment 4471'])['records'];
        $id = self::str(ArFixtures::rows($record)[0]['id']);

        $one = $this->records->get($id);

        $attachments = ArFixtures::rows($one['attachments']);
        self::assertCount(1, $attachments);
        self::assertSame(['Remittance_4471.pdf', 'application/pdf', true, true, 18420.5, 'extracted'], [
            $attachments[0]['file_name'], $attachments[0]['content_type'], $attachments[0]['is_remittance'], $attachments[0]['reconciles'], $attachments[0]['total_amount'], $attachments[0]['extraction_status'],
        ]);
        $lines = ArFixtures::rows($attachments[0]['line_items']);
        self::assertSame(['INV-88101', 'INV-88130', 'INV-88164'], array_column($lines, 'invoice_number'));
        self::assertSame([6140.0, 7880.5, 4400.0], array_column($lines, 'paid_amount'));

        $service = new ArAttachmentService(new AzureSqlArRecordStore($this->connection), new FinanceAttachmentService($this->files()), new ArRecordGuard(new AzureSqlArRecordStore($this->connection)), $this->time);
        $file = $service->file(new ArActor(1, 'Sample', 'sample.admin@sample.local', []), $id, self::str($attachments[0]['id']));
        self::assertStringStartsWith('%PDF-1.4', $file->bytes);
        self::assertSame('application/pdf', $file->contentType);
        $row = $this->rowsOf("SELECT [sha256], [byte_size] FROM [finance].[attachments] WHERE [id] = '5a100000000000000000000000000001'")[0];
        self::assertSame($row['sha256'], hash('sha256', $file->bytes), 'the hash in the seed is the hash of the bytes the class makes');
        self::assertSame(self::int($row['byte_size']), strlen($file->bytes));
    }

    public function test_the_unreconciled_remittance_the_skipped_application_and_the_failed_reading_are_all_there(): void
    {
        $summary = [];
        foreach ($this->rowsOf('SELECT [extraction_status], [reconciles], COUNT(*) AS n FROM [finance].[ar_email_attachments] GROUP BY [extraction_status], [reconciles] ORDER BY 1, 2') as $r) {
            $summary[] = [self::str($r['extraction_status']), $r['reconciles'] === null ? null : self::int($r['reconciles']), self::int($r['n'])];
        }

        self::assertSame([['extracted', 0, 1], ['extracted', 1, 2], ['failed', null, 1], ['skipped', null, 1]], $summary);
    }

    public function test_the_assignee_list_is_the_four_people_who_can_write_and_not_the_reviewer(): void
    {
        $names = array_map(static fn ($assignee): string => $assignee->name, (new AzureSqlArAssigneeDirectory($this->connection))->assignees());

        self::assertSame(['Lena Marsh', 'Mia Ford', 'Noah Hale', 'Sample Administrator'], $names);
        $assigned = ArFixtures::rows($this->records->list(['assigned_person' => 'Lena'])['records']);
        self::assertCount(3, $assigned, 'Lena has three live records');
        self::assertNotNull(ArFixtures::rows($this->records->list(['assigned_person' => 'Mia'])['records'])[0]['assigned_user_id']);
    }

    public function test_the_credit_note_requests_hold_only_the_hash_of_their_sample_tokens(): void
    {
        $store = new AzureSqlArCreditNoteStore($this->connection);

        $open = $store->findCreditNote('5a130000000000000000000000000001');
        $submitted = $store->findCreditNote('5a130000000000000000000000000002');
        $completed = $store->findCreditNote('5a130000000000000000000000000003');

        self::assertNotNull($open);
        self::assertNotNull($submitted);
        self::assertNotNull($completed);
        self::assertSame(['form_sent', 'submitted', 'completed'], [$open->status, $submitted->status, $completed->status]);
        self::assertSame(hash('sha256', 'sample-token-01-local-run-only'), $open->tokenHash);
        self::assertSame(hash('sha256', 'sample-token-02-local-run-only'), $submitted->tokenHash);
        self::assertTrue($open->tokenExpiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'the open form is still open');
        self::assertTrue($completed->tokenExpiresAt < new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'the completed one has expired');
        self::assertSame(1840.0, $submitted->creditAmount);
        self::assertSame('5a0e0000000000000000000000000006', trim((string) $open->emailRecordId), 'the open request is linked to the Calder & Finch record');
        $linked = $this->records->get((string) $open->emailRecordId);
        self::assertSame($open->id, self::str($linked['credit_note_request_id']));
        self::assertSame('theo.park@sample.local', $linked['assigned_sales_rep_email']);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_credit_note_requests] WHERE [token_hash] LIKE 'sample-token%'"));
    }

    public function test_the_sent_mail_and_the_scan_runs_read_back(): void
    {
        $outbound = (new AzureSqlArOutboundStore($this->connection))->outboundOf($this->recordId('Invoice copy needed - INV-87990'));
        $ledger = new AzureSqlFinanceMailLedger($this->connection);

        self::assertCount(2, $outbound);
        $statuses = [];
        foreach ($outbound as $link) {
            self::assertTrue($link->threaded);
            self::assertStringContainsString('invoice copy attached', $link->body);
            $delivery = $ledger->findById($link->deliveryId);
            self::assertNotNull($delivery);
            self::assertSame('AR', $delivery->application);
            $statuses[] = $delivery->status;
        }
        sort($statuses);
        self::assertSame(['failed', 'sent'], $statuses);

        $scans = new AzureSqlArScanStore($this->connection);
        $runs = $scans->recent(10);
        self::assertCount(3, $runs);
        self::assertSame(['stopped', 'completed', 'completed'], array_map(static fn ($r): string => $r->status, $runs), 'newest first');
        self::assertNotNull($scans->latestWatermark('ar@tricab.com'));
        self::assertNull($runs[0]->watermark, 'a period scan never holds a watermark');
        self::assertNotNull($runs[0]->fromDate);
        self::assertNotNull($runs[0]->toDate);
        self::assertSame([], $scans->overlapping('2000-01-01', '2100-01-01'), 'only completed and running period scans make a period "already scanned"; the sample has none');
    }

    private function recordId(string $subject): string
    {
        $statement = $this->pdo->prepare('SELECT [id] FROM [finance].[ar_email_records] WHERE [subject] = :subject');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['subject' => $subject]);

        return trim((string) $statement->fetchColumn());
    }
}
