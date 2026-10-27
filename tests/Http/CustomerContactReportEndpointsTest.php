<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportDetails;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository;
use TriCab\Core\CustomerContactReport\Domain\CustomerContactReportSearch;
use TriCab\Core\CustomerContactReport\Module;
use TriCab\Core\Identity\Contract\UserRepository;

final class CustomerContactReportEndpointsTest extends TestCase
{
    private const MISSING_ID = '22222222-2222-4222-8222-222222222222';

    /** @var list<string> */
    private const STAFF = [
        'ACCOUNT.LOGIN',
        Module::PERMISSION_ACCESS,
        Module::PERMISSION_READ,
        Module::PERMISSION_CREATE,
    ];

    public function test_routes_are_absent_until_the_module_is_enabled(): void
    {
        $app = TestAppFactory::create();

        self::assertSame(404, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts'))->getStatusCode());
        self::assertSame(404, $app->handle(TestAppFactory::request('POST', '/api/v1/customer-contacts'))->getStatusCode());
    }

    public function test_routes_require_authentication(): void
    {
        $app = $this->app(['DEV_AUTH_ENABLED' => 'false']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $this->record($payload['error'] ?? null)['code'] ?? null);
    }

    public function test_access_read_and_create_are_required_separately(): void
    {
        $app = $this->app();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ]);
        self::assertSame(403, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts'))->getStatusCode());

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_CREATE]);
        $deniedRead = TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts')));
        self::assertSame('INSUFFICIENT_PERMISSION', $this->record($deniedRead['error'] ?? null)['code'] ?? null);

        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS, Module::PERMISSION_READ]);
        $deniedCreate = $app->handle($this->json('POST', '/api/v1/customer-contacts', $this->validBody()));
        self::assertSame(403, $deniedCreate->getStatusCode());
        self::assertSame(0, $this->reports($app)->numberSequenceRows());
    }

    public function test_me_hides_the_module_without_access(): void
    {
        $app = $this->app();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_READ, Module::PERMISSION_CREATE]);

        $payload = TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/me')));
        $modules = $this->record($this->record($payload['data'] ?? null)['modules'] ?? null);

        self::assertFalse($modules['customerContacts'] ?? true);
    }

    public function test_create_rejects_missing_blank_and_invalid_values(): void
    {
        $app = $this->app();

        foreach (['reportDate', 'location', 'customerCode', 'description', 'creditRequired'] as $field) {
            $missing = $this->validBody();
            unset($missing[$field]);
            self::assertSame(422, $this->create($app, $missing)->getStatusCode(), $field);

            $blank = $this->validBody();
            $blank[$field] = '   ';
            self::assertSame(422, $this->create($app, $blank)->getStatusCode(), $field . ' blank');
        }

        $noOrder = $this->validBody();
        unset($noOrder['orderNo']);
        self::assertSame(422, $this->create($app, $noOrder)->getStatusCode(), 'no order and no invoice');
        $invoiceOnly = $noOrder;
        $invoiceOnly['invoiceNo'] = '63995202';
        self::assertSame(201, $this->create($app, $invoiceOnly)->getStatusCode(), 'invoice only');
        $noProduct = $this->validBody();
        unset($noProduct['productCode']);
        self::assertSame(201, $this->create($app, $noProduct)->getStatusCode(), 'product code is optional');

        $yesNoType = $this->validBody();
        $yesNoType['creditRequired'] = 'Yes';
        self::assertSame(422, $this->create($app, $yesNoType)->getStatusCode(), 'credit type required');
        $yesNoType['creditType'] = 'Overcharged';
        self::assertSame(422, $this->create($app, $yesNoType)->getStatusCode(), 'amount required');

        $invalid = $this->validBody();
        $invalid['location'] = 'Z';
        self::assertSame(422, $this->create($app, $invalid)->getStatusCode());
        self::assertSame(0, $this->reports($app)->numberSequenceRows());
        self::assertSame(2, $this->page($this->list($app))['totalItems'] ?? null);
    }

    public function test_create_rejects_server_owned_fields_and_does_not_insert(): void
    {
        $app = $this->app();

        foreach (['status', 'ccNumber', 'route', 'errorCode', 'assignedTo', 'assignedBy', 'closedBy', 'createdByEmail', 'createdByName'] as $field) {
            $body = $this->validBody();
            $body[$field] = 'injected';
            $response = $this->create($app, $body);
            $payload = TestAppFactory::json($response);
            self::assertSame(422, $response->getStatusCode(), $field);
            self::assertSame('CLIENT_FIELD_REJECTED', $this->record($payload['error'] ?? null)['code'] ?? null, $field);
        }

        self::assertSame(0, $this->page($this->list($app))['totalItems'] ?? null);
        self::assertSame(0, $this->reports($app)->numberSequenceRows());
    }

    public function test_valid_create_is_submitted_without_a_number(): void
    {
        $app = $this->app();
        $response = $this->create($app, $this->validBody([
            'customerContact' => ' Ada ',
            'productCode' => ' ABC,DEF ',
            'orderNo' => '639952',
            'creditRequired' => 'No',
            'tricabRefTrace' => 'T-1',
            'traceNum' => '99',
            'conNoteNo' => 'CN1',
            'tricabContact' => 'Sam',
        ]));
        $payload = TestAppFactory::json($response);
        $data = $this->record($payload['data'] ?? null);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('submitted', $data['status']);
        self::assertNull($data['ccNumber']);
        self::assertNull($data['route']);
        self::assertNull($data['errorCode']);
        self::assertNull($data['assignedTo']);
        self::assertSame('2026-09-30', $data['reportDate']);
        self::assertSame('M', $data['location']);
        self::assertSame('CUST1', $data['customerCode']);
        self::assertSame('ABC,DEF', $data['productCode']);
        self::assertSame('Cable failed', $data['description']);
        self::assertSame('Ada', $data['customerContact']);
        self::assertSame('thilanh@tricab.com', $data['createdByEmail']);
        self::assertSame('Thilan Lakshitha', $data['createdByName']);
        self::assertIsString($data['publicId']);
        self::assertIsString($data['createdAtUtc']);
        self::assertArrayNotHasKey('reportId', $data);
        self::assertArrayNotHasKey('icarOperatorName', $data);
        self::assertArrayNotHasKey('icarSignatureUrl', $data);
        self::assertSame(0, $this->reports($app)->numberSequenceRows());
    }

    public function test_creator_can_read_own_report_and_a_stranger_cannot_tell_it_exists(): void
    {
        $app = $this->app();
        $created = $this->created($app);
        $publicId = $created['publicId'];
        self::assertIsString($publicId);

        $own = $this->record(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $publicId)))['data'] ?? null);
        self::assertSame($publicId, $own['publicId'] ?? null);
        self::assertArrayNotHasKey('icarOperatorName', $own);
        self::assertArrayNotHasKey('reportId', $own);

        $hidden = $this->reports($app)->seedCopy(
            $this->stored($app, $publicId),
            99,
            'other@tricab.com',
            icarOperatorName: 'ICAR Operator',
        );
        $stranger = $this->record(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $hidden->publicId)))['error'] ?? null);
        $missing = $this->record(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . self::MISSING_ID)))['error'] ?? null);

        self::assertSame(404, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $hidden->publicId))->getStatusCode());
        self::assertSame('CUSTOMER_CONTACT_REPORT_NOT_FOUND', $stranger['code'] ?? null);
        self::assertSame($missing['code'] ?? null, $stranger['code']);
        self::assertSame($missing['message'] ?? null, $stranger['message'] ?? null);

        $visibleIds = $this->column($this->list($app), 'publicId');
        self::assertContains($publicId, $visibleIds);
        self::assertNotContains($hidden->publicId, $visibleIds);
    }

    public function test_read_all_sees_every_report_including_restricted_fields(): void
    {
        $app = $this->app();
        $created = $this->created($app);
        $publicId = $created['publicId'];
        self::assertIsString($publicId);
        $restricted = $this->reports($app)->seedCopy(
            $this->stored($app, $publicId),
            1,
            'thilanh@tricab.com',
            icarOperatorName: 'ICAR Operator',
        );

        $originator = $this->record(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $restricted->publicId)))['data'] ?? null);
        self::assertArrayNotHasKey('icarOperatorName', $originator);
        self::assertArrayNotHasKey('icarSignatureUrl', $originator);

        $this->replacePermissions($app, [...self::STAFF, Module::PERMISSION_READ_ALL]);
        $admin = $this->record(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $restricted->publicId)))['data'] ?? null);
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $restricted->publicId))->getStatusCode());
        self::assertSame('ICAR Operator', $admin['icarOperatorName'] ?? null);
        self::assertArrayHasKey('icarSignatureUrl', $admin);

        $stranger = $this->reports($app)->seedCopy($this->stored($app, $publicId), 99, 'other@tricab.com');
        $seen = $this->record(TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/' . $stranger->publicId)))['data'] ?? null);
        self::assertSame($stranger->publicId, $seen['publicId'] ?? null);
    }

    public function test_list_filters_search_pagination_sort_and_counts(): void
    {
        $app = $this->app();
        $first = $this->created($app, $this->validBody([
            'reportDate' => '2026-01-02',
            'customerCode' => 'OLD',
            'customerContact' => 'Pat',
            'productCode' => 'OLD-1',
            'orderNo' => '639952',
            'creditRequired' => 'No',
            'description' => 'older submitted',
            'location' => 'S',
        ]));
        $second = $this->created($app, $this->validBody([
            'reportDate' => '2026-01-15',
            'description' => 'newer submitted',
        ]));
        $sourceId = $second['publicId'];
        self::assertIsString($sourceId);
        $source = $this->stored($app, $sourceId);
        $repo = $this->reports($app);
        $repo->seedCopy($source, 1, 'thilanh@tricab.com', 'assigned', '2601-001M-SLS', 'branch', 'SLS', 'assignee@tricab.com', reportDate: new \DateTimeImmutable('2026-08-01'), description: 'assigned work', customerCode: 'ASSIGNED', location: 'B');
        $repo->seedCopy($source, 1, 'thilanh@tricab.com', 'completed', '2601-002M-CUS', 'icar', 'CUS', 'assignee@tricab.com', reportDate: new \DateTimeImmutable('2026-07-01'), description: 'completed work');
        $repo->seedCopy($source, 1, 'thilanh@tricab.com', 'closed', '2609-003M-MFG', 'branch', 'MFG', 'assignee@tricab.com', reportDate: new \DateTimeImmutable('2026-09-01'), description: 'closed work');
        $repo->seedCopy($source, 1, 'thilanh@tricab.com', description: 'size 100% of reel', customerCode: 'PCT');
        $repo->seedCopy($source, 1, 'thilanh@tricab.com', description: 'size 1000 of reel', customerCode: 'THOUSAND');
        $repo->seedCopy($source, 1, 'thilanh@tricab.com', description: 'code A_B', productCode: 'UNDER');
        $repo->seedCopy($source, 1, 'thilanh@tricab.com', description: 'code AXB', productCode: 'NOUNDER');
        $repo->seedCopy($source, 99, 'other@tricab.com', description: 'hidden from staff');

        $ordered = $this->column($this->list($app), 'status');
        self::assertSame(['submitted', 'submitted', 'submitted', 'submitted', 'submitted', 'submitted', 'assigned', 'completed', 'closed'], $ordered);

        $sameDay = $this->list($app, ['customer' => 'CUST1', 'pageSize' => '10']);
        self::assertSame(
            ['2026-01-15', '2026-01-15', '2026-01-15', '2026-07-01', '2026-09-01'],
            $this->column($sameDay, 'reportDate'),
        );
        self::assertSame(
            ['submitted', 'submitted', 'submitted', 'completed', 'closed'],
            $this->column($sameDay, 'status'),
        );

        $page = $this->list($app, ['page' => '2', 'pageSize' => '1']);
        $pageMeta = $this->page($page);
        self::assertSame(2, $pageMeta['page']);
        self::assertSame(1, $pageMeta['pageSize']);
        self::assertSame(9, $pageMeta['totalItems']);
        self::assertSame(9, $pageMeta['totalPages']);
        self::assertCount(1, $this->rows($page['items'] ?? null));

        self::assertSame(['closed'], $this->column($this->list($app, ['status' => 'closed']), 'status'));
        self::assertSame(['2026-01-02'], $this->column($this->list($app, ['month' => '1', 'year' => '2026', 'customer' => 'OLD']), 'reportDate'));
        self::assertSame(['B'], $this->column($this->list($app, ['location' => 'b']), 'location'));
        self::assertSame(['SLS'], $this->column($this->list($app, ['errorCode' => 'sls']), 'errorCode'));
        self::assertSame(
            ['assignee@tricab.com', 'assignee@tricab.com', 'assignee@tricab.com'],
            $this->column($this->list($app, ['assignee' => 'assignee@tricab.com']), 'assignedTo'),
        );

        self::assertSame(['2601-001M-SLS'], $this->column($this->list($app, ['search' => '2601-001']), 'ccNumber'));
        self::assertSame(['OLD'], $this->column($this->list($app, ['search' => 'OLD']), 'customerCode'));
        self::assertSame(['Pat'], $this->column($this->list($app, ['search' => 'Pat']), 'customerContact'));
        self::assertSame(['OLD-1'], $this->column($this->list($app, ['search' => 'OLD-1']), 'productCode'));
        self::assertSame(['assigned work'], $this->column($this->list($app, ['search' => 'assigned work']), 'description'));
        self::assertSame(['PCT'], $this->column($this->list($app, ['search' => '100%']), 'customerCode'));
        self::assertSame(['UNDER'], $this->column($this->list($app, ['search' => 'A_B']), 'productCode'));
        self::assertSame('%100!%%', CustomerContactReportSearch::likePattern('100%'));
        self::assertSame('%A!_B%', CustomerContactReportSearch::likePattern('A_B'));

        $filtered = $this->list($app, ['status' => 'closed']);
        $counts = $this->record($filtered['counts'] ?? null);
        $labels = $this->record($counts['labels'] ?? null);
        self::assertSame(6, $counts['submitted']);
        self::assertSame(1, $counts['assigned']);
        self::assertSame(1, $counts['completed']);
        self::assertSame(1, $counts['closed']);
        self::assertSame('Awaiting Review', $labels['submitted']);
        self::assertSame('In Progress', $labels['assigned']);
        self::assertSame('Completed', $labels['completed']);
        self::assertSame('Closed', $labels['closed']);
        self::assertSame(1, $this->page($filtered)['totalItems']);

        $firstId = $first['publicId'];
        self::assertIsString($firstId);
        self::assertNotContains($firstId, $this->column($this->list($app, ['year' => '2025']), 'publicId'));
    }

    public function test_patch_amends_with_a_row_version_and_conflicts_when_stale(): void
    {
        $app = $this->app();
        $created = $this->created($app, $this->validBody([
            'creditRequired' => 'Yes',
            'creditType' => 'Overcharged',
            'valueExGst' => '100.00',
        ]));
        $id = $created['publicId'];
        self::assertSame('110.00', $created['creditTotal']);

        $saved = $app->handle($this->json('PATCH', '/api/v1/customer-contacts/' . $id, [
            'rowVersion' => $created['rowVersion'],
            'valueExGst' => '200.00',
        ]));
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $data = $this->record(TestAppFactory::json($saved)['data'] ?? null);
        self::assertSame('220.00', $data['creditTotal']);
        self::assertSame([], $data['warnings']);
        self::assertNotSame($created['rowVersion'], $data['rowVersion']);

        $stale = $app->handle($this->json('PATCH', '/api/v1/customer-contacts/' . $id, [
            'rowVersion' => $created['rowVersion'],
            'description' => 'late',
        ]));
        self::assertSame(409, $stale->getStatusCode());

        $forbidden = $app->handle($this->json('PATCH', '/api/v1/customer-contacts/' . $id, [
            'rowVersion' => $data['rowVersion'],
            'creditTotal' => '1.00',
        ]));
        self::assertSame(422, $forbidden->getStatusCode());
    }

    public function test_export_returns_csv_for_the_callers_rows_and_lookup_fails_closed_without_4js(): void
    {
        $app = $this->app();
        $this->created($app, $this->validBody(['description' => '=cmd']));

        $csv = $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/export'));
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('text/csv', $csv->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment; filename="customer-contacts-', $csv->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString("'=cmd", (string) $csv->getBody());

        $lookup = $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/lookup/order/639952'));
        self::assertSame(200, $lookup->getStatusCode());
        $payload = $this->record(TestAppFactory::json($lookup)['data'] ?? null);
        self::assertSame('unavailable', $payload['status']);

        $bad = $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/lookup/order/abc'));
        self::assertSame('invalid', $this->record(TestAppFactory::json($bad)['data'] ?? null)['status']);
        self::assertSame(404, $app->handle(TestAppFactory::request('GET', '/api/v1/customer-contacts/lookup/widget/1'))->getStatusCode());
    }

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(array $env = []): App
    {
        $app = TestAppFactory::create(['CUSTOMER_CONTACT_MODULE_ENABLED' => 'true', ...$env]);
        $this->replacePermissions($app, self::STAFF);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissions
     */
    private function replacePermissions(App $app, array $permissions): void
    {
        $users = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validBody(array $overrides = []): array
    {
        return $overrides + [
            'reportDate' => '2026-09-30',
            'location' => 'M',
            'customerCode' => 'CUST1',
            'productCode' => 'CAB-1',
            'orderNo' => '639952',
            'creditRequired' => 'No',
            'description' => 'Cable failed',
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $body
     */
    private function create(App $app, array $body): ResponseInterface
    {
        return $app->handle($this->json('POST', '/api/v1/customer-contacts', $body));
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, string> $body
     * @return array<string, mixed>
     */
    private function created(App $app, array $body = []): array
    {
        $response = $this->create($app, $body === [] ? $this->validBody() : $body);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = TestAppFactory::json($response);

        return $this->record($payload['data'] ?? null);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function list(App $app, array $query = []): array
    {
        $path = '/api/v1/customer-contacts';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }
        $payload = TestAppFactory::json($app->handle(TestAppFactory::request('GET', $path)));

        return $this->record($payload['data'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected a JSON object.');
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('Expected string keys.');
            }
            $record[$key] = $item;
        }

        return $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected a JSON list.');
        }

        $rows = [];
        foreach ($value as $item) {
            $rows[] = $this->record($item);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $list
     * @return list<mixed>
     */
    private function column(array $list, string $key): array
    {
        $values = [];
        foreach ($this->rows($list['items'] ?? null) as $row) {
            $values[] = $row[$key] ?? null;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $list
     * @return array<string, mixed>
     */
    private function page(array $list): array
    {
        return $this->record($list['pagination'] ?? null);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function reports(App $app): InMemoryCustomerContactReportRepository
    {
        $reports = $this->container($app)->get(CustomerContactReportRepository::class);
        self::assertInstanceOf(InMemoryCustomerContactReportRepository::class, $reports);

        return $reports;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function stored(App $app, string $publicId): CustomerContactReportDetails
    {
        foreach ($this->reports($app)->list(
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListCriteria(1, 100, null, null, null, null, null, null, null, null),
            new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportVisibility(true, 1, 'thilanh@tricab.com'),
        )->items as $item) {
            if ($item->publicId === $publicId) {
                return $item;
            }
        }

        self::fail('Stored report was not found: ' . $publicId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function container(App $app): ContainerInterface
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
