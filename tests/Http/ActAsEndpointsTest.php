<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Payload;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionEntry;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSession;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserManagementAuditCriteria;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;
use TriCab\Core\Identity\Contract\UserManagementAuditRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Shared\Time\Clock;

final class ActAsEndpointsTest extends TestCase
{
    public function test_admin_switches_to_a_sales_user_and_me_returns_that_user(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-one', ['EMPLOYEE'], ['account.login', 'QUOTE.READ']);

        $sessionId = $this->start($app, $sales);

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId),
        );
        $data = Payload::obj(Payload::at(TestAppFactory::json($response), 'data'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($sales->publicId()->toString(), $data['publicId']);
        self::assertSame('sales-one@tricab.com', $data['emailAddress']);
        self::assertNotContains('ADMINISTRATOR', Payload::list($data['roles']));
        $actingAs = Payload::obj($data['actingAs']);
        self::assertSame($sessionId, $actingAs['sessionId']);
        self::assertSame('Thilan Lakshitha', Payload::at($actingAs, 'admin', 'name'));
        self::assertSame('sales-one@tricab.com', Payload::at($actingAs, 'target', 'email'));
        self::assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', Payload::str($actingAs['expiresAt']));
    }

    public function test_me_has_no_acting_as_block_when_not_switched(): void
    {
        $app = TestAppFactory::create();

        $data = Payload::obj(Payload::at(
            TestAppFactory::json($app->handle(TestAppFactory::request('GET', '/api/v1/me'))),
            'data',
        ));

        self::assertNull($data['actingAs']);
        self::assertSame('thilanh@tricab.com', $data['emailAddress']);
    }

    public function test_start_records_the_reason_and_audit_event(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-two', ['EMPLOYEE'], ['account.login']);

        $response = $this->startRequest($app, $sales->publicId()->toString(), 'Reproduce quote bug');
        $data = Payload::obj(Payload::at(TestAppFactory::json($response), 'data'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($sales->publicId()->toString(), Payload::at($data, 'target', 'publicId'));

        $expires = new DateTimeImmutable(Payload::str($data['expiresAt']));
        $minutes = ($expires->getTimestamp() - time()) / 60;
        self::assertGreaterThan(28, $minutes);
        self::assertLessThanOrEqual(30.1, $minutes);

        $session = $this->sessions($app)->findById(Payload::str($data['sessionId']));
        self::assertNotNull($session);
        self::assertSame('Reproduce quote bug', $session->reason);

        $events = $this->container($app)->get(UserManagementAuditRepository::class);
        self::assertInstanceOf(UserManagementAuditRepository::class, $events);
        $types = array_map(
            static fn ($entry): string => $entry->eventType,
            $events->list(new UserManagementAuditCriteria(eventType: 'ACT_AS_STARTED'))->items,
        );
        self::assertContains('ACT_AS_STARTED', $types);
    }

    public function test_start_requires_a_reason(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-three', ['EMPLOYEE'], ['account.login']);

        $response = $this->startRequest($app, $sales->publicId()->toString(), '   ');

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_switching_to_an_administrator_is_refused(): void
    {
        $app = TestAppFactory::create();
        $other = $this->register($app, 'admin-two', ['EMPLOYEE', 'ADMINISTRATOR'], ['account.login']);

        $response = $this->startRequest($app, $other->publicId()->toString(), 'because');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('act_as_not_allowed', Payload::at(TestAppFactory::json($response), 'error', 'code'));
    }

    public function test_switching_to_an_inactive_user_is_refused(): void
    {
        $app = TestAppFactory::create();
        $pending = $this->register($app, 'pending-user', ['EMPLOYEE'], ['account.login'], UserStatus::pending());

        $response = $this->startRequest($app, $pending->publicId()->toString(), 'because');

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_switching_to_yourself_is_refused(): void
    {
        $app = TestAppFactory::create();

        $response = $this->startRequest($app, DevelopmentUserManagementCatalog::USER_PUBLIC_ID, 'because');

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_unknown_target_is_not_found(): void
    {
        $app = TestAppFactory::create();

        $response = $this->startRequest($app, '11111111-1111-4111-8111-111111111111', 'because');

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_non_administrator_cannot_start(): void
    {
        $app = TestAppFactory::create();
        $this->register($app, 'plain-user', ['EMPLOYEE'], ['account.login']);
        $target = $this->register($app, 'target-user', ['EMPLOYEE'], ['account.login']);

        $response = $app->handle(
            $this->jsonRequest('POST', '/api/v1/admin/act-as', [
                'userPublicId' => $target->publicId()->toString(),
                'reason' => 'because',
            ])->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'plain-user')
                ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_EMAIL, 'plain-user@tricab.com'),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PLATFORM_ADMINISTRATOR_REQUIRED', Payload::at(TestAppFactory::json($response), 'error', 'code'));
    }

    public function test_ended_session_is_refused(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-four', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->start($app, $sales);

        $end = $app->handle(TestAppFactory::request('DELETE', '/api/v1/admin/act-as/' . $sessionId));
        self::assertSame(200, $end->getStatusCode());
        self::assertSame('exit', Payload::at(TestAppFactory::json($end), 'data', 'endReason'));

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('act_as_invalid', Payload::at(TestAppFactory::json($response), 'error', 'code'));
    }

    public function test_expired_session_is_refused_and_marked_expired(): void
    {
        $app = TestAppFactory::create();
        $clock = FixedClock::utc('2026-10-22 01:00:00');
        $this->container($app)->set(Clock::class, $clock);
        $sales = $this->register($app, 'sales-five', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->start($app, $sales);

        $clock->advance('+31 minutes');
        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('act_as_invalid', Payload::at(TestAppFactory::json($response), 'error', 'code'));
        $session = $this->sessions($app)->findById($sessionId);
        self::assertNotNull($session);
        self::assertSame(ActAsSession::END_REASON_EXPIRED, $session->endReason);
    }

    public function test_another_administrators_session_is_refused(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-six', ['EMPLOYEE'], ['account.login']);
        $this->register($app, 'admin-three', ['EMPLOYEE', 'ADMINISTRATOR'], ['account.login']);
        $sessionId = $this->start($app, $sales);

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')
                ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'admin-three')
                ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_EMAIL, 'admin-three@tricab.com')
                ->withHeader('X-Act-As-Session', $sessionId),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('act_as_invalid', Payload::at(TestAppFactory::json($response), 'error', 'code'));

        $end = $app->handle(
            TestAppFactory::request('DELETE', '/api/v1/admin/act-as/' . $sessionId)
                ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'admin-three')
                ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_EMAIL, 'admin-three@tricab.com'),
        );
        self::assertSame(404, $end->getStatusCode());
    }

    public function test_unknown_session_id_is_refused(): void
    {
        $app = TestAppFactory::create();

        foreach (['not-a-uuid', '11111111-1111-4111-8111-111111111111'] as $id) {
            $response = $app->handle(
                TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $id),
            );

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('act_as_invalid', Payload::at(TestAppFactory::json($response), 'error', 'code'));
        }
    }

    public function test_a_write_is_logged_and_a_read_is_not(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-seven', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->start($app, $sales);

        $app->handle(TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId));
        self::assertSame([], $this->sessions($app)->listActions($sessionId, 50));

        $write = $this->jsonRequest('POST', '/api/v1/regions/select', ['regionCode' => 'AU'])
            ->withHeader('X-Act-As-Session', $sessionId)
            ->withHeader('X-Request-Id', 'req-123');
        $response = $app->handle($write);

        $actions = $this->sessions($app)->listActions($sessionId, 50);
        self::assertCount(1, $actions);
        $action = array_shift($actions);
        self::assertInstanceOf(ActAsActionEntry::class, $action);
        self::assertSame('POST', $action->method);
        self::assertSame('/api/v1/regions/select', $action->routePattern);
        self::assertSame('/api/v1/regions/select', $action->path);
        self::assertSame($response->getStatusCode(), $action->statusCode);
        self::assertSame('req-123', $action->requestId);
        self::assertGreaterThanOrEqual(0, $action->durationMs);

        $list = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/act-as/' . $sessionId . '/actions'));
        self::assertCount(1, Payload::list(Payload::at(TestAppFactory::json($list), 'data', 'items')));
    }

    public function test_a_write_that_throws_is_still_logged(): void
    {
        $app = TestAppFactory::create();
        $app->post('/api/v1/act-as-test/throws', function (): never {
            throw new \RuntimeException('Write failed part-way through.');
        });
        $sales = $this->register($app, 'sales-throws', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->start($app, $sales);

        $response = $app->handle(
            $this->jsonRequest('POST', '/api/v1/act-as-test/throws', [])->withHeader('X-Act-As-Session', $sessionId),
        );

        self::assertSame(500, $response->getStatusCode());
        $actions = $this->sessions($app)->listActions($sessionId, 50);
        self::assertCount(1, $actions);
        $action = array_shift($actions);
        self::assertInstanceOf(ActAsActionEntry::class, $action);
        self::assertSame(500, $action->statusCode);
        self::assertSame('/api/v1/act-as-test/throws', $action->routePattern);
    }

    public function test_a_failure_to_log_does_not_fail_the_request(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-eight', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->start($app, $sales);
        $this->container($app)->set(ActAsSessionRepository::class, new class ($this->sessions($app)) implements ActAsSessionRepository {
            public function __construct(private readonly ActAsSessionRepository $inner)
            {
            }

            public function findUserIdByPublicId(\TriCab\Core\Identity\Domain\ValueObject\UserPublicId $publicId): ?\TriCab\Core\Identity\Domain\ValueObject\UserId
            {
                return $this->inner->findUserIdByPublicId($publicId);
            }

            public function create(string $sessionId, \TriCab\Core\Identity\Domain\ValueObject\UserId $adminUserId, \TriCab\Core\Identity\Domain\ValueObject\UserId $targetUserId, string $reason, DateTimeImmutable $startedAt, DateTimeImmutable $expiresAt, ?string $clientIp, ?string $userAgent): ActAsSession
            {
                return $this->inner->create($sessionId, $adminUserId, $targetUserId, $reason, $startedAt, $expiresAt, $clientIp, $userAgent);
            }

            public function findById(string $sessionId): ?ActAsSession
            {
                return $this->inner->findById($sessionId);
            }

            public function end(string $sessionId, string $endReason, DateTimeImmutable $endedAt): bool
            {
                return $this->inner->end($sessionId, $endReason, $endedAt);
            }

            public function appendAction(\TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionWrite $action): void
            {
                throw new \RuntimeException('log store down');
            }

            public function listRecent(int $limit): array
            {
                return $this->inner->listRecent($limit);
            }

            public function listActions(string $sessionId, int $limit): array
            {
                return $this->inner->listActions($sessionId, $limit);
            }
        });

        $response = $app->handle(
            $this->jsonRequest('POST', '/api/v1/regions/select', ['regionCode' => 'AU'])
                ->withHeader('X-Act-As-Session', $sessionId),
        );

        self::assertNotSame(403, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode());
    }

    public function test_permissions_checked_are_the_targets_not_the_admins(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-nine', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->start($app, $sales);

        $plain = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/users'));
        self::assertSame(200, $plain->getStatusCode());

        $switched = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/users')->withHeader('X-Act-As-Session', $sessionId),
        );
        self::assertSame(403, $switched->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', Payload::at(TestAppFactory::json($switched), 'error', 'code'));
    }

    public function test_act_as_endpoints_ignore_the_header(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-ten', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->start($app, $sales);

        $list = $app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/act-as')->withHeader('X-Act-As-Session', $sessionId),
        );
        self::assertSame(200, $list->getStatusCode());
        $items = Payload::objList(Payload::at(TestAppFactory::json($list), 'data', 'items'));
        self::assertSame($sessionId, $items[0]['sessionId']);
        self::assertSame('Thilan Lakshitha', Payload::at($items[0], 'admin', 'displayName'));
        self::assertSame('sales-ten@tricab.com', Payload::at($items[0], 'target', 'emailAddress'));

        $end = $app->handle(
            TestAppFactory::request('DELETE', '/api/v1/admin/act-as/' . $sessionId)->withHeader('X-Act-As-Session', $sessionId),
        );
        self::assertSame(200, $end->getStatusCode());

        $stale = $app->handle(
            TestAppFactory::request('DELETE', '/api/v1/admin/act-as/' . $sessionId)->withHeader('X-Act-As-Session', $sessionId),
        );
        self::assertSame(200, $stale->getStatusCode());
    }

    public function test_options_preflight_allows_the_header(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(
            TestAppFactory::request('OPTIONS', '/api/v1/me')->withHeader('Origin', 'http://localhost:5173'),
        );

        self::assertStringContainsString('X-Act-As-Session', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function test_switched_session_ends_when_target_becomes_administrator(): void
    {
        $app = TestAppFactory::create();
        $sales = $this->register($app, 'sales-eleven', ['EMPLOYEE'], ['account.login']);
        $sessionId = $this->start($app, $sales);

        $this->userRepository($app)->replacePermissions($sales->id()->toInt(), ['account.login'], ['EMPLOYEE', 'ADMINISTRATOR']);

        $response = $app->handle(
            TestAppFactory::request('GET', '/api/v1/me')->withHeader('X-Act-As-Session', $sessionId),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function start(App $app, User $target): string
    {
        $response = $this->startRequest($app, $target->publicId()->toString(), 'Investigating a support case');
        self::assertSame(201, $response->getStatusCode());

        return Payload::str(Payload::at(TestAppFactory::json($response), 'data', 'sessionId'));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function startRequest(App $app, string $publicId, string $reason): ResponseInterface
    {
        return $app->handle($this->jsonRequest('POST', '/api/v1/admin/act-as', [
            'userPublicId' => $publicId,
            'reason' => $reason,
        ]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function register(App $app, string $subject, array $roles, array $permissions, ?UserStatus $status = null): User
    {
        return $this->userRepository($app)->register(
            EmailAddress::fromString($subject . '@tricab.com'),
            $subject,
            $status ?? UserStatus::active(),
            array_map(static fn (string $code): RoleCode => RoleCode::fromString($code), $roles),
            array_map(static fn (string $code): PermissionCode => PermissionCode::fromString($code), $permissions),
            new ExternalIdentity(
                ExternalProvider::fromString(ExternalProvider::MICROSOFT_ENTRA),
                ExternalSubjectId::fromString($subject),
                ExternalTenantId::fromString('development-tenant'),
            ),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function userRepository(App $app): InMemoryApiUserRepository
    {
        $repository = $this->container($app)->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);

        return $repository;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function sessions(App $app): ActAsSessionRepository
    {
        $sessions = $this->container($app)->get(ActAsSessionRepository::class);
        self::assertInstanceOf(ActAsSessionRepository::class, $sessions);

        return $sessions;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function container(App $app): \DI\Container
    {
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);

        return $container;
    }
}
