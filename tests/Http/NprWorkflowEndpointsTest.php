<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Npr\NprFixtures;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Npr\Application\Service\NprSigningPolicy;
use TriCab\Core\Npr\Contract\ManagerRouteRepository;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\NewManagerRoute;
use TriCab\Core\Npr\Domain\NprPerson;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Infrastructure\InMemoryNprRequestRepository;
use TriCab\Core\Npr\Module;

/** The NPR workflow endpoints end to end over the in-memory repositories. The test app signs in as user 1. */
final class NprWorkflowEndpointsTest extends TestCase
{
    private const ME_USER = 1;
    private const MINE = '11111111-1111-4111-8111-111111111111';
    private const ASSIGNED = '22222222-2222-4222-8222-222222222222';
    private const NOT_MINE = '33333333-3333-4333-8333-333333333333';
    private const WITH_TSD = '44444444-4444-4444-8444-444444444444';

    private const TEAM = '/api/v1/npr/team/requests';
    private const TSD = '/api/v1/npr/tsd/requests';

    public function test_submit_sends_a_complete_draft_to_the_routed_manager(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
        $this->registerManager($app);
        $this->seed($app, $this->draft(self::MINE));

        $response = $app->handle($this->post('/api/v1/npr/me/requests/' . self::MINE . '/submit', ['version' => '0x00000000000000AA']));
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('awaiting_manager', $data['status']);
        self::assertSame('shanep@tricab.com', self::get($data, 'manager', 'email'));
        self::assertSame(['read'], $data['allowedActions']);
        self::assertNotSame('0x00000000000000AA', $data['version']);
        self::assertNotNull($data['submittedAt']);
    }

    public function test_submit_with_no_route_is_a_409_and_the_draft_stays_saved(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
        $this->seed($app, $this->draft(self::MINE));

        $response = $app->handle($this->post('/api/v1/npr/me/requests/' . self::MINE . '/submit', ['version' => '0x00000000000000AA']));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('NPR_NO_MANAGER_ROUTE', self::get(TestAppFactory::json($response), 'error', 'code'));
        $kept = $app->handle(TestAppFactory::request('GET', '/api/v1/npr/me/requests/' . self::MINE));
        self::assertSame('draft', $this->data($kept)['status']);
        self::assertSame('0x00000000000000AA', $this->data($kept)['version']);
    }

    public function test_submit_validation_and_version_failures(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
        $this->registerManager($app);
        $this->seed($app, NprFixtures::record(self::MINE, self::ME_USER, RequestStatus::Draft), $this->draft(self::NOT_MINE, 99));

        $incomplete = $app->handle($this->post('/api/v1/npr/me/requests/' . self::MINE . '/submit', ['version' => '0x00000000000000AA']));
        self::assertSame(422, $incomplete->getStatusCode());
        self::assertSame('NPR_VALIDATION_FAILED', self::get(TestAppFactory::json($incomplete), 'error', 'code'));
        self::assertIsArray(self::get(TestAppFactory::json($incomplete), 'error', 'errors'));

        $noVersion = $app->handle($this->post('/api/v1/npr/me/requests/' . self::MINE . '/submit', []));
        self::assertSame(422, $noVersion->getStatusCode());

        $stale = $app->handle($this->post('/api/v1/npr/me/requests/' . self::MINE . '/submit', ['version' => '0x0000000000000001']));
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('NPR_VERSION_CONFLICT', self::get(TestAppFactory::json($stale), 'error', 'code'));

        $notMine = $app->handle($this->post('/api/v1/npr/me/requests/' . self::NOT_MINE . '/submit', ['version' => '0x00000000000000AA']));
        self::assertSame(404, $notMine->getStatusCode());
    }

    public function test_a_manager_lists_reads_and_endorses_only_their_own(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_MANAGER]);
        $this->seed(
            $app,
            $this->awaiting(self::ASSIGNED, self::ME_USER),
            $this->awaiting(self::NOT_MINE, 77),
        );

        $list = $this->data($app->handle(TestAppFactory::request('GET', self::TEAM)));
        self::assertSame([self::ASSIGNED], array_column(self::items($list, 'items'), 'publicId'));

        $detail = $this->data($app->handle(TestAppFactory::request('GET', self::TEAM . '/' . self::ASSIGNED)));
        self::assertSame(['read', 'endorse', 'return', 'reject'], $detail['allowedActions']);
        self::assertSame(404, $app->handle(TestAppFactory::request('GET', self::TEAM . '/' . self::NOT_MINE))->getStatusCode());
        foreach (['endorse', 'return', 'reject'] as $step) {
            $other = $app->handle($this->post(self::TEAM . '/' . self::NOT_MINE . '/' . $step, ['version' => '0x00000000000000AA', 'declaration' => true, 'reason' => 'x']));
            self::assertSame(404, $other->getStatusCode(), $step . ' on someone else\'s request');
        }

        $noDeclaration = $app->handle($this->post(self::TEAM . '/' . self::ASSIGNED . '/endorse', ['version' => $detail['version']]));
        self::assertSame(422, $noDeclaration->getStatusCode());

        $stale = $app->handle($this->post(self::TEAM . '/' . self::ASSIGNED . '/endorse', ['version' => '0x0000000000000001', 'declaration' => true]));
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame($detail['version'], self::get(TestAppFactory::json($stale), 'error', 'currentVersion'));

        $endorsed = $app->handle($this->post(self::TEAM . '/' . self::ASSIGNED . '/endorse', ['version' => $detail['version'], 'declaration' => true, 'comment' => 'Go']));
        self::assertSame(200, $endorsed->getStatusCode(), (string) $endorsed->getBody());
        self::assertSame('with_tsd', $this->data($endorsed)['status']);
        self::assertSame(['read'], $this->data($endorsed)['allowedActions']);
        self::assertNotNull($this->data($endorsed)['tsdReceivedAt']);

        $again = $app->handle($this->post(self::TEAM . '/' . self::ASSIGNED . '/return', ['version' => $this->data($endorsed)['version'], 'reason' => 'late']));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('NPR_ACTION_NOT_ALLOWED', self::get(TestAppFactory::json($again), 'error', 'code'));
    }

    public function test_a_manager_returns_and_rejects_with_a_reason(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_MANAGER]);
        $this->seed($app, $this->awaiting(self::ASSIGNED, self::ME_USER), $this->awaiting(self::NOT_MINE, self::ME_USER));

        $missing = $app->handle($this->post(self::TEAM . '/' . self::ASSIGNED . '/return', ['version' => '0x00000000000000AA']));
        self::assertSame(422, $missing->getStatusCode());

        $returned = $app->handle($this->post(self::TEAM . '/' . self::ASSIGNED . '/return', ['version' => '0x00000000000000AA', 'reason' => 'More detail']));
        self::assertSame('returned_to_sales', $this->data($returned)['status']);

        $rejected = $app->handle($this->post(self::TEAM . '/' . self::NOT_MINE . '/reject', ['version' => '0x00000000000000AA', 'reason' => 'No']));
        self::assertSame('cancelled', $this->data($rejected)['status']);
    }

    public function test_self_endorsement_is_a_409(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_MANAGER]);
        $this->seed($app, $this->awaiting(self::ASSIGNED, self::ME_USER, self::ME_USER));

        $response = $app->handle($this->post(self::TEAM . '/' . self::ASSIGNED . '/endorse', ['version' => '0x00000000000000AA', 'declaration' => true]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('NPR_SELF_ENDORSEMENT_NOT_ALLOWED', self::get(TestAppFactory::json($response), 'error', 'code'));
    }

    public function test_tsd_acknowledges_assigns_assesses_and_returns(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER]);
        $this->seed($app, $this->withTsd(self::WITH_TSD));
        $url = self::TSD . '/' . self::WITH_TSD;
        $version = '0x00000000000000AA';

        $badAck = $app->handle($this->post($url . '/acknowledge', ['version' => $version]));
        self::assertSame(422, $badAck->getStatusCode());

        $ack = $this->data($app->handle($this->post($url . '/acknowledge', ['version' => $version, 'formComplete' => true, 'notes' => 'ok'])));
        self::assertNotNull($ack['tsdAcknowledgedAt']);

        $owned = $this->data($app->handle($this->post($url . '/assign', ['version' => $ack['version'], 'ownerUserId' => self::ME_USER])));
        self::assertSame(self::ME_USER, self::get($owned, 'tsdOwner', 'userId'));

        $notOwner = $app->handle($this->post($url . '/assign', ['version' => $owned['version'], 'ownerEmail' => 'nobody@tricab.com']));
        self::assertSame(422, $notOwner->getStatusCode());

        $assessed = $app->handle($this->put($url . '/assessment', ['version' => $owned['version'], 'answers' => ['technical_risks' => ['state' => 'provided', 'value' => 'Tooling']]]));
        self::assertSame(200, $assessed->getStatusCode(), (string) $assessed->getBody());
        self::assertSame('Tooling', self::get($this->data($assessed), 'answers', 'technical_risks', 'value'));
        self::assertSame('BHP', self::get($this->data($assessed), 'answers', 'customer_name', 'value'));

        $sales = $app->handle($this->put($url . '/assessment', ['version' => $this->data($assessed)['version'], 'answers' => ['customer_name' => ['state' => 'provided', 'value' => 'X']]]));
        self::assertSame(422, $sales->getStatusCode(), 'Sales answers are not TSD\'s to write');

        $returned = $app->handle($this->post($url . '/return', ['version' => $this->data($assessed)['version'], 'reason' => 'Need drawings']));
        self::assertSame('returned_to_sales', $this->data($returned)['status']);
        $still = $app->handle(TestAppFactory::request('GET', $url));
        self::assertSame(200, $still->getStatusCode(), 'TSD still sees a request it returned');
    }

    public function test_screening_is_blocked_with_a_clear_error_until_signatories_are_nominated(): void
    {
        $permissions = [...Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER], Module::PERMISSION_SCREENING_DECIDE];
        $app = $this->app($permissions);
        $with = $this->withTsd(self::WITH_TSD);
        $waiting = $this->withTsd(self::ASSIGNED)->with([
            'status' => RequestStatus::AwaitingScreening,
            'signatory' => new NprPerson(self::ME_USER, 'thilanh@tricab.com', 'Thilan'),
        ]);
        $this->seed($app, $with, $waiting);

        $request = $app->handle($this->post(self::TSD . '/' . self::WITH_TSD . '/request-screening', ['version' => '0x00000000000000AA', 'signatoryUserId' => self::ME_USER]));
        self::assertSame(409, $request->getStatusCode());
        self::assertSame('NPR_SIGNING_POLICY_NOT_CONFIGURED', self::get(TestAppFactory::json($request), 'error', 'code'));

        $decide = $app->handle($this->post(self::TSD . '/' . self::ASSIGNED . '/screening-decision', ['version' => '0x00000000000000AA', 'outcome' => 'approved', 'rationale' => 'Yes']));
        self::assertSame(409, $decide->getStatusCode());
        self::assertSame('NPR_SIGNING_POLICY_NOT_CONFIGURED', self::get(TestAppFactory::json($decide), 'error', 'code'));
    }

    public function test_screening_signatories_lists_who_tsd_can_nominate(): void
    {
        $app = $this->app([...Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER], Module::PERMISSION_SCREENING_DECIDE]);

        $unset = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/npr/tsd/screening-signatories')));
        self::assertSame(['configured' => false, 'items' => []], $unset);

        $app = $this->app([...Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER], Module::PERMISSION_SCREENING_DECIDE]);
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $me = $users->findById(UserId::fromInt(self::ME_USER));
        self::assertNotNull($me);
        $container->set(NprSigningPolicy::class, new NprSigningPolicy([$me->emailAddress()->toString(), 'nobody@tricab.com']));

        $set = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/npr/tsd/screening-signatories')));
        self::assertTrue($set['configured']);
        self::assertSame([['userId' => self::ME_USER, 'name' => $me->displayName(), 'email' => $me->emailAddress()->toString()]], $set['items']);
    }

    public function test_only_the_assigned_signatory_reaches_the_decision(): void
    {
        $app = $this->app([...Module::ROLE_PERMISSIONS[Module::ROLE_TSD_SIGNATORY]]);
        $this->seed($app, $this->withTsd(self::WITH_TSD)->with(['status' => RequestStatus::AwaitingScreening, 'signatory' => new NprPerson(77, 'someone@tricab.com', 'Someone')]));

        $response = $app->handle($this->post(self::TSD . '/' . self::WITH_TSD . '/screening-decision', ['version' => '0x00000000000000AA', 'outcome' => 'approved', 'rationale' => 'Yes']));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_admin_manager_routes(): void
    {
        $app = $this->app([Module::PERMISSION_ADMIN]);
        $this->registerManager($app, route: false);

        $created = $app->handle($this->post('/api/v1/npr/admin/manager-routes', ['branch' => 'Brisbane', 'managerEmail' => 'shanep@tricab.com', 'validFrom' => '2026-10-01']));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $route = $this->data($created);
        self::assertSame('Brisbane', $route['branch']);
        self::assertSame('shanep@tricab.com', self::get($route, 'manager', 'email'));
        self::assertNull($route['validTo']);
        self::assertFalse($route['isDelegate']);
        self::assertArrayHasKey('active', $route);

        $overlap = $app->handle($this->post('/api/v1/npr/admin/manager-routes', ['branch' => 'brisbane', 'managerEmail' => 'shanep@tricab.com', 'validFrom' => '2026-12-01']));
        self::assertSame(409, $overlap->getStatusCode());
        self::assertSame('NPR_ROUTE_OVERLAP', self::get(TestAppFactory::json($overlap), 'error', 'code'));

        $invalid = $app->handle($this->post('/api/v1/npr/admin/manager-routes', ['branch' => 'Perth', 'managerEmail' => 'nobody@tricab.com']));
        self::assertSame(422, $invalid->getStatusCode());

        $list = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/npr/admin/manager-routes')));
        self::assertCount(1, self::items($list, 'items'));

        $ended = $app->handle($this->put('/api/v1/npr/admin/manager-routes/' . self::str($route, 'id'), ['validTo' => '2026-12-31']));
        self::assertSame(200, $ended->getStatusCode(), (string) $ended->getBody());
        self::assertSame('2026-12-31', self::get($this->data($ended), 'items', 0, 'validTo'));

        self::assertSame(422, $app->handle($this->put('/api/v1/npr/admin/manager-routes/' . self::str($route, 'id'), []))->getStatusCode());
        self::assertSame(404, $app->handle($this->put('/api/v1/npr/admin/manager-routes/999', ['validTo' => '2026-12-31']))->getStatusCode());
        self::assertSame(404, $app->handle($this->put('/api/v1/npr/admin/manager-routes/abc', ['validTo' => '2026-12-31']))->getStatusCode());
    }

    // ------------------------------------------------------------ helpers

    private function draft(string $id, int $owner = self::ME_USER): \TriCab\Core\Npr\Domain\NprRequest
    {
        return NprFixtures::record($id, $owner, RequestStatus::Draft, 1, NprFixtures::validRequestAnswers(), NprFixtures::validVariants());
    }

    private function awaiting(string $id, int $managerId, int $owner = 99): \TriCab\Core\Npr\Domain\NprRequest
    {
        return NprFixtures::record($id, $owner, RequestStatus::AwaitingManager, 1, NprFixtures::validRequestAnswers(), NprFixtures::validVariants())
            ->with(['manager' => new NprPerson($managerId, 'manager@tricab.com', 'A Manager')]);
    }

    private function withTsd(string $id): \TriCab\Core\Npr\Domain\NprRequest
    {
        return NprFixtures::record(
            $id,
            99,
            RequestStatus::WithTsd,
            1,
            NprFixtures::validRequestAnswers(),
            NprFixtures::validVariants(),
            new DateTimeImmutable('2026-10-02 00:00:00'),
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return TestAppFactory::request('POST', $path)->withParsedBody($body);
    }

    /** @param array<string, mixed> $body */
    private function put(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return TestAppFactory::request('PUT', $path)->withParsedBody($body);
    }

    /**
     * @param list<string> $permissions
     * @return App<ContainerInterface>
     */
    private function app(array $permissions): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(self::ME_USER, ['account.login', ...$permissions], ['EMPLOYEE']);

        return $app;
    }

    /**
     * Shane exists as an active user, and (unless `$route` is false) Brisbane routes to him.
     *
     * @param App<ContainerInterface> $app
     */
    private function registerManager(App $app, bool $route = true): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserProvisioningRepository::class);
        self::assertInstanceOf(UserProvisioningRepository::class, $users);
        $shane = $users->register(
            EmailAddress::fromString('shanep@tricab.com'),
            'Shane Plumridge',
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            array_map(static fn (string $code): PermissionCode => PermissionCode::fromString($code), Module::ROLE_PERMISSIONS[Module::ROLE_MANAGER]),
            EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('shane-seed@tricab.com')),
        );
        if (!$route) {
            return;
        }
        $routes = $container->get(ManagerRouteRepository::class);
        self::assertInstanceOf(ManagerRouteRepository::class, $routes);
        $routes->create(new NewManagerRoute(
            'QLD',
            new NprPerson($shane->id()->toInt(), 'shanep@tricab.com', 'Shane Plumridge'),
            false,
            new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
            null,
            null,
        ));
    }

    /** @param App<ContainerInterface> $app */
    private function seed(App $app, \TriCab\Core\Npr\Domain\NprRequest ...$requests): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(NprRequestRepository::class);
        self::assertInstanceOf(InMemoryNprRequestRepository::class, $repository);
        foreach ($requests as $request) {
            $repository->withRequest($request);
        }
    }

    private static function get(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }

        return $data;
    }

    private static function str(mixed $data, string|int ...$path): string
    {
        $value = self::get($data, ...$path);
        self::assertTrue(is_string($value) || is_int($value));

        return (string) $value;
    }

    /** @return array<mixed> */
    private static function items(mixed $data, string|int ...$path): array
    {
        $value = self::get($data, ...$path);
        self::assertIsArray($value);

        return $value;
    }

    /** @return array<string, mixed> */
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
