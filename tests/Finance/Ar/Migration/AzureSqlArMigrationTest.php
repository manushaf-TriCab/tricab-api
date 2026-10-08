<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar\Migration;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\AzureSqlFinancePortalUserMap;
use TriCab\Api\Finance\Ar\AzureSqlArOptionStore;
use TriCab\Api\Finance\Ar\AzureSqlArRecordStore;
use TriCab\Api\Finance\Ar\AzureSqlArScanStore;
use TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory;
use TriCab\Api\Finance\Ar\Migration\AzureSqlArMigrationTarget;
use TriCab\Api\Finance\Ar\Migration\InMemoryArMessageIdTranslator;
use TriCab\Api\Finance\Ar\Migration\SqliteArMigrationSource;
use TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Ar\ArFixtures;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Migration\ArAttachmentFolder;
use TriCab\Core\Finance\Ar\Migration\ArMigratedAttachment;
use TriCab\Core\Finance\Ar\Migration\ArMigratedLine;
use TriCab\Core\Finance\Ar\Migration\ArMigratedScanRun;
use TriCab\Core\Finance\Ar\Migration\ArMigrationOptions;
use TriCab\Core\Finance\Ar\Migration\ArMigrationRefused;
use TriCab\Core\Finance\Ar\Migration\ArMigrationReport;
use TriCab\Core\Finance\Ar\Migration\ArMigrationTarget;
use TriCab\Core\Finance\Ar\Migration\ArMigrationUsers;
use TriCab\Core\Finance\Ar\Migration\ArMigrator;
use TriCab\Core\Finance\Ar\Service\ArAttachmentService;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArRecordGuard;
use TriCab\Core\Finance\Ar\Service\ArRecordService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMapping;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;

/**
 * The migration against a real, disposable local SQL Server ({@see LocalFinanceSqlServer}); skipped without one.
 * The invented source is migrated for real and read back through the real Tri-AR stores and services: the register's
 * list, one record with its attachment and lines, the derived overdue, the Bin, the option lists the team set, the
 * file's bytes, and the watermark the next scan would start from. Also what the twin cannot prove: the repeat run
 * against the filtered unique index, the refusal on a clash with a platform-scanned row, and a rollback.
 * "Today" is 8 October 2026 in Melbourne.
 */
#[Group('local-sql-server')]
final class AzureSqlArMigrationTest extends TestCase
{
    private PDO $pdo;

    private PdoSqlConnection $connection;

    private AzureSqlFinanceAttachmentStore $files;

    private InventedTriArData $data;

    private ArTime $time;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->files = new AzureSqlFinanceAttachmentStore($this->connection, new InMemoryProductBlobStore('', 'finance-attachments'), 'finance-attachments');
        $this->data = new InventedTriArData();
        $this->time = new ArTime(FixedClock::utc('2026-10-08 03:00:00'), 'Australia/Melbourne');
    }

    private function migrator(?ArMigrationTarget $target = null, ?InMemoryArMessageIdTranslator $translator = null): ArMigrator
    {
        $users = new ArMigrationUsers((new AzureSqlFinancePortalUserMap($this->connection))->all());

        return new ArMigrator(
            new SqliteArMigrationSource($this->data->database),
            $target ?? new AzureSqlArMigrationTarget($this->connection),
            new ArAttachmentFolder($this->data->attachments),
            $this->files,
            $users,
            $translator,
        );
    }

    private function apply(?ArMigrationTarget $target = null, ?InMemoryArMessageIdTranslator $translator = null): ArMigrationReport
    {
        $migrator = $this->migrator($target, $translator);

        return $migrator->apply($migrator->prepare(new ArMigrationOptions(true, $translator !== null, $translator === null)));
    }

    private function records(): ArRecordService
    {
        return new ArRecordService(
            new AzureSqlArRecordStore($this->connection),
            new ArOptionService(new AzureSqlArOptionStore($this->connection), $this->time),
            new InMemoryArAssigneeDirectory(),
            $this->time,
            $this->files,
        );
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} the overdue record, the completed one, the binned one and the attachment */
    private function seedSource(): array
    {
        $overdue = $this->data->record([
            'subject' => 'Please release order SO-1', 'follow_up_status' => 'overdue', 'due_date' => '2026-10-02T00:00:00+00:00',
            'assigned_person' => 'lena.marsh@example.test', 'reminder_sent_at' => '2026-10-03T00:00:00.123456+00:00',
            'email_date' => '2026-10-01T09:00:00+10:00', 'body' => str_repeat('Überprüfung — 保証 ✓ ', 500),
        ]);
        $attachment = $this->data->attachment($overdue);
        $this->data->line($attachment, $overdue, ['id' => 'f' . substr($this->data->id(), 1), 'invoice_number' => 'INV-1', 'paid_amount' => 600.25]);
        $this->data->line($attachment, $overdue, ['invoice_number' => 'INV-2', 'paid_amount' => 650.25]);
        $completed = $this->data->record(['subject' => 'Statement query', 'follow_up_status' => 'completed', 'due_date' => '2026-09-01', 'email_date' => '2026-09-30T00:00:00Z']);
        $binned = $this->data->record([
            'subject' => 'Old thread', 'deleted_at' => '2026-10-05T01:00:00+00:00', 'deleted_by' => 'lena.marsh@example.test', 'email_date' => '2026-09-20T00:00:00Z',
        ]);
        $this->data->record(['subject' => 'Not valid', 'status' => 'bogus']);
        $this->data->scanRun(['watermark' => '2026-10-05T20:58:11Z', 'started_at' => '2026-10-05T21:00:00+00:00']);
        $this->data->scanRun(['watermark' => '2026-10-06T20:58:11.250000+00:00']);
        $this->data->option('category', 'other', ['label' => 'Everything else', 'color' => 'rose', 'sort_order' => 2, 'is_system' => 1]);
        $this->data->option('category', 'remittance_advice', ['label' => 'Remittances', 'color' => 'green', 'sort_order' => 1, 'active' => 0]);
        $this->data->option('method', 'email', ['label' => 'E-mail', 'is_system' => 1]);
        $this->data->option('method', 'phone', ['label' => 'Phone call', 'is_system' => 1, 'sort_order' => 1]);

        return [$overdue, $completed, $binned, $attachment];
    }

    public function test_the_rows_land_and_read_back_through_the_real_register(): void
    {
        [$overdue, $completed, $binned, $attachment] = $this->seedSource();
        (new AzureSqlFinancePortalUserMap($this->connection))->add(new FinancePortalUserMapping('17', 'Lena.Marsh@example.test', 4001, new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC'))));
        $translator = new InMemoryArMessageIdTranslator([
            'AAMkAD-old-folder-id-' . $overdue => 'AAMkImmutable-OVERDUE',
            'AAMkAD-old-folder-id-' . $completed => 'AAMkImmutable-COMPLETED',
        ]);

        $report = $this->apply(translator: $translator);

        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_email_records]'), 'the invalid one is not in');
        self::assertSame(1, $report->count('problems', 'email_records.status_not_allowed'));
        $service = $this->records();
        $live = $service->list(['limit' => '100']);
        self::assertSame(2, $live['total']);
        self::assertSame([$overdue, $completed], ArFixtures::idsOf($live), 'newest message first (the 1 October local time is 30 September UTC)');
        $bin = $service->list(['deleted' => '1']);
        self::assertSame([$binned], ArFixtures::idsOf($bin));

        $one = $service->get($overdue);
        self::assertSame('overdue', $one['follow_up_status'], 'overdue is derived on read from the due date');
        self::assertSame('2026-10-02', $one['due_date']);
        self::assertSame('lena.marsh@example.test', $one['assigned_person']);
        self::assertSame(4001, $this->scalar("SELECT [assigned_user_id] FROM [finance].[ar_email_records] WHERE [id] = '{$overdue}'"));
        self::assertSame(str_repeat('Überprüfung — 保証 ✓ ', 500), $one['body']);
        self::assertSame('AAMkImmutable-OVERDUE', $this->text("SELECT [source_message_id] FROM [finance].[ar_email_records] WHERE [id] = '{$overdue}'"));
        self::assertSame('2026-10-03 00:00:00.123456', $this->text("SELECT CONVERT(VARCHAR(30), [reminder_sent_at], 121) FROM [finance].[ar_email_records] WHERE [id] = '{$overdue}'"));
        self::assertSame('2026-09-30 23:00:00.000000', $this->text("SELECT CONVERT(VARCHAR(30), [email_date], 121) FROM [finance].[ar_email_records] WHERE [id] = '{$overdue}'"), 'a local time stored as UTC');
        self::assertSame(1, $this->scalar("SELECT [message_unreachable] FROM [finance].[ar_email_records] WHERE [id] = '{$binned}'"));
        self::assertSame(0, $this->scalar("SELECT [message_unreachable] FROM [finance].[ar_email_records] WHERE [id] = '{$completed}'"));

        $attachments = ArFixtures::rows($one['attachments']);
        self::assertCount(1, $attachments);
        self::assertSame($attachment, $attachments[0]['id']);
        self::assertSame(['Remittance Advice.pdf', 'application/pdf', true, true, 1250.5, 'extracted'], [
            $attachments[0]['file_name'], $attachments[0]['content_type'], $attachments[0]['is_remittance'], $attachments[0]['reconciles'], $attachments[0]['total_amount'], $attachments[0]['extraction_status'],
        ]);
        $lines = ArFixtures::rows($attachments[0]['line_items']);
        self::assertSame(['INV-1', 'INV-2'], array_column($lines, 'invoice_number'), 'numbered from 1 in the source\'s row order, not id order');
        self::assertSame([600.25, 650.25], array_column($lines, 'paid_amount'));
        self::assertSame([1, 2], array_map(static fn (mixed $n): int => is_numeric($n) ? (int) $n : -1, $this->column('SELECT [line_no] FROM [finance].[ar_remittance_lines] ORDER BY [line_no]')));

        $store = new AzureSqlArRecordStore($this->connection);
        $file = (new ArAttachmentService($store, new FinanceAttachmentService($this->files), new ArRecordGuard($store), $this->time))
            ->file(new ArActor(1, 'Reader', 'reader@example.test', []), $overdue, $attachment);
        self::assertSame(InventedTriArData::BYTES, $file->bytes);
        self::assertSame('Remittance Advice.pdf', $file->downloadName);

        $metrics = $service->metrics(null, null);
        self::assertSame(2, $metrics['total_emails']);
        self::assertSame([0, 0, 1, 1], [$metrics['needs_review'], $metrics['in_progress'], $metrics['overdue_items'], $metrics['completed']]);
        self::assertSame(1, $metrics['in_bin']);
    }

    public function test_the_next_scan_starts_from_the_highest_completed_watermark_and_the_teams_lists_replace_the_defaults(): void
    {
        $this->seedSource();

        $this->apply();

        $watermark = (new AzureSqlArScanStore($this->connection))->latestWatermark('ar@tricab.com');
        self::assertSame('2026-10-06 20:58:11.250000', $watermark?->format('Y-m-d H:i:s.u'));
        $options = (new ArOptionService(new AzureSqlArOptionStore($this->connection), $this->time))->lists();
        $categories = ArFixtures::rows($options['category']);
        self::assertSame(['remittance_advice', 'other'], array_column($categories, 'value'), 'the team\'s order');
        self::assertSame(['Remittances', 'Everything else'], array_column($categories, 'label'));
        self::assertSame([false, true], array_column($categories, 'active'), 'an archived option stays archived');
        self::assertSame(['E-mail', 'Phone call'], array_column(ArFixtures::rows($options['method']), 'label'));
    }

    public function test_a_second_run_inserts_nothing_and_the_counts_do_not_move(): void
    {
        [$overdue] = $this->seedSource();
        $translator = new InMemoryArMessageIdTranslator(['AAMkAD-old-folder-id-' . $overdue => 'AAMkImmutable-OVERDUE']);
        $this->apply(translator: $translator);
        $tables = ['ar_email_records', 'ar_email_attachments', 'ar_remittance_lines', 'ar_scan_runs', 'ar_option_lists', 'attachments'];
        $before = array_map(fn (string $t): int => $this->scalar("SELECT COUNT(*) FROM [finance].[{$t}]"), $tables);

        $second = $this->apply(translator: $translator);

        self::assertSame($before, array_map(fn (string $t): int => $this->scalar("SELECT COUNT(*) FROM [finance].[{$t}]"), $tables));
        self::assertSame([3, 1, 2, 2, 4, 1], $before);
        foreach (['email_records', 'email_attachments', 'remittance_lines', 'scan_runs', 'option_lists', 'files'] as $table) {
            self::assertSame(0, $second->count('inserted', $table), $table);
        }
        self::assertSame(3, $second->count('already_present', 'email_records'));
    }

    public function test_a_row_the_platform_scanned_with_the_same_message_id_refuses_apply_and_writes_nothing(): void
    {
        [$overdue] = $this->seedSource();
        (new AzureSqlArRecordStore($this->connection))->insert(ArFixtures::record('0000000000000000000000000000beef', sourceMessageId: 'aamkimmutable-overdue'));
        $translator = new InMemoryArMessageIdTranslator(['AAMkAD-old-folder-id-' . $overdue => 'AAMkImmutable-OVERDUE']);
        $migrator = $this->migrator(translator: $translator);
        $plan = $migrator->prepare(new ArMigrationOptions(false, true, false));

        self::assertSame(1, $plan->report->conflictCount(), 'the unique index compares without regard to case, so the tool does too');
        try {
            $migrator->apply($plan);
            self::fail('Expected a refusal.');
        } catch (ArMigrationRefused) {
            self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_email_records]'));
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_email_attachments]'));
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]'));
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_scan_runs]'));
        }
    }

    public function test_an_option_list_already_in_the_target_with_other_content_is_a_conflict_and_is_not_overwritten(): void
    {
        $this->seedSource();
        (new AzureSqlArOptionStore($this->connection))->insertOption(new ArOption(
            'category', 'other', 'Other', '', 'slate', 0, true, true,
            new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')),
        ));
        $plan = $this->migrator()->prepare(new ArMigrationOptions(false, false, true));

        self::assertSame(1, $plan->report->conflictCount());
        self::assertTrue($plan->blocked());
        self::assertSame('Other', $this->text("SELECT [label] FROM [finance].[ar_option_lists] WHERE [list_name] = 'category' AND [value] = 'other'"));
    }

    public function test_a_failure_part_way_through_a_record_rolls_the_record_back_and_removes_its_file(): void
    {
        [$overdue, $completed] = $this->seedSource();
        $failing = new class (new AzureSqlArMigrationTarget($this->connection)) implements ArMigrationTarget {
            public function __construct(private readonly AzureSqlArMigrationTarget $real)
            {
            }

            public function counts(): array
            {
                return $this->real->counts();
            }

            public function hasRecord(string $id): bool
            {
                return $this->real->hasRecord($id);
            }

            public function recordHoldingMessage(string $messageId): ?string
            {
                return $this->real->recordHoldingMessage($messageId);
            }

            public function attachmentIdsOf(string $recordId): array
            {
                return $this->real->attachmentIdsOf($recordId);
            }

            public function options(string $listName): array
            {
                return $this->real->options($listName);
            }

            public function hasScanRun(string $id): bool
            {
                return $this->real->hasScanRun($id);
            }

            public function hasTombstone(string $messageId): bool
            {
                return $this->real->hasTombstone($messageId);
            }

            public function latestWatermarks(): array
            {
                return $this->real->latestWatermarks();
            }

            public function transactional(callable $work): mixed
            {
                return $this->real->transactional($work);
            }

            public function insertRecord(ArRecord $record): void
            {
                $this->real->insertRecord($record);
            }

            public function insertAttachment(ArMigratedAttachment $attachment): void
            {
                $this->real->insertAttachment($attachment);
            }

            public function insertLine(ArMigratedLine $line): void
            {
                if ($line->lineNo === 2) {
                    throw new \RuntimeException('Injected failure after the record, the attachment and a line were written.');
                }
                $this->real->insertLine($line);
            }

            public function insertScanRun(ArMigratedScanRun $run): void
            {
                $this->real->insertScanRun($run);
            }

            public function insertOption(ArOption $option): void
            {
                $this->real->insertOption($option);
            }

            public function insertTombstone(string $messageId, DateTimeImmutable $at): void
            {
                $this->real->insertTombstone($messageId, $at);
            }
        };

        $report = $this->apply($failing);

        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [id] = '{$overdue}'"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_email_attachments]'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_remittance_lines]'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]'), 'the file written for it is removed again, row and bytes');
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [id] = '{$completed}'"), 'the other records are not affected');
        self::assertSame(1, $report->count('failed', 'email_records'));

        $again = $this->apply();
        self::assertSame(1, $again->count('inserted', 'email_records'), 'a repeat writes what failed');
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[attachments]'));
    }

    private function text(string $sql): string
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        $value = $statement->fetchColumn();
        self::assertIsString($value);

        return $value;
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
