<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\CableFit\Module;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Npr\NprRequestReader;
use TriCab\Core\Npr\NprRequestSnapshot;
use TriCab\Core\Tests\CableFit\FakeNprRequestReader;

final class CableFitEndpointsTest extends TestCase
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const MISSING = '33333333-3333-4333-8333-333333333333';

    /** @var App<ContainerInterface> */
    private App $app;

    private FakeNprRequestReader $reader;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create();
        $container = $this->app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $this->reader = new FakeNprRequestReader();
        $container->set(NprRequestReader::class, $this->reader);
        $this->grant([Module::PERMISSION_USE]);
    }

    /**
     * @param list<string> $permissions
     */
    private function grant(array $permissions): void
    {
        $container = $this->app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['ACCOUNT.LOGIN', ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null): \Psr\Http\Message\ResponseInterface
    {
        $request = TestAppFactory::request($method, $path);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        }

        return $this->app->handle($request);
    }

    private function createBlank(string $name = 'Blank design'): string
    {
        $response = $this->call('POST', '/api/v1/tsd/cablefit/candidates', ['name' => $name]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{data: array{candidatePublicId: string}} $payload */
        $payload = TestAppFactory::json($response);

        return $payload['data']['candidatePublicId'];
    }

    public function test_every_route_needs_the_use_permission(): void
    {
        $this->grant([Module::PERMISSION_RESEARCH]);

        foreach ([
            ['GET', '/api/v1/tsd/cablefit/candidates?nprRequest=x'],
            ['POST', '/api/v1/tsd/cablefit/candidates'],
            ['GET', '/api/v1/tsd/cablefit/candidates/' . self::MISSING],
            ['PUT', '/api/v1/tsd/cablefit/candidates/' . self::MISSING],
        ] as [$method, $path]) {
            self::assertSame(403, $this->call($method, $path, $method === 'GET' ? null : [])->getStatusCode(), "$method $path");
        }
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        self::assertSame(401, $app->handle(TestAppFactory::request('GET', '/api/v1/tsd/cablefit/candidates?nprRequest=x'))->getStatusCode());
    }

    public function test_a_blank_candidate_is_created_with_a_guid_and_read_back_at_version_one(): void
    {
        $id = $this->createBlank('  My cable  ');
        self::assertMatchesRegularExpression(self::GUID, $id);

        $response = $this->call('GET', '/api/v1/tsd/cablefit/candidates/' . $id);
        self::assertSame(200, $response->getStatusCode());
        /** @var array{data: array<string, mixed>} $payload */
        $payload = TestAppFactory::json($response);
        self::assertSame($id, $payload['data']['candidatePublicId']);
        self::assertSame('My cable', $payload['data']['name']);
        self::assertSame(1, $payload['data']['version']);
        self::assertNull($payload['data']['nprRequestPublicId']);
    }

    public function test_create_validates_the_name(): void
    {
        foreach ([[], ['name' => ''], ['name' => '   '], ['name' => 5], ['name' => str_repeat('x', 256)]] as $body) {
            self::assertSame(422, $this->call('POST', '/api/v1/tsd/cablefit/candidates', $body)->getStatusCode());
        }
        self::assertSame(201, $this->call('POST', '/api/v1/tsd/cablefit/candidates', ['name' => str_repeat('x', 255)])->getStatusCode());
    }

    public function test_an_unknown_npr_request_is_a_404_on_create_and_list(): void
    {
        self::assertSame(404, $this->call('POST', '/api/v1/tsd/cablefit/candidates', ['name' => 'n', 'nprRequestPublicId' => 'npr-1'])->getStatusCode());
        self::assertSame(404, $this->call('GET', '/api/v1/tsd/cablefit/candidates?nprRequest=npr-1')->getStatusCode());
    }

    public function test_listing_needs_the_npr_request_parameter(): void
    {
        self::assertSame(400, $this->call('GET', '/api/v1/tsd/cablefit/candidates')->getStatusCode());
        self::assertSame(400, $this->call('GET', '/api/v1/tsd/cablefit/candidates?nprRequest=')->getStatusCode());
    }

    public function test_a_candidate_linked_to_a_readable_request_is_listed(): void
    {
        $this->reader->put(new NprRequestSnapshot('npr-1', 'NPR-0001', 1, 'Title', 'Customer', [
            'voltage_rating' => 11,
            'main_core_count' => 3,
            'conductor_size' => 95,
        ], []));

        $create = $this->call('POST', '/api/v1/tsd/cablefit/candidates', ['name' => 'Linked', 'nprRequestPublicId' => 'npr-1']);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());

        $list = $this->call('GET', '/api/v1/tsd/cablefit/candidates?nprRequest=npr-1');
        self::assertSame(200, $list->getStatusCode());
        /** @var array{data: array{candidates: list<array<string, mixed>>}} $payload */
        $payload = TestAppFactory::json($list);
        self::assertCount(1, $payload['data']['candidates']);
        self::assertSame('Linked', $payload['data']['candidates'][0]['name']);
        self::assertSame('npr-1', $payload['data']['candidates'][0]['nprRequestPublicId']);
        self::assertFalse($payload['data']['candidates'][0]['stale']);
    }

    public function test_an_unknown_candidate_is_a_404(): void
    {
        self::assertSame(404, $this->call('GET', '/api/v1/tsd/cablefit/candidates/' . self::MISSING)->getStatusCode());
        self::assertSame(404, $this->call('PUT', '/api/v1/tsd/cablefit/candidates/' . self::MISSING, ['version' => 1, 'construction' => ['a' => 1]])->getStatusCode());
    }

    public function test_save_advances_the_version_and_a_stale_version_is_a_409(): void
    {
        $id = $this->createBlank();
        $path = '/api/v1/tsd/cablefit/candidates/' . $id;

        $saved = $this->call('PUT', $path, ['version' => 1, 'construction' => ['conductorSize' => 95]]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        /** @var array{data: array{version: int}} $payload */
        $payload = TestAppFactory::json($saved);
        self::assertSame(2, $payload['data']['version']);

        $detail = TestAppFactory::json($this->call('GET', $path));
        /** @var array{data: array{version: int, construction: array<string, mixed>}} $detail */
        self::assertSame(2, $detail['data']['version']);
        self::assertSame(['conductorSize' => 95], $detail['data']['construction']);

        $conflict = $this->call('PUT', $path, ['version' => 1, 'construction' => ['conductorSize' => 120]]);
        self::assertSame(409, $conflict->getStatusCode());
        $body = TestAppFactory::json($conflict);
        self::assertIsArray($body['error']);
        self::assertSame(2, $body['error']['currentVersion']);
        self::assertArrayHasKey('error', $body);
    }

    public function test_save_validates_its_body(): void
    {
        $path = '/api/v1/tsd/cablefit/candidates/' . $this->createBlank();

        foreach ([
            [],
            ['version' => 1],
            ['version' => '1', 'construction' => ['a' => 1]],
            ['version' => 0, 'construction' => ['a' => 1]],
            ['version' => 1, 'construction' => 'x'],
            ['version' => 1, 'construction' => [1, 2]],
        ] as $body) {
            self::assertSame(422, $this->call('PUT', $path, $body)->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        }
    }
}
