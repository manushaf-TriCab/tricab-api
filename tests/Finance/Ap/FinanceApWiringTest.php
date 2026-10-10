<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\InMemoryApSigningLimitStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap\Domain\ApSigningLimit;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Ap\Contract\ApSigningLimitStore;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * Tri-AP's routes through the real app on the in-memory stores (T6.3): the switch, the permission each
 * route needs and nothing else, the 403 and 404 cases, and what each action answers. Nothing here
 * reaches a mailbox, a model, Odoo or a database.
 */
final class FinanceApWiringTest extends TestCase
{
    private const ON = ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true'];
    private const BASE = '/api/v1/finance/ap';

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(array $env = self::ON): App
    {
        return TestAppFactory::create($env);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissions
     */
    private function grant(App $app, array $permissions): void
    {
        $users = $app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(App $app, string $method, string $path, ?array $body = null): array
    {
        $request = TestAppFactory::request($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function id(array $row): string
    {
        self::assertIsString($row['id']);

        return $row['id'];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function data(array $json): array
    {
        $data = $json['data'];
        self::assertIsArray($data);
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function error(array $json): array
    {
        $error = $json['error'];
        self::assertIsArray($error);
        $out = [];
        foreach ($error as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    public function test_with_the_ap_switch_off_nothing_of_tri_ap_is_reachable(): void
    {
        foreach ([['FINANCE_MODULE_ENABLED' => 'true'], ['FINANCE_AP_ENABLED' => 'true'], [], ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AR_ENABLED' => 'true']] as $env) {
            $app = $this->app($env);
            $this->grant($app, [Module::PERMISSION_AP_ACCESS, ...Module::AP_PERMISSIONS]);
            foreach (['/me', '/mailboxes', '/signing-authority', '/companies', '/cost-centres', '/account-categories', '/invoices', '/invoices/x', '/invoices/x/file'] as $path) {
                [$status] = $this->call($app, 'GET', self::BASE . $path);
                self::assertSame(404, $status, json_encode($env, JSON_THROW_ON_ERROR) . ' ' . $path);
            }
            [$status] = $this->call($app, 'POST', self::BASE . '/companies', ['name' => 'X']);
            self::assertSame(404, $status);
        }
    }

    public function test_the_scan_resolves_on_the_in_memory_defaults_with_its_settings_and_has_no_job(): void
    {
        $container = $this->app(self::ON + ['FINANCE_AP_MIN_ATTACHMENT_BYTES' => '1000', 'FINANCE_AP_MAX_SCAN_MESSAGES' => '25'])->getContainer();

        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApScanStore::class, $container->get(\TriCab\Core\Finance\Ap\Contract\ApScanStore::class));
        $service = $container->get(\TriCab\Core\Finance\Ap\Service\ApScanService::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApScanService::class, $service);
        self::assertSame($service, $container->get(\TriCab\Core\Finance\Ap\Service\ApScanService::class));
        $settings = \TriCab\Api\Config\Settings::fromEnvironment(['FINANCE_AP_MIN_ATTACHMENT_BYTES' => '1000', 'FINANCE_AP_MAX_SCAN_MESSAGES' => '25']);
        self::assertSame([1000, 25], [$settings->financeApMinAttachmentBytes, $settings->financeApMaxScanMessages]);
        $bad = \TriCab\Api\Config\Settings::fromEnvironment(['FINANCE_AP_MIN_ATTACHMENT_BYTES' => '-5', 'FINANCE_AP_MAX_SCAN_MESSAGES' => 'many']);
        self::assertSame([0, 0], [$bad->financeApMinAttachmentBytes, $bad->financeApMaxScanMessages]);

        $defaults = \TriCab\Api\Config\Settings::fromEnvironment([]);
        self::assertSame([25600, 0], [$defaults->financeApMinAttachmentBytes, $defaults->financeApMaxScanMessages]);
        // Q6: nothing scans on a timer for Tri-AP.
        $registry = $container->get(\TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry::class, $registry);
        $names = array_map(static fn ($job): string => $job->name(), $registry->all());
        self::assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_contains(strtolower($name), 'ap'))));
    }

    public function test_the_vendor_and_extraction_services_resolve_on_the_in_memory_defaults(): void
    {
        $container = $this->app()->getContainer();
        $store = $container->get(\TriCab\Core\Finance\Ap\Contract\ApVendorStore::class);
        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApVendorStore::class, $store);
        foreach ([
            \TriCab\Core\Finance\Ap\ApAiService::class,
            \TriCab\Core\Finance\Ap\Service\ApVendorService::class,
            \TriCab\Core\Finance\Ap\Service\ApInvoiceVendorService::class,
            \TriCab\Core\Finance\Ap\Service\ApExtractService::class,
        ] as $class) {
            self::assertInstanceOf($class, $container->get($class));
        }
        self::assertSame($container->get(\TriCab\Core\Finance\Ap\Service\ApVendorService::class), $container->get(\TriCab\Core\Finance\Ap\Service\ApVendorService::class));
    }

    public function test_the_register_services_resolve_on_the_in_memory_stores_and_share_one_of_each(): void
    {
        $container = $this->app()->getContainer();

        $invoices = $container->get(\TriCab\Core\Finance\Ap\Contract\ApInvoiceStore::class);
        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApInvoiceStore::class, $invoices);
        self::assertSame($invoices, $container->get(\TriCab\Core\Finance\Ap\Contract\ApInvoiceStore::class));
        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApAuditStore::class, $container->get(\TriCab\Core\Finance\Ap\Contract\ApAuditStore::class));
        foreach ([\TriCab\Core\Finance\Ap\Service\ApInvoiceService::class, \TriCab\Core\Finance\Ap\Service\ApAttachmentService::class, \TriCab\Core\Finance\Ap\Service\ApInvoiceDownloadService::class, \TriCab\Core\Finance\Ap\Service\ApInvoiceGuard::class] as $class) {
            self::assertInstanceOf($class, $container->get($class));
        }
        // The file store's guard sees the same invoices as the service.
        $invoices->insert(new \TriCab\Core\Finance\Ap\Domain\ApInvoice('inv-1', new \DateTimeImmutable('2026-10-09'), new \DateTimeImmutable('2026-10-09')));
        $guard = $container->get(\TriCab\Core\Finance\Ap\Service\ApInvoiceGuard::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApInvoiceGuard::class, $guard);
        self::assertTrue($guard->maySee('AP', 'invoice', 'inv-1'));
        self::assertFalse($guard->maySee('AP', 'invoice', 'inv-2'));
        self::assertFalse($guard->maySee('AR', 'invoice', 'inv-1'));
        self::assertFalse($guard->maySee('AP', 'email_record', 'inv-1'));
    }

    public function test_me_answers_the_portal_strings_the_role_the_scope_and_the_sending_switch(): void
    {
        $app = $this->app(self::ON + ['FINANCE_AP_SENDING_ENABLED' => 'true']);
        $this->grant($app, [Module::PERMISSION_AP_ACCESS, Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_APPROVALS_PREPARE]);
        $scopes = $app->getContainer()->get(FinanceAccessScopeReader::class);
        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $scopes);
        $scopes->grant(1, 'AP', FinanceAccessScope::TYPE_MAILBOX, 'Christine@Example.test');

        [$status, $json] = $this->call($app, 'GET', self::BASE . '/me');

        self::assertSame(200, $status);
        self::assertTrue($json['success']);
        $data = self::data($json);
        self::assertSame('1', $data['id']);
        self::assertSame(['invoice.approvals.prepare', 'invoice.invoices.read'], $data['permissions']);
        self::assertSame('user', $data['role']);
        self::assertSame('preparer', $data['finance_role']);
        self::assertSame(['christine@example.test'], $data['allowed_mailboxes']);
        self::assertTrue($data['sending_enabled']);
    }

    public function test_me_defaults_to_every_mailbox_and_sending_off(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP_ACCESS]);

        [, $json] = $this->call($app, 'GET', self::BASE . '/me');

        $data = self::data($json);
        self::assertNull($data['allowed_mailboxes']);
        self::assertFalse($data['sending_enabled']);
        self::assertSame([], $data['permissions']);
    }

    public function test_an_administrator_is_role_admin_and_unrestricted_whatever_scope_rows_they_have(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP_ACCESS, Module::PERMISSION_AP_SETTINGS_MANAGE]);
        $scopes = $app->getContainer()->get(FinanceAccessScopeReader::class);
        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $scopes);
        $scopes->grant(1, 'AP', FinanceAccessScope::TYPE_MAILBOX, 'christine@example.test');

        [, $json] = $this->call($app, 'GET', self::BASE . '/me');

        $data = self::data($json);
        self::assertSame('admin', $data['role']);
        self::assertNull($data['allowed_mailboxes']);
    }

    public function test_a_scope_for_another_app_does_not_limit_tri_ap(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP_ACCESS]);
        $scopes = $app->getContainer()->get(FinanceAccessScopeReader::class);
        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $scopes);
        $scopes->grant(1, 'AR', FinanceAccessScope::TYPE_MAILBOX, 'ar@example.test');

        [, $json] = $this->call($app, 'GET', self::BASE . '/me');

        self::assertNull(self::data($json)['allowed_mailboxes']);
    }

    public function test_me_is_refused_without_the_app_permission(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_SETTINGS_MANAGE]);

        [$status, $json] = $this->call($app, 'GET', self::BASE . '/me');

        self::assertSame(403, $status);
        self::assertFalse($json['success']);
    }

    public function test_mailboxes_come_from_the_registry_limited_to_the_scope_and_need_the_invoices_read_permission(): void
    {
        $app = $this->app();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ap@example.test', 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m2', 'christine@example.test', 'Christine', 'AP', true, ['AP' => ['read' => true, 'send' => false]]),
            new FinanceMailbox('m3', 'ar@example.test', 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => true]]),
        ]));

        $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ]);
        [$status, $json] = $this->call($app, 'GET', self::BASE . '/mailboxes');
        self::assertSame(200, $status);
        self::assertSame([
            ['id' => 'm1', 'email' => 'ap@example.test', 'label' => 'Accounts Payable', 'is_active' => true, 'can_read' => true, 'can_send' => true],
            ['id' => 'm2', 'email' => 'christine@example.test', 'label' => 'Christine', 'is_active' => true, 'can_read' => true, 'can_send' => false],
        ], $json['data']);

        $scopes = $container->get(FinanceAccessScopeReader::class);
        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $scopes);
        $scopes->grant(1, 'AP', FinanceAccessScope::TYPE_MAILBOX, 'christine@example.test');
        [, $json] = $this->call($app, 'GET', self::BASE . '/mailboxes');
        self::assertSame(['christine@example.test'], array_column(self::list($json), 'email'));

        $this->grant($app, [Module::PERMISSION_AP_MAIL_READ, Module::PERMISSION_AP_ACCESS]);
        [$status] = $this->call($app, 'GET', self::BASE . '/mailboxes');
        self::assertSame(403, $status);
    }

    public function test_mailboxes_have_no_write_routes(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP_ACCESS, ...Module::AP_PERMISSIONS]);

        foreach ([['POST', ''], ['PATCH', '/m1'], ['PUT', '/m1'], ['DELETE', '/m1']] as [$method, $suffix]) {
            [$status] = $this->call($app, $method, self::BASE . '/mailboxes' . $suffix, ['email' => 'x@example.test']);
            self::assertContains($status, [404, 405], $method . ' /mailboxes' . $suffix);
        }
    }

    public function test_signing_authority_is_a_read_of_the_limits(): void
    {
        $app = $this->app();
        $store = $app->getContainer()->get(ApSigningLimitStore::class);
        self::assertInstanceOf(InMemoryApSigningLimitStore::class, $store);
        $store->add(new ApSigningLimit('1', 'Manager', 'MGR', 'Amy Adams', 'amy@example.test', 'Finance Manager', 1000.0, 500.0, 100.0, 50.0, null, true));
        $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ]);

        [$status, $json] = $this->call($app, 'GET', self::BASE . '/signing-authority');

        self::assertSame(200, $status);
        $rows = self::list($json);
        self::assertCount(1, $rows);
        self::assertSame('Amy Adams', $rows[0]['name']);
        self::assertSame('amy@example.test', $rows[0]['email']);
        self::assertSame('Finance Manager', $rows[0]['position']);
        self::assertEquals(['po_inventory' => 1000.0, 'po_non_inventory' => 500.0, 'expense_claim' => 100.0, 'customer_order' => 50.0, 'credit_notes' => null], $rows[0]['limits']);
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private static function list(array $json): array
    {
        $data = $json['data'];
        self::assertIsArray($data);
        $out = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $out[] = $typed;
        }

        return $out;
    }

    public function test_the_three_lists_run_the_whole_cycle_through_the_routes(): void
    {
        foreach ([
            ['companies', ['name' => 'TriCab Pty Ltd', 'code' => 'TRI', 'sites' => 'Somerton'], ['sites' => 'Somerton, Altona'], 'name'],
            ['cost-centres', ['code' => 'CC100', 'code_no' => '100', 'name' => 'Warehouse'], ['name' => 'Main warehouse'], 'code'],
            ['account-categories', ['code' => 'FREIGHT', 'name' => 'Freight'], ['name' => 'Inbound freight'], 'code'],
        ] as [$list, $create, $patch, $key]) {
            $app = $this->app();
            $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_SETTINGS_MANAGE]);
            $base = self::BASE . '/' . $list;

            [$status, $json] = $this->call($app, 'POST', $base, $create);
            self::assertSame(201, $status, $list);
            $row = self::data($json);
            self::assertSame($create[$key], $row[$key]);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', self::id($row));
            self::assertTrue($row['is_active']);

            [$status, $json] = $this->call($app, 'GET', $base);
            self::assertSame(200, $status);
            self::assertSame([$row], self::list($json));

            [$status, $json] = $this->call($app, 'PATCH', $base . '/' . self::id($row), $patch);
            self::assertSame(200, $status);
            foreach ($patch as $field => $value) {
                self::assertSame($value, self::data($json)[$field]);
            }

            [$status, $json] = $this->call($app, 'DELETE', $base . '/' . self::id($row));
            self::assertSame(200, $status);
            self::assertSame(['ok' => true], $json['data']);
            [, $json] = $this->call($app, 'GET', $base);
            self::assertSame([], $json['data']);
        }
    }

    public function test_a_reader_reads_the_lists_but_cannot_write_them_and_a_writer_cannot_read_them(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_INVOICES_WRITE, Module::PERMISSION_AP_ACCESS]);

        [$status] = $this->call($app, 'GET', self::BASE . '/companies');
        self::assertSame(200, $status);
        [$status, $json] = $this->call($app, 'POST', self::BASE . '/companies', ['name' => 'Nope']);
        self::assertSame(403, $status, 'reading and editing invoices does not change Settings');
        self::assertFalse($json['success']);
        [$status] = $this->call($app, 'PATCH', self::BASE . '/cost-centres/' . str_repeat('a', 32), ['name' => 'Nope']);
        self::assertSame(403, $status);
        [$status] = $this->call($app, 'DELETE', self::BASE . '/account-categories/' . str_repeat('a', 32));
        self::assertSame(403, $status);

        $this->grant($app, [Module::PERMISSION_AP_SETTINGS_MANAGE]);
        [$status] = $this->call($app, 'GET', self::BASE . '/companies');
        self::assertSame(403, $status, 'managing Settings is not reading invoices: one permission per route');
    }

    public function test_the_sources_refusals_are_422_404_and_409_with_the_ap_code_and_its_words(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP_SETTINGS_MANAGE]);

        [$status, $json] = $this->call($app, 'POST', self::BASE . '/companies', ['code' => 'X']);
        self::assertSame(422, $status);
        self::assertSame('FINANCE_AP_INPUT_INVALID', self::error($json)['code']);
        self::assertSame('name is required', self::error($json)['message']);

        [$status, $json] = $this->call($app, 'POST', self::BASE . '/cost-centres');
        self::assertSame(422, $status, 'no body reads as {}');
        self::assertSame('code is required', self::error($json)['message']);

        [$status, $json] = $this->call($app, 'PATCH', self::BASE . '/account-categories/' . str_repeat('0', 32), ['name' => 'X']);
        self::assertSame(404, $status);
        self::assertSame('FINANCE_AP_NOT_FOUND', self::error($json)['code']);
        self::assertSame('Not found', self::error($json)['message']);

        $this->call($app, 'POST', self::BASE . '/account-categories', ['code' => 'A']);
        [$status, $json] = $this->call($app, 'POST', self::BASE . '/account-categories', ['code' => 'a']);
        self::assertSame(409, $status);
        self::assertSame('FINANCE_AP_CONFLICT', self::error($json)['code']);
    }

    public function test_the_sort_and_limit_in_the_query_string_are_honoured(): void
    {
        $app = $this->app();
        $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_SETTINGS_MANAGE]);
        foreach (['B', 'A', 'C'] as $code) {
            $this->call($app, 'POST', self::BASE . '/account-categories', ['code' => $code]);
        }

        [, $json] = $this->call($app, 'GET', self::BASE . '/account-categories?sort=-code&limit=2');

        self::assertSame(['C', 'B'], array_column(self::list($json), 'code'));
    }

    public function test_a_bad_time_zone_is_refused_at_startup_and_a_zone_and_the_default_are_configurable(): void
    {
        $settings = \TriCab\Api\Config\Settings::fromEnvironment(['FINANCE_AP_TIMEZONE' => 'Australia/Perth', 'FINANCE_AP_SENDING_ENABLED' => 'true']);
        self::assertSame('Australia/Perth', $settings->financeApTimezone);
        self::assertTrue($settings->financeApSendingEnabled);

        $defaults = \TriCab\Api\Config\Settings::fromEnvironment([]);
        self::assertSame('Australia/Melbourne', $defaults->financeApTimezone);
        self::assertFalse($defaults->financeApSendingEnabled);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FINANCE_AP_TIMEZONE');
        $this->app(self::ON + ['FINANCE_AP_TIMEZONE' => 'Mars/Olympus']);
    }

    public function test_the_ap_mailbox_services_resolve_on_the_in_memory_stores_and_its_routes_need_mail_read(): void
    {
        $app = $this->app();
        $container = $app->getContainer();

        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApEmailStore::class, $container->get(\TriCab\Core\Finance\Ap\Contract\ApEmailStore::class));
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApEmailService::class, $container->get(\TriCab\Core\Finance\Ap\Service\ApEmailService::class));
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApMessageService::class, $container->get(\TriCab\Core\Finance\Ap\Service\ApMessageService::class));

        $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ]);
        [$status] = $this->call($app, 'GET', self::BASE . '/emails');
        self::assertSame(403, $status);
        $this->grant($app, [Module::PERMISSION_AP_MAIL_READ]);
        [$status, $json] = $this->call($app, 'GET', self::BASE . '/emails');
        self::assertSame(200, $status);
        self::assertSame([], $json['data']);
    }

    public function test_the_mail_services_resolve_on_the_in_memory_stores_and_its_routes_need_their_own_permissions(): void
    {
        $app = $this->app();
        $container = $app->getContainer();

        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApOutboundStore::class, $container->get(\TriCab\Core\Finance\Ap\Contract\ApOutboundStore::class));
        $mail = $container->get(\TriCab\Core\Finance\Ap\Service\ApMailService::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApMailService::class, $mail);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApDraftMailService::class, $container->get(\TriCab\Core\Finance\Ap\Service\ApDraftMailService::class));
        self::assertFalse($mail->sendingEnabled(), 'sending is off unless FINANCE_AP_SENDING_ENABLED says otherwise (D79)');
        self::assertTrue($this->appWith(['FINANCE_AP_SENDING_ENABLED' => 'true'])->sendingEnabled());

        $this->grant($app, [Module::PERMISSION_AP_MAIL_READ]);
        [$status] = $this->call($app, 'POST', self::BASE . '/emails/e1/send-reply', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b']);
        self::assertSame(403, $status, 'reading mail does not send it');
        $this->grant($app, [Module::PERMISSION_AP_MAIL_SEND]);
        [$status, $json] = $this->call($app, 'POST', self::BASE . '/emails/e1/send-reply', ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b']);
        self::assertSame(503, $status, 'sending is off by default');
        $error = $json['error'];
        self::assertIsArray($error);
        self::assertSame('FINANCE_AP_UNAVAILABLE', $error['code']);
        [$status] = $this->call($app, 'GET', self::BASE . '/send-mailboxes');
        self::assertSame(403, $status);
        $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ]);
        [$status, $json] = $this->call($app, 'GET', self::BASE . '/send-mailboxes');
        self::assertSame(200, $status);
        self::assertSame(['mailboxes' => [], 'default' => 'ap@tricab.com'], $json['data']);
    }

    /**
     * @param array<string, string> $env
     */
    private function appWith(array $env): \TriCab\Core\Finance\Ap\Service\ApMailService
    {
        $service = $this->app(self::ON + $env)->getContainer()->get(\TriCab\Core\Finance\Ap\Service\ApMailService::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApMailService::class, $service);

        return $service;
    }

    public function test_the_approval_services_resolve_on_the_in_memory_stores_and_the_directory_starts_empty(): void
    {
        $app = $this->app();
        $container = $app->getContainer();

        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApApprovalStore::class, $container->get(\TriCab\Core\Finance\Ap\Contract\ApApprovalStore::class));
        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApApproverDirectory::class, $container->get(\TriCab\Core\Finance\Ap\Contract\ApApproverDirectory::class));
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApApprovalService::class, $container->get(\TriCab\Core\Finance\Ap\Service\ApApprovalService::class));

        $this->grant($app, []);
        self::assertSame(403, $this->call($app, 'GET', self::BASE . '/approvers')[0]);
        self::assertSame(403, $this->call($app, 'GET', self::BASE . '/approval-batches')[0]);
        $this->grant($app, [Module::PERMISSION_AP_INVOICES_READ]);
        [$status, $json] = $this->call($app, 'GET', self::BASE . '/approvers');
        self::assertSame(200, $status);
        self::assertSame([], $json['data']);
        [$status, $json] = $this->call($app, 'GET', self::BASE . '/approval-batches');
        self::assertSame(200, $status);
        self::assertSame([], $json['data']);
    }

    public function test_the_link_in_approval_mail_is_built_on_the_public_origin_of_the_web_app_and_the_mail_goes_from_the_default_mailbox(): void
    {
        $app = $this->app(self::ON + ['PASSKEY_PUBLIC_APP_BASE_URL' => 'https://www.example.test/', 'FINANCE_AP_SENDING_ENABLED' => 'true']);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $transport = new \TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport();
        $container->set(\TriCab\Core\Finance\Shared\Mail\FinanceMailTransport::class, $transport);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ap@tricab.com', 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
        ]));
        $invoices = $container->get(\TriCab\Core\Finance\Ap\Contract\ApInvoiceStore::class);
        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApInvoiceStore::class, $invoices);
        $invoices->insert(ApInvoiceFixtures::invoice('i1'));
        $directory = $container->get(\TriCab\Core\Finance\Ap\Contract\ApApproverDirectory::class);
        self::assertInstanceOf(\TriCab\Api\Finance\Ap\InMemoryApApproverDirectory::class, $directory);
        $directory->add(21, 'Ann Approver', 'ann@example.test');
        $service = $container->get(\TriCab\Core\Finance\Ap\Service\ApApprovalService::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap\Service\ApApprovalService::class, $service);

        $out = $service->request(ApApprovalWorld::preparer(), ['invoice_ids' => ['i1']]);

        self::assertNull($out['warning']);
        $id = $out['batch']['id'];
        self::assertIsString($id);
        self::assertCount(1, $transport->standalone);
        self::assertSame('ap@tricab.com', $transport->standalone[0]->mailbox, 'the default mailbox of the mail service, ap@tricab.com when FINANCE_AP_MAILBOX is unset');
        self::assertStringContainsString("<a href='https://www.example.test/finance/ap/approvals/" . $id . "'>", $transport->standalone[0]->body);
    }
}
