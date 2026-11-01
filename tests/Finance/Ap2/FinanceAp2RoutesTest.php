<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Ap2\InMemoryAp2ApprovalStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2HistoryStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2InvoiceStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2PersonDirectory;
use TriCab\Api\Finance\Ap2\InMemoryAp2SupplierStore;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;
use TriCab\Api\Finance\Odoo\InMemoryOdooRecordStore;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordRow;
use TriCab\Core\Identity\Contract\UserRepository;

/** The Tri-AP routes through the real app on the in-memory stores. The person is platform user 1. */
final class FinanceAp2RoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ap2';

    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true']);
        $this->grant([Module::PERMISSION_AP2_ACCESS, ...Module::AP2_PERMISSIONS]);
    }

    /** @param list<string> $permissions */
    private function grant(array $permissions): void
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function store(string $contract, string $class): object
    {
        $store = $this->app->getContainer()->get($contract);
        self::assertInstanceOf($class, $store);

        return $store;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    /**
     * @param array<array-key, mixed> $json
     * @return array<array-key, mixed>
     */
    private function data(int $status, array $json, int $expected = 200): array
    {
        self::assertSame($expected, $status, json_encode($json, JSON_THROW_ON_ERROR));
        $data = $json['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /** A calendar day relative to today in the Tri-AP zone, so the tests do not age. */
    private function day(int $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    private function supplier(?Ap2Supplier $s = null): Ap2Supplier
    {
        $s ??= Ap2Fixtures::supplier();
        $this->store(Ap2SupplierStore::class, InMemoryAp2SupplierStore::class)->insert($s);

        return $s;
    }

    private function invoice(Ap2Invoice $b): Ap2Invoice
    {
        $this->store(Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->insert($b);

        return $b;
    }

    public function test_me_returns_full_permission_codes_settings_and_flags(): void
    {
        [$status, $json] = $this->call('GET', '/me');
        $data = $this->data($status, $json);

        self::assertContains('FINANCE.AP2.INVOICES.WRITE', Ap2Json::arr($data, 'permissions'));
        self::assertSame(['AUD', 'USD', 'NZD', 'SGD'], Ap2Json::get($data, 'settings.currencies'));
        self::assertSame('6000', Ap2Json::get($data, 'settings.glDefault'));
        self::assertSame(['sending' => false, 'odooPosting' => false, 'ai' => false], $data['flags']);
        self::assertSame(1, Ap2Json::get($data, 'user.userId'));
    }

    public function test_the_routes_are_absent_while_the_ap2_switch_is_off(): void
    {
        $app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true']);
        $users = $app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_AP2_ACCESS], ['EMPLOYEE']);

        self::assertSame(404, $app->handle(TestAppFactory::request('GET', self::BASE . '/me'))->getStatusCode());
    }

    public function test_the_finance_access_summary_lists_ap2(): void
    {
        $response = $this->app->handle(TestAppFactory::request('GET', '/api/v1/finance/me'));
        $apps = Ap2Json::arr(TestAppFactory::json($response), 'data.apps');
        $ap2 = array_values(array_filter($apps, static fn (mixed $a): bool => Ap2Json::get($a, 'key') === 'AP2'));

        self::assertSame([['key' => 'AP2', 'name' => 'Tri-AP', 'path' => 'ap', 'enabled' => true, 'canAccess' => true]], $ap2);
    }

    public function test_supplier_create_get_patch_and_history(): void
    {
        $this->store(Ap2PersonDirectory::class, InMemoryAp2PersonDirectory::class)->add(new Ap2Person(9, 'Priya Shah', 'priya@tricab.com'), [Module::PERMISSION_AP2_ACCESS]);

        [$status, $json] = $this->call('POST', '/suppliers', ['name' => ' Metro Freight ', 'terms' => 14, 'email' => 'billing@metro.example', 'ownerUserId' => 9, 'bankVerified' => true]);
        $created = $this->data($status, $json, 201);
        self::assertSame('Metro Freight', $created['name']);
        self::assertFalse($created['bankVerified'], 'bank state is not settable through a supplier write');
        self::assertSame(['userId' => 9, 'name' => 'Priya Shah', 'email' => 'priya@tricab.com'], $created['owner']);
        self::assertEquals(0, $created['balance']);

        [$status, $json] = $this->call('PATCH', '/suppliers/' . Ap2Json::str($created, 'id'), ['hold' => true, 'notes' => 'Chase receipts', 'ownerUserId' => null]);
        $patched = $this->data($status, $json);
        self::assertTrue($patched['hold']);
        self::assertNull($patched['owner']);

        [$status, $json] = $this->call('POST', '/suppliers/' . Ap2Json::str($created, 'id') . '/history', ['type' => 'Call', 'detail' => 'Spoke to accounts']);
        $this->data($status, $json, 201);

        [$status, $json] = $this->call('GET', '/suppliers/' . Ap2Json::str($created, 'id') . '/history');
        $types = array_map(static fn (mixed $e): string => Ap2Json::str($e, 'type'), $this->listOf($status, $json));
        self::assertSame(['Call', 'Supplier details updated', 'Supplier created'], $types, 'newest first, every write recorded');

        [$status, $json] = $this->call('GET', '/suppliers/' . Ap2Json::str($created, 'id') . '/history?from=2999-01-01');
        self::assertSame([], $this->listOf($status, $json));

        [$status, $json] = $this->call('GET', '/suppliers');
        self::assertCount(1, $this->listOf($status, $json));
    }

    /**
     * @param array<array-key, mixed> $json
     * @return list<mixed>
     */
    private function listOf(int $status, array $json): array
    {
        self::assertSame(200, $status);
        $data = $json['data'];
        self::assertIsArray($data);

        return array_values($data);
    }

    public function test_supplier_validation_and_not_found(): void
    {
        [$status, $json] = $this->call('POST', '/suppliers', ['name' => '', 'terms' => 9999, 'email' => 'nope']);
        self::assertSame(422, $status);
        self::assertSame('FINANCE_AP2_INPUT_INVALID', Ap2Json::get($json, 'error.code'));
        self::assertContains('name is required', Ap2Json::arr($json, 'error.details.issues'));
        self::assertCount(3, Ap2Json::arr($json, 'error.details.issues'));

        [$status] = $this->call('POST', '/suppliers', ['name' => 'X', 'ownerUserId' => 99]);
        self::assertSame(422, $status, 'an owner must be a Tri-AP user');

        [$status, $json] = $this->call('GET', '/suppliers/' . str_repeat('a', 32));
        self::assertSame([404, 'FINANCE_AP2_NOT_FOUND'], [$status, Ap2Json::get($json, 'error.code')]);
        [$status] = $this->call('POST', '/suppliers/' . str_repeat('a', 32) . '/history', ['type' => 'Call', 'detail' => 'x']);
        self::assertSame(404, $status);
    }

    public function test_the_history_action_needs_a_known_type_and_text(): void
    {
        $s = $this->supplier();
        [$status, $json] = $this->call('POST', '/suppliers/' . $s->id . '/history', ['type' => 'Gossip']);
        self::assertSame(422, $status);
        self::assertCount(2, Ap2Json::arr($json, 'error.details.issues'));
    }

    public function test_invoice_create_computes_everything_on_the_server(): void
    {
        $s = $this->supplier();
        [$status, $json] = $this->call('POST', '/invoices', [
            'supplierId' => $s->id, 'number' => 'CM-2200', 'net' => 20000, 'tax' => 2000, 'issued' => '2026-10-16', 'due' => '2026-11-02',
            'kind' => 'Goods PO', 'po' => 'PO-1001', 'quantity' => 1000, 'orderedQty' => 1000, 'receivedQty' => 900, 'poUnitPrice' => 20,
            'receiptRef' => 'GRN-1', 'verified' => true, 'costCentre' => 'Operations', 'currency' => 'USD',
        ]);
        $b = $this->data($status, $json, 201);

        self::assertEquals(22000, $b['total'], 'total defaults to net + tax');
        self::assertSame('6000', $b['gl'], 'the settings default');
        self::assertSame('Tricab Australia', $b['entity'], 'the supplier entity');
        self::assertSame(['Quantity shortfall / credit mismatch'], $b['validation']);
        self::assertSame(['Quantity shortfall / credit mismatch'], $b['matching']);
        self::assertSame('Needs verification', $b['stage']);
        self::assertSame('Needs review', $b['queue']);
        self::assertFalse($b['postable']);
        self::assertFalse($b['approvalRequired']);
        self::assertTrue($b['approvalCurrent'], 'no business approval required');
        self::assertSame('OK', $b['businessApproval']);
        self::assertSame('triap_' . Ap2Json::str($b, 'id'), $b['externalId']);
        self::assertSame('', $b['journal']);
        self::assertEquals([['label' => 'CM-2200', 'account' => '6000', 'quantity' => 1000, 'unitPrice' => 20, 'taxes' => 'GST10']], $b['invoiceLines'], 'the default line from the invoice coding');
        self::assertFalse($b['locked']);
        self::assertSame('Cable Materials Pty Ltd', $b['supplierName']);

        // CN-2200 arrives: the server re-validates the PATCH and the invoice now matches.
        [$status, $json] = $this->call('PATCH', '/invoices/' . Ap2Json::str($b, 'id'), ['credit' => 2200, 'creditRef' => 'CN-2200', 'creditNet' => 2000, 'creditTax' => 200, 'creditResolved' => true]);
        $fixed = $this->data($status, $json);
        self::assertSame([], $fixed['validation']);
        self::assertSame('Ready to import', $fixed['queue'], 'no business approval required');
        self::assertSame('Confirmed', $fixed['stage']);
        self::assertEquals(19800, $fixed['balance']);

        $history = array_map(static fn ($e) => $e->type, $this->store(Ap2HistoryStore::class, InMemoryAp2HistoryStore::class)->all());
        self::assertSame(['Invoice created', 'Invoice review saved'], $history);
    }

    public function test_invoice_input_is_refused_with_the_reasons(): void
    {
        [$status, $json] = $this->call('POST', '/invoices', ['number' => 'A', 'currency' => 'EUR', 'net' => -5, 'issued' => '2026-02-30', 'kind' => 'Mystery']);
        self::assertSame(422, $status);
        $issues = Ap2Json::arr($json, 'error.details.issues');
        self::assertContains('supplierId is required', $issues);
        self::assertContains('currency must be one of AUD, USD, NZD, SGD', $issues);
        self::assertContains('net must be a number of zero or more', $issues);
        self::assertContains('issued must be a date as yyyy-mm-dd', $issues);

        [$status] = $this->call('POST', '/invoices', ['supplierId' => str_repeat('b', 32), 'number' => 'A']);
        self::assertSame(422, $status);
        [$status] = $this->call('GET', '/invoices/' . str_repeat('c', 32));
        self::assertSame(404, $status);
        [$status] = $this->call('GET', '/invoices/not-an-id');
        self::assertSame(404, $status);
    }

    public function test_a_save_that_changes_the_fingerprint_clears_the_approval_and_one_that_does_not_keeps_it(): void
    {
        $s = $this->supplier();
        $b = $this->invoice(Ap2Fixtures::invoice($s)->with(approvalRequired: true));
        $this->store(Ap2ApprovalStore::class, InMemoryAp2ApprovalStore::class)->addRef(Ap2Fixtures::approved($b, $s));
        $this->store(Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->update($b->with(approvalId: 'a1'));

        [$status, $json] = $this->call('GET', '/invoices/' . $b->id);
        $current = $this->data($status, $json);
        self::assertTrue($current['approvalCurrent']);
        self::assertSame('Approved', $current['businessApproval']);
        self::assertSame('Ready to import', $current['queue']);
        self::assertTrue($current['postable']);

        [$status, $json] = $this->call('PATCH', '/invoices/' . $b->id, ['paymentHold' => true]);
        $held = $this->data($status, $json);
        self::assertSame('a1', $held['approvalId'], 'a payment hold is not part of the approved values');
        self::assertTrue($held['approvalCurrent']);

        [$status, $json] = $this->call('PATCH', '/invoices/' . $b->id, ['gl' => '7000']);
        $edited = $this->data($status, $json);
        self::assertNull($edited['approvalId']);
        self::assertFalse($edited['approvalCurrent']);
        self::assertSame('Required', $edited['businessApproval']);
        self::assertFalse($edited['postable']);
        self::assertSame('Confirmed', $edited['stage']);

        $types = array_map(static fn ($e) => $e->type, $this->store(Ap2HistoryStore::class, InMemoryAp2HistoryStore::class)->all());
        self::assertSame(['Payment hold set', 'Invoice review saved', 'Approval superseded by edit'], $types);
    }

    public function test_a_posted_invoice_accepts_only_the_payment_hold(): void
    {
        $s = $this->supplier();
        $b = $this->invoice(Ap2Fixtures::invoice($s, importState: 'Posted', odooRef: 'BILL/1'));

        [$status, $json] = $this->call('PATCH', '/invoices/' . $b->id, ['gl' => '7000', 'net' => 5, 'paymentHold' => true]);
        self::assertSame(409, $status);
        self::assertSame('FINANCE_AP2_RULE_FAILED', Ap2Json::get($json, 'error.code'));
        self::assertCount(2, Ap2Json::arr($json, 'error.details.issues'));

        [$status, $json] = $this->call('PATCH', '/invoices/' . $b->id, ['paymentHold' => true]);
        $held = $this->data($status, $json);
        self::assertTrue($held['paymentHold']);
        self::assertTrue($held['locked']);
        self::assertSame('Posted', $held['stage']);
        self::assertContains('Payment hold', Ap2Json::arr($held, 'paymentIssues'));

        $stored = $this->store(Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->find($b->id);
        self::assertSame('6000', $stored?->gl, 'the locked fields did not move');
    }

    public function test_exclude_duplicate_retains_the_record_and_clears_the_survivor(): void
    {
        $s = $this->supplier(Ap2Fixtures::supplier('S003', 'Office Hub'));
        $b003 = $this->invoice(Ap2Fixtures::invoice($s, 'B003', 'OH-004'));
        $b005 = $this->invoice(Ap2Fixtures::invoice($s, 'B005', 'OH-004'));

        [$status, $json] = $this->call('GET', '/invoices?queue=Duplicate');
        self::assertCount(2, $this->listOf($status, $json));
        [$status, $json] = $this->call('GET', '/invoices/' . $b003->id);
        self::assertSame([$b005->id], $this->data($status, $json)['duplicateOf']);

        [$status, $json] = $this->call('POST', '/invoices/' . $b005->id . '/exclude-duplicate', []);
        self::assertSame(422, $status, 'a reason is required');

        [$status, $json] = $this->call('POST', '/invoices/' . $b005->id . '/exclude-duplicate', ['reason' => 'Resent copy of OH-004']);
        $excluded = $this->data($status, $json);
        self::assertTrue($excluded['excluded']);
        self::assertSame('Excluded', $excluded['queue']);
        self::assertSame('Needs verification', $excluded['stage'], 'exclusion is its own flag, not an invoice state');
        self::assertSame('Resent copy of OH-004', $excluded['excludedReason']);

        [$status, $json] = $this->call('GET', '/invoices/' . $b003->id);
        $survivor = $this->data($status, $json);
        self::assertSame([], $survivor['duplicateOf']);
        self::assertSame('Ready to import', $survivor['queue'], 'clean and needing no approval');

        [$status] = $this->call('POST', '/invoices/' . $b005->id . '/exclude-duplicate', ['reason' => 'again']);
        self::assertSame(409, $status);
        [$status] = $this->call('POST', '/invoices/' . $b003->id . '/exclude-duplicate', ['reason' => 'not a dup now']);
        self::assertSame(409, $status, 'no longer a suspected duplicate');

        [$status, $json] = $this->call('GET', '/invoices');
        self::assertCount(2, $this->listOf($status, $json), 'the excluded invoice stays in the register');
        $types = array_map(static fn ($e) => $e->type, $this->store(Ap2HistoryStore::class, InMemoryAp2HistoryStore::class)->all());
        self::assertSame(['Duplicate excluded'], $types);
    }

    public function test_the_posted_invoice_cannot_be_excluded(): void
    {
        $s = $this->supplier();
        $this->invoice(Ap2Fixtures::invoice($s, 'B001', 'DUP-1', importState: 'Posted'));
        $b = $this->invoice(Ap2Fixtures::invoice($s, 'B002', 'DUP-1', importState: 'Posted'));

        [$status] = $this->call('POST', '/invoices/' . $b->id . '/exclude-duplicate', ['reason' => 'x']);
        self::assertSame(409, $status);
    }

    public function test_the_queue_filter_uses_the_dashboard_queue_names(): void
    {
        $s = $this->supplier();
        $this->invoice(Ap2Fixtures::invoice($s, 'B001', 'A-1', issued: '2000-01-01', due: $this->day(2), importState: 'Posted', odooRef: 'x'));
        $this->invoice(Ap2Fixtures::invoice($s, 'B002', 'A-2', verified: false));
        $this->invoice(Ap2Fixtures::invoice($s, 'B003', 'A-3', importState: 'Import failed'));
        foreach (['B001', 'B003'] as $id) {
            $one = $this->store(Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->find(md5($id));
            self::assertNotNull($one);
            $this->store(Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->update($one->with(approvalRequired: true));
        }

        $count = fn (string $queue): int => count($this->listOf(...$this->call('GET', '/invoices?queue=' . rawurlencode($queue))));
        self::assertSame(1, $count('Due in 5 days'));
        self::assertSame(0, $count('Open AP'), 'a required, unapproved approval makes it Approval until approved');
        self::assertSame(1, $count('Needs review'));
        self::assertSame(1, $count('Sync failed'));
        self::assertSame(0, $count('Awaiting matching'));
        self::assertSame(3, count($this->listOf(...$this->call('GET', '/invoices'))));
        self::assertSame(422, $this->call('GET', '/invoices?queue=Nonsense')[0]);
        self::assertSame(2, $count('Approval'), 'the two unposted invoices that pass every check');
    }

    public function test_the_dashboard(): void
    {
        $s = $this->supplier();
        $hold = $this->supplier(Ap2Fixtures::supplier('S002', 'Held Co', false, true, true));
        $this->invoice(Ap2Fixtures::invoice($s, 'B001', 'A-1', issued: '2000-01-01', due: $this->day(2), importState: 'Posted')); // open 1,100, not yet due
        $this->invoice(Ap2Fixtures::invoice($s, 'B002', 'A-2', issued: '2000-01-01', due: $this->day(-20), importState: 'Posted')); // 20 days overdue
        $this->invoice(Ap2Fixtures::invoice($s, 'B003', 'A-3', due: $this->day(-100), issued: '2000-01-01', importState: 'Posted', paid: 1100.0)); // settled
        $this->invoice(Ap2Fixtures::invoice($hold, 'B004', 'H-1', issued: '2000-01-01', due: $this->day(1), importState: 'Posted')); // held
        $this->invoice(Ap2Fixtures::invoice($s, 'B005', 'A-5', due: $this->day(-70), issued: '2000-01-01', importState: 'Posted')); // 70 days
        $this->store(Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->withPoForecast(new \TriCab\Core\Finance\Ap2\Domain\Ap2PoForecastRow('PO-1010', $s->id, 4800.0, 'AUD', $this->day(12)));

        [$status, $json] = $this->call('GET', '/dashboard');
        $d = $this->data($status, $json);

        self::assertSame(2, Ap2Json::get($d, 'kpis.suppliers'));
        self::assertEquals(4400, Ap2Json::get($d, 'kpis.openAp'));
        self::assertEquals(2200, Ap2Json::get($d, 'kpis.overdueAp'));
        self::assertEquals(1100, Ap2Json::get($d, 'kpis.outflow5d'), 'only the unblocked invoice due within 5 days');
        self::assertEquals(['current' => 2200, 'd1_30' => 1100, 'd31_60' => 0, 'd61_90' => 1100, 'd90p' => 0], $d['ageing']);
        self::assertSame(1, Ap2Json::get($d, 'queues.paymentHold'), 'the open invoice of the held supplier');
        self::assertSame(2, Ap2Json::get($d, 'queues.due5d'));
        self::assertSame(0, Ap2Json::get($d, 'queues.syncFailed'));
        self::assertSame($s->id, Ap2Json::get($d, 'topSuppliers.0.supplierId'));
        self::assertSame(['supplierId', 'name', 'total'], array_keys(Ap2Json::arr($d, 'topSuppliers.0')));
        $kinds = array_column(Ap2Json::arr($d, 'forecast'), 'kind');
        self::assertContains('PO estimate', $kinds);
        self::assertSame(['id', 'source', 'supplierId', 'supplierName', 'date', 'amount', 'currency', 'kind', 'blocked', 'invoiceId'], array_keys(Ap2Json::arr($d, 'forecast.0')));
    }

    public function test_users_and_priorities(): void
    {
        $directory = $this->store(Ap2PersonDirectory::class, InMemoryAp2PersonDirectory::class);
        $directory->add(new Ap2Person(9, 'Priya Shah', 'priya@tricab.com'), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE]);
        $directory->add(new Ap2Person(10, 'Alex Chen', 'alex@tricab.com'), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_INVOICES_WRITE]);

        [$status, $json] = $this->call('GET', '/users?permission=APPROVALS.DECIDE');
        self::assertSame([['userId' => 9, 'name' => 'Priya Shah', 'email' => 'priya@tricab.com']], $this->listOf($status, $json));
        self::assertSame(422, $this->call('GET', '/users?permission=ROOT')[0]);
        self::assertSame(422, $this->call('GET', '/users')[0]);

        self::assertSame([], $this->data(...$this->call('GET', '/priorities')));
        [$status, $json] = $this->call('PUT', '/priorities/invoice/abc', ['flag' => true]);
        self::assertSame(['flag' => true, 'pin' => false], $this->data($status, $json));
        [$status, $json] = $this->call('PUT', '/priorities/invoice/abc', ['pin' => true]);
        self::assertSame(['flag' => true, 'pin' => true], $this->data($status, $json));
        self::assertSame(['invoice:abc' => ['flag' => true, 'pin' => true]], $this->data(...$this->call('GET', '/priorities')));
        $this->call('PUT', '/priorities/invoice/abc', ['flag' => false, 'pin' => false]);
        self::assertSame([], $this->data(...$this->call('GET', '/priorities')));
        self::assertSame(422, $this->call('PUT', '/priorities/rocket/abc', ['flag' => true])[0]);
    }

    public function test_money_in_other_currencies_is_converted_only_with_a_rate_and_otherwise_reported(): void
    {
        $s = $this->supplier();
        $this->invoice(Ap2Fixtures::invoice($s, 'B001', 'U-1', currency: 'USD', importState: 'Posted', issued: '2000-01-01', due: $this->day(30)));

        [$status, $json] = $this->call('GET', '/dashboard');
        $d = $this->data($status, $json);
        self::assertEquals(1100, Ap2Json::get($d, 'kpis.openAp'));
        self::assertSame(['USD'], Ap2Json::get($d, 'fx.unconverted'));
    }

    public function test_the_export_routes_make_and_download_the_csv(): void
    {
        $s = $this->supplier();
        $b = $this->invoice(Ap2Fixtures::invoice($s, 'B070', 'R-1', verified: false));

        [$status, $json] = $this->call('POST', '/exports', ['invoiceIds' => [$b->id]]);
        $data = $this->data($status, $json, 201);
        self::assertSame('CSV-0001', $data['id']);
        self::assertStringContainsString('"triap_' . $b->id . '","Cable Materials Pty Ltd","1/10/2026"', Ap2Json::str($data, 'csv'), 'unverified still exports');

        $response = $this->app->handle(TestAppFactory::request('GET', self::BASE . '/exports/CSV-0001/csv'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith("\xEF\xBB\xBF\"External ID\"", (string) $response->getBody());
        self::assertSame(404, $this->call('GET', '/exports/CSV-0042/csv')[0]);
        self::assertSame(1, count($this->listOf(...$this->call('GET', '/exports'))));
    }

    public function test_odoo_taxes_lists_synced_purchase_taxes_only(): void
    {
        self::assertSame([], $this->listOf(...$this->call('GET', '/odoo/taxes')), 'never synced');

        $store = $this->store(\TriCab\Core\Finance\Shared\Odoo\OdooRecordStore::class, InMemoryOdooRecordStore::class);
        $at = new \DateTimeImmutable('2026-10-26 00:00:00');
        $add = static function (int $id, string $name, string $use) use ($store, $at): void {
            $payload = ['name' => $name, 'description' => 'GST', 'amount' => 10, 'amount_type' => 'percent', 'type_tax_use' => $use, 'company_id' => 2, 'company_name' => 'Tricab Australia'];
            $store->insert(new OdooRecordRow(OdooLists::TAXES, $id, $name, null, 2, true, json_encode($payload, JSON_THROW_ON_ERROR), null), $at);
        };
        $add(11, '10% GST', 'purchase');
        $add(12, '10% GST sales', 'sale');

        $rows = $this->listOf(...$this->call('GET', '/odoo/taxes'));
        self::assertEquals([['id' => 11, 'name' => '10% GST', 'description' => 'GST', 'amount' => 10.0, 'amountType' => 'percent', 'companyId' => 2, 'companyName' => 'Tricab Australia']], $rows);
    }

    public function test_settings_notifications_and_statements_routes(): void
    {
        [$status, $json] = $this->call('PATCH', '/settings', ['batchCashLimit' => 5000]);
        self::assertEquals(5000, $this->data($status, $json)['batchCashLimit']);
        self::assertTrue(Ap2Json::get($this->data(...$this->call('GET', '/me')), 'settings.paymentProposalsEnabled'));
        self::assertSame(422, $this->call('PATCH', '/settings', ['mailbox' => 'other@x.com'])[0]);
        self::assertSame([], $this->listOf(...$this->call('GET', '/notifications')));
        self::assertSame(['read' => true], $this->data(...$this->call('POST', '/notifications/read-all', [])));
        self::assertSame([], $this->listOf(...$this->call('GET', '/reports/aged')));
        self::assertSame([], $this->listOf(...$this->call('GET', '/payments')));
        self::assertSame([], $this->listOf(...$this->call('GET', '/approvals')));

        $s = $this->supplier();
        [$status, $json] = $this->call('POST', '/statements', ['supplierId' => $s->id, 'date' => '2026-10-20', 'amount' => 10, 'reference' => 'X']);
        $this->data($status, $json, 201);
        self::assertCount(1, $this->listOf(...$this->call('GET', '/statements')));
    }
}
