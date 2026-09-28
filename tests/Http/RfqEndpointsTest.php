<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Tests\Rfq\Application\ReadRfqQueueServiceTest;

/**
 * Plan 1.5 exit: denied users cannot reach RFQ data even by calling the
 * endpoints directly. Everyone who reads RFQs reads all of them, every
 * employee reads (owner decision, 28 Sep 2026), and branch is a filter.
 *
 * Written against permissions no database holds yet: the in-memory user
 * repository is the authority here, exactly as in `OnboardingEndpointsTest`.
 */
final class RfqEndpointsTest extends TestCase
{
    private const ME = '/api/v1/rfqs/me';
    private const QUEUE = '/api/v1/rfqs';

    /** The id InMemoryApiUserRepository authenticates as. */
    private const SIGNED_IN_USER_ID = 1;

    private const OWN_RFQ = '11111111-1111-4111-8111-111111111111';
    private const COLLEAGUE_RFQ = '22222222-2222-4222-8222-222222222222';

    // ------------------------------------------------------------ deny by default

    public function test_a_signed_in_person_without_rfq_permissions_reaches_nothing(): void
    {
        $app = $this->app(['account.login']);

        foreach ([self::ME, self::QUEUE, self::QUEUE . '/' . self::OWN_RFQ] as $path) {
            $response = $app->handle(TestAppFactory::request('GET', $path));
            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getBody());
        }
    }

    /** RFQ.ACCESS reaches the module entry and no record. */
    public function test_module_access_alone_reads_no_rfq(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_ACCESS]);
        $this->seed($app);

        self::assertSame(200, $app->handle(TestAppFactory::request('GET', self::ME))->getStatusCode());
        self::assertSame(403, $app->handle(TestAppFactory::request('GET', self::QUEUE))->getStatusCode());
        self::assertSame(403, $app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::OWN_RFQ))->getStatusCode());
    }

    /** The platform administrator's RFQ grant is module administration, which reads nothing. */
    public function test_the_administrator_grant_reads_no_rfq(): void
    {
        $app = $this->app(['account.login', ...Module::ADMINISTRATOR_PERMISSIONS], ['ADMINISTRATOR']);
        $this->seed($app);

        self::assertSame(403, $app->handle(TestAppFactory::request('GET', self::QUEUE))->getStatusCode());
    }

    // ----------------------------------------------------------------- access

    public function test_the_access_summary_is_what_the_server_resolved(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);

        $response = $app->handle(TestAppFactory::request('GET', self::ME));
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode());
        $permissions = $data['permissions'];
        self::assertIsArray($permissions);
        self::assertContains(Module::PERMISSION_DRAFT_READ, $permissions);
        self::assertNotContains('ACCOUNT.LOGIN', $permissions, 'Only RFQ.* codes are reported.');
        self::assertArrayNotHasKey('grantedScope', $data, 'reading is not scoped');
        self::assertArrayNotHasKey('effectiveScope', $data);
        self::assertSame(['code' => null, 'source' => 'unavailable'], $data['branch']);
    }

    /** The scaffold invented a person and a branch; the server invents neither. */
    public function test_the_access_summary_names_no_person_and_no_default_branch(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_ACCESS]);

        $body = (string) $app->handle(TestAppFactory::request('GET', self::ME))->getBody();

        self::assertStringNotContainsString('VIC', $body);
        self::assertStringNotContainsString('@', $body);
    }

    // ------------------------------------------------------------------ queue

    public function test_an_empty_store_answers_an_empty_queue(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);

        $data = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE)));

        self::assertSame([], $data['items']);
        self::assertSame(0, $data['total']);
        self::assertSame([], $data['branches']);
    }

    /** As in 4js: an employee sees every RFQ, a colleague's in another branch included. */
    public function test_an_employee_sees_every_rfq(): void
    {
        $app = $this->app(['account.login', ...Module::EMPLOYEE_PERMISSIONS]);
        $this->seed($app);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE));
        $body = (string) $response->getBody();
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertSame(2, $data['total']);
        self::assertStringContainsString(self::OWN_RFQ, $body);
        self::assertStringContainsString(self::COLLEAGUE_RFQ, $body);
        self::assertSame(['NSW', 'VIC'], $data['branches']);
    }

    public function test_branch_is_a_filter(): void
    {
        $app = $this->app(['account.login', ...Module::EMPLOYEE_PERMISSIONS]);
        $this->seed($app);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE)->withQueryParams(['branch' => 'nsw']));
        $body = (string) $response->getBody();

        self::assertSame(1, $this->data($response)['total']);
        self::assertStringContainsString(self::COLLEAGUE_RFQ, $body);
        self::assertSame(400, $app->handle(TestAppFactory::request('GET', self::QUEUE)->withQueryParams(['branch' => "VIC'--"]))->getStatusCode());
    }

    /** The browser renders from `allowedActions`; a reader who holds nothing else gets `view`. */
    public function test_each_item_carries_the_actions_the_caller_may_take(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);
        $this->seed($app);

        $items = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE)))['items'];

        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        self::assertSame(['view'], $items[0]['allowedActions']);
    }

    /** No email address leaves the server in a queue item (invariant 11). */
    public function test_queue_items_carry_no_email_address(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);
        $this->seed($app);

        self::assertStringNotContainsString('@', (string) $app->handle(TestAppFactory::request('GET', self::QUEUE))->getBody());
    }

    /** Reading is the only thing an employee holds: a colleague's RFQ offers `view`, nothing more. */
    public function test_an_employee_may_view_a_colleague_s_rfq_and_not_work_it(): void
    {
        $app = $this->app(['account.login', ...Module::EMPLOYEE_PERMISSIONS]);
        $this->seed($app);

        $data = $this->data($app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::COLLEAGUE_RFQ)));

        self::assertSame(self::COLLEAGUE_RFQ, $data['publicId']);
        self::assertSame(['view'], $data['allowedActions']);
    }

    public function test_an_invalid_filter_is_a_400_not_an_ignored_filter(): void
    {
        $app = $this->app(['account.login', Module::PERMISSION_DRAFT_READ]);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE)->withQueryParams(['status' => 'synced']));
        $payload = TestAppFactory::json($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertIsArray($payload['error'] ?? null);
        self::assertSame('RFQ_QUERY_INVALID', $payload['error']['code'] ?? null);
    }

    // ----------------------------------------------------------------- detail

    public function test_an_rfq_is_returned(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $this->seed($app);

        $response = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/' . self::OWN_RFQ));
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::OWN_RFQ, $data['publicId']);
        self::assertSame([], $data['documents']);
        self::assertNull($data['latestRevision']);
    }

    public function test_an_unknown_or_malformed_id_is_a_404(): void
    {
        $app = $this->app(['account.login', ...Module::EMPLOYEE_PERMISSIONS]);
        $this->seed($app);

        $absent = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/33333333-3333-4333-8333-333333333333'));
        $garbage = $app->handle(TestAppFactory::request('GET', self::QUEUE . '/drop-table'));

        foreach ([$absent, $garbage] as $response) {
            self::assertSame(404, $response->getStatusCode());
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     * @return App<ContainerInterface>
     */
    private function app(array $permissions, array $roles = ['EMPLOYEE']): App
    {
        $app = TestAppFactory::create(['RFQ_MODULE_ENABLED' => 'true']);
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(self::SIGNED_IN_USER_ID, $permissions, $roles);

        return $app;
    }

    /**
     * Two RFQs: one the signed-in user owns, one a colleague in another branch
     * owns. Everyone who reads sees both.
     *
     * @param App<ContainerInterface> $app
     */
    private function seed(App $app): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(RfqRepository::class);
        self::assertInstanceOf(InMemoryRfqRepository::class, $repository);

        $repository
            ->withRecord(ReadRfqQueueServiceTest::record(self::OWN_RFQ, self::SIGNED_IN_USER_ID, 'VIC'))
            ->withRecord(ReadRfqQueueServiceTest::record(self::COLLEAGUE_RFQ, 99, 'NSW'));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        self::assertArrayHasKey('data', $payload, (string) $response->getBody());
        $data = $payload['data'];
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
