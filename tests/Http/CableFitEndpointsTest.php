<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\CableFit\Contract\ResearchRunRepository;
use TriCab\Core\CableFit\Domain\ResearchRun;
use TriCab\Core\CableFit\Infrastructure\InMemoryResearchRunRepository;
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
            ['GET', '/api/v1/tsd/cablefit/candidates'],
            ['GET', '/api/v1/tsd/cablefit/candidates?nprRequest=x'],
            ['POST', '/api/v1/tsd/cablefit/candidates'],
            ['GET', '/api/v1/tsd/cablefit/candidates/' . self::MISSING],
            ['PUT', '/api/v1/tsd/cablefit/candidates/' . self::MISSING],
            ['DELETE', '/api/v1/tsd/cablefit/candidates/' . self::MISSING],
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

    public function test_an_empty_npr_request_parameter_is_a_400(): void
    {
        self::assertSame(400, $this->call('GET', '/api/v1/tsd/cablefit/candidates?nprRequest=')->getStatusCode());
    }

    /**
     * @return array{candidates: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    private function listAll(string $query = ''): array
    {
        $response = $this->call('GET', '/api/v1/tsd/cablefit/candidates' . ($query === '' ? '' : '?' . $query));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{data: array{candidates: list<array<string, mixed>>, total: int, limit: int, offset: int}} $payload */
        $payload = TestAppFactory::json($response);

        return $payload['data'];
    }

    /**
     * @param array{candidates: list<array<string, mixed>>} $list
     *
     * @return list<mixed>
     */
    private static function names(array $list): array
    {
        return array_column($list['candidates'], 'name');
    }

    private function putRequest(string $publicId, string $number, string $customer, int $revision = 1): void
    {
        $this->reader->put(new NprRequestSnapshot($publicId, $number, $revision, 'Title', $customer, [], []));
    }

    public function test_without_a_filter_every_design_is_listed_newest_first(): void
    {
        $this->createBlank('First');
        $this->createBlank('Second');
        $this->putRequest('npr-1', 'NPR-2026-0007', 'BHP');
        self::assertSame(201, $this->call('POST', '/api/v1/tsd/cablefit/candidates', ['name' => 'Linked', 'nprRequestPublicId' => 'npr-1'])->getStatusCode());

        $list = $this->listAll();

        self::assertSame(['Linked', 'Second', 'First'], self::names($list));
        self::assertSame(3, $list['total']);
        self::assertSame(50, $list['limit']);
        self::assertSame(0, $list['offset']);
        self::assertSame('npr-1', $list['candidates'][0]['nprRequestPublicId']);
        self::assertSame('NPR-2026-0007', $list['candidates'][0]['nprRequestNumber']);
        self::assertSame(1, $list['candidates'][0]['currentRevision']);
        self::assertFalse($list['candidates'][0]['stale']);
        self::assertNull($list['candidates'][0]['latestResearch']);
    }

    public function test_a_design_without_an_npr_request_has_no_number(): void
    {
        $this->createBlank('Standalone');

        $row = $this->listAll()['candidates'][0];

        self::assertNull($row['nprRequestPublicId']);
        self::assertNull($row['nprRequestNumber']);
        self::assertArrayHasKey('createdBy', $row);
        self::assertArrayHasKey('updatedBy', $row);
        self::assertArrayHasKey('createdAt', $row);
        self::assertArrayHasKey('updatedAt', $row);
    }

    public function test_q_matches_the_name_the_npr_number_or_the_customer(): void
    {
        $this->createBlank('Drag chain 4C');
        $this->createBlank('Reeling 3C');
        $this->putRequest('npr-1', 'NPR-2026-0007', 'Rio Tinto');
        self::assertSame(201, $this->call('POST', '/api/v1/tsd/cablefit/candidates', ['name' => 'Trailing', 'nprRequestPublicId' => 'npr-1'])->getStatusCode());

        self::assertSame(['Drag chain 4C'], self::names($this->listAll('q=drag')));
        self::assertSame(['Trailing'], self::names($this->listAll('q=0007')));
        self::assertSame(['Trailing'], self::names($this->listAll('q=rio')));
        self::assertSame(['Reeling 3C', 'Drag chain 4C'], self::names($this->listAll('q=C')));
        self::assertSame(0, $this->listAll('q=nothing')['total']);
    }

    public function test_an_npr_request_the_user_cannot_read_shows_no_number_and_is_not_searchable(): void
    {
        $this->putRequest('npr-1', 'NPR-2026-0007', 'BHP');
        self::assertSame(201, $this->call('POST', '/api/v1/tsd/cablefit/candidates', ['name' => 'Linked', 'nprRequestPublicId' => 'npr-1'])->getStatusCode());
        $this->reader->forget('npr-1');

        $list = $this->listAll();

        self::assertSame(['Linked'], self::names($list));
        self::assertSame('npr-1', $list['candidates'][0]['nprRequestPublicId']);
        self::assertNull($list['candidates'][0]['nprRequestNumber']);
        self::assertSame(0, $this->listAll('q=0007')['total']);
    }

    public function test_a_design_built_from_an_older_npr_revision_is_stale(): void
    {
        $this->putRequest('npr-1', 'NPR-2026-0007', 'BHP');
        self::assertSame(201, $this->call('POST', '/api/v1/tsd/cablefit/candidates', ['name' => 'Linked', 'nprRequestPublicId' => 'npr-1'])->getStatusCode());
        $this->putRequest('npr-1', 'NPR-2026-0007', 'BHP', 2);

        self::assertTrue($this->listAll()['candidates'][0]['stale']);
    }

    public function test_paging_uses_limit_and_offset_and_reports_the_total(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->createBlank($name);
        }

        $first = $this->listAll('limit=2');
        $second = $this->listAll('limit=2&offset=2');

        self::assertSame(['C', 'B'], self::names($first));
        self::assertSame(['A'], self::names($second));
        self::assertSame(3, $first['total']);
        self::assertSame(3, $second['total']);
        self::assertSame(200, $this->listAll('limit=500')['limit']);
    }

    public function test_bad_paging_or_query_values_are_a_400(): void
    {
        foreach (['limit=0', 'limit=-1', 'limit=abc', 'offset=-5', 'offset=1.5', 'q[]=x', 'q=' . str_repeat('x', 201)] as $query) {
            self::assertSame(400, $this->call('GET', '/api/v1/tsd/cablefit/candidates?' . $query)->getStatusCode(), $query);
        }
    }

    public function test_each_design_carries_its_latest_research_run(): void
    {
        $researched = $this->createBlank('Researched');
        $this->createBlank('Never researched');
        $container = $this->app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $runs = $container->get(ResearchRunRepository::class);
        self::assertInstanceOf(InMemoryResearchRunRepository::class, $runs);
        $runs->add(ResearchRun::queued('44444444-4444-4444-8444-444444444441', $researched, 'Drag chain', [], [], 1, null, new \DateTimeImmutable('2026-10-20T00:00:00Z')));
        $runs->add(ResearchRun::queued('44444444-4444-4444-8444-444444444442', $researched, 'Reeling', [], [], 1, null, new \DateTimeImmutable('2026-10-21T00:00:00Z')));

        $rows = array_column($this->listAll()['candidates'], null, 'name');

        self::assertNull($rows['Never researched']['latestResearch']);
        self::assertSame([
            'runPublicId' => '44444444-4444-4444-8444-444444444442',
            'status' => 'queued',
            'application' => 'Reeling',
            'verdict' => null,
            'finishedAt' => null,
        ], $rows['Researched']['latestResearch']);
    }

    public function test_the_latest_research_carries_its_verdict_once_assessed(): void
    {
        $id = $this->createBlank('Assessed');
        $container = $this->app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $runs = $container->get(ResearchRunRepository::class);
        self::assertInstanceOf(InMemoryResearchRunRepository::class, $runs);
        $runs->add(ResearchRun::queued('44444444-4444-4444-8444-444444444443', $id, 'Drag Chain', [], [], 1, null, new \DateTimeImmutable('2026-10-21T00:00:00Z')));
        $runs->markSucceeded(
            '44444444-4444-4444-8444-444444444443',
            [],
            [],
            new \TriCab\Core\CableFit\Domain\ResearchCost('m', 1, 1, 0),
            0,
            new \DateTimeImmutable('2026-10-21T00:05:00Z'),
            new \TriCab\Core\CableFit\Domain\ResearchAssessment('likely_unsuitable', 's', [], [], [], []),
        );

        $latest = $this->listAll()['candidates'][0]['latestResearch'];

        self::assertIsArray($latest);
        self::assertSame('succeeded', $latest['status']);
        self::assertSame('likely_unsuitable', $latest['verdict']);
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

    public function test_delete_hides_the_design_from_the_list_and_lookups(): void
    {
        $id = $this->createBlank('Throwaway');
        /** @var array{data: array{candidates: list<array<string, mixed>>}} $before */
        $before = TestAppFactory::json($this->call('GET', '/api/v1/tsd/cablefit/candidates'));
        self::assertTrue($before['data']['candidates'][0]['canDelete']);

        $response = $this->call('DELETE', '/api/v1/tsd/cablefit/candidates/' . $id);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{data: array<string, mixed>} $payload */
        $payload = TestAppFactory::json($response);
        self::assertSame(['candidatePublicId' => $id, 'deleted' => true], $payload['data']);

        self::assertSame(404, $this->call('GET', '/api/v1/tsd/cablefit/candidates/' . $id)->getStatusCode());
        self::assertSame(404, $this->call('DELETE', '/api/v1/tsd/cablefit/candidates/' . $id)->getStatusCode());
        /** @var array{data: array{candidates: list<array<string, mixed>>, total: int}} $after */
        $after = TestAppFactory::json($this->call('GET', '/api/v1/tsd/cablefit/candidates'));
        self::assertSame(0, $after['data']['total']);
    }

    public function test_deleting_a_missing_design_is_not_found(): void
    {
        self::assertSame(404, $this->call('DELETE', '/api/v1/tsd/cablefit/candidates/' . self::MISSING)->getStatusCode());
    }
}
