<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\InMemoryApAuditStore;
use TriCab\Api\Finance\Ap\InMemoryApInvoiceStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceUnattachedAttachmentsJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The register's and the files' routes (T6.4, T6.5) through the real app on the in-memory stores: one permission each, the
 * mailbox scope's 404s, every validation message, the Bin and permanent delete, the upload, the file route's headers, and the
 * download's single, zip, cap, missing-count and 404 cases. The person is user 1, `thilanh@tricab.com`.
 */
final class FinanceApInvoiceRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ap';
    private const ON = ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true'];

    private const READ = Module::PERMISSION_AP_INVOICES_READ;
    private const WRITE = Module::PERMISSION_AP_INVOICES_WRITE;
    private const DELETE = Module::PERMISSION_AP_INVOICES_DELETE;

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(self::ON);
    }

    private function invoices(): InMemoryApInvoiceStore
    {
        $store = $this->app->getContainer()->get(ApInvoiceStore::class);
        self::assertInstanceOf(InMemoryApInvoiceStore::class, $store);

        return $store;
    }

    private function audit(): InMemoryApAuditStore
    {
        $store = $this->app->getContainer()->get(ApAuditStore::class);
        self::assertInstanceOf(InMemoryApAuditStore::class, $store);

        return $store;
    }

    /**
     * Stores an invoice, with a document when bytes are given (attached to it by user 1, as the capture would).
     */
    private function seed(ApInvoice $invoice, ?string $bytes = null, string $type = 'application/pdf'): ApInvoice
    {
        if ($bytes !== null) {
            $attachments = $this->app->getContainer()->get(ApAttachmentService::class);
            $files = $this->app->getContainer()->get(FinanceAttachmentService::class);
            self::assertInstanceOf(ApAttachmentService::class, $attachments);
            self::assertInstanceOf(FinanceAttachmentService::class, $files);
            $file = $attachments->keep($invoice->attachment !== '' ? $invoice->attachment : 'document.pdf', $type, $bytes, 1);
            $files->attach(Module::APP_AP, $file->id, ApAttachmentService::RECORD_TYPE, $invoice->id, 1);
            $invoice = $invoice->with(fileId: $file->id);
        }
        $this->invoices()->insert($invoice);

        return $invoice;
    }

    /**
     * @param list<string> $permissions
     */
    private function grant(array $permissions): void
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    private function scope(string ...$mailboxes): void
    {
        $scopes = $this->app->getContainer()->get(FinanceAccessScopeReader::class);
        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $scopes);
        foreach ($mailboxes as $mailbox) {
            $scopes->grant(1, 'AP', FinanceAccessScope::TYPE_MAILBOX, $mailbox);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    private function upload(string $name, string $bytes, string $field = 'file'): ResponseInterface
    {
        $file = new UploadedFile((new StreamFactory())->createStream($bytes), $name, 'application/octet-stream', strlen($bytes), UPLOAD_ERR_OK);

        return $this->app->handle(TestAppFactory::request('POST', self::BASE . '/uploads')->withUploadedFiles([$field => $file]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(ResponseInterface $response): array
    {
        $json = TestAppFactory::json($response);
        self::assertTrue($json['success'], json_encode($json) ?: '');
        $data = $json['data'];
        self::assertIsArray($data);
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(ResponseInterface $response): array
    {
        $json = TestAppFactory::json($response);
        self::assertTrue($json['success']);
        $data = $json['data'];
        self::assertIsArray($data);
        self::assertTrue(array_is_list($data), 'a bare array of rows');
        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $one = [];
            foreach ($row as $key => $value) {
                $one[(string) $key] = $value;
            }
            $rows[] = $one;
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function error(ResponseInterface $response): array
    {
        $json = TestAppFactory::json($response);
        self::assertFalse($json['success']);
        $error = $json['error'];
        self::assertIsArray($error);

        self::assertIsString($error['code']);
        self::assertIsString($error['message']);

        return [$error['code'], $error['message']];
    }

    private static function binary(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    // ---- the switch and the permissions ----------------------------------------------------------------

    public function test_with_the_ap_switch_off_the_register_is_not_reachable(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true']);
        $this->grant([self::READ, self::WRITE, self::DELETE]);

        foreach ([['GET', '/invoices'], ['POST', '/invoices'], ['POST', '/invoices/download'], ['GET', '/invoices/x/file'], ['POST', '/uploads']] as [$method, $path]) {
            self::assertSame(404, $this->call($method, $path)->getStatusCode(), $method . ' ' . $path);
        }
    }

    public function test_each_route_needs_its_one_permission(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('a'));
        $cases = [
            ['GET', '/invoices', self::READ],
            ['GET', '/invoices/a', self::READ],
            ['GET', '/invoices/a/file', self::READ],
            ['POST', '/invoices/download', self::READ],
            ['POST', '/invoices', self::WRITE],
            ['PATCH', '/invoices/a', self::WRITE],
            ['DELETE', '/invoices/a', self::WRITE],
            ['POST', '/invoices/a/restore', self::WRITE],
            ['POST', '/uploads', self::WRITE],
        ];
        foreach ($cases as [$method, $path, $needed]) {
            $this->grant(array_values(array_diff([self::READ, self::WRITE, self::DELETE, Module::PERMISSION_AP_MAIL_READ], [$needed])));
            self::assertSame(403, $this->call($method, $path)->getStatusCode(), $method . ' ' . $path . ' opened without ' . $needed);
            $this->grant([$needed]);
            self::assertNotSame(403, $this->call($method, $path)->getStatusCode(), $method . ' ' . $path . ' refused its own permission');
        }
    }

    public function test_a_permanent_delete_needs_the_delete_permission_as_well(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('a', deletedAt: ApInvoiceFixtures::utc('2026-10-04 00:00:00')));
        $this->grant([self::WRITE]);

        $response = $this->call('DELETE', '/invoices/a?permanent=1');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['FINANCE_AP_FORBIDDEN', 'You do not have permission to delete invoices permanently'], self::error($response));
        self::assertNotNull($this->invoices()->find('a'));
        $this->grant([self::WRITE, self::DELETE]);
        self::assertSame(200, $this->call('DELETE', '/invoices/a?permanent=1')->getStatusCode());
    }

    // ---- list and one ----------------------------------------------------------------------------------------

    public function test_the_list_is_a_bare_array_of_rows_in_the_sources_shape_with_the_file_flags_and_no_address(): void
    {
        $this->invoices()->withEmail('em-1', 'msg-a', 'Bill Biller');
        $this->seed(ApInvoiceFixtures::invoice('a', emailRecordId: 'em-1', attachment: 'Smith 77.pdf', createdAt: ApInvoiceFixtures::utc('2026-10-06 00:00:00')), '%PDF');
        $this->seed(ApInvoiceFixtures::invoice('b', mailbox: null, emailDate: null, sender: '', createdAt: ApInvoiceFixtures::utc('2026-10-05 00:00:00')));
        $this->grant([self::READ]);

        $rows = self::rows($this->call('GET', '/invoices?sort=-created_date&limit=1000'));

        self::assertSame(['a', 'b'], ApInvoiceFixtures::ids($rows));
        self::assertTrue($rows[0]['has_file']);
        self::assertIsString($rows[0]['file_id']);
        self::assertSame('Smith 77.pdf', $rows[0]['attachment']);
        self::assertSame('Bill Biller', $rows[0]['sender_name']);
        self::assertSame('', $rows[1]['sender_name']);
        self::assertNull($rows[1]['sender']);
        self::assertNull($rows[1]['mailbox']);
        self::assertFalse($rows[1]['has_file']);
        self::assertNull($rows[1]['file_id']);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('attachment_url', $row);
            self::assertArrayNotHasKey('deleted_by_user_id', $row);
        }
        self::assertSame('2026-11-01', $rows[0]['due_date']);
        self::assertEquals(110, $rows[0]['total_amount']);
        self::assertSame('In Progress', $rows[0]['status']);
    }

    public function test_the_list_refuses_a_bad_limit_and_a_bad_date_with_the_sources_words(): void
    {
        $this->grant([self::READ]);

        foreach ([['limit=0', 'limit must be at least 1'], ['limit=-1', 'limit must be at least 1'], ['limit=abc', 'limit must be a whole number'], ['fromDate=01/10/2026', 'Dates must use YYYY-MM-DD'], ['toDate=20261001', 'Dates must use YYYY-MM-DD']] as [$query, $message]) {
            $response = $this->call('GET', '/invoices?' . $query);
            self::assertSame(422, $response->getStatusCode(), $query);
            self::assertSame(['FINANCE_AP_INPUT_INVALID', $message], self::error($response), $query);
        }
    }

    public function test_the_bin_is_the_same_route_with_deleted_one(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('live'));
        $this->seed(ApInvoiceFixtures::invoice('binned', deletedAt: ApInvoiceFixtures::utc('2026-10-04 00:00:00'), deletedBy: 'dee@example.test'));
        $this->grant([self::READ]);

        $bin = self::rows($this->call('GET', '/invoices?deleted=1&sort=-deleted_at&limit=1000'));

        self::assertSame(['binned'], ApInvoiceFixtures::ids($bin));
        self::assertSame('dee@example.test', $bin[0]['deleted_by']);
        self::assertSame(['live'], ApInvoiceFixtures::ids(self::rows($this->call('GET', '/invoices'))));
    }

    public function test_a_scoped_person_sees_their_mailbox_and_hand_captures_and_a_404_for_the_rest(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('mine', mailbox: 'Christine@Example.test', createdAt: ApInvoiceFixtures::utc('2026-10-01 00:00:00')), '%PDF');
        $this->seed(ApInvoiceFixtures::invoice('hand', mailbox: null, createdAt: ApInvoiceFixtures::utc('2026-10-02 00:00:00')));
        for ($i = 0; $i < 3; ++$i) {
            $this->seed(ApInvoiceFixtures::invoice('theirs' . $i, mailbox: 'ap@example.test', createdAt: ApInvoiceFixtures::utc('2026-10-06 0' . $i . ':00:00')), '%PDF');
        }
        $this->grant([self::READ, self::WRITE, self::DELETE]);
        $this->scope('christine@example.test');

        self::assertSame(['hand', 'mine'], ApInvoiceFixtures::ids(self::rows($this->call('GET', '/invoices?limit=2'))), 'scoped before the limit');
        self::assertSame(200, $this->call('GET', '/invoices/mine')->getStatusCode());
        self::assertSame(200, $this->call('GET', '/invoices/hand')->getStatusCode());
        foreach ([['GET', '/invoices/theirs0'], ['GET', '/invoices/theirs0/file'], ['PATCH', '/invoices/theirs0'], ['DELETE', '/invoices/theirs0'], ['POST', '/invoices/theirs0/restore'], ['GET', '/invoices/ghost']] as [$method, $path]) {
            $response = $this->call($method, $path, $method === 'PATCH' ? ['notes' => 'x'] : null);
            self::assertSame(404, $response->getStatusCode(), $method . ' ' . $path);
            self::assertSame(['FINANCE_AP_NOT_FOUND', 'Not found'], self::error($response), $method . ' ' . $path);
        }
        self::assertSame(404, $this->call('DELETE', '/invoices/theirs0?permanent=1')->getStatusCode());
        self::assertNull($this->invoices()->find('theirs0')?->deletedAt);
    }

    // ---- capture, patch, bin, restore -------------------------------------------------------------------------

    public function test_a_capture_answers_201_with_the_new_row_and_takes_the_upload(): void
    {
        $this->grant([self::WRITE]);
        $upload = self::data($this->upload('Smith Invoice 77.pdf', '%PDF-1.4 invented'));
        self::assertSame(['upload_id', 'name'], array_keys($upload));
        self::assertSame('Smith Invoice 77.pdf', $upload['name']);

        $response = $this->call('POST', '/invoices', [
            'upload_id' => $upload['upload_id'], 'attachment' => 'Smith Invoice 77.pdf', 'invoice_no' => 'A-77', 'vendor' => 'Smith', 'company' => 'TriCab (Australia) Pty Ltd',
            'invoice_date' => '2026-10-01', 'due_date' => '30/10/2026', 'currency' => 'AUD', 'net_amount' => 100, 'tax_amount' => 10, 'total_amount' => 110,
            'is_revised' => false, 'extraction_confidence' => 0.97, 'notes' => null,
        ]);

        self::assertSame(201, $response->getStatusCode());
        $row = self::data($response);
        self::assertSame('In Progress', $row['status']);
        self::assertNull($row['review_reason']);
        self::assertSame(0.97, $row['extraction_confidence']);
        self::assertSame('2026-10-30', $row['due_date']);
        self::assertSame($upload['upload_id'], $row['file_id']);
        self::assertTrue($row['has_file']);
        self::assertSame(hash('sha256', '%PDF-1.4 invented'), $row['content_hash']);
        self::assertIsString($row['id']);
        $this->grant([self::READ]);
        self::assertSame($row['id'], self::data($this->call('GET', '/invoices/' . $row['id']))['id']);
    }

    public function test_a_capture_refuses_what_the_source_refused_and_an_unknown_upload(): void
    {
        $this->grant([self::WRITE]);

        foreach ([
            [['status' => 'Overdue'], "Invalid status: 'Overdue'"],
            [['duplicate_status' => 'Maybe'], "Invalid duplicate_status: 'Maybe'"],
            [['invoice_date' => 'Net 30'], "invoice_date is not a date: 'Net 30'"],
            [['upload_id' => 'nope'], 'The upload was not found, or it is already on an invoice'],
            [['total_amount' => 'twelve'], 'total_amount must be a number'],
            [['vendor' => str_repeat('v', 401)], 'vendor must be at most 400 characters'],
        ] as [$body, $message]) {
            $response = $this->call('POST', '/invoices', $body);
            self::assertSame(422, $response->getStatusCode(), $message);
            self::assertSame(['FINANCE_AP_INPUT_INVALID', $message], self::error($response));
        }
        $this->grant([self::READ]);
        self::assertSame([], self::rows($this->call('GET', '/invoices')), 'nothing was created');
    }

    public function test_a_patch_edits_the_fixed_list_only_and_refuses_the_bad_values(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('a', approvalStatus: 'Pending approval', contentHash: str_repeat('a', 64)));
        $this->grant([self::WRITE]);

        $row = self::data($this->call('PATCH', '/invoices/a', ['vendor' => 'New', 'status' => 'Needs Review', 'approval_status' => 'Approved', 'deleted_at' => '2026-01-01', 'content_hash' => 'x', 'odoo_vendor_id' => 5, 'vendor_match' => 'confirmed']));

        self::assertSame('New', $row['vendor']);
        self::assertSame('Needs Review', $row['status']);
        self::assertSame('Pending approval', $row['approval_status']);
        self::assertNull($row['deleted_at']);
        self::assertSame(str_repeat('a', 64), $row['content_hash']);
        self::assertNull($row['odoo_vendor_id']);
        self::assertNull($row['vendor_match']);
        foreach ([
            [['status' => 'Overdue'], "Invalid status: 'Overdue'"],
            [['status' => 'Approved'], "Invalid status: 'Approved'"],
            [['duplicate_status' => 'Maybe'], "Invalid duplicate_status: 'Maybe'"],
            [['due_date' => '31/02/2026'], "due_date is not a date: '31/02/2026'"],
        ] as [$body, $message]) {
            $response = $this->call('PATCH', '/invoices/a', $body);
            self::assertSame(422, $response->getStatusCode(), $message);
            self::assertSame(['FINANCE_AP_INPUT_INVALID', $message], self::error($response));
        }
        self::assertSame(404, $this->call('PATCH', '/invoices/ghost', ['notes' => 'x'])->getStatusCode());
    }

    public function test_clearing_a_duplicate_through_the_route_writes_the_audit_event(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('a', duplicateStatus: 'Potential', duplicateReason: ApInvoice::BUSINESS_DUPLICATE_REASON));
        $this->grant([self::WRITE]);

        $row = self::data($this->call('PATCH', '/invoices/a', ['duplicate_status' => 'Cleared by user', 'duplicate_reason' => 'Reviewed and marked not a duplicate']));

        self::assertSame('Cleared by user', $row['duplicate_status']);
        $events = $this->audit()->forEntity('invoice', 'a');
        self::assertSame(['duplicate_override'], array_map(static fn ($e): string => $e->action, $events));
        self::assertSame(['reason' => 'Reviewed and marked not a duplicate'], $events[0]->details);
        self::assertSame(1, $events[0]->actorUserId);
    }

    public function test_the_bin_restore_and_permanent_delete_through_the_routes(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('a', invoiceNo: 'A-1', vendor: 'Smith', emailId: 'AAMk-1', attachmentId: 'ATT-1'), '%PDF');
        $this->grant([self::WRITE, self::DELETE]);

        $binned = self::data($this->call('DELETE', '/invoices/a'));
        self::assertTrue($binned['ok']);
        self::assertTrue($binned['in_bin']);
        self::assertIsArray($binned['invoice']);
        self::assertSame('thilanh@tricab.com', $binned['invoice']['deleted_by']);
        self::assertNotNull($binned['invoice']['deleted_at']);

        $conflict = $this->call('DELETE', '/invoices/ghost?permanent=1');
        self::assertSame(['ok' => true], self::data($conflict), 'a missing id is ok');

        $restored = self::data($this->call('POST', '/invoices/a/restore'));
        self::assertNull($restored['deleted_at']);
        self::assertNull($restored['deleted_by']);

        $notBinned = $this->call('DELETE', '/invoices/a?permanent=true');
        self::assertSame(409, $notBinned->getStatusCode());
        self::assertSame(['FINANCE_AP_CONFLICT', 'Move the invoice to the Bin before deleting it permanently'], self::error($notBinned));

        $this->call('DELETE', '/invoices/a');
        self::assertSame(['ok' => true, 'in_bin' => false], self::data($this->call('DELETE', '/invoices/a?permanent=1')));
        self::assertNull($this->invoices()->find('a'));
        self::assertSame([['AAMk-1', 'ATT-1']], $this->invoices()->skips());
        self::assertSame(
            ['invoice_binned', 'invoice_restored', 'invoice_binned', 'invoice_deleted_permanently'],
            array_map(static fn ($e): string => $e->action, $this->audit()->forEntity('invoice', 'a')),
        );
        self::assertSame(404, $this->call('POST', '/invoices/a/restore')->getStatusCode());
    }

    // ---- uploads -----------------------------------------------------------------------------------------------

    public function test_an_upload_answers_201_with_the_id_and_the_original_name(): void
    {
        $this->grant([self::WRITE]);

        $response = $this->upload('发票 Invoice.PDF', '%PDF-1.4');

        self::assertSame(201, $response->getStatusCode());
        $data = self::data($response);
        self::assertSame('发票 Invoice.PDF', $data['name']);
        self::assertIsString($data['upload_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['upload_id']);
    }

    public function test_an_upload_without_a_file_or_with_the_wrong_type_is_refused_with_the_sources_words(): void
    {
        $this->grant([self::WRITE]);

        $none = $this->call('POST', '/uploads');
        self::assertSame(422, $none->getStatusCode());
        self::assertSame(['FINANCE_AP_INPUT_INVALID', 'No file provided'], self::error($none));
        self::assertSame(['FINANCE_AP_INPUT_INVALID', 'No file provided'], self::error($this->upload('a.pdf', '%PDF', 'wrong')), 'the part must be named file');
        $exe = $this->upload('setup.exe', 'MZ');
        self::assertSame(422, $exe->getStatusCode());
        self::assertSame(['FINANCE_AP_INPUT_INVALID', 'Unsupported file type: setup.exe'], self::error($exe));
        self::assertSame(['FINANCE_AP_INPUT_INVALID', 'The file is empty.'], self::error($this->upload('a.pdf', '')));
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<string>
     */
    private static function jobNames(App $app): array
    {
        $registry = $app->getContainer()->get(FinanceJobRegistry::class);
        self::assertInstanceOf(FinanceJobRegistry::class, $registry);

        return array_map(static fn ($job): string => $job->name(), $registry->all());
    }

    public function test_the_sweep_of_unattached_uploads_is_registered_when_tri_ap_is_on_alone_and_only_once(): void
    {
        self::assertContains(FinanceUnattachedAttachmentsJob::NAME, self::jobNames($this->app));
        $both = TestAppFactory::create(self::ON + ['FINANCE_AR_ENABLED' => 'true']);
        self::assertSame(1, count(array_keys(self::jobNames($both), FinanceUnattachedAttachmentsJob::NAME, true)), 'Tri-AR and Tri-AP share the one sweep');
        $off = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true']);
        self::assertNotContains(FinanceUnattachedAttachmentsJob::NAME, self::jobNames($off));
    }

    // ---- the invoice's file ---------------------------------------------------------------------------------------

    public function test_the_file_is_served_with_the_locked_down_headers_as_a_download(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('a', attachment: 'Smith Invoice.pdf'), '%PDF-1.4 invented');
        $this->grant([self::READ]);

        $response = $this->call('GET', '/invoices/a/file');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('attachment; filename="Smith Invoice.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('%PDF-1.4 invented', self::binary($response));
    }

    public function test_inline_one_shows_a_pdf_png_or_jpeg_in_place_and_nothing_else(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('pdf', attachment: 'a.pdf'), 'x', 'application/pdf');
        $this->seed(ApInvoiceFixtures::invoice('png', attachment: 'a.png'), 'x', 'image/png');
        $this->seed(ApInvoiceFixtures::invoice('jpg', attachment: 'a.jpg'), 'x', 'image/jpeg');
        $this->seed(ApInvoiceFixtures::invoice('tiff', attachment: 'a.tiff'), 'x', 'image/tiff');
        $this->grant([self::READ]);

        foreach (['pdf' => 'inline', 'png' => 'inline', 'jpg' => 'inline', 'tiff' => 'attachment'] as $id => $kind) {
            $inline = $this->call('GET', '/invoices/' . $id . '/file?inline=1');
            self::assertStringStartsWith($kind . ';', $inline->getHeaderLine('Content-Disposition'), $id);
            self::assertSame('nosniff', $inline->getHeaderLine('X-Content-Type-Options'));
            self::assertSame("default-src 'none'; sandbox", $inline->getHeaderLine('Content-Security-Policy'));
        }
        self::assertStringStartsWith('attachment;', $this->call('GET', '/invoices/pdf/file?inline=true')->getHeaderLine('Content-Disposition'), 'only inline=1 shows it in place');
        self::assertStringStartsWith('attachment;', $this->call('GET', '/invoices/pdf/file')->getHeaderLine('Content-Disposition'));
    }

    public function test_the_header_name_is_ascii_safe_with_the_utf8_form_beside_it_and_never_a_path(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('a', attachment: '..\\..\\发票 "7".pdf'), 'x');
        $this->grant([self::READ]);

        $disposition = $this->call('GET', '/invoices/a/file')->getHeaderLine('Content-Disposition');

        self::assertStringStartsWith('attachment; filename="__ _7_.pdf"', $disposition);
        self::assertStringContainsString("filename*=UTF-8''" . rawurlencode('发票 _7_.pdf'), $disposition);
        self::assertStringNotContainsString('..', $disposition);
    }

    public function test_a_binned_invoices_file_is_served_and_no_document_or_lost_bytes_are_404(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('binned', deletedAt: ApInvoiceFixtures::utc('2026-10-04 00:00:00')), 'bytes');
        $this->seed(ApInvoiceFixtures::invoice('nodoc'));
        $this->seed(ApInvoiceFixtures::invoice('lost', fileId: 'file-the-store-lost'));
        $this->grant([self::READ]);

        self::assertSame(200, $this->call('GET', '/invoices/binned/file')->getStatusCode());
        $nodoc = $this->call('GET', '/invoices/nodoc/file');
        self::assertSame(404, $nodoc->getStatusCode());
        self::assertSame(['FINANCE_AP_NOT_FOUND', 'This invoice has no document'], self::error($nodoc));
        $lost = $this->call('GET', '/invoices/lost/file');
        self::assertSame(404, $lost->getStatusCode());
        self::assertSame(['FINANCE_AP_NOT_FOUND', 'The file is missing'], self::error($lost));
    }

    // ---- the download -------------------------------------------------------------------------------------------------

    public function test_one_invoice_downloads_as_the_file_with_the_filing_name_and_the_headers(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('a', vendor: 'DHL Express (Australia) Pty Ltd', invoiceDate: '2026-10-02', totalAmount: 162.10, costCentre: '890', attachment: 'scan.PDF'), '%PDF-1.4 invented');
        $this->grant([self::READ]);

        $response = $this->call('POST', '/invoices/download', ['ids' => ['a']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment; filename="DHL Express (Australia) Pty Ltd - 2026-10-02 - 162.10 - 890.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('0', $response->getHeaderLine('X-Invoices-Without-File'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('%PDF-1.4 invented', self::binary($response));
    }

    public function test_several_download_as_a_zip_and_count_the_ones_without_a_file(): void
    {
        $same = ['vendor' => 'Smith Freight', 'invoiceDate' => '2026-10-02', 'totalAmount' => 50.0, 'costCentre' => '890', 'attachment' => 'a.pdf'];
        $this->seed(ApInvoiceFixtures::invoice('first', ...$same), 'first');
        $this->seed(ApInvoiceFixtures::invoice('twin', ...$same), 'twin');
        $this->seed(ApInvoiceFixtures::invoice('nofile', attachment: 'x.pdf'));
        $this->grant([self::READ]);

        $response = $this->call('POST', '/invoices/download', ['ids' => ['first', 'twin', 'nofile']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('filename="AP invoices 20', $response->getHeaderLine('Content-Disposition'));
        self::assertMatchesRegularExpression('/\(2\)\.zip"/', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('1', $response->getHeaderLine('X-Invoices-Without-File'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            ['Smith Freight - 2026-10-02 - 50.00 - 890.pdf' => 'first', 'Smith Freight - 2026-10-02 - 50.00 - 890 (2).pdf' => 'twin'],
            ApInvoiceRulesTest::unzip(self::binary($response)),
        );
    }

    public function test_the_download_refusals(): void
    {
        $this->seed(ApInvoiceFixtures::invoice('nodoc'));
        $this->seed(ApInvoiceFixtures::invoice('mine', mailbox: 'christine@example.test', attachment: 'a.pdf'), 'x');
        $this->seed(ApInvoiceFixtures::invoice('theirs', mailbox: 'ap@example.test', attachment: 'b.pdf'), 'x');
        $this->grant([self::READ]);

        foreach ([[[], 'Select the invoices to download'], [['ids' => []], 'Select the invoices to download'], [['ids' => 'a'], 'Select the invoices to download'], [['ids' => array_map(static fn (int $n): string => 'id' . $n, range(1, 501))], 'Download up to 500 invoices at a time']] as [$body, $message]) {
            $response = $this->call('POST', '/invoices/download', $body);
            self::assertSame(422, $response->getStatusCode(), $message);
            self::assertSame(['FINANCE_AP_INPUT_INVALID', $message], self::error($response));
        }
        $unknown = $this->call('POST', '/invoices/download', ['ids' => ['mine', 'ghost']]);
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(['FINANCE_AP_NOT_FOUND', 'Not found'], self::error($unknown));
        $none = $this->call('POST', '/invoices/download', ['ids' => ['nodoc']]);
        self::assertSame(404, $none->getStatusCode());
        self::assertSame(['FINANCE_AP_NOT_FOUND', 'None of the selected invoices has a document to download'], self::error($none));

        $this->scope('christine@example.test');
        $outOfScope = $this->call('POST', '/invoices/download', ['ids' => ['mine', 'theirs']]);
        self::assertSame(404, $outOfScope->getStatusCode(), 'one invoice out of scope fails the whole request');
        self::assertSame(['FINANCE_AP_NOT_FOUND', 'Not found'], self::error($outOfScope));
        self::assertSame(200, $this->call('POST', '/invoices/download', ['ids' => ['mine']])->getStatusCode());
    }
}
