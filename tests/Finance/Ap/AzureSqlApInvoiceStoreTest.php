<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Ap\AzureSqlApAuditStore;
use TriCab\Api\Finance\Ap\AzureSqlApInvoiceStore;
use TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore;
use TriCab\Api\Finance\Audit\AzureSqlFinanceAuditLog;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ap\ApExtraction;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceCapture;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceQuery;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;
use TriCab\Core\Finance\Ap\Service\ApInvoiceGuard;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;

/**
 * The register over `create-finance-invoice-schema.sql`, against a real local SQL Server ({@see LocalFinanceSqlServer});
 * skipped without one: the store's reads and writes with their types, the scope and period before the limit, the derived
 * status in the filter and the sort, the permanent delete's children, tombstone and transaction, the duplicate rules, and
 * the service's whole cycle on the real tables with the file store's rows.
 */
#[Group('local-sql-server')]
final class AzureSqlApInvoiceStoreTest extends TestCase
{
    private PDO $pdo;

    private PdoSqlConnection $connection;

    private AzureSqlApInvoiceStore $store;

    private AzureSqlApAuditStore $audit;

    private FixedClock $clock;

    private ApInvoiceService $service;

    private ApAttachmentService $attachments;

    private FinanceAttachmentService $files;

    private AzureSqlFinanceAttachmentStore $fileStore;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlApInvoiceStore($this->connection);
        $this->audit = new AzureSqlApAuditStore($this->connection);
        $this->clock = FixedClock::utc('2026-10-09 01:02:03.456789');
        $this->fileStore = new AzureSqlFinanceAttachmentStore($this->connection, new InMemoryProductBlobStore('', 'finance-attachments'), 'finance-attachments');
        $this->files = new FinanceAttachmentService($this->fileStore);
        $this->attachments = new ApAttachmentService($this->files, $this->fileStore, new ApInvoiceGuard($this->store));
        $this->service = new ApInvoiceService(
            $this->store,
            $this->audit,
            $this->attachments,
            new ApTime($this->clock, 'Australia/Melbourne'),
            new AzureSqlFinanceAuditLog($this->connection),
        );
    }

    private static function id(string $name): string
    {
        return substr(md5($name), 0, 32);
    }

    private static function at(string $when): DateTimeImmutable
    {
        return ApInvoiceFixtures::utc($when);
    }

    /**
     * A live invoice with real 32-character ids (the columns are CHAR(32)).
     */
    private function invoice(string $name, mixed ...$over): ApInvoice
    {
        return ApInvoiceFixtures::invoice(self::id($name), ...$over);
    }

    private function put(ApInvoice $invoice): ApInvoice
    {
        $this->store->insert($invoice);

        return $invoice;
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function sql(string $sql, array $params = []): void
    {
        $this->connection->execute($sql, $params);
    }

    private function rowsIn(string $table, string $where = '1 = 1'): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM [finance].[' . $table . '] WHERE ' . $where);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private function email(string $name, string $graphId, string $mailbox, string $senderName): string
    {
        $id = self::id('email-' . $name);
        $this->sql('INSERT INTO [finance].[invoice_emails] ([id], [graph_message_id], [mailbox], [sender_name]) VALUES (:id, :graph, :mailbox, :sender)', ['id' => $id, 'graph' => $graphId, 'mailbox' => $mailbox, 'sender' => $senderName]);

        return $id;
    }

    /**
     * @param list<ApInvoice> $rows
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        return array_map(static fn (ApInvoice $row): string => $row->invoiceNo, $rows);
    }

    /**
     * @param list<string>|null $mailboxes
     */
    private function query(
        bool $deleted = false,
        ?string $id = null,
        ?string $status = null,
        ?DateTimeImmutable $start = null,
        ?DateTimeImmutable $end = null,
        ?array $mailboxes = null,
        string $sortBy = 'created_date',
        bool $descending = true,
        int $limit = 200,
    ): ApInvoiceQuery {
        return new ApInvoiceQuery($deleted, $id, $status, $start, $end, $mailboxes, $sortBy, $descending, $limit, '2026-10-09');
    }

    // ---- the store ---------------------------------------------------------------------------------------------

    public function test_an_invoice_round_trips_with_its_types(): void
    {
        $email = $this->email('one', 'AAMk-msg-1', 'ap@example.test', 'Bill Biller');
        $original = $this->put($this->invoice(
            'one',
            emailId: 'AAMk-msg-1',
            emailRecordId: $email,
            attachmentId: 'AAMkATT=',
            emailDate: self::at('2026-10-05 01:02:03.123456'),
            sender: 'billing@vendor.example.test',
            subject: 'Invoice für 发票 77',
            attachment: 'Rechnung 发票.pdf',
            contentHash: str_repeat('ab', 32),
            invoiceNo: 'INV-77',
            vendor: 'Café Müller Pty Ltd',
            vendorEmail: 'ar@vendor.example.test',
            taxId: '12 345 678 901',
            invoiceDate: '2026-10-01',
            dueDate: '2026-10-31',
            poNumber: 'PO-5',
            netAmount: 100.10,
            taxAmount: 10.01,
            totalAmount: 110.11,
            company: 'TriCab (Australia) Pty Ltd',
            accountCategory: '6100',
            costCentre: '890',
            notes: "line one\nline two",
            isRevised: true,
            duplicateStatus: 'Potential',
            duplicateOfInvoiceId: self::id('earlier'),
            duplicateReason: ApInvoice::BUSINESS_DUPLICATE_REASON,
            approvalStatus: 'Pending approval',
            extractionConfidence: 0.87654321,
            reviewReason: 'Low extraction confidence (87%)',
            odooVendorId: 4_000_000_001,
            vendorRef: 'V-4',
            odooVendorName: 'Café Müller',
            vendorMatch: 'matched',
            vendorMatchNote: 'name match',
            vendorMatchByUserId: 12,
        ));

        $read = $this->store->find($original->id);

        self::assertNotNull($read);
        self::assertEquals($original->with(senderName: 'Bill Biller'), $read);
        self::assertSame(110.11, $read->totalAmount);
        self::assertSame('2026-10-01', $read->invoiceDate);
        self::assertSame(0.87654321, $read->extractionConfidence);
        self::assertSame('2026-10-05T01:02:03.123456+00:00', $read->emailDate?->format('Y-m-d\TH:i:s.uP'));
        self::assertNull($this->store->find(self::id('nothing')));
        self::assertTrue($this->store->exists($original->id));
        self::assertFalse($this->store->exists(self::id('nothing')));
        $many = $this->store->findMany([$original->id, self::id('nothing'), $original->id]);
        self::assertSame([$original->id], array_keys($many));
    }

    public function test_a_hand_capture_round_trips_with_its_nulls(): void
    {
        $original = $this->put(new ApInvoice(self::id('hand'), self::at('2026-10-09 01:02:03.456789'), self::at('2026-10-09 01:02:03.456789')));

        $read = $this->store->find($original->id);

        self::assertNotNull($read);
        self::assertEquals($original, $read);
        self::assertNull($read->mailbox);
        self::assertNull($read->totalAmount);
        self::assertSame('Needs Review', $read->status);
    }

    public function test_the_sender_name_is_found_by_the_record_id_else_by_the_graph_message_id(): void
    {
        $byRecord = $this->email('r', 'AAMk-r', 'ap@example.test', 'By Record');
        $this->email('m', 'AAMk-m', 'ap@example.test', 'By Message');
        $this->email('blank', 'AAMk-blank', 'ap@example.test', '');
        $this->put($this->invoice('byRecord', emailRecordId: $byRecord, emailId: 'something else'));
        $this->put($this->invoice('byMessage', emailRecordId: null, emailId: 'AAMk-m'));
        $this->put($this->invoice('blankName', emailRecordId: null, emailId: 'AAMk-blank'));
        $this->put($this->invoice('none', emailRecordId: null, emailId: 'AAMk-unknown'));

        self::assertSame('By Record', $this->store->find(self::id('byRecord'))?->senderName);
        self::assertSame('By Message', $this->store->find(self::id('byMessage'))?->senderName);
        self::assertSame('', $this->store->find(self::id('blankName'))?->senderName);
        self::assertSame('', $this->store->find(self::id('none'))?->senderName);
        $names = array_column(array_map(static fn (ApInvoice $r): array => ['n' => $r->invoiceNo, 's' => $r->senderName], $this->store->page($this->query())), 's', 'n');
        self::assertSame('By Record', $names['INV-' . self::id('byRecord')]);
    }

    public function test_the_page_filters_the_bin_the_id_and_orders_by_the_sort_key_then_the_id(): void
    {
        $this->put($this->invoice('a', invoiceNo: 'A', vendor: 'bravo', createdAt: self::at('2026-10-01 00:00:00')));
        $this->put($this->invoice('b', invoiceNo: 'B', vendor: 'Alpha', createdAt: self::at('2026-10-02 00:00:00')));
        $this->put($this->invoice('c', invoiceNo: 'C', vendor: 'Charlie', createdAt: self::at('2026-10-03 00:00:00'), deletedAt: self::at('2026-10-04 00:00:00')));

        self::assertSame(['B', 'A'], self::names($this->store->page($this->query())));
        self::assertSame(['A', 'B'], self::names($this->store->page($this->query(descending: false))));
        self::assertSame(['C'], self::names($this->store->page($this->query(deleted: true, sortBy: 'deleted_at'))));
        self::assertSame(['A'], self::names($this->store->page($this->query(id: self::id('a')))));
        self::assertSame(['B', 'A'], self::names($this->store->page($this->query(sortBy: 'vendor', descending: false))), 'case-insensitive: Alpha, bravo');
        self::assertSame(['B'], self::names($this->store->page($this->query(limit: 1))));
        // The same creation instant: the id breaks the tie, in the sort's direction.
        $same = self::at('2026-10-07 00:00:00');
        $this->put($this->invoice('t1', invoiceNo: 'T1', createdAt: $same));
        $this->put($this->invoice('t2', invoiceNo: 'T2', createdAt: $same));
        $ids = [self::id('t1'), self::id('t2')];
        sort($ids);
        $asc = array_map(static fn (ApInvoice $row): string => $row->id, array_slice($this->store->page($this->query(descending: false, sortBy: 'created_date')), 2));
        self::assertSame($ids, $asc);
    }

    public function test_every_sort_key_on_the_list_is_accepted_by_the_database(): void
    {
        $this->put($this->invoice('a'));
        foreach (ApInvoiceQuery::SORTS as $key) {
            self::assertCount(1, $this->store->page($this->query(sortBy: $key)), $key);
            self::assertCount(1, $this->store->page($this->query(sortBy: $key, descending: false)), $key);
        }
        // Not on the list: the store falls back to created_date rather than putting the name in SQL.
        self::assertCount(1, $this->store->page($this->query(sortBy: 'notes; DROP TABLE x')));
    }

    public function test_the_scope_the_period_and_the_limit_are_applied_in_one_query(): void
    {
        $this->put($this->invoice('mine', invoiceNo: 'MINE', mailbox: 'Christine@Example.test', createdAt: self::at('2026-10-01 00:00:00'), emailDate: self::at('2026-10-01 00:00:00')));
        $this->put($this->invoice('hand', invoiceNo: 'HAND', mailbox: null, emailDate: null, createdAt: self::at('2026-10-02 12:00:00')));
        $this->put($this->invoice('blank', invoiceNo: 'BLANK', mailbox: '  ', createdAt: self::at('2026-10-03 00:00:00'), emailDate: self::at('2026-10-03 00:00:00')));
        for ($i = 0; $i < 3; ++$i) {
            $this->put($this->invoice('theirs' . $i, invoiceNo: 'THEIRS' . $i, mailbox: 'ap@example.test', createdAt: self::at('2026-10-06 0' . $i . ':00:00'), emailDate: self::at('2026-10-06 0' . $i . ':00:00')));
        }

        self::assertSame(['BLANK', 'HAND'], self::names($this->store->page($this->query(mailboxes: ['christine@example.test'], limit: 2))), 'scoped before the limit');
        self::assertSame(['BLANK', 'HAND', 'MINE'], self::names($this->store->page($this->query(mailboxes: ['christine@example.test']))));
        // The period is on the email date, else the created date (a hand capture is filed by the day it was captured).
        $window = $this->query(start: self::at('2026-10-02 00:00:00'), end: self::at('2026-10-04 00:00:00'));
        self::assertSame(['BLANK', 'HAND'], self::names($this->store->page($window)));
        self::assertSame(['HAND'], self::names($this->store->page($this->query(start: self::at('2026-10-02 00:00:00'), end: self::at('2026-10-03 00:00:00')))), 'the end is exclusive');
        self::assertSame(['HAND'], self::names($this->store->page($this->query(start: self::at('2026-10-02 00:00:00'), end: self::at('2026-10-04 00:00:00'), mailboxes: ['christine@example.test'], limit: 1, sortBy: 'created_date', descending: false))), 'period and scope and limit together');
    }

    public function test_the_status_filter_and_sort_use_the_derived_status(): void
    {
        $this->put($this->invoice('overdue', invoiceNo: 'OVERDUE', dueDate: '2026-10-08', status: 'In Progress'));
        $this->put($this->invoice('review', invoiceNo: 'REVIEW', dueDate: '2026-12-01', status: 'Needs Review'));
        $this->put($this->invoice('today', invoiceNo: 'TODAY', dueDate: '2026-10-09', status: 'In Progress'));
        $this->put($this->invoice('done', invoiceNo: 'DONE', dueDate: '2026-01-01', status: 'Completed'));
        $this->put($this->invoice('binned', invoiceNo: 'BINNED', dueDate: '2026-01-01', status: 'In Progress', deletedAt: self::at('2026-10-04 00:00:00')));

        self::assertSame(['OVERDUE'], self::names($this->store->page($this->query(status: 'Overdue'))));
        self::assertSame(['TODAY'], self::names($this->store->page($this->query(status: 'In Progress'))), 'In Progress excludes the overdue ones; due today is not overdue');
        self::assertSame(['BINNED'], self::names($this->store->page($this->query(deleted: true, status: 'In Progress'))), 'a binned invoice shows its stored status');
        self::assertSame([], self::names($this->store->page($this->query(deleted: true, status: 'Overdue'))));
        self::assertSame(['DONE', 'TODAY', 'REVIEW', 'OVERDUE'], self::names($this->store->page($this->query(sortBy: 'status', descending: false))));
        // Nothing was written by reading.
        self::assertSame('In Progress', $this->store->find(self::id('overdue'))?->status);
    }

    public function test_update_writes_only_the_named_editable_columns(): void
    {
        $original = $this->put($this->invoice('a', notes: 'keep', approvalStatus: 'Pending approval', contentHash: str_repeat('a', 64)));
        $at = self::at('2026-10-09 05:00:00.5');

        $this->store->update($original->id, ['vendor' => 'New Vendor', 'total_amount' => 120.46, 'due_date' => '2026-12-31', 'is_revised' => true, 'review_reason' => null, 'notes' => 'changed', 'invoice_date' => null], $at);

        $read = $this->store->find($original->id);
        self::assertEquals($original->with(vendor: 'New Vendor', totalAmount: 120.46, dueDate: '2026-12-31', isRevised: true, reviewReason: null, notes: 'changed', invoiceDate: null, updatedAt: $at), $read);
        foreach (['approval_status', 'deleted_at', 'content_hash', 'file_id', 'mailbox', 'odoo_vendor_id', 'vendor_match', 'id', 'created_date'] as $column) {
            try {
                $this->store->update($original->id, [$column => 'x'], $at);
                self::fail($column . ' was writable');
            } catch (LogicException $refused) {
                self::assertStringContainsString($column, $refused->getMessage());
            }
        }
        self::assertEquals($read, $this->store->find($original->id));
    }

    public function test_the_database_refuses_a_stored_overdue_and_an_unknown_duplicate_status(): void
    {
        $original = $this->put($this->invoice('a'));

        foreach (['status' => 'Overdue', 'duplicate_status' => 'Maybe'] as $column => $value) {
            try {
                $this->store->update($original->id, [$column => $value], self::at('2026-10-09 05:00:00'));
                self::fail('The check constraint should refuse ' . $value);
            } catch (\Throwable $failure) {
                self::assertStringContainsString('CK_finance_invoice_invoices', $failure->getMessage() . (string) $failure->getPrevious()?->getMessage());
            }
        }
    }

    public function test_bin_and_restore_set_and_clear_the_person_columns(): void
    {
        $original = $this->put($this->invoice('a'));
        $this->store->bin($original->id, 7, 'dee@example.test', self::at('2026-10-09 02:00:00'));
        $binned = $this->store->find($original->id);
        self::assertNotNull($binned);
        self::assertEquals(self::at('2026-10-09 02:00:00'), $binned->deletedAt);
        self::assertSame(7, $binned->deletedByUserId);
        self::assertSame('dee@example.test', $binned->deletedBy);

        // A second bin leaves the first alone.
        $this->store->bin($original->id, 8, 'someone@example.test', self::at('2026-10-09 03:00:00'));
        self::assertSame(7, $this->store->find($original->id)?->deletedByUserId);

        $this->store->restore($original->id, self::at('2026-10-09 04:00:00'));
        $restored = $this->store->find($original->id);
        self::assertNotNull($restored);
        self::assertNull($restored->deletedAt);
        self::assertNull($restored->deletedByUserId);
        self::assertSame('', $restored->deletedBy);
        self::assertEquals(self::at('2026-10-09 04:00:00'), $restored->updatedAt);
    }

    public function test_purge_removes_the_children_first_and_writes_one_tombstone(): void
    {
        $doomed = $this->put($this->invoice('doomed', emailId: 'AAMk-doomed', attachmentId: 'ATT-doomed'));
        $other = $this->put($this->invoice('other', duplicateOfInvoiceId: $doomed->id, duplicateStatus: 'Potential'));
        $email = $this->email('d', 'AAMk-doomed', 'ap@example.test', 'Bill');
        $this->sql('INSERT INTO [finance].[invoice_approval_batches] ([id]) VALUES (:id)', ['id' => self::id('batch')]);
        $this->sql('INSERT INTO [finance].[invoice_approval_items] ([id], [batch_id], [invoice_id]) VALUES (:id, :batch, :invoice)', ['id' => self::id('item1'), 'batch' => self::id('batch'), 'invoice' => $doomed->id]);
        $this->sql('INSERT INTO [finance].[invoice_approval_items] ([id], [batch_id], [invoice_id]) VALUES (:id, :batch, :invoice)', ['id' => self::id('item2'), 'batch' => self::id('batch'), 'invoice' => $other->id]);
        $this->sql('INSERT INTO [finance].[invoice_outbound_messages] ([id], [invoice_id], [delivery_id]) VALUES (:id, :invoice, :delivery)', ['id' => self::id('out1'), 'invoice' => $doomed->id, 'delivery' => self::id('delivery1')]);
        $this->sql('INSERT INTO [finance].[invoice_outbound_messages] ([id], [invoice_id], [delivery_id]) VALUES (:id, :invoice, :delivery)', ['id' => self::id('out2'), 'invoice' => $other->id, 'delivery' => self::id('delivery2')]);
        $this->sql('INSERT INTO [finance].[invoice_email_attachments] ([id], [email_record_id], [invoice_id]) VALUES (:id, :email, :invoice)', ['id' => self::id('ea1'), 'email' => $email, 'invoice' => $doomed->id]);
        $this->sql('INSERT INTO [finance].[invoice_email_attachments] ([id], [email_record_id], [invoice_id], [duplicate_of_invoice_id]) VALUES (:id, :email, NULL, :dup)', ['id' => self::id('ea2'), 'email' => $email, 'dup' => $doomed->id]);
        $this->audit->record(new ApAuditEvent('invoice_binned', 'invoice', $doomed->id, ['invoice_no' => 'X'], self::at('2026-10-09 00:00:00'), 7, 'Dee'));

        $this->store->purge($doomed->id, 'AAMk-doomed', 'ATT-doomed', self::at('2026-10-09 06:00:00'));

        self::assertNull($this->store->find($doomed->id));
        self::assertSame(1, $this->rowsIn('invoice_approval_items'), 'the other invoice keeps its approval item');
        self::assertSame(1, $this->rowsIn('invoice_approval_batches'), 'the batch stays');
        self::assertSame(1, $this->rowsIn('invoice_outbound_messages'));
        self::assertSame(2, $this->rowsIn('invoice_email_attachments'), 'the scanned attachments stay');
        self::assertSame(0, $this->rowsIn('invoice_email_attachments', '[invoice_id] IS NOT NULL OR [duplicate_of_invoice_id] IS NOT NULL'), 'but no longer point at it');
        self::assertNull($this->store->find($other->id)?->duplicateOfInvoiceId);
        self::assertSame(1, $this->rowsIn('invoice_audit_events'), 'the audit rows stay');
        self::assertSame(1, $this->rowsIn('invoice_scan_skips', "[email_id] = N'AAMk-doomed' AND [attachment] = N'ATT-doomed'"));
        self::assertTrue($this->store->scanSkipped('AAMk-doomed', 'ATT-doomed', 'whatever.pdf'));
        self::assertTrue($this->store->scanSkipped('AAMk-doomed', null, 'ATT-doomed'));
        self::assertFalse($this->store->scanSkipped('AAMk-doomed', 'ATT-2', 'x.pdf'));

        // The same pair again is left as it is: the tombstone is unique and a second delete does not fail.
        $again = $this->put($this->invoice('again', emailId: 'AAMk-doomed', attachmentId: 'ATT-doomed'));
        $this->store->purge($again->id, 'AAMk-doomed', 'ATT-doomed', self::at('2026-10-09 07:00:00'));
        self::assertSame(1, $this->rowsIn('invoice_scan_skips'));
    }

    public function test_purge_takes_the_file_link_off_a_scanned_attachment_that_shared_the_file(): void
    {
        $file = $this->attachments->keep('Doomed.pdf', 'application/pdf', '%PDF', 7);
        $doomed = $this->put($this->invoice('doomed', fileId: $file->id));
        $email = $this->email('d', 'AAMk-d', 'ap@example.test', 'Bill');
        $this->sql('INSERT INTO [finance].[invoice_email_attachments] ([id], [email_record_id], [file_id], [invoice_id]) VALUES (:id, :email, :file, :invoice)', ['id' => self::id('ea'), 'email' => $email, 'file' => $file->id, 'invoice' => $doomed->id]);

        $this->store->purge($doomed->id, null, null, self::at('2026-10-09 06:00:00'));

        self::assertSame(0, $this->rowsIn('invoice_email_attachments', '[file_id] IS NOT NULL'));
        self::assertTrue($this->fileStore->delete(Module::APP_AP, $file->id), 'so the file row can now be removed without a key in the way');
    }

    public function test_a_failure_inside_the_transaction_leaves_nothing_behind(): void
    {
        $original = $this->put($this->invoice('a'));

        try {
            $this->store->transactional(function () use ($original): void {
                $this->store->bin($original->id, 7, 'dee@example.test', self::at('2026-10-09 02:00:00'));
                $this->audit->record(new ApAuditEvent('invoice_binned', 'invoice', $original->id, [], self::at('2026-10-09 02:00:00'), 7, 'Dee'));
                $this->store->purge($original->id, 'AAMk-a', 'ATT-a', self::at('2026-10-09 02:00:00'));
                throw new RuntimeException('the commit never happened');
            });
            self::fail('The failure should surface');
        } catch (RuntimeException) {
            // expected
        }

        $still = $this->store->find($original->id);
        self::assertNotNull($still, 'the invoice is still there');
        self::assertNull($still->deletedAt);
        self::assertSame(0, $this->rowsIn('invoice_audit_events'));
        self::assertSame(0, $this->rowsIn('invoice_scan_skips'));
    }

    public function test_the_hash_and_business_duplicate_lookups_take_the_oldest_in_any_state(): void
    {
        $hash = str_repeat('c', 64);
        $this->put($this->invoice('later', contentHash: $hash, createdAt: self::at('2026-10-02 00:00:00'), invoiceNo: 'INV-7', vendor: 'Acme Freight', totalAmount: 110.00));
        $this->put($this->invoice('earlier', contentHash: $hash, createdAt: self::at('2026-10-01 00:00:00'), deletedAt: self::at('2026-10-03 00:00:00'), invoiceNo: 'INV-7', vendor: 'Acme Freight', totalAmount: 110.00));
        $this->put($this->invoice('usd', invoiceNo: 'INV-8', vendor: 'Acme Freight', currency: 'USD', totalAmount: 50.00));
        $this->put($this->invoice('blankCurrency', invoiceNo: 'INV-9', vendor: 'Blank Ltd', currency: '', totalAmount: null));

        self::assertSame(self::id('earlier'), $this->store->findByContentHash($hash)?->id);
        self::assertNull($this->store->findByContentHash(str_repeat('d', 64)));
        self::assertNull($this->store->findByContentHash(''));
        self::assertSame(self::id('earlier'), $this->store->findBusinessDuplicate('inv-7', 'acme freight', 'AUD', 110.0)?->id);
        self::assertNull($this->store->findBusinessDuplicate('inv-7', 'acme freight', 'AUD', 110.01), 'a cent out is a different document');
        self::assertNull($this->store->findBusinessDuplicate('inv-7', 'acme freight', 'USD', 110.0));
        self::assertSame(self::id('usd'), $this->store->findBusinessDuplicate('inv-8', 'acme freight', 'USD', 50.0)?->id);
        self::assertSame(self::id('blankCurrency'), $this->store->findBusinessDuplicate('inv-9', 'blank ltd', 'AUD', 0.0)?->id, 'a blank currency reads AUD and a missing total reads 0');
    }

    public function test_findcaptured_matches_the_attachment_id_else_the_name_of_an_invoice_stored_without_one(): void
    {
        $this->put($this->invoice('withId', emailId: 'msg-1', attachmentId: 'ATT-1', attachment: 'Invoice.pdf'));
        $this->put($this->invoice('sibling', emailId: 'msg-1', attachmentId: 'ATT-2', attachment: 'Invoice.pdf'));
        $this->put($this->invoice('legacy', emailId: 'msg-2', attachmentId: null, attachment: 'Old.pdf'));

        self::assertSame(self::id('withId'), $this->store->findCaptured('msg-1', 'ATT-1', 'Invoice.pdf')?->id);
        self::assertSame(self::id('sibling'), $this->store->findCaptured('msg-1', 'ATT-2', 'Invoice.pdf')?->id, 'three invoices all called Invoice.pdf are three captures');
        self::assertNull($this->store->findCaptured('msg-1', 'ATT-3', 'Invoice.pdf'), 'a new id is a new attachment, whatever it is called');
        self::assertSame(self::id('legacy'), $this->store->findCaptured('msg-2', null, 'Old.pdf')?->id);
        self::assertSame(self::id('legacy'), $this->store->findCaptured('msg-2', 'ATT-X', 'Old.pdf')?->id, 'a row stored before attachment ids matches by name');
        self::assertNull($this->store->findCaptured('msg-3', 'ATT-1', 'Invoice.pdf'));
    }

    public function test_the_ids_of_the_invoices_of_each_email_come_in_one_query_oldest_first(): void
    {
        $one = $this->email('1', 'AAMk-1', 'ap@example.test', 'One');
        $two = $this->email('2', 'AAMk-2', 'ap@example.test', 'Two');
        $this->email('3', 'AAMk-3', 'ap@example.test', 'Three');
        $this->put($this->invoice('b', emailRecordId: $one, createdAt: self::at('2026-10-02 00:00:00')));
        $this->put($this->invoice('a', emailRecordId: $one, createdAt: self::at('2026-10-01 00:00:00')));
        $this->put($this->invoice('c', emailRecordId: $two));
        $this->put($this->invoice('hand', emailRecordId: null));

        self::assertSame([$one => [self::id('a'), self::id('b')], $two => [self::id('c')]], $this->store->invoiceIdsByEmail([$one, $two, self::id('email-3')]));
        self::assertSame([], $this->store->invoiceIdsByEmail([]));
    }

    public function test_the_vendor_columns_the_counts_and_the_approval_status_are_written_by_their_own_methods(): void
    {
        $this->put($this->invoice('a', odooVendorId: null));
        $this->put($this->invoice('b', odooVendorId: 5, vendorMatch: 'matched'));
        $this->put($this->invoice('c', odooVendorId: 5, vendorMatch: 'matched', deletedAt: self::at('2026-10-04 00:00:00')));

        $this->store->setVendorMatch(self::id('a'), new ApVendorMatch(5, 'V-5', 'Odoo Five', ApVendorMatch::CONFIRMED, 'Chosen by Dee'), 7, self::at('2026-10-09 02:00:00'));

        $a = $this->store->find(self::id('a'));
        self::assertSame([5, 'V-5', 'Odoo Five', 'confirmed', 'Chosen by Dee', 7], [$a?->odooVendorId, $a?->vendorRef, $a?->odooVendorName, $a?->vendorMatch, $a?->vendorMatchNote, $a?->vendorMatchByUserId]);
        self::assertSame([5 => 2], $this->store->liveCountsByVendor(), 'the binned one is not counted');
        $this->store->setVendorMatch(self::id('a'), new ApVendorMatch(null, null, null, ApVendorMatch::UNMATCHED, ''), null, self::at('2026-10-09 03:00:00'));
        self::assertNull($this->store->find(self::id('a'))?->odooVendorId);
        self::assertSame('', $this->store->find(self::id('a'))?->vendorRef);
        self::assertSame([5 => 1], $this->store->liveCountsByVendor());

        $this->store->setApprovalStatus([self::id('a'), self::id('c')], 'Approved', self::at('2026-10-09 04:00:00'));
        self::assertSame('Approved', $this->store->find(self::id('a'))?->approvalStatus);
        self::assertSame('Approved', $this->store->find(self::id('c'))?->approvalStatus, 'a binned invoice too, as the source did');
        self::assertSame('Not requested', $this->store->find(self::id('b'))?->approvalStatus);
    }

    public function test_the_audit_store_round_trips_details_and_unicode(): void
    {
        $this->audit->record(new ApAuditEvent('duplicate_override', 'invoice', self::id('a'), ['reason' => 'Reviewed 发票 — not a duplicate'], self::at('2026-10-09 02:00:00.5'), 7, 'Dée'));
        $this->audit->record(new ApAuditEvent('invoice_restored', 'invoice', self::id('a'), [], self::at('2026-10-09 03:00:00'), null, ''));
        $this->audit->record(new ApAuditEvent('invoice_restored', 'invoice', self::id('b'), [], self::at('2026-10-09 03:00:00'), 7, 'Dée'));

        $events = $this->audit->forEntity('invoice', self::id('a'));

        self::assertCount(2, $events);
        self::assertSame('duplicate_override', $events[0]->action);
        self::assertSame(['reason' => 'Reviewed 发票 — not a duplicate'], $events[0]->details);
        self::assertSame(7, $events[0]->actorUserId);
        self::assertSame('Dée', $events[0]->actorName);
        self::assertSame([], $events[1]->details);
        self::assertNull($events[1]->actorUserId);
    }

    // ---- the service on the real tables ---------------------------------------------------------------------------

    public function test_a_capture_with_an_upload_attaches_the_file_and_a_permanent_delete_removes_everything(): void
    {
        $actor = ApInvoiceFixtures::actor();
        $upload = $this->attachments->upload($actor, 'Smith Invoice 77.pdf', '%PDF-1.4 invented');
        $created = $this->service->create($actor, ['upload_id' => $upload['upload_id'], 'invoice_no' => 'A-77', 'vendor' => 'Smith', 'total_amount' => 110, 'extraction_confidence' => 0.9]);
        $id = $created['id'];
        self::assertIsString($id);

        self::assertSame($upload['upload_id'], $created['file_id']);
        self::assertSame(hash('sha256', '%PDF-1.4 invented'), $created['content_hash']);
        self::assertSame(1, $this->rowsIn('attachments', "[record_type] = N'invoice' AND [record_id] = '" . $id . "'"));
        $document = $this->service->document($actor, $id);
        self::assertSame('%PDF-1.4 invented', $document->bytes);

        $this->service->update($actor, $id, ['duplicate_status' => 'Cleared by user', 'duplicate_reason' => 'Reviewed']);
        $binned = $this->service->delete($actor, $id, false);
        self::assertTrue($binned['in_bin']);
        self::assertSame(['ok' => true, 'in_bin' => false], $this->service->delete($actor, $id, true));

        self::assertSame(0, $this->rowsIn('invoice_invoices'));
        self::assertSame(0, $this->rowsIn('attachments'), 'the file row and its bytes went with the invoice');
        $actions = array_map(static fn (ApAuditEvent $e): string => $e->action, $this->audit->forEntity('invoice', $id));
        sort($actions); // the clock is fixed, so the three events share an instant
        self::assertSame(['duplicate_override', 'invoice_binned', 'invoice_deleted_permanently'], $actions);
    }

    public function test_the_scan_filing_flags_a_business_duplicate_and_the_exact_file_check_finds_the_hash(): void
    {
        $actor = ApInvoiceFixtures::actor();
        $first = $this->service->createFromScan($this->capture('first', 'INV-500', 110.00), 7);
        self::assertSame('Clear', $first->duplicateStatus);
        self::assertSame('ap@example.test', $first->mailbox);
        self::assertSame('Needs Review', $first->status, 'a review reason means Needs Review');

        $second = $this->service->createFromScan($this->capture('second', 'inv-500', 110.00), 7);
        self::assertSame('Potential', $second->duplicateStatus);
        self::assertSame($first->id, $second->duplicateOfInvoiceId);
        self::assertSame('Possible duplicate: same vendor, invoice number, currency, and total', $second->duplicateReason);

        // Nothing re-checks it: a person clears it, and editing does not bring it back.
        $this->service->update($actor, $second->id, ['duplicate_status' => 'Cleared by user']);
        $this->service->update($actor, $second->id, ['invoice_no' => 'INV-501', 'total_amount' => 1]);
        self::assertSame('Cleared by user', $this->store->find($second->id)?->duplicateStatus);
        $third = $this->service->createFromScan($this->capture('third', 'INV-500', 110.00), 7);
        self::assertSame($first->id, $third->duplicateOfInvoiceId, 'the oldest match wins');

        $file = $this->attachments->keep('Scan.pdf', 'application/pdf', '%PDF scanned', 7);
        $withFile = $this->service->createFromScan($this->capture('fourth', 'INV-900', 5.00, $file->id, $file->sha256), 7);
        self::assertSame($file->id, $withFile->fileId);
        self::assertSame($withFile->id, $this->service->findExactDuplicate($file->sha256)?->id);
        self::assertSame(1, $this->rowsIn('attachments', "[record_type] = N'invoice'"));
    }

    private function capture(string $name, string $invoiceNo, float $total, ?string $fileId = null, ?string $hash = null): ApInvoiceCapture
    {
        $extraction = new ApExtraction($invoiceNo, 'Acme Freight', null, null, null, '3 Aug 2026', '2026-09-02', null, 'AUD', null, null, $total, 0.5, null, 'Low extraction confidence (50%)');

        return new ApInvoiceCapture('AP@Example.test', 'AAMk-' . $name, null, 'ATT-' . $name, self::at('2026-10-05 01:00:00'), 'billing@vendor.example.test', 'Invoice ' . $name, $name . '.pdf', $extraction, 'Needs Review', 'Low extraction confidence (50%)', 'billing@vendor.example.test', $fileId, $hash);
    }

    public function test_a_tombstone_for_a_pair_that_differs_only_in_case_is_the_same_pair(): void
    {
        // The ApConflict mapping of a unique violation is the support class's: prove it on the tombstone's own index.
        $this->sql('INSERT INTO [finance].[invoice_scan_skips] ([id], [email_id], [attachment]) VALUES (:id, N\'e\', N\'a\')', ['id' => self::id('s1')]);
        try {
            $this->connection->execute('INSERT INTO [finance].[invoice_scan_skips] ([id], [email_id], [attachment]) VALUES (:id, N\'e\', N\'A\')', ['id' => self::id('s2')]);
            self::fail('The index should refuse the same pair in another case');
        } catch (\Throwable $failure) {
            self::assertNotInstanceOf(ApConflict::class, $failure);
        }
        $doomed = $this->put($this->invoice('doomed', emailId: 'e', attachmentId: 'A'));
        $this->store->purge($doomed->id, 'e', 'A', self::at('2026-10-09 06:00:00'));
        self::assertSame(1, $this->rowsIn('invoice_scan_skips'));
    }
}
