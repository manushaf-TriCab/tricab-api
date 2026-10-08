<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\AzureSqlArOptionStore;
use TriCab\Api\Finance\Ar\AzureSqlArRecordStore;
use TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArExtraction;
use TriCab\Core\Finance\Ar\Domain\ArKeptAttachment;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArRecordService;
use TriCab\Core\Finance\Ar\Service\ArTime;

/**
 * The register over `create-finance-ar-schema.sql`, against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one. What the twin cannot prove: the unique message id,
 * the derived status in a `WHERE`, an `ORDER BY` and a `GROUP BY`, the Melbourne day filter before the
 * limit, the escaped search, the transaction around a permanent delete, and a unicode round trip.
 * "Today" is 22 September 2026 in Melbourne.
 */
#[Group('local-sql-server')]
final class AzureSqlArRecordStoreTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlArRecordStore $store;

    private ArRecordService $service;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlArRecordStore($connection);
        $time = new ArTime(FixedClock::utc('2026-09-22 03:00:00'), 'Australia/Melbourne');
        $this->service = new ArRecordService(
            $this->store,
            new ArOptionService(new AzureSqlArOptionStore($connection), $time),
            new InMemoryArAssigneeDirectory(),
            $time,
            new InMemoryFinanceAttachmentStore(),
        );
    }

    private function add(string $id, mixed ...$over): ArRecord
    {
        $record = ArFixtures::record($id, ...$over);
        $this->store->insert($record);

        return $record;
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function ids(array $params = []): array
    {
        return ArFixtures::idsOf($this->service->list($params));
    }

    public function test_a_record_round_trips_with_unicode_six_digit_time_and_a_person_id_past_two_to_the_53(): void
    {
        $body = str_repeat('Überprüfung — 保証 ✓ ', 1000);    // about 20 kB
        $sent = ArFixtures::utc('2026-09-21 02:09:00.123456');
        $this->add(
            'a', body: $body, customerName: 'Zoë Łukasz 北京 Pty Ltd', emailDate: $sent, dueDate: '2026-10-05', assignedUserId: 9007199254740993,
            assignedName: 'Zoë', classificationConfidence: 0.91, reminderSentAt: ArFixtures::utc('2026-09-20 01:02:03.654321'), messageUnreachable: true,
        );

        $found = $this->store->find('a');

        self::assertInstanceOf(ArRecord::class, $found);
        self::assertSame($body, $found->body);
        self::assertSame('Zoë Łukasz 北京 Pty Ltd', $found->customerName);
        self::assertSame('2026-09-21 02:09:00.123456', $found->emailDate->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-10-05', $found->dueDate);
        self::assertSame(9007199254740993, $found->assignedUserId);
        self::assertSame(0.91, $found->classificationConfidence);
        self::assertSame('2026-09-20 01:02:03.654321', $found->reminderSentAt?->format('Y-m-d H:i:s.u'));
        self::assertTrue($found->messageUnreachable);
        self::assertSame('ar@tricab.com', $found->mailbox);
        self::assertNull($found->creditNoteRequestId);
        self::assertNull($this->store->find('missing'));
    }

    public function test_the_message_id_is_unique_where_present_and_absent_ids_do_not_clash(): void
    {
        $this->add('a', sourceMessageId: null, messageUnreachable: true);
        $this->add('b', sourceMessageId: null, messageUnreachable: true);
        $this->add('c', sourceMessageId: 'AAMk-immutable-id');

        $this->expectException(ArConflict::class);
        $this->add('d', sourceMessageId: 'aamk-IMMUTABLE-id');
    }

    public function test_overdue_can_not_be_stored(): void
    {
        $this->expectException(\Throwable::class);
        $this->add('a', followUpStatus: 'overdue');
    }

    public function test_the_stored_status_is_never_written_by_a_read_but_the_register_shows_overdue(): void
    {
        $this->add('late', dueDate: '2026-09-21', followUpStatus: 'in_progress');
        $this->add('later', dueDate: '2026-09-01', followUpStatus: 'needs_review');
        $this->add('today', dueDate: '2026-09-22', followUpStatus: 'in_progress');
        $this->add('done', dueDate: '2026-09-01', followUpStatus: 'completed');

        $listed = $this->service->list(['sort_by' => 'created_date', 'sort_dir' => 'asc']);
        $this->service->metrics(null, null);
        $this->service->get('late');

        $records = $listed['records'];
        self::assertIsArray($records);
        self::assertEquals(['late' => 'overdue', 'later' => 'overdue', 'today' => 'in_progress', 'done' => 'completed'], array_column($records, 'follow_up_status', 'id'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [follow_up_status] = N'overdue'"));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [follow_up_status] = N'in_progress'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [follow_up_status] = N'needs_review'"), 'a needs_review record keeps its stored status');
        self::assertEqualsCanonicalizing(['late', 'later'], $this->ids(['follow_up_status' => 'overdue']));
        self::assertSame(['today'], $this->ids(['follow_up_status' => 'in_progress']));
        self::assertSame([], $this->ids(['follow_up_status' => 'needs_review']));
        self::assertSame(['done', 'today', 'late', 'later'], $this->ids(['sort_by' => 'follow_up_status', 'sort_dir' => 'asc']), 'completed, in_progress, then the two overdue');

        $metrics = $this->service->metrics(null, null);
        self::assertSame(2, $metrics['overdue_items']);
        self::assertSame(1, $metrics['in_progress']);
        self::assertSame(0, $metrics['needs_review']);
        self::assertSame(1, $metrics['completed']);
    }

    public function test_melbourne_days_filter_before_the_limit_and_the_total_is_the_filters(): void
    {
        $this->add('first-minutes', emailDate: ArFixtures::utc('2026-05-14 14:30:00'));
        $this->add('last-second', emailDate: ArFixtures::utc('2026-05-15 13:59:59'));
        $this->add('day-before', emailDate: ArFixtures::utc('2026-05-14 13:00:00'));
        $this->add('next-day', emailDate: ArFixtures::utc('2026-05-15 14:00:00'));
        $this->add('april', emailDate: ArFixtures::utc('2026-03-31 14:00:00'));    // 1 April in Melbourne (AEDT)
        $this->add('march', emailDate: ArFixtures::utc('2026-03-30 14:00:00'));
        for ($n = 1; $n <= 5; ++$n) {
            $this->add('newer-' . $n, emailDate: ArFixtures::utc('2026-06-0' . $n . ' 01:00:00'));
        }

        $result = $this->service->list(['from_date' => '2026-05-15', 'to_date' => '2026-05-15', 'limit' => '2']);

        $records = $result['records'];
        self::assertIsArray($records);
        $ids = array_column($records, 'id');
        sort($ids);
        self::assertSame(['first-minutes', 'last-second'], $ids);
        self::assertSame(2, $result['total']);
        self::assertSame(['march'], $this->ids(['from_date' => '2026-03-01', 'to_date' => '2026-03-31']));
        self::assertSame(['april'], $this->ids(['from_date' => '2026-04-01', 'to_date' => '2026-04-30']));
        self::assertSame(1, $this->service->metrics('2026-03-01', '2026-03-31')['total_emails']);
        self::assertSame(1, $this->service->metrics('2026-04-01', '2026-04-30')['total_emails']);
    }

    public function test_search_looks_in_five_columns_the_body_only_on_request_and_wildcards_match_themselves(): void
    {
        $this->add('s', subject: 'Freight invoice', customerName: 'Other', body: 'xyzzy in the body only');
        $this->add('c', subject: 'Hello', customerName: 'Harbour Cables Pty Ltd');
        $this->add('e', subject: 'Hello', senderEmail: 'pay@harbour.example.test', customerName: 'Other');
        $this->add('n', subject: 'Hello', senderName: 'Harbour Hannah', customerName: 'Other');
        $this->add('m', subject: 'Hello', aiSummary: 'Asks about the Harbour account', customerName: 'Other');
        $this->add('pct', subject: '50% off', customerName: 'Other');
        $this->add('und', subject: 'a_b ref', customerName: 'Other');
        $this->add('bra', subject: 'item [x] list', customerName: 'Other');
        $this->add('plain', subject: 'abcd ref', customerName: 'Other');

        self::assertEqualsCanonicalizing(['c', 'e', 'n', 'm'], $this->ids(['search' => 'HARBOUR']));
        self::assertSame([], $this->ids(['search' => 'xyzzy']));
        self::assertSame(['s'], $this->ids(['search' => 'xyzzy', 'search_body' => '1']));
        self::assertSame(['pct'], $this->ids(['search' => '50%']));
        self::assertSame(['und'], $this->ids(['search' => 'a_b']));
        self::assertSame(['bra'], $this->ids(['search' => '[x]']));
        self::assertSame(['bra'], $this->ids(['search' => '[']));
        self::assertSame(['s'], $this->ids(['search' => 'FREIGHT']));
        self::assertSame(['c'], $this->ids(['customer_name' => 'cables']));
        self::assertSame(['n'], $this->ids(['search' => 'Hannah']));
    }

    public function test_search_finds_unicode_text(): void
    {
        $this->add('u', subject: 'Garantie Überprüfung 保証', customerName: 'Zoë');

        self::assertSame(['u'], $this->ids(['search' => 'überprüfung']));
        self::assertSame(['u'], $this->ids(['search' => '保証']));
    }

    public function test_every_sort_key_runs_and_orders_by_its_column(): void
    {
        $this->add('A', subject: 'Banana', customerName: 'Zed', dueDate: null, classificationConfidence: 0.5, assignedName: 'Una', emailDate: ArFixtures::utc('2026-03-01 00:00:00'), senderEmail: 'c@x.test', status: 'sent', followUpCategory: 'b_cat', proposedResponseType: 'phone', createdAt: ArFixtures::utc('2026-03-03 00:00:00'));
        $this->add('B', subject: 'Apple', customerName: 'Yan', dueDate: '2026-10-01', classificationConfidence: 0.9, assignedName: 'Max', emailDate: ArFixtures::utc('2026-03-02 00:00:00'), senderEmail: 'a@x.test', status: 'new', followUpCategory: 'a_cat', proposedResponseType: 'email', createdAt: ArFixtures::utc('2026-03-01 00:00:00'));
        $this->add('C', subject: 'Cherry', customerName: 'Xi', dueDate: '2026-09-30', classificationConfidence: null, assignedName: '', emailDate: ArFixtures::utc('2026-03-03 00:00:00'), senderEmail: 'b@x.test', status: 'reviewed', followUpCategory: 'c_cat', proposedResponseType: 'email', createdAt: ArFixtures::utc('2026-03-02 00:00:00'), deletedAt: null);

        $expected = [
            'email_date' => ['A', 'B', 'C'],
            'customer_name' => ['C', 'B', 'A'],
            'sender_email' => ['B', 'C', 'A'],
            'subject' => ['B', 'A', 'C'],
            'follow_up_category' => ['B', 'A', 'C'],
            'assigned_person' => ['C', 'B', 'A'],
            'proposed_response_type' => ['B', 'C', 'A'],
            'due_date' => ['A', 'C', 'B'],
            'classification_confidence' => ['C', 'A', 'B'],
            'status' => ['B', 'C', 'A'],
            'created_date' => ['B', 'C', 'A'],
        ];
        foreach ($expected as $key => $order) {
            self::assertSame($order, $this->ids(['sort_by' => $key, 'sort_dir' => 'asc']), $key . ' ascending');
            self::assertSame(array_reverse($order), $this->ids(['sort_by' => $key, 'sort_dir' => 'desc']), $key . ' descending');
        }
        // follow_up_status and deleted_at have no spread here; they must still run.
        self::assertCount(3, $this->ids(['sort_by' => 'follow_up_status']));
        self::assertCount(3, $this->ids(['sort_by' => 'deleted_at']));
        self::assertSame(['A', 'B', 'C'], $this->ids(['sort_by' => 'final_outcome', 'sort_dir' => 'asc']), 'not sortable: by date');
        self::assertSame(['A', 'B', 'C'], $this->ids(['sort_by' => '[id]; DROP TABLE x', 'sort_dir' => 'asc']), 'an unknown key is never put into the SQL');
    }

    public function test_equal_keys_break_on_the_id_in_the_sort_direction(): void
    {
        foreach (['c', 'a', 'b'] as $id) {
            $this->add($id, subject: 'Same');
        }

        self::assertSame(['a', 'b', 'c'], $this->ids(['sort_by' => 'subject', 'sort_dir' => 'asc']));
        self::assertSame(['c', 'b', 'a'], $this->ids(['sort_by' => 'subject', 'sort_dir' => 'desc']));
        self::assertSame(['b', 'c'], array_slice($this->ids(['sort_by' => 'subject', 'sort_dir' => 'asc', 'offset' => '1']), 0, 2));
    }

    public function test_an_edit_writes_only_the_editable_columns_and_clears_the_reminder_on_a_new_date(): void
    {
        $this->add('a', dueDate: '2026-12-10', reminderSentAt: ArFixtures::utc('2026-09-20 00:00:00'), body: 'Original body', sourceMessageId: 'graph-1', mailbox: 'ar@tricab.com');

        $this->service->update('a', ['body' => 'Rewritten', 'source_message_id' => 'graph-2', 'mailbox' => 'x@example.test', 'final_outcome' => 'Done', 'customer_name' => 'Renamed Pty', 'status' => 'reviewed']);
        $kept = $this->store->find('a');
        self::assertInstanceOf(ArRecord::class, $kept);
        self::assertSame('Original body', $kept->body);
        self::assertSame('graph-1', $kept->sourceMessageId);
        self::assertSame('ar@tricab.com', $kept->mailbox);
        self::assertSame('Done', $kept->finalOutcome);
        self::assertSame('Renamed Pty', $kept->customerName);
        self::assertSame('reviewed', $kept->status);
        self::assertNotNull($kept->reminderSentAt);

        $this->service->update('a', ['due_date' => '2026-12-20']);
        $moved = $this->store->find('a');
        self::assertInstanceOf(ArRecord::class, $moved);
        self::assertSame('2026-12-20', $moved->dueDate);
        self::assertNull($moved->reminderSentAt);

        $this->service->update('a', ['due_date' => '']);
        self::assertNull($this->store->find('a')?->dueDate);
    }

    public function test_the_bin_and_restore(): void
    {
        $this->add('a');
        $this->add('b');
        $actor = new ArActor(7, 'Mia', 'mia@example.test', []);

        $this->service->delete($actor, 'a', false);

        self::assertSame(['b'], $this->ids());
        self::assertSame(['a'], $this->ids(['deleted' => '1', 'sort_by' => 'deleted_at']));
        $binned = $this->store->find('a');
        self::assertInstanceOf(ArRecord::class, $binned);
        self::assertSame(7, $binned->deletedByUserId);
        self::assertSame('mia@example.test', $binned->deletedBy);
        self::assertSame(1, $this->service->metrics(null, null)['in_bin']);

        $this->service->restore('a');
        $back = $this->store->find('a');
        self::assertInstanceOf(ArRecord::class, $back);
        self::assertNull($back->deletedAt);
        self::assertNull($back->deletedByUserId);
        self::assertSame('', $back->deletedBy);
    }

    private function insertFile(string $id, string $recordId, string $name): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO [finance].[attachments] ([id], [application_key], [record_type], [record_id], [blob_path], [original_name], [content_type], [byte_size], [sha256], [created_at], [attached_at])
             VALUES (:id, N'AR', N'email_record', :record, :path, :name, N'application/pdf', 3, :sha, SYSUTCDATETIME(), SYSUTCDATETIME())",
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['id' => $id, 'record' => $recordId, 'path' => 'AR/' . $id, 'name' => $name, 'sha' => str_repeat('0', 64)]);
    }

    private function insertAttachmentRow(string $id, string $recordId, string $fileId, bool $remittance = true): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO [finance].[ar_email_attachments] ([id], [email_record_id], [attachment_id], [is_remittance], [payer_name], [total_amount], [reconciles], [extraction_status])
             VALUES (:id, :record, :file, :remittance, N'Acme Marine', 120.50, 1, N'extracted')",
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['id' => $id, 'record' => $recordId, 'file' => $fileId, 'remittance' => $remittance ? 1 : 0]);
    }

    private function insertLine(string $id, string $attachmentId, string $recordId, int $lineNo, string $invoice, string $paid): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [finance].[ar_remittance_lines] ([id], [email_attachment_id], [email_record_id], [line_no], [invoice_number], [paid_amount]) VALUES (:id, :att, :record, :no, :invoice, :paid)',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['id' => $id, 'att' => $attachmentId, 'record' => $recordId, 'no' => $lineNo, 'invoice' => $invoice, 'paid' => $paid]);
    }

    public function test_attachments_are_metadata_from_the_row_and_the_file_store_with_lines_in_order(): void
    {
        $this->add('a');
        $this->insertFile('f1', 'a', 'Remittance — 保証.pdf');
        $this->insertAttachmentRow('att1', 'a', 'f1');
        $this->insertLine('l2', 'att1', 'a', 2, 'INV-2', '20.25');
        $this->insertLine('l1', 'att1', 'a', 1, 'INV-1', '100.25');

        $one = $this->service->get('a');

        $attachments = ArFixtures::rows($one['attachments']);
        self::assertCount(1, $attachments);
        $attachment = $attachments[0];
        self::assertSame('Remittance — 保証.pdf', $attachment['file_name']);
        self::assertSame('application/pdf', $attachment['content_type']);
        self::assertSame(3, $attachment['size_bytes']);
        self::assertTrue($attachment['is_remittance']);
        self::assertTrue($attachment['reconciles']);
        self::assertSame(120.5, $attachment['total_amount']);
        self::assertSame('extracted', $attachment['extraction_status']);
        self::assertArrayNotHasKey('blob_path', $attachment);
        self::assertArrayNotHasKey('stored_name', $attachment);
        $lines = ArFixtures::rows($attachment['line_items']);
        self::assertSame(['INV-1', 'INV-2'], array_column($lines, 'invoice_number'));
        self::assertSame([100.25, 20.25], array_column($lines, 'paid_amount'));
    }

    public function test_a_kept_attachment_row_points_at_its_file_and_only_its_own_record_resolves_it(): void
    {
        $this->add('a');
        $this->add('b');
        $this->insertFile('f1', 'a', 'Advice.pdf');
        $this->store->addAttachment(new ArKeptAttachment('att1', 'a', 'f1', 'Advice.pdf', 'application/pdf', 3, 'pending', ArFixtures::utc('2026-09-22 01:02:03.123456')));

        self::assertTrue($this->store->exists('a'));
        self::assertFalse($this->store->exists('nope'));
        self::assertSame('f1', $this->store->attachmentFileId('a', 'att1'));
        self::assertNull($this->store->attachmentFileId('b', 'att1'), 'another record cannot reach it');
        self::assertNull($this->store->attachmentFileId('a', 'missing'));
        $rows = $this->store->attachmentsOf('a');
        self::assertCount(1, $rows);
        self::assertSame('Advice.pdf', $rows[0]['file_name']);
        self::assertSame('pending', $rows[0]['extraction_status']);
        self::assertFalse($rows[0]['is_remittance']);
        self::assertSame('AUD', $rows[0]['currency']);
    }

    public function test_a_permanent_delete_removes_the_children_and_leaves_a_tombstone_in_one_transaction(): void
    {
        $this->add('a', sourceMessageId: 'graph-1');
        $this->add('b', sourceMessageId: 'graph-2');
        $this->insertFile('f1', 'a', 'a.pdf');
        $this->insertFile('f2', 'b', 'b.pdf');
        $this->insertAttachmentRow('att1', 'a', 'f1');
        $this->insertAttachmentRow('att2', 'b', 'f2');
        $this->insertLine('l1', 'att1', 'a', 1, 'INV-1', '1.00');
        $this->insertLine('l2', 'att2', 'b', 1, 'INV-9', '9.00');
        $this->pdo->exec("INSERT INTO [finance].[ar_outbound_messages] ([id], [email_record_id], [delivery_id], [body]) VALUES (N'o1', N'a', N'd1', N'Thanks')");
        $this->pdo->exec("INSERT INTO [finance].[ar_deleted_messages] ([source_message_id], [deleted_date]) VALUES (N'graph-1', '2020-01-01')");
        $actor = new ArActor(7, 'Mia', 'mia@example.test', []);
        $this->service->delete($actor, 'a', false);

        $this->service->delete($actor, 'a', true);

        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [id] = N'a'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_attachments] WHERE [email_record_id] = N'a'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_remittance_lines] WHERE [email_record_id] = N'a'"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_outbound_messages] WHERE [email_record_id] = N'a'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_deleted_messages] WHERE [source_message_id] = N'graph-1'"), 'one tombstone, the older replaced');
        self::assertGreaterThan(2020, $this->scalar("SELECT YEAR([deleted_date]) FROM [finance].[ar_deleted_messages] WHERE [source_message_id] = N'graph-1'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [id] = N'b'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_attachments] WHERE [email_record_id] = N'b'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_remittance_lines] WHERE [email_record_id] = N'b'"));
    }

    /** True once the purge has taken the record out, as the work in the failing transaction sees it. */
    private function purgedInThisTransaction(): bool
    {
        return $this->store->find('a') === null;
    }

    public function test_a_failure_inside_the_purge_transaction_leaves_everything_in_place(): void
    {
        $this->add('a', sourceMessageId: 'graph-1');
        $this->insertFile('f1', 'a', 'a.pdf');
        $this->insertAttachmentRow('att1', 'a', 'f1');
        $this->insertLine('l1', 'att1', 'a', 1, 'INV-1', '1.00');

        $failed = false;
        try {
            $this->store->transactional(function (): void {
                $this->store->purge('a', ArFixtures::utc('2026-09-22 03:00:00'));
                if ($this->purgedInThisTransaction()) {
                    throw new \RuntimeException('connection lost');
                }
            });
        } catch (\RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed, 'The work was made to fail.');

        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [id] = N'a'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_email_attachments]"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_remittance_lines]"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_deleted_messages]"), 'the tombstone was rolled back too');
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_the_tiles_over_real_rows(): void
    {
        $this->add('m1', emailDate: ArFixtures::utc('2026-03-02 08:00:00'), followUpStatus: 'needs_review', followUpCategory: 'other');
        $this->add('m2', emailDate: ArFixtures::utc('2026-03-09 08:00:00'), followUpStatus: 'needs_review', followUpCategory: 'credit_notes');
        $this->add('j1', emailDate: ArFixtures::utc('2026-06-15 08:00:00'), followUpStatus: 'completed', followUpCategory: 'other');
        $this->add('bin', emailDate: ArFixtures::utc('2026-03-05 08:00:00'), deletedAt: ArFixtures::utc('2026-03-06 00:00:00'));
        $this->pdo->exec("INSERT INTO [finance].[ar_credit_note_requests] ([id], [customer_name], [sales_rep_email], [token_hash], [token_expires_at], [status]) VALUES (N'c1', N'Acme', N'rep@example.test', REPLICATE(N'1', 64), SYSUTCDATETIME(), N'form_sent')");
        $this->pdo->exec("INSERT INTO [finance].[ar_credit_note_requests] ([id], [customer_name], [sales_rep_email], [token_hash], [token_expires_at], [status]) VALUES (N'c2', N'Acme', N'rep@example.test', REPLICATE(N'2', 64), SYSUTCDATETIME(), N'approved')");
        $this->pdo->exec("INSERT INTO [finance].[ar_credit_note_requests] ([id], [customer_name], [sales_rep_email], [token_hash], [token_expires_at], [status]) VALUES (N'c3', N'Acme', N'rep@example.test', REPLICATE(N'3', 64), SYSUTCDATETIME(), N'form_sent')");

        $march = $this->service->metrics('2026-03-01', '2026-03-31');

        self::assertSame(2, $march['total_emails']);
        self::assertSame(2, $march['needs_review']);
        self::assertEquals(['other' => 1, 'credit_notes' => 1], $march['category_counts']);
        self::assertEquals(['needs_review' => 2], $march['follow_up_status_counts']);
        self::assertSame(1, $march['in_bin']);
        self::assertEquals(['form_sent' => 2, 'approved' => 1], $march['credit_note_counts']);
        self::assertSame(1, $this->service->metrics('2026-06-01', '2026-06-30')['completed']);
        self::assertSame(3, $this->service->metrics(null, null)['total_emails']);
        self::assertSame(2, $this->service->metrics(null, '2026-03-31')['total_emails']);
    }

    public function test_a_page_past_the_end_is_empty_and_the_total_still_counts(): void
    {
        for ($n = 1; $n <= 3; ++$n) {
            $this->add('r' . $n, emailDate: ArFixtures::utc('2026-04-0' . $n . ' 00:00:00'));
        }

        $result = $this->service->list(['offset' => '10']);

        self::assertSame([], $result['records']);
        self::assertSame(3, $result['total']);
        self::assertSame(['r2'], array_slice($this->ids(['limit' => '1', 'offset' => '1', 'sort_dir' => 'asc']), 0, 1));
    }

    public function test_the_scan_asks_whether_a_message_is_known_or_deleted_for_good(): void
    {
        $this->add('a', sourceMessageId: 'graph-ABC');
        $this->add('b', sourceMessageId: null);

        self::assertTrue($this->store->messageIdKnown('graph-ABC'));
        self::assertTrue($this->store->messageIdKnown('graph-abc'), 'the database compares without case, as the unique index does');
        self::assertFalse($this->store->messageIdKnown('graph-other'));
        self::assertFalse($this->store->isTombstoned('graph-ABC'));

        $this->store->bin('a', 5, 'rae@example.test', ArFixtures::utc('2026-09-22 01:00:00'));
        self::assertTrue($this->store->messageIdKnown('graph-ABC'), 'a binned record still blocks a second copy');
        $this->store->purge('a', ArFixtures::utc('2026-09-22 02:00:00'));
        self::assertFalse($this->store->messageIdKnown('graph-ABC'));
        self::assertTrue($this->store->isTombstoned('graph-ABC'));
    }

    public function test_an_extraction_is_saved_on_the_attachment_with_its_lines_in_order_and_reads_back(): void
    {
        $this->add('a');
        $this->insertFile('f1', 'a', 'Advice.pdf');
        $this->store->addAttachment(new ArKeptAttachment('att1', 'a', 'f1', 'Advice.pdf', 'application/pdf', 3, 'pending', ArFixtures::utc('2026-09-22 01:02:03')));
        $extraction = new ArExtraction(
            true, 'Zoë Łukasz Pty Ltd', '2026-09-20', 'EFT-77', 'AUD', 120.5,
            [
                ['invoice_number' => 'INV-1', 'invoice_date' => '2026-08-01', 'gross_amount' => 110.0, 'discount_amount' => 9.75, 'paid_amount' => 100.25],
                ['invoice_number' => 'INV-2', 'invoice_date' => null, 'gross_amount' => null, 'discount_amount' => null, 'paid_amount' => 20.25],
            ],
            0.88, 'Two invoices',
        );

        $this->store->saveExtraction('att1', 'a', $extraction, ArFixtures::utc('2026-09-22 03:04:05.123456'), ['l1', 'l2']);

        $row = $this->store->attachmentsOf('a')[0];
        self::assertSame('extracted', $row['extraction_status']);
        self::assertTrue($row['is_remittance']);
        self::assertSame('Zoë Łukasz Pty Ltd', $row['payer_name']);
        self::assertSame(120.5, $row['total_amount']);
        self::assertTrue($row['reconciles']);
        self::assertSame(0.88, $row['extraction_confidence']);
        $lines = ArFixtures::rows($row['line_items']);
        self::assertSame(['INV-1', 'INV-2'], array_column($lines, 'invoice_number'));
        self::assertSame([100.25, 20.25], array_column($lines, 'paid_amount'));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_remittance_lines] WHERE [email_attachment_id] = N'att1' AND [line_no] IN (1, 2)"));
    }

    public function test_an_extraction_with_nothing_to_check_stores_reconciles_as_null_and_a_failure_is_marked(): void
    {
        $this->add('a');
        $this->insertFile('f1', 'a', 'Advice.pdf');
        $this->store->addAttachment(new ArKeptAttachment('att1', 'a', 'f1', 'Advice.pdf', 'application/pdf', 3, 'pending', ArFixtures::utc('2026-09-22 01:02:03')));

        $this->store->saveExtraction('att1', 'a', new ArExtraction(false, '', null, null, 'AUD', null, [], 0.0, ''), ArFixtures::utc('2026-09-22 03:00:00'), []);
        self::assertNull($this->store->attachmentsOf('a')[0]['reconciles']);

        $this->store->markExtractionFailed('att1', str_repeat('e', 2000), ArFixtures::utc('2026-09-22 03:05:00'));
        $row = $this->store->attachmentsOf('a')[0];
        self::assertSame('failed', $row['extraction_status']);
        self::assertIsString($row['extraction_error']);
        self::assertSame(1000, strlen($row['extraction_error']), 'the reason is cut to the column');
    }

    public function test_a_failed_line_insert_leaves_the_header_unchanged(): void
    {
        $this->add('a');
        $this->insertFile('f1', 'a', 'Advice.pdf');
        $this->store->addAttachment(new ArKeptAttachment('att1', 'a', 'f1', 'Advice.pdf', 'application/pdf', 3, 'pending', ArFixtures::utc('2026-09-22 01:02:03')));
        $line = ['invoice_number' => 'INV-1', 'invoice_date' => null, 'gross_amount' => null, 'discount_amount' => null, 'paid_amount' => 1.0];

        try {
            // Two lines with one id: the second insert breaks the primary key, so the whole save must roll back.
            $this->store->saveExtraction('att1', 'a', new ArExtraction(true, 'Payer', null, null, 'AUD', 2.0, [$line, $line], 0.5, ''), ArFixtures::utc('2026-09-22 03:00:00'), ['same', 'same']);
            self::fail('Expected the duplicate line id to be refused.');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame('pending', $this->store->attachmentsOf('a')[0]['extraction_status']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[ar_remittance_lines]'));
    }

    public function test_the_credit_note_link_on_a_record_is_written_by_update_and_read_back(): void
    {
        $record = $this->add('a');

        $requestId = str_repeat('c', 32);
        $this->store->update($record->with(creditNoteRequestId: $requestId, status: 'reviewed', assignedSalesRepEmail: 'rep@example.test', updatedAt: ArFixtures::utc('2026-09-22 03:00:00')));

        $found = $this->store->find('a');
        self::assertSame([$requestId, 'reviewed', 'rep@example.test'], [$found?->creditNoteRequestId, $found?->status, $found?->assignedSalesRepEmail]);
    }

    public function test_the_reminder_query_finds_open_live_unreminded_records_past_due_oldest_first_and_bounded(): void
    {
        $this->add('b', followUpStatus: 'in_progress', dueDate: '2026-09-01');
        $this->add('a', followUpStatus: 'needs_review', dueDate: '2026-09-01');
        $this->add('c', followUpStatus: 'in_progress', dueDate: '2026-08-15');
        $this->add('today', followUpStatus: 'in_progress', dueDate: '2026-09-22');
        $this->add('future', followUpStatus: 'in_progress', dueDate: '2026-12-01');
        $this->add('nodate', followUpStatus: 'in_progress', dueDate: null);
        $this->add('done', followUpStatus: 'completed', dueDate: '2026-08-01');
        $this->add('binned', followUpStatus: 'in_progress', dueDate: '2026-08-01', deletedAt: ArFixtures::utc('2026-09-01 00:00:00'));
        $this->add('reminded', followUpStatus: 'in_progress', dueDate: '2026-08-01', reminderSentAt: ArFixtures::utc('2026-09-10 00:00:00'));

        $due = $this->store->overdueAwaitingReminder('2026-09-22', 10);

        self::assertSame(['c', 'a', 'b'], array_map(static fn (ArRecord $r): string => $r->id, $due), 'derived overdue: due before today, open, live, not reminded; oldest first, then by id');
        self::assertNull($due[0]->body, 'a reminder row carries no body');
        self::assertSame(['c'], array_map(static fn (ArRecord $r): string => $r->id, $this->store->overdueAwaitingReminder('2026-09-22', 1)));
        self::assertSame(['c', 'a', 'b', 'today'], array_map(static fn (ArRecord $r): string => $r->id, $this->store->overdueAwaitingReminder('2026-09-23', 10)), 'a later day makes today\'s overdue');
    }

    public function test_marking_a_reminder_is_conditional_touches_only_that_column_and_a_new_due_date_clears_it(): void
    {
        $record = $this->add('a', followUpStatus: 'in_progress', dueDate: '2026-09-01');
        $before = $record->updatedAt;
        $at = ArFixtures::utc('2026-09-22 03:00:00.654321');

        self::assertTrue($this->store->markReminderSent('a', $at));
        self::assertFalse($this->store->markReminderSent('a', ArFixtures::utc('2026-09-23 00:00:00')), 'only the first caller sets it');
        self::assertFalse($this->store->markReminderSent('missing', $at));

        $found = $this->store->find('a');
        self::assertInstanceOf(ArRecord::class, $found);
        self::assertSame('2026-09-22 03:00:00.654321', $found->reminderSentAt?->format('Y-m-d H:i:s.u'));
        self::assertEquals($before, $found->updatedAt, 'marking a reminder is not an edit');
        self::assertSame([], $this->store->overdueAwaitingReminder('2026-09-22', 10));

        $this->store->update($record->with(dueDate: '2026-09-10', reminderSentAt: null, updatedAt: ArFixtures::utc('2026-09-22 04:00:00')));
        self::assertSame(['a'], array_map(static fn (ArRecord $r): string => $r->id, $this->store->overdueAwaitingReminder('2026-09-22', 10)));
    }
}
