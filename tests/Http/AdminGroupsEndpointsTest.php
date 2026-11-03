<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Groups\EntraGroup;
use TriCab\Api\Identity\Groups\EntraMember;
use TriCab\Api\Identity\Groups\InMemoryEntraGroupDirectory;
use TriCab\Api\Identity\Groups\InMemoryGroupUserDirectory;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

/** The Administrator group routes over HTTP. */
final class AdminGroupsEndpointsTest extends TestCase
{
    private const PERMISSION = 'IDENTITY.GROUPS.MANAGE';
    private const ENTRA = 'aaaaaaaa-1111-4111-8111-000000000001';

    public function test_every_group_route_holds_the_permission_and_nobody_without_it_gets_in(): void
    {
        $routes = [
            ['GET', '/api/v1/admin/groups'], ['GET', '/api/v1/admin/groups/lookup'], ['GET', '/api/v1/admin/groups/entra-search'],
            ['POST', '/api/v1/admin/groups'], ['POST', '/api/v1/admin/groups/link'],
            ['GET', '/api/v1/admin/groups/x'], ['PATCH', '/api/v1/admin/groups/x'], ['DELETE', '/api/v1/admin/groups/x'],
            ['POST', '/api/v1/admin/groups/x/members'], ['DELETE', '/api/v1/admin/groups/x/members/y'],
            ['POST', '/api/v1/admin/groups/x/unlink'], ['POST', '/api/v1/admin/groups/x/sync'],
        ];

        $registered = [];
        foreach (TestAppFactory::create()->getRouteCollector()->getRoutes() as $route) {
            if (str_starts_with($route->getPattern(), '/api/v1/admin/groups')) {
                foreach ($route->getMethods() as $method) {
                    $registered[] = $method . ' ' . str_replace(['{publicId}', '{userPublicId}'], ['x', 'y'], $route->getPattern());
                }
            }
        }
        $expected = array_map(static fn (array $r): string => $r[0] . ' ' . $r[1], $routes);
        sort($registered);
        sort($expected);
        self::assertSame($expected, $registered, 'A new group route must be listed here.');

        $app = $this->app(['USER.READ', 'ROLE.READ']);
        foreach ($routes as [$method, $path]) {
            self::assertSame(403, $app->handle(TestAppFactory::request($method, $path))->getStatusCode(), "$method $path");
        }
        self::assertSame(401, TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false'])->handle(TestAppFactory::request('GET', '/api/v1/admin/groups'))->getStatusCode());
    }

    public function test_an_administrator_creates_edits_and_fills_a_platform_group(): void
    {
        $app = $this->app([self::PERMISSION]);
        $userId = $this->directory($app)->add('ann@tricab.com', 'Ann');
        $userPublicId = $this->directory($app)->describe([$userId])[$userId]['publicId'];

        $created = $this->call($app, 'POST', '/api/v1/admin/groups', ['name' => 'Warehouse', 'description' => 'Pickers']);
        self::assertSame(201, $created->getStatusCode());
        $publicId = $this->text($this->data($created)['publicId']);

        $added = $this->data($this->call($app, 'POST', "/api/v1/admin/groups/$publicId/members", ['userPublicId' => $userPublicId]));
        self::assertSame(1, $added['memberCount']);
        self::assertSame('Ann', $this->rows($added['members'])[0]['displayName']);

        self::assertSame(409, $this->call($app, 'POST', '/api/v1/admin/groups', ['name' => 'warehouse'])->getStatusCode());
        self::assertSame(422, $this->call($app, 'POST', '/api/v1/admin/groups', ['name' => ''])->getStatusCode());

        $renamed = $this->data($this->call($app, 'PATCH', "/api/v1/admin/groups/$publicId", ['name' => 'Warehouse AU']));
        self::assertSame('Warehouse AU', $renamed['name']);

        $list = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/admin/groups?q=ware')));
        self::assertSame(1, $this->rows($list['items'])[0]['memberCount']);

        $removed = $this->data($app->handle(TestAppFactory::request('DELETE', "/api/v1/admin/groups/$publicId/members/$userPublicId")));
        self::assertSame(0, $removed['memberCount']);
        self::assertSame(200, $app->handle(TestAppFactory::request('DELETE', "/api/v1/admin/groups/$publicId"))->getStatusCode());
        self::assertSame(404, $app->handle(TestAppFactory::request('GET', "/api/v1/admin/groups/$publicId"))->getStatusCode());
    }

    public function test_linking_an_entra_group_syncs_it_flags_pending_members_and_locks_member_edits(): void
    {
        $app = $this->app([self::PERMISSION]);
        $this->entra($app)->withGroup(new EntraGroup(self::ENTRA, 'Finance Controls', 'fc@tricab.com', null), [
            new EntraMember('o1', 'New Person', 'new@tricab.com', 'new@tricab.com'),
        ]);

        $search = $this->data($app->handle(TestAppFactory::request('GET', '/api/v1/admin/groups/entra-search?q=fin')));
        self::assertSame(self::ENTRA, $this->rows($search['items'])[0]['entraGroupId']);

        $linked = $this->call($app, 'POST', '/api/v1/admin/groups/link', ['entraGroupId' => self::ENTRA]);
        self::assertSame(201, $linked->getStatusCode());
        $data = $this->data($linked);
        $group = $this->assoc($data['group']);
        self::assertSame(1, $this->assoc($data['sync'])['createdPending']);
        self::assertSame(1, $group['pendingMemberCount']);
        self::assertSame('pending', $this->rows($group['members'])[0]['status']);
        self::assertTrue($group['membersReadOnly']);

        $publicId = $this->text($group['publicId']);
        $memberId = $this->text($this->rows($group['members'])[0]['userPublicId']);
        $locked = $app->handle(TestAppFactory::request('DELETE', "/api/v1/admin/groups/$publicId/members/$memberId"));
        self::assertSame(409, $locked->getStatusCode());
        self::assertSame('GROUP_MEMBERS_READ_ONLY', $this->code($locked));
        self::assertSame(409, $this->call($app, 'POST', "/api/v1/admin/groups/$publicId/members", ['userPublicId' => $memberId])->getStatusCode());
        self::assertSame(409, $this->call($app, 'POST', '/api/v1/admin/groups/link', ['entraGroupId' => self::ENTRA])->getStatusCode());

        $this->entra($app)->removeGroup(self::ENTRA);
        $this->entra($app)->withGroup(new EntraGroup(self::ENTRA, 'Finance Controls', null, null), []);
        $synced = $this->data($this->call($app, 'POST', "/api/v1/admin/groups/$publicId/sync", ['allowShrink' => true]));
        self::assertSame(1, $this->assoc($synced['sync'])['removed']);

        $unlinked = $this->data($app->handle(TestAppFactory::request('POST', "/api/v1/admin/groups/$publicId/unlink")));
        self::assertTrue($unlinked['isUnlinked']);
        self::assertSame(409, $app->handle(TestAppFactory::request('POST', "/api/v1/admin/groups/$publicId/sync"))->getStatusCode());
    }

    public function test_sync_now_guards_a_short_read_unless_the_body_accepts_the_shrink(): void
    {
        $app = $this->app([self::PERMISSION]);
        $this->entra($app)->withGroup(new EntraGroup(self::ENTRA, 'Finance Controls', null, null), [
            new EntraMember('o1', 'One', 'one@tricab.com', 'one@tricab.com'),
        ]);
        $group = $this->assoc($this->data($this->call($app, 'POST', '/api/v1/admin/groups/link', ['entraGroupId' => self::ENTRA]))['group']);
        $publicId = $this->text($group['publicId']);
        $this->entra($app)->setMembers(self::ENTRA, []);

        $guarded = $this->data($app->handle(TestAppFactory::request('POST', "/api/v1/admin/groups/$publicId/sync")));
        self::assertSame('guarded', $this->assoc($guarded['sync'])['status']);
        self::assertSame(1, $this->assoc($guarded['group'])['memberCount']);

        $accepted = $this->data($this->call($app, 'POST', "/api/v1/admin/groups/$publicId/sync", ['allowShrink' => true]));
        self::assertSame(0, $this->assoc($accepted['group'])['memberCount']);
    }

    public function test_before_consent_the_entra_routes_answer_503_with_a_typed_code_and_the_rest_keep_working(): void
    {
        $app = $this->app([self::PERMISSION]);
        $this->entra($app)->denyPermission();

        foreach ([['GET', '/api/v1/admin/groups/entra-search?q=fin', null], ['POST', '/api/v1/admin/groups/link', ['entraGroupId' => self::ENTRA]]] as [$method, $path, $body]) {
            $response = $body === null ? $app->handle(TestAppFactory::request($method, $path)) : $this->call($app, $method, $path, $body);
            self::assertSame(503, $response->getStatusCode(), $path);
            self::assertSame('GRAPH_PERMISSION_NOT_GRANTED', $this->code($response));
        }

        self::assertSame(201, $this->call($app, 'POST', '/api/v1/admin/groups', ['name' => 'Still works'])->getStatusCode());
        self::assertSame(200, $app->handle(TestAppFactory::request('GET', '/api/v1/admin/groups'))->getStatusCode());
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', ...$permissions], ['EMPLOYEE']);

        return $app;
    }

    /** @param App<ContainerInterface> $app */
    private function directory(App $app): InMemoryGroupUserDirectory
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $directory = $container->get(InMemoryGroupUserDirectory::class);
        self::assertInstanceOf(InMemoryGroupUserDirectory::class, $directory);

        return $directory;
    }

    /** @param App<ContainerInterface> $app */
    private function entra(App $app): InMemoryEntraGroupDirectory
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $entra = $container->get(InMemoryEntraGroupDirectory::class);
        self::assertInstanceOf(InMemoryEntraGroupDirectory::class, $entra);

        return $entra;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $body
     */
    private function call(App $app, string $method, string $path, array $body): ResponseInterface
    {
        $request = $this->json($method, $path, $body);

        return $app->handle($request);
    }

    /** @param array<string, mixed> $body */
    private function json(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $payload = TestAppFactory::json($response);
        self::assertTrue($payload['success'] ?? false, json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->assoc($payload['data'] ?? null);
    }

    private function code(ResponseInterface $response): string
    {
        $error = TestAppFactory::json($response)['error'] ?? null;

        return is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : '';
    }

    /** @return array<string, mixed> */
    private function assoc(mixed $value): array
    {
        self::assertIsArray($value);
        $found = [];
        foreach ($value as $key => $item) {
            $found[(string) $key] = $item;
        }

        return $found;
    }

    private function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(fn (mixed $row): array => $this->assoc($row), $value));
    }
}
