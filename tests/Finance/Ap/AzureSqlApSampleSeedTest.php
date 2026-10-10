<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\AzureSqlFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\ApSampleFiles;
use TriCab\Api\Finance\Ap\AzureSqlApApprovalStore;
use TriCab\Api\Finance\Ap\AzureSqlApApproverDirectory;
use TriCab\Api\Finance\Ap\AzureSqlApAuditStore;
use TriCab\Api\Finance\Ap\AzureSqlApEmailStore;
use TriCab\Api\Finance\Ap\AzureSqlApInvoiceStore;
use TriCab\Api\Finance\Ap\AzureSqlApOutboundStore;
use TriCab\Api\Finance\Ap\AzureSqlApReferenceStore;
use TriCab\Api\Finance\Ap\AzureSqlApScanStore;
use TriCab\Api\Finance\Ap\AzureSqlApSigningLimitStore;
use TriCab\Api\Finance\Ap\AzureSqlApVendorStore;
use TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailLedger;
use TriCab\Api\Finance\Odoo\AzureSqlOdooRecordStore;
use TriCab\Api\Finance\Odoo\AzureSqlOdooSyncRuns;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApMailboxScope;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;
use TriCab\Core\Finance\Ap\Service\ApApprovalService;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;
use TriCab\Core\Finance\Ap\Service\ApEmailService;
use TriCab\Core\Finance\Ap\Service\ApInvoiceDownloadService;
use TriCab\Core\Finance\Ap\Service\ApInvoiceGuard;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;
use TriCab\Core\Finance\Ap\Service\ApMailboxService;
use TriCab\Core\Finance\Ap\Service\ApMailService;
use TriCab\Core\Finance\Ap\Service\ApReferenceService;
use TriCab\Core\Finance\Ap\Service\ApSigningAuthorityService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap\Service\ApVendorService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;

/**
 * The Tri-AP sample seed (T6.12) run for real on a disposable local SQL Server, twice, and then read through the real SQL
 * stores and services the way the pages will: the register (derived Overdue, the duplicate states, the Bin, the period
 * views), an invoice with its document, the multi-invoice download, the AP Mailbox with its links, the vendors, the
 * approvers and the three approval batches, the sent mail, the signing authority, the Settings lists, and a person
 * scoped to one mailbox. Skipped without a local server. Nothing here sends mail.
 */
#[Group('local-sql-server')]
final class AzureSqlApSampleSeedTest extends TestCase
{
    private const MAILBOX = 'ap@tricab.com';

    private const PROJECTS = 'ap.projects@sample.local';

    private PDO $pdo;

    private PdoSqlConnection $connection;

    private ApTime $time;

    private AzureSqlApInvoiceStore $invoiceStore;

    private AzureSqlApEmailStore $emailStore;

    private AzureSqlApApprovalStore $approvalStore;

    private AzureSqlApAuditStore $auditStore;

    private AzureSqlApOutboundStore $outboundStore;

    private AzureSqlFinanceMailLedger $ledger;

    private ApAttachmentService $attachments;

    private ApInvoiceService $invoices;

    private ApEmailService $emails;

    private ApApprovalService $approvals;

    private ApMailService $mail;

    protected function setUp(): void
    {
        $pdo = LocalApSampleServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalApSampleServer::reset($pdo);
        LocalApSampleServer::run($pdo, 'seed-finance-mailboxes-local.sql');
        LocalApSampleServer::run($pdo, 'seed-finance-ap-sample-local.sql');

        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->time = new ApTime(FixedClock::utc(gmdate('Y-m-d H:i:s')), 'Australia/Melbourne');
        $this->invoiceStore = new AzureSqlApInvoiceStore($this->connection);
        $this->emailStore = new AzureSqlApEmailStore($this->connection);
        $this->approvalStore = new AzureSqlApApprovalStore($this->connection);
        $this->auditStore = new AzureSqlApAuditStore($this->connection);
        $this->outboundStore = new AzureSqlApOutboundStore($this->connection);
        $this->ledger = new AzureSqlFinanceMailLedger($this->connection);

        $blobs = new InMemoryProductBlobStore('', 'finance-attachments');
        ApSampleFiles::load($blobs, 'finance-attachments');
        $fileStore = new AzureSqlFinanceAttachmentStore($this->connection, $blobs, 'finance-attachments');
        $this->attachments = new ApAttachmentService(new FinanceAttachmentService($fileStore), $fileStore, new ApInvoiceGuard($this->invoiceStore));
        $this->invoices = new ApInvoiceService($this->invoiceStore, $this->auditStore, $this->attachments, $this->time);
        $this->emails = new ApEmailService($this->emailStore, $this->invoiceStore, $this->auditStore, $this->time);

        $registry = new AzureSqlFinanceMailboxRegistry($this->connection);
        $this->mail = new ApMailService(
            $this->invoiceStore,
            $this->emails,
            $this->attachments,
            $this->outboundStore,
            $this->auditStore,
            new FinanceMailService($registry, $this->ledger, new RecordingReplyMailTransport()),
            $this->ledger,
            $registry,
            $this->time,
            self::MAILBOX,
            false,
        );
        $this->approvals = new ApApprovalService(
            $this->approvalStore,
            $this->invoiceStore,
            new AzureSqlApApproverDirectory($this->connection),
            $this->auditStore,
            $this->mail,
            $this->time,
            'https://app.example.test',
        );
    }

    // ---- helpers ----------------------------------------------------------------------

    private static function inv(int $n): string
    {
        return 'a901' . str_pad((string) $n, 28, '0', STR_PAD_LEFT);
    }

    private static function email(int $n): string
    {
        return 'a902' . str_pad((string) $n, 28, '0', STR_PAD_LEFT);
    }

    private static function batch(int $n): string
    {
        return 'a906' . str_pad((string) $n, 28, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $key => $cell) {
            $out[(string) $key] = $cell;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(self::map(...), $value));
    }

    private static function num(mixed $value): float
    {
        self::assertTrue(is_int($value) || is_float($value));

        return (float) $value;
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
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

    /**
     * A person as the resolver builds them: their permissions from their roles, their scope from `finance.access_scopes`,
     * the administrator never restricted.
     */
    private function actor(string $email): ApActor
    {
        $user = $this->rowsOf("SELECT [UserId], [DisplayName] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = '" . $email . "'");
        self::assertCount(1, $user, $email . ' exists');
        $userId = (int) self::str($user[0]['UserId']);
        $permissions = [];
        foreach ($this->rowsOf(
            'SELECT DISTINCT UPPER(pe.[PermissionCode]) AS [code]
               FROM [identity].[UserRoles] ur
               JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
               JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
               JOIN [identity].[Permissions] pe ON pe.[PermissionId] = rp.[PermissionId] AND pe.[IsActive] = 1
              WHERE ur.[UserId] = ' . $userId . ' AND ur.[IsActive] = 1 ORDER BY 1',
        ) as $row) {
            $permissions[] = self::str($row['code']);
        }
        $isAdmin = in_array(Module::PERMISSION_AP_SETTINGS_MANAGE, $permissions, true);
        $scope = $isAdmin
            ? ApMailboxScope::unrestricted()
            : ApMailboxScope::limitedTo((new AzureSqlFinanceAccessScopeReader($this->connection))->forUser($userId, Module::APP_AP)->mailboxes);

        return new ApActor($userId, self::str($user[0]['DisplayName']), $email, $permissions, $scope, $isAdmin);
    }

    private function admin(): ApActor
    {
        return $this->actor('sample.admin@sample.local');
    }

    /**
     * The whole live register, keyed by invoice number in the sample (1 to 32).
     *
     * @return array<int, array<string, mixed>>
     */
    private function register(?ApActor $actor = null, bool $bin = false): array
    {
        $rows = $this->invoices->list($actor ?? $this->admin(), ['limit' => '1000'] + ($bin ? ['deleted' => '1'] : []));
        $byNumber = [];
        foreach ($rows as $row) {
            $id = self::str($row['id']);
            self::assertStringStartsWith('a901', $id);
            $byNumber[(int) ltrim(substr($id, 4), '0')] = $row;
        }

        return $byNumber;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<int>
     */
    private static function numbers(array $rows): array
    {
        $numbers = array_keys($rows);
        sort($numbers);

        return $numbers;
    }

    // ---- idempotency ------------------------------------------------------------------

    public function test_a_second_run_adds_nothing(): void
    {
        $tables = [
            'invoice_invoices', 'invoice_emails', 'invoice_email_attachments', 'invoice_scan_runs', 'invoice_scan_skips', 'invoice_outbound_messages',
            'invoice_approval_batches', 'invoice_approval_items', 'invoice_approval_decisions', 'invoice_audit_events', 'invoice_companies',
            'invoice_cost_centres', 'invoice_account_categories', 'invoice_signing_limits', 'attachments', 'mail_deliveries', 'mailboxes',
            'mailbox_grants', 'access_scopes', 'odoo_records', 'odoo_sync_runs',
        ];
        $before = array_map(fn (string $t): int => $this->scalar("SELECT COUNT(*) FROM [finance].[{$t}]"), $tables);
        $users = $this->scalar('SELECT COUNT(*) FROM [identity].[Users]');
        $userRoles = $this->scalar('SELECT COUNT(*) FROM [identity].[UserRoles]');

        LocalApSampleServer::run($this->pdo, 'seed-finance-ap-sample-local.sql');
        LocalApSampleServer::run($this->pdo, 'seed-finance-mailboxes-local.sql');

        self::assertSame($before, array_map(fn (string $t): int => $this->scalar("SELECT COUNT(*) FROM [finance].[{$t}]"), $tables));
        self::assertSame($users, $this->scalar('SELECT COUNT(*) FROM [identity].[Users]'));
        self::assertSame($userRoles, $this->scalar('SELECT COUNT(*) FROM [identity].[UserRoles]'));
        self::assertSame([32, 26, 24, 3, 1, 5, 3, 8, 2, 10, 4, 6, 7, 6, 22, 5, 3, 4, 1, 13, 1], $before);
        self::assertSame(5, $users);
        self::assertSame(5, $userRoles);
    }

    public function test_no_scan_run_is_left_open_so_a_scan_can_start(): void
    {
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[invoice_scan_runs] WHERE [status] IN (N'Listing', N'Running')"));
        $scans = new AzureSqlApScanStore($this->connection);
        $complete = $scans->find('a905' . str_pad('1', 28, '0', STR_PAD_LEFT));
        self::assertNotNull($complete);
        self::assertSame('Complete', $complete->status);
        self::assertSame([self::MAILBOX, self::PROJECTS], $complete->mailboxes);
        $fail = $scans->find('a905' . str_pad('3', 28, '0', STR_PAD_LEFT));
        self::assertNotNull($fail);
        self::assertSame('Failed', $fail->status);
        // "Already scanned": the period scan lists, the failed one never does.
        $today = new DateTimeImmutable('now', new DateTimeZone('Australia/Melbourne'));
        $overlapping = $scans->overlapping($today->modify('-30 days')->format('Y-m-d'), $today->modify('-10 days')->format('Y-m-d'));
        self::assertSame(['a905' . str_pad('2', 28, '0', STR_PAD_LEFT)], array_map(static fn ($run): string => $run->id, $overlapping));
        self::assertSame('sample.admin@sample.local', $overlapping[0]->requestedBy);
    }

    // ---- people ---------------------------------------------------------------------------

    public function test_the_sample_people_hold_the_four_roles_and_one_is_scoped_to_one_mailbox(): void
    {
        $admin = $this->admin();
        $operator = $this->actor('lena.marsh@sample.local');
        $preparer = $this->actor('mia.ford@sample.local');
        $approver = $this->actor('owen.reed@sample.local');
        $scoped = $this->actor('noah.hale@sample.local');

        self::assertTrue($admin->isAdmin);
        self::assertFalse($admin->mailboxScope->isRestricted());
        self::assertTrue($operator->can(Module::PERMISSION_AP_INVOICES_WRITE) && $operator->can(Module::PERMISSION_AP_MAIL_SEND));
        self::assertFalse($operator->can(Module::PERMISSION_AP_APPROVALS_DECIDE) || $operator->can(Module::PERMISSION_AP_SETTINGS_MANAGE));
        self::assertTrue($preparer->can(Module::PERMISSION_AP_APPROVALS_PREPARE));
        self::assertFalse($preparer->can(Module::PERMISSION_AP_INVOICES_WRITE) || $preparer->can(Module::PERMISSION_AP_APPROVALS_DECIDE));
        self::assertTrue($approver->can(Module::PERMISSION_AP_APPROVALS_DECIDE));
        self::assertFalse($approver->can(Module::PERMISSION_AP_APPROVALS_PREPARE) || $approver->can(Module::PERMISSION_AP_INVOICES_WRITE));
        self::assertTrue($scoped->can(Module::PERMISSION_AP_INVOICES_WRITE));
        self::assertSame([self::PROJECTS], $scoped->mailboxScope->mailboxes());
        self::assertSame('Owen Reed', $approver->name);

        $approvers = array_map(static fn (array $a): string => (string) $a['full_name'], $this->approvals->approvers());
        self::assertSame(['Owen Reed', 'Sample Administrator'], $approvers, 'the Approver and the Administrator are the people who can decide');
        $meId = array_map(static fn (array $a): string => (string) $a['email'], $this->approvals->approvers());
        self::assertSame(['owen.reed@sample.local', 'sample.admin@sample.local'], $meId);
    }

    // ---- the register ---------------------------------------------------------------------

    public function test_the_register_lists_28_and_the_bin_holds_4(): void
    {
        $live = $this->register();
        $bin = $this->register(null, true);

        self::assertCount(28, $live);
        self::assertSame(range(1, 28), self::numbers($live));
        self::assertSame([29, 30, 31, 32], self::numbers($bin));
        foreach ($bin as $row) {
            self::assertSame('sample.admin@sample.local', $row['deleted_by']);
            self::assertNotNull($row['deleted_at']);
            self::assertNotSame('Overdue', $row['status'], 'a binned invoice is never Overdue, whatever its due date');
        }
        foreach ($live as $row) {
            self::assertNull($row['deleted_at']);
        }
        self::assertSame('Needs Review', $bin[29]['status'], 'binned with a past due date and an open status: shown as stored');
    }

    public function test_every_stored_status_is_there_and_four_invoices_derive_as_overdue(): void
    {
        $live = $this->register();
        $counts = [];
        foreach ($live as $row) {
            $status = self::str($row['status']);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts);

        self::assertSame(['Completed' => 6, 'Exported' => 3, 'In Progress' => 7, 'Needs Review' => 8, 'Overdue' => 4], $counts);
        $overdue = $this->invoices->list($this->admin(), ['status' => 'Overdue', 'limit' => '100']);
        $ids = array_map(static fn (array $row): string => self::str($row['id']), $overdue);
        sort($ids);
        self::assertSame([self::inv(9), self::inv(12), self::inv(15), self::inv(20)], $ids);
        foreach ($overdue as $row) {
            self::assertContains($this->storedStatus(self::str($row['id'])), ['Needs Review', 'In Progress'], 'Overdue is derived, never stored');
            self::assertLessThan($this->time->today(), $row['due_date']);
        }
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[invoice_invoices] WHERE [status] = N'Overdue'"));
        self::assertCount(7, $this->invoices->list($this->admin(), ['status' => 'In Progress', 'limit' => '100']));
        // The completed and exported ones with a past due date are not overdue.
        self::assertSame('Completed', $live[7]['status']);
        self::assertSame('Exported', $live[10]['status']);
    }

    private function storedStatus(string $id): string
    {
        return self::str($this->rowsOf("SELECT [status] FROM [finance].[invoice_invoices] WHERE [id] = '" . $id . "'")[0]['status']);
    }

    public function test_today_this_week_and_this_month_are_never_empty_and_the_register_spans_about_sixty_days(): void
    {
        $zone = new DateTimeZone('Australia/Melbourne');
        $today = new DateTimeImmutable('now', $zone);
        $days = [];
        foreach ($this->register() as $number => $row) {
            $when = $row['email_date'] ?? $row['created_date'];
            self::assertIsString($when);
            $days[$number] = (int) $today->setTime(0, 0)->diff((new DateTimeImmutable($when))->setTimezone($zone)->setTime(0, 0))->format('%r%a');
        }

        self::assertGreaterThanOrEqual(3, count(array_filter($days, static fn (int $d): bool => $d === 0)), 'today');
        self::assertGreaterThanOrEqual(8, count(array_filter($days, static fn (int $d): bool => $d >= -6 && $d <= 0)), 'the last seven days');
        self::assertGreaterThanOrEqual(10, count(array_filter($days, static fn (int $d): bool => $d >= -29)), 'the last month');
        $sorted = array_values($days);
        sort($sorted);
        self::assertGreaterThanOrEqual(-60, $sorted[0]);
        self::assertLessThanOrEqual(-55, $sorted[0]);
        self::assertLessThanOrEqual(0, $sorted[count($sorted) - 1], 'nothing is dated in the future');
        // The API's own period filter agrees.
        $day = $today->format('Y-m-d');
        self::assertGreaterThanOrEqual(3, count($this->invoices->list($this->admin(), ['fromDate' => $day, 'toDate' => $day, 'limit' => '100'])));
    }

    public function test_the_three_duplicate_states_use_the_wording_the_api_writes(): void
    {
        $live = $this->register();
        $reason = 'Possible duplicate: same vendor, invoice number, currency, and total';

        $potential = array_keys(array_filter($live, static fn (array $row): bool => $row['duplicate_status'] === 'Potential'));
        sort($potential);
        self::assertSame([8, 18], $potential);
        self::assertSame(self::inv(7), $live[8]['duplicate_of_invoice_id']);
        self::assertSame(self::inv(17), $live[18]['duplicate_of_invoice_id']);
        self::assertSame($reason, $live[8]['duplicate_reason']);
        self::assertSame('Cleared by user', $live[11]['duplicate_status']);
        self::assertSame(self::inv(10), $live[11]['duplicate_of_invoice_id']);
        foreach ($live as $number => $row) {
            if (!in_array($number, [8, 11, 18], true)) {
                self::assertSame('Clear', $row['duplicate_status']);
                self::assertNull($row['duplicate_of_invoice_id']);
                self::assertNull($row['duplicate_reason']);
            }
        }
        // The binned one is a duplicate of a live one.
        self::assertSame(self::inv(1), $this->register(null, true)[32]['duplicate_of_invoice_id']);

        // The scan's own rules find the same pairs in the stored rows.
        $business = $this->invoices->findBusinessDuplicate('BW-1180', 'Brightwell Safety Equipment', 'AUD', 704.0);
        self::assertInstanceOf(ApInvoice::class, $business);
        self::assertSame(self::inv(7), $business->id, 'the earliest of the pair');
        $sha = ApSampleFiles::all()[ApSampleFiles::fileId(1)]['sha256'];
        $exact = $this->invoices->findExactDuplicate($sha);
        self::assertInstanceOf(ApInvoice::class, $exact);
        self::assertSame(self::inv(1), $exact->id);
    }

    public function test_low_confidence_invoices_carry_the_review_reason_in_the_apis_words(): void
    {
        $live = $this->register();
        $low = array_keys(array_filter($live, static fn (array $row): bool => is_float($row['extraction_confidence']) && $row['extraction_confidence'] < 0.80));
        sort($low);

        self::assertSame([4, 13, 22], $low);
        self::assertSame('Low extraction confidence (62%); Amounts do not add up: 4,300.00 + 430.00 = 4,730.00, but the total reads 5,000.00', $live[4]['review_reason']);
        self::assertSame('Could not read the vendor, total; Low extraction confidence (41%)', $live[13]['review_reason']);
        self::assertSame('Low extraction confidence (72%)', $live[22]['review_reason']);
        self::assertSame('Unknown Vendor', $live[13]['vendor']);
        self::assertNull($live[13]['total_amount']);
        foreach ($live as $number => $row) {
            if (!in_array($number, [4, 13, 22], true)) {
                self::assertNull($row['review_reason']);
            }
            if ($number !== 4 && $number !== 13) {
                self::assertEqualsWithDelta(self::num($row['net_amount']) + self::num($row['tax_amount']), self::num($row['total_amount']), 0.005, 'invoice ' . $number . ' adds up');
            }
        }
    }

    public function test_every_vendor_match_value_and_none_is_there_with_its_note(): void
    {
        $live = $this->register();
        $values = [];
        foreach ($live as $row) {
            $values[] = $row['vendor_match'];
        }

        self::assertEqualsCanonicalizing(['matched', 'confirmed', 'unmatched', 'new', null], array_values(array_unique($values, SORT_REGULAR)));
        self::assertNull($live[23]['vendor_match']);
        self::assertSame(101, $live[1]['odoo_vendor_id']);
        self::assertSame('V-0101', $live[1]['vendor_ref']);
        self::assertSame('Alder & Vale Logistics Pty Ltd', $live[1]['odoo_vendor_name']);
        self::assertSame('confirmed', $live[6]['vendor_match']);
        self::assertSame('Chosen by sample.admin@sample.local', $live[6]['vendor_match_note']);
        self::assertSame('new', $live[19]['vendor_match']);
        self::assertNull($live[19]['odoo_vendor_id']);
        self::assertStringContainsString('create it in Odoo', self::str($live[19]['vendor_match_note']));
        self::assertSame('unmatched', $live[21]['vendor_match']);
        self::assertSame('The vendor read from the invoice looks like TriCab itself; pick the real vendor', $live[21]['vendor_match_note']);
        self::assertSame(109, $live[20]['odoo_vendor_id'], 'matched to an archived Odoo vendor');
    }

    public function test_the_register_mixes_currencies_entities_sources_documents_and_approvals(): void
    {
        $live = $this->register();
        $currencies = array_unique(array_filter(array_map(static fn (array $row): string => self::str($row['currency']), $live), static fn (string $c): bool => $c !== ''));
        $companies = array_unique(array_filter(array_map(static fn (array $row): string => self::str($row['company']), $live), static fn (string $c): bool => $c !== ''));
        $approvals = array_unique(array_map(static fn (array $row): string => self::str($row['approval_status']), $live));
        sort($currencies);
        sort($companies);
        sort($approvals);

        self::assertSame(['AUD', 'EUR', 'GBP', 'NZD', 'USD'], $currencies);
        self::assertSame(['Harbourview Trading Pty Ltd', 'Meridian Components Pty Ltd', 'Northfield Cables NZ Ltd'], $companies);
        self::assertSame(['Approved', 'Not requested', 'Pending approval', 'Rejected'], $approvals);
        $handCaptures = array_filter($live, static fn (array $row): bool => $row['mailbox'] === null);
        self::assertSame(range(21, 28), self::numbers($handCaptures));
        foreach ($handCaptures as $row) {
            self::assertNull($row['email_id']);
            self::assertNull($row['email_record_id']);
            self::assertSame('', $row['sender_name']);
        }
        $scanned = array_filter($live, static fn (array $row): bool => $row['mailbox'] !== null);
        self::assertCount(20, $scanned);
        $mailboxes = array_unique(array_map(static fn (array $row): string => self::str($row['mailbox']), $scanned));
        sort($mailboxes);
        self::assertSame([self::PROJECTS, self::MAILBOX], $mailboxes);
        self::assertSame('Alder & Vale Accounts', $live[1]['sender_name'], 'the sender is found from the email the invoice came from');
        self::assertCount(21, array_filter($live, static fn (array $row): bool => $row['has_file'] === true));
        self::assertCount(7, array_filter($live, static fn (array $row): bool => $row['has_file'] === false));
        self::assertEqualsCanonicalizing(['Approved' => 3, 'Pending approval' => 3, 'Rejected' => 2], array_count_values(array_filter(array_map(static fn (array $row): string => self::str($row['approval_status']), $live), static fn (string $s): bool => $s !== 'Not requested')));
    }

    // ---- documents ----------------------------------------------------------------------------

    public function test_every_invoice_with_a_file_serves_the_bytes_whose_hash_and_size_the_rows_hold(): void
    {
        $admin = $this->admin();
        $rows = $this->rowsOf(
            'SELECT i.[id], i.[content_hash], i.[attachment], a.[byte_size], a.[sha256], a.[content_type], a.[application_key], a.[record_type], a.[record_id]
               FROM [finance].[invoice_invoices] i JOIN [finance].[attachments] a ON a.[id] = i.[file_id] ORDER BY i.[id]',
        );

        self::assertCount(22, $rows);
        foreach ($rows as $row) {
            $file = $this->invoices->document($admin, self::str($row['id']));
            self::assertSame(self::str($row['content_hash']), hash('sha256', $file->bytes), self::str($row['attachment']) . ': the invoice\'s hash is the file\'s');
            self::assertSame(self::str($row['sha256']), self::str($row['content_hash']));
            self::assertSame((int) self::str($row['byte_size']), strlen($file->bytes));
            self::assertSame(self::str($row['content_type']), $file->contentType);
            self::assertTrue($file->inlineAllowed, 'a PDF or a PNG shows inline in the preview');
            self::assertSame(self::str($row['attachment']), $file->downloadName);
            self::assertSame(['AP', 'invoice', self::str($row['id'])], [self::str($row['application_key']), self::str($row['record_type']), self::str($row['record_id'])]);
        }
    }

    public function test_the_pdf_the_png_the_missing_document_and_a_binned_invoices_document(): void
    {
        $admin = $this->admin();

        $pdf = $this->invoices->document($admin, self::inv(1));
        self::assertSame('application/pdf', $pdf->contentType);
        self::assertStringStartsWith('%PDF-1.4', $pdf->bytes);
        self::assertStringContainsString('AV-20841', $pdf->bytes);

        $png = $this->invoices->document($admin, self::inv(6));
        self::assertSame('image/png', $png->contentType);
        self::assertStringStartsWith("\x89PNG", $png->bytes);

        try {
            $this->invoices->document($admin, self::inv(11));
            self::fail('Expected an invoice with no document to say so.');
        } catch (ApNotFound $none) {
            self::assertSame(ApAttachmentService::NO_DOCUMENT, $none->getMessage());
        }
        $binned = $this->invoices->document($admin, self::inv(31));
        self::assertStringContainsString('TC-2800', $binned->bytes);
    }

    public function test_the_download_zips_the_documents_and_counts_the_invoices_without_one(): void
    {
        $download = new ApInvoiceDownloadService($this->invoiceStore, $this->attachments, $this->time);

        $zip = $download->download($this->admin(), [self::inv(1), self::inv(2), self::inv(3), self::inv(6), self::inv(11), self::inv(17)]);

        self::assertSame('application/zip', $zip->contentType);
        self::assertSame(2, $zip->withoutFile, 'two of the six have no document');
        self::assertStringStartsWith("PK\x03\x04", $zip->bytes);
        self::assertSame(4, substr_count($zip->bytes, "PK\x03\x04"));
        self::assertStringContainsString('(4).zip', $zip->fileName);
        $one = $download->download($this->admin(), [self::inv(1), self::inv(11)]);
        self::assertSame('application/pdf', $one->contentType);
        self::assertSame(1, $one->withoutFile);
        self::assertStringStartsWith('%PDF-1.4', $one->bytes);
    }

    // ---- the AP Mailbox -----------------------------------------------------------------------------

    public function test_the_mailbox_lists_invoice_and_other_emails_in_two_mailboxes_with_their_invoice_ids(): void
    {
        $admin = $this->admin();
        $emails = $this->emails->list($admin, ['limit' => '1000']);
        $byId = [];
        foreach ($emails as $email) {
            $byId[self::str($email['id'])] = $email;
        }

        self::assertCount(25, $emails);
        $classes = array_count_values(array_map(static fn (array $e): string => self::str($e['classification']), $emails));
        ksort($classes);
        self::assertSame(['Invoice' => 22, 'Other' => 3], $classes);
        $reviews = array_unique(array_map(static fn (array $e): string => self::str($e['review_status']), $emails));
        sort($reviews);
        self::assertSame(['Needs review', 'Unverified', 'Verified'], $reviews);
        $mailboxes = array_unique(array_map(static fn (array $e): string => self::str($e['mailbox']), $emails));
        sort($mailboxes);
        self::assertSame([self::PROJECTS, self::MAILBOX], $mailboxes);
        self::assertSame([self::inv(2), self::inv(3)], $byId[self::email(2)]['invoice_ids']);
        self::assertSame([self::inv(1)], $byId[self::email(1)]['invoice_ids']);
        self::assertSame([], $byId[self::email(1)]['duplicate_invoice_ids']);
        self::assertSame([], $byId[self::email(4)]['invoice_ids']);
        // An email whose attachment is an exact copy of an invoice already filed names that invoice.
        self::assertSame([self::inv(1)], $byId[self::email(9)]['invoice_ids']);
        self::assertSame([self::inv(1)], $byId[self::email(9)]['duplicate_invoice_ids']);
        // One proposed reply, on the payment enquiry.
        $withReply = array_filter($emails, static fn (array $e): bool => $e['proposed_response'] !== null);
        self::assertSame([self::email(4)], array_map(static fn (array $e): string => self::str($e['id']), array_values($withReply)));
        self::assertStringStartsWith("Hi,\n\nThanks for getting in touch.", self::str($byId[self::email(4)]['proposed_response']));

        $bin = $this->emails->list($admin, ['deleted' => '1']);
        self::assertCount(1, $bin);
        self::assertSame('Out of office: back on Monday', $bin[0]['subject']);
        self::assertSame('sample.admin@sample.local', $bin[0]['deleted_by']);
        self::assertSame(['total' => 25, 'invoice' => 22, 'other' => 3, 'errors' => 0], $this->emails->stats($admin, []));
        self::assertGreaterThanOrEqual(2, count($this->emails->list($admin, ['fromDate' => $this->time->today(), 'toDate' => $this->time->today()])));
        self::assertCount(3, $this->emails->list($admin, ['classification' => 'Other']));
    }

    public function test_the_emails_attachments_link_to_their_invoices_and_files(): void
    {
        $two = $this->emailStore->attachmentsOf(self::email(2));
        self::assertCount(2, $two);
        foreach ($two as $attachment) {
            self::assertTrue($attachment->isInvoice);
            self::assertNotNull($attachment->invoiceId);
            self::assertNotNull($attachment->fileId);
            self::assertNotNull($attachment->contentHash);
        }
        $copy = $this->emailStore->attachmentsOf(self::email(9));
        self::assertCount(1, $copy);
        self::assertTrue($copy[0]->isInvoice);
        self::assertNull($copy[0]->invoiceId);
        self::assertSame(self::inv(1), $copy[0]->duplicateOfInvoiceId);
        self::assertSame(ApSampleFiles::all()[ApSampleFiles::fileId(1)]['sha256'], $copy[0]->contentHash, 'the copy has the original\'s hash');
        $other = $this->emailStore->attachmentsOf(self::email(7));
        self::assertCount(1, $other);
        self::assertFalse($other[0]->isInvoice);
        self::assertSame([], $this->emailStore->attachmentsOf(self::email(5)));
        self::assertTrue($this->invoiceStore->scanSkipped('AAMkSAMPLEmsg-gone-0001', null, 'Old_scan_0003.pdf'), 'the tombstone is found');
        self::assertFalse($this->invoiceStore->scanSkipped('AAMkSAMPLEmsg-gone-0001', null, 'Another.pdf'));
    }

    public function test_a_person_scoped_to_one_mailbox_sees_only_that_mailbox_and_hand_captures(): void
    {
        $scoped = $this->actor('noah.hale@sample.local');

        $invoices = $this->register($scoped);
        self::assertSame([5, 9, 12, 19, 21, 22, 23, 24, 25, 26, 27, 28], self::numbers($invoices));
        foreach ($invoices as $row) {
            self::assertContains($row['mailbox'], [null, self::PROJECTS]);
        }
        self::assertSame([29, 31], self::numbers($this->register($scoped, true)), 'the Bin too: the hand captures, not the scanned ones');
        try {
            $this->invoices->get($scoped, self::inv(1));
            self::fail('Expected an out-of-scope invoice to be not found.');
        } catch (ApNotFound) {
            self::addToAssertionCount(1);
        }
        $emails = $this->emails->list($scoped, ['limit' => '1000']);
        $ids = array_map(static fn (array $e): string => self::str($e['id']), $emails);
        sort($ids);
        self::assertSame([self::email(6), self::email(7), self::email(12), self::email(16), self::email(23)], $ids);
        self::assertSame(5, $this->emails->stats($scoped, [])['total']);
        $mailboxes = new ApMailboxService(new AzureSqlFinanceMailboxRegistry($this->connection));
        self::assertSame([self::PROJECTS], array_map(static fn (array $m): string => $m['email'], $mailboxes->list($scoped)));
        self::assertSame([self::PROJECTS, self::MAILBOX], array_map(static fn (array $m): string => $m['email'], $mailboxes->list($this->admin())), 'in address order');
        foreach ($mailboxes->list($this->admin()) as $mailbox) {
            self::assertTrue($mailbox['can_read'] && $mailbox['can_send'] && $mailbox['is_active']);
        }
        $send = $this->mail->sendMailboxes($this->admin());
        self::assertSame(self::MAILBOX, $send['default']);
        self::assertSame([self::MAILBOX, self::PROJECTS], array_map(static fn (array $m): string => $m['email'], $send['mailboxes']));
        self::assertSame([self::PROJECTS], array_map(static fn (array $m): string => $m['email'], $this->mail->sendMailboxes($scoped)['mailboxes']));
        // A batch is visible only when every one of its invoices is.
        self::assertSame(['Rejected'], array_map(static fn (array $b): string => self::str($b['status']), $this->approvals->list($scoped)));
    }

    // ---- vendors, approvals, sent mail, settings -------------------------------------------------

    public function test_the_vendors_page_has_thirteen_vendors_two_archived_counts_and_a_last_synced_time(): void
    {
        $vendors = new ApVendorService(new AzureSqlApVendorStore(
            new AzureSqlOdooRecordStore($this->connection),
            new AzureSqlOdooSyncRuns($this->connection),
            $this->invoiceStore,
            new AzureSqlApReferenceStore($this->connection),
            $this->time,
        ));

        $page = $vendors->vendors();

        self::assertCount(13, $page['items']);
        self::assertCount(2, array_filter($page['items'], static fn (array $v): bool => $v['active'] === false));
        self::assertNotNull($page['synced_at']);
        $synced = new DateTimeImmutable($page['synced_at']);
        $age = time() - $synced->getTimestamp();
        self::assertGreaterThan(3 * 3600 - 120, $age);
        self::assertLessThan(3 * 3600 + 3600, $age, 'synced about three hours ago');
        $counts = [];
        foreach ($page['items'] as $vendor) {
            $counts[$vendor['odoo_id']] = $vendor['invoice_count'];
        }
        self::assertSame(3, $counts[101], 'Alder & Vale: invoices 1, 16 and 28; the binned one is not counted');
        self::assertSame(2, $counts[104]);
        self::assertSame(2, $counts[107]);
        self::assertSame(1, $counts[109], 'the archived vendor still has its invoice');
        self::assertSame(0, $counts[111]);
        $first = $page['items'][0];
        self::assertSame('Alder & Vale Logistics Pty Ltd', $first['name'], 'in name order');
        self::assertSame('V-0101', $first['ref']);
        self::assertStringEndsWith('.example.test', $first['email']);
    }

    public function test_the_three_approval_batches_show_the_people_by_name(): void
    {
        $admin = $this->admin();
        $batches = $this->approvals->list($admin);

        self::assertSame(['Pending', 'Approved', 'Rejected'], array_map(static fn (array $b): string => self::str($b['status']), $batches), 'newest first');
        [$pending, $approved, $rejected] = $batches;
        self::assertSame(self::batch(1), $pending['id']);
        foreach ($batches as $batch) {
            $preparer = self::map($batch['prepared_by_user']);
            self::assertSame('Mia Ford', $preparer['full_name']);
            self::assertSame('mia.ford@sample.local', $preparer['email']);
        }
        self::assertSame([], $pending['decisions']);
        self::assertNull($pending['decided_by_user']);
        self::assertSame(['owen.reed@sample.local'], $pending['notified_emails'], 'the request mail went to the Approver');
        self::assertCount(3, self::rows($pending['invoices']));
        self::assertSame('Owen Reed', self::map($approved['decided_by_user'])['full_name']);
        self::assertSame('Approved. Please schedule for the Friday payment run.', $approved['decision_comment']);
        self::assertCount(1, self::rows($approved['decisions']));
        self::assertSame('Approved', self::rows($approved['decisions'])[0]['decision']);
        self::assertSame('Owen Reed', self::rows($approved['decisions'])[0]['approver_name']);
        self::assertSame('Sample Administrator', self::map($rejected['decided_by_user'])['full_name']);
        self::assertSame('Rejected', self::rows($rejected['decisions'])[0]['decision']);
        self::assertStringContainsString('purchase order', self::str($rejected['decision_comment']));
        self::assertCount(2, self::rows($rejected['invoices']));
        $expect = ['Pending' => 'Pending approval', 'Approved' => 'Approved', 'Rejected' => 'Rejected'];
        foreach ($batches as $batch) {
            foreach (self::rows($batch['invoices']) as $invoice) {
                self::assertSame($expect[self::str($batch['status'])], $invoice['approval_status'], 'the invoice agrees with its batch');
            }
        }
        self::assertSame($pending['id'], $this->approvals->get($admin, self::batch(1))['id']);
    }

    public function test_the_sent_mail_history_shows_sent_and_failed_for_invoices_emails_and_a_batch(): void
    {
        $admin = $this->admin();

        $sent = $this->mail->invoiceHistory($admin, self::inv(12));
        self::assertCount(1, $sent);
        self::assertSame(['Sent', self::MAILBOX, ['accounts@marlow-freight.example.test'], 'Query about invoice MF-5398', true], [$sent[0]['status'], $sent[0]['from_mailbox'], $sent[0]['to_addresses'], $sent[0]['subject'], $sent[0]['attach_invoice']]);
        self::assertNotNull($sent[0]['sent_at']);
        self::assertNull($sent[0]['error']);
        self::assertStringContainsString('Geelong delivery', self::str($sent[0]['body']));
        self::assertSame(self::inv(12), $sent[0]['invoice_id']);

        $failed = $this->mail->invoiceHistory($admin, self::inv(20));
        self::assertSame(['Failed', 'The mailbox could not send this message.'], [$failed[0]['status'], $failed[0]['error']]);
        self::assertNull($failed[0]['sent_at']);

        $reply = $this->mail->emailHistory($admin, self::email(4));
        self::assertSame(['Sent', 'Re: Where is our payment?'], [$reply[0]['status'], $reply[0]['subject']]);
        self::assertSame(self::email(4), $reply[0]['email_id']);
        self::assertSame('Failed', $this->mail->emailHistory($admin, self::email(7))[0]['status']);
        self::assertSame([], $this->mail->invoiceHistory($admin, self::inv(1)));

        $links = $this->outboundStore->of(ApOutboundTarget::approvalBatch(self::batch(1)), 10);
        self::assertCount(1, $links);
        $delivery = $this->ledger->findById($links[0]->deliveryId);
        self::assertNotNull($delivery);
        self::assertSame('sent', $delivery->status);
        self::assertSame('AP', $delivery->application);
        self::assertSame(['owen.reed@sample.local'], $delivery->to);
        self::assertSame(3, $this->scalar("SELECT COUNT(*) FROM [finance].[mail_deliveries] WHERE [application_key] = N'AP' AND [status] = N'sent'"));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM [finance].[mail_deliveries] WHERE [application_key] = N'AP' AND [status] = N'failed'"));
        // The sender of each is a person who may send.
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[mail_deliveries] d WHERE d.[actor_user_id] IS NULL OR NOT EXISTS (SELECT 1 FROM [identity].[Users] u WHERE u.[UserId] = d.[actor_user_id])"));
    }

    public function test_signing_authority_has_an_unlimited_position_and_one_with_no_authority_for_a_document_type(): void
    {
        $rows = (new ApSigningAuthorityService(new AzureSqlApSigningLimitStore($this->connection)))->list();

        self::assertCount(5, $rows, 'the retired position and anyone without an address are not listed');
        $byPosition = [];
        foreach ($rows as $row) {
            $byPosition[self::str($row['d365_position'])] = self::map($row['limits']);
        }
        self::assertSame(99999999999.0, $byPosition['Chief Financial Officer']['po_inventory']);
        self::assertSame(99999999999.0, $byPosition['Chief Financial Officer']['credit_notes']);
        self::assertNull($byPosition['Warehouse Supervisor']['customer_order'], 'no authority is NULL, not 0');
        self::assertNull($byPosition['Sales Manager']['po_inventory']);
        self::assertSame(250000.0, $byPosition['Finance Manager']['po_inventory']);
        self::assertArrayNotHasKey('Acting Director', $byPosition);
        self::assertSame('Theo Park', self::str(array_values(array_filter($rows, static fn (array $r): bool => $r['d365_position'] === 'Finance Manager'))[0]['full_name']));
    }

    public function test_the_settings_lists_have_companies_with_sites_and_former_names_cost_centres_and_categories(): void
    {
        $lists = new ApReferenceService(new AzureSqlApReferenceStore($this->connection), $this->time);

        $companies = $lists->list('companies', 'name', 100);
        $byName = [];
        foreach ($companies as $company) {
            $byName[self::str($company['name'])] = $company;
        }
        self::assertCount(4, $companies);
        self::assertSame('Melbourne; Sydney', $byName['Harbourview Trading Pty Ltd']['sites']);
        self::assertSame('Harbourview Wholesale Pty Ltd', $byName['Harbourview Trading Pty Ltd']['former_names']);
        self::assertSame('', $byName['Northfield Cables NZ Ltd']['former_names']);
        self::assertFalse($byName['Sandgate Distribution Pty Ltd']['is_active']);
        self::assertTrue($byName['Meridian Components Pty Ltd']['is_active']);
        $costCentres = $lists->list('cost-centres', 'code', 100);
        self::assertSame(['CC-100', 'CC-210', 'CC-310', 'CC-410', 'CC-510', 'CC-990'], array_map(static fn (array $c): string => self::str($c['code']), $costCentres));
        self::assertFalse($costCentres[5]['is_active']);
        self::assertSame('1000', $costCentres[0]['code_no']);
        $categories = $lists->list('account-categories', 'code', 100);
        self::assertCount(7, $categories);
        self::assertSame('AC-FREIGHT', $categories[0]['code']);
        // Every code and company an invoice uses is in a list.
        $codes = array_map(static fn (array $c): string => self::str($c['code']), $categories);
        $centres = array_map(static fn (array $c): string => self::str($c['code']), $costCentres);
        foreach ($this->register() as $row) {
            if ($row['account_category'] !== null) {
                self::assertContains($row['account_category'], $codes);
            }
            if ($row['cost_centre'] !== null) {
                self::assertContains($row['cost_centre'], $centres);
            }
            if ($row['company'] !== null) {
                self::assertArrayHasKey(self::str($row['company']), $byName);
            }
        }
    }

    public function test_the_audit_trail_has_events_for_an_invoice_an_email_and_a_batch(): void
    {
        $binned = $this->auditStore->forEntity('invoice', self::inv(29));
        self::assertCount(1, $binned);
        self::assertSame('invoice_binned', $binned[0]->action);
        self::assertSame('Sample Administrator', $binned[0]->actorName);
        self::assertSame(['invoice_no' => 'PC-2199', 'vendor' => 'Pinecrest Cleaning Co'], $binned[0]->details);

        $override = $this->auditStore->forEntity('invoice', self::inv(11));
        self::assertSame('duplicate_override', $override[0]->action);
        self::assertSame('Corrected copy of SP-2210, not a second invoice', $override[0]->details['reason']);
        self::assertSame('Lena Marsh', $override[0]->actorName);

        self::assertSame('email_reply_sent', $this->auditStore->forEntity('email', self::email(4))[0]->action);
        self::assertSame('approval_requested', $this->auditStore->forEntity('approval_batch', self::batch(1))[0]->action);
        self::assertSame('approval_approved', $this->auditStore->forEntity('approval_batch', self::batch(2))[0]->action);
        self::assertSame('approval_rejected', $this->auditStore->forEntity('approval_batch', self::batch(3))[0]->action);
        self::assertSame('vendor_email_sent', $this->auditStore->forEntity('invoice', self::inv(12))[0]->action);
    }

    public function test_the_odoo_sync_run_is_a_success_and_every_invoice_vendor_is_in_the_vendor_list(): void
    {
        $run = (new AzureSqlOdooSyncRuns($this->connection))->lastSuccess();

        self::assertNotNull($run);
        self::assertSame('succeeded', $run->status);
        self::assertSame(13, $run->counts['vendors']['fetched']);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[invoice_invoices] i WHERE i.[odoo_vendor_id] IS NOT NULL AND NOT EXISTS (SELECT 1 FROM [finance].[odoo_records] o WHERE o.[list_key] = N'vendors' AND o.[odoo_id] = i.[odoo_vendor_id])"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[odoo_sync_runs] WHERE [status] = N'running'"), 'no sync is left running');
    }
}
