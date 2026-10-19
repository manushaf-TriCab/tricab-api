<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\CableFit\Application\ResearchRunner;
use TriCab\Core\CableFit\Contract\ResearchModel;
use TriCab\Core\CableFit\Module;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Npr\NprRequestReader;
use TriCab\Core\Tests\CableFit\FakeNprRequestReader;
use TriCab\Core\Tests\CableFit\FakeResearchModel;

/** The research endpoints (C3 contract): permissions, validation, queueing, and reading a finished run. */
final class CableFitResearchEndpointsTest extends TestCase
{
    private const MISSING = '33333333-3333-4333-8333-333333333333';

    private string $wakeDir;

    protected function setUp(): void
    {
        $this->wakeDir = sys_get_temp_dir() . '/cablefit-endpoints-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->wakeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->wakeDir);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function app(bool $researchOn = true): App
    {
        $app = TestAppFactory::create([
            'CABLEFIT_RESEARCH_ENABLED' => $researchOn ? 'true' : 'false',
            'CABLEFIT_RESEARCH_WAKE_PATH' => $this->wakeDir,
        ]);
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $container->set(NprRequestReader::class, new FakeNprRequestReader());
        $this->grant($app, [Module::PERMISSION_USE, Module::PERMISSION_RESEARCH]);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string>            $permissions
     */
    private function grant(App $app, array $permissions): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['ACCOUNT.LOGIN', ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $body
     */
    private function call(App $app, string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = TestAppFactory::request($method, $path);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        }

        return $app->handle($request);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function data(App $app, string $method, string $path, ?array $body = null, int $status = 200): array
    {
        $response = $this->call($app, $method, $path, $body);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{data: array<string, mixed>} $payload */
        $payload = TestAppFactory::json($response);

        return $payload['data'];
    }

    /**
     * Queue a run (expecting a 202) and return its public id.
     *
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>    $body
     */
    private function queueRun(App $app, string $candidate, array $body): string
    {
        $id = $this->data($app, 'POST', "/api/v1/tsd/cablefit/candidates/$candidate/research", $body, 202)['runPublicId'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $rows = $data[$key] ?? null;
        self::assertIsArray($rows);
        /** @var list<array<string, mixed>> $rows */

        return $rows;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>|null $body
     */
    private function errorCode(App $app, string $method, string $path, ?array $body, int $status): string
    {
        $response = $this->call($app, $method, $path, $body);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{error: array{code: string}} $payload */
        $payload = TestAppFactory::json($response);

        return $payload['error']['code'];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function candidate(App $app, bool $withConstruction = true): string
    {
        $id = $this->data($app, 'POST', '/api/v1/tsd/cablefit/candidates', ['name' => 'Design'], 201)['candidatePublicId'];
        self::assertIsString($id);
        if ($withConstruction) {
            $this->data($app, 'PUT', '/api/v1/tsd/cablefit/candidates/' . $id, ['version' => 1, 'construction' => ['conductor' => ['material' => 'copper', 'size_mm2' => 95], 'insulation' => ['material' => 'XLPE']]]);
        }

        return $id;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function source(App $app, string $domain = 'iec.ch'): string
    {
        $id = $this->data($app, 'POST', '/api/v1/tsd/cablefit/research-sources', ['domain' => $domain, 'name' => 'IEC', 'category' => 'standards_body'], 201)['publicId'];
        self::assertIsString($id);

        return $id;
    }

    public function test_reads_need_use_and_writes_need_research(): void
    {
        $app = $this->app();
        $candidate = $this->candidate($app);
        $this->source($app);
        $run = $this->queueRun($app, $candidate, ['application' => 'a']);

        $reads = [
            ['GET', '/api/v1/tsd/cablefit/research-sources'],
            ['GET', "/api/v1/tsd/cablefit/candidates/$candidate/research"],
            ['GET', "/api/v1/tsd/cablefit/candidates/$candidate/research/$run"],
        ];
        $writes = [
            ['POST', '/api/v1/tsd/cablefit/research-sources'],
            ['PUT', '/api/v1/tsd/cablefit/research-sources/' . self::MISSING],
            ['POST', "/api/v1/tsd/cablefit/candidates/$candidate/research"],
        ];

        // USE alone reads but cannot write.
        $this->grant($app, [Module::PERMISSION_USE]);
        foreach ($reads as [$method, $path]) {
            self::assertSame(200, $this->call($app, $method, $path)->getStatusCode(), "$method $path");
        }
        foreach ($writes as [$method, $path]) {
            self::assertSame(403, $this->call($app, $method, $path, ['application' => 'a'])->getStatusCode(), "$method $path");
        }

        // RESEARCH alone writes but cannot read.
        $this->grant($app, [Module::PERMISSION_RESEARCH]);
        foreach ($reads as [$method, $path]) {
            self::assertSame(403, $this->call($app, $method, $path)->getStatusCode(), "$method $path");
        }
        self::assertSame(202, $this->call($app, 'POST', "/api/v1/tsd/cablefit/candidates/$candidate/research", ['application' => 'again'])->getStatusCode());

        // Neither: nothing.
        $this->grant($app, []);
        foreach ([...$reads, ...$writes] as [$method, $path]) {
            self::assertSame(403, $this->call($app, $method, $path, ['application' => 'a'])->getStatusCode(), "$method $path");
        }
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);

        self::assertSame(401, $this->call($app, 'GET', '/api/v1/tsd/cablefit/research-sources')->getStatusCode());
        self::assertSame(401, $this->call($app, 'POST', '/api/v1/tsd/cablefit/candidates/' . self::MISSING . '/research', ['application' => 'a'])->getStatusCode());
    }

    public function test_with_no_enabled_sources_a_run_is_a_422(): void
    {
        $app = $this->app();
        $candidate = $this->candidate($app);

        self::assertSame('CABLEFIT_NO_SOURCES', $this->errorCode($app, 'POST', "/api/v1/tsd/cablefit/candidates/$candidate/research", ['application' => 'a'], 422));

        $id = $this->source($app);
        $this->data($app, 'PUT', "/api/v1/tsd/cablefit/research-sources/$id", ['name' => 'IEC', 'category' => 'standards_body', 'enabled' => false]);
        self::assertSame('CABLEFIT_NO_SOURCES', $this->errorCode($app, 'POST', "/api/v1/tsd/cablefit/candidates/$candidate/research", ['application' => 'a'], 422));
        self::assertFileDoesNotExist($this->wakeDir . '/wake');
    }

    public function test_when_research_is_off_a_run_is_a_503_and_nothing_is_signalled(): void
    {
        $app = $this->app(false);
        $candidate = $this->candidate($app);
        $this->source($app);

        self::assertSame('CABLEFIT_RESEARCH_OFF', $this->errorCode($app, 'POST', "/api/v1/tsd/cablefit/candidates/$candidate/research", ['application' => 'a'], 503));
        self::assertFileDoesNotExist($this->wakeDir . '/wake');
        // Reading and the source list still work while it is off.
        self::assertSame([], self::rows($this->data($app, 'GET', "/api/v1/tsd/cablefit/candidates/$candidate/research"), 'runs'));
    }

    public function test_research_is_off_by_default(): void
    {
        $app = TestAppFactory::create(['CABLEFIT_RESEARCH_WAKE_PATH' => $this->wakeDir]);
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $container->set(NprRequestReader::class, new FakeNprRequestReader());
        $this->grant($app, [Module::PERMISSION_USE, Module::PERMISSION_RESEARCH]);
        $candidate = $this->candidate($app);
        $this->source($app);

        self::assertSame('CABLEFIT_RESEARCH_OFF', $this->errorCode($app, 'POST', "/api/v1/tsd/cablefit/candidates/$candidate/research", ['application' => 'a'], 503));
    }

    public function test_a_run_is_queued_with_a_202_and_the_worker_is_signalled(): void
    {
        $app = $this->app();
        $candidate = $this->candidate($app);
        $this->source($app);

        $queued = $this->data($app, 'POST', "/api/v1/tsd/cablefit/candidates/$candidate/research", ['application' => '  underground distribution in a hot climate '], 202);

        self::assertSame('queued', $queued['status']);
        self::assertIsString($queued['runPublicId']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $queued['runPublicId']);
        self::assertFileExists($this->wakeDir . '/wake');

        $list = self::rows($this->data($app, 'GET', "/api/v1/tsd/cablefit/candidates/$candidate/research"), 'runs');
        self::assertCount(1, $list);
        self::assertSame(
            ['runPublicId', 'status', 'application', 'requestedAt', 'requestedByName', 'startedAt', 'finishedAt', 'findingCount', 'error'],
            array_keys($list[0]),
        );
        self::assertSame('queued', $list[0]['status']);
        self::assertSame('underground distribution in a hot climate', $list[0]['application']);
        self::assertNull($list[0]['startedAt']);
        self::assertNull($list[0]['finishedAt']);
        self::assertSame(0, $list[0]['findingCount']);
        self::assertNull($list[0]['error']);
    }

    public function test_a_run_with_no_parameters_records_the_latest_construction_and_given_parameters_win(): void
    {
        $app = $this->app();
        $candidate = $this->candidate($app);
        $this->source($app);

        $a = $this->queueRun($app, $candidate, ['application' => 'a']);
        $b = $this->queueRun($app, $candidate, ['application' => 'b', 'parameters' => ['conductor' => ['material' => 'aluminium']]]);

        $first = $this->data($app, 'GET', "/api/v1/tsd/cablefit/candidates/$candidate/research/$a");
        $second = $this->data($app, 'GET', "/api/v1/tsd/cablefit/candidates/$candidate/research/$b");
        self::assertSame(['conductor' => ['material' => 'copper', 'size_mm2' => 95], 'insulation' => ['material' => 'XLPE']], $first['parameters']);
        self::assertSame(['conductor' => ['material' => 'aluminium']], $second['parameters']);
        self::assertSame(['iec.ch'], $first['sourcesUsed']);
        self::assertSame(['inputTokens' => 0, 'outputTokens' => 0, 'searchRequests' => 0], $first['cost']);
        self::assertSame([], $first['findings']);
        self::assertNull($first['model']);

        // Newest first.
        $runs = self::rows($this->data($app, 'GET', "/api/v1/tsd/cablefit/candidates/$candidate/research"), 'runs');
        self::assertSame($b, $runs[0]['runPublicId'] ?? null);
    }

    public function test_request_validation(): void
    {
        $app = $this->app();
        $candidate = $this->candidate($app);
        $blank = $this->candidate($app, false);
        $this->source($app);
        $path = "/api/v1/tsd/cablefit/candidates/$candidate/research";

        foreach ([[], ['application' => ''], ['application' => 5], ['application' => str_repeat('x', 2001)], ['application' => 'a', 'parameters' => 'text'], ['application' => 'a', 'parameters' => [1, 2]]] as $body) {
            self::assertSame('CABLEFIT_INPUT_INVALID', $this->errorCode($app, 'POST', $path, $body, 422), json_encode($body) ?: '');
        }
        // A blank design has nothing to research unless parameters are sent.
        self::assertSame('CABLEFIT_INPUT_INVALID', $this->errorCode($app, 'POST', "/api/v1/tsd/cablefit/candidates/$blank/research", ['application' => 'a'], 422));
        self::assertSame(202, $this->call($app, 'POST', "/api/v1/tsd/cablefit/candidates/$blank/research", ['application' => 'a', 'parameters' => ['conductor' => ['material' => 'copper']]])->getStatusCode());
        // Unknown candidate and unknown run.
        self::assertSame('CABLEFIT_CANDIDATE_NOT_FOUND', $this->errorCode($app, 'POST', '/api/v1/tsd/cablefit/candidates/' . self::MISSING . '/research', ['application' => 'a'], 404));
        self::assertSame('CABLEFIT_CANDIDATE_NOT_FOUND', $this->errorCode($app, 'GET', '/api/v1/tsd/cablefit/candidates/' . self::MISSING . '/research', null, 404));
        self::assertSame('CABLEFIT_RUN_NOT_FOUND', $this->errorCode($app, 'GET', "$path/" . self::MISSING, null, 404));
    }

    public function test_a_run_of_another_candidate_is_not_found(): void
    {
        $app = $this->app();
        $one = $this->candidate($app);
        $two = $this->candidate($app);
        $this->source($app);
        $run = $this->queueRun($app, $one, ['application' => 'a']);

        self::assertSame('CABLEFIT_RUN_NOT_FOUND', $this->errorCode($app, 'GET', "/api/v1/tsd/cablefit/candidates/$two/research/$run", null, 404));
    }

    public function test_source_crud(): void
    {
        $app = $this->app();

        $id = $this->source($app);
        self::assertSame('CABLEFIT_SOURCE_EXISTS', $this->errorCode($app, 'POST', '/api/v1/tsd/cablefit/research-sources', ['domain' => 'IEC.ch', 'name' => 'Again', 'category' => 'other'], 409));
        self::assertSame('CABLEFIT_INPUT_INVALID', $this->errorCode($app, 'POST', '/api/v1/tsd/cablefit/research-sources', ['domain' => 'https://iec.ch/x', 'name' => 'n', 'category' => 'other'], 422));
        self::assertSame('CABLEFIT_INPUT_INVALID', $this->errorCode($app, 'POST', '/api/v1/tsd/cablefit/research-sources', ['domain' => 'x.example.com', 'name' => 'n', 'category' => 'blog'], 422));

        $this->data($app, 'PUT', "/api/v1/tsd/cablefit/research-sources/$id", ['name' => 'IEC Geneva', 'category' => 'other', 'enabled' => false, 'notes' => 'paused'], 200);
        self::assertSame('CABLEFIT_SOURCE_NOT_FOUND', $this->errorCode($app, 'PUT', '/api/v1/tsd/cablefit/research-sources/' . self::MISSING, ['name' => 'n', 'category' => 'other', 'enabled' => true], 404));
        self::assertSame('CABLEFIT_INPUT_INVALID', $this->errorCode($app, 'PUT', "/api/v1/tsd/cablefit/research-sources/$id", ['name' => 'n', 'category' => 'other'], 422));
        // The domain in the body is ignored: it cannot change.
        $this->data($app, 'PUT', "/api/v1/tsd/cablefit/research-sources/$id", ['domain' => 'evil.com', 'name' => 'IEC Geneva', 'category' => 'other', 'enabled' => false, 'notes' => 'paused'], 200);

        $sources = self::rows($this->data($app, 'GET', '/api/v1/tsd/cablefit/research-sources'), 'sources');
        self::assertCount(1, $sources);
        self::assertSame(['publicId', 'domain', 'name', 'category', 'enabled', 'notes', 'updatedAt'], array_keys($sources[0]));
        self::assertSame($id, $sources[0]['publicId']);
        self::assertSame('iec.ch', $sources[0]['domain']);
        self::assertSame('IEC Geneva', $sources[0]['name']);
        self::assertSame('other', $sources[0]['category']);
        self::assertFalse($sources[0]['enabled']);
        self::assertSame('paused', $sources[0]['notes']);
    }

    public function test_a_finished_run_is_read_with_findings_recent_first(): void
    {
        $app = $this->app();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $candidate = $this->candidate($app);
        $this->source($app);
        $this->source($app, 'prysmian.com');
        $run = $this->queueRun($app, $candidate, ['application' => 'underground']);

        $recent = (new \DateTimeImmutable('-2 months'))->format('Y-m-d');
        $container->set(ResearchModel::class, new FakeResearchModel(FakeResearchModel::answer(
            FakeResearchModel::json([
                FakeResearchModel::finding(['claim' => 'old standard', 'url' => 'https://webstore.iec.ch/old', 'publishedOn' => '2015-03-01', 'dateBasis' => 'stated_on_page', 'confidence' => 'high']),
                FakeResearchModel::finding(['claim' => 'fresh', 'url' => 'https://www.prysmian.com/new', 'confidence' => 'medium', 'publisher' => 'Prysmian']),
                FakeResearchModel::finding(['claim' => 'off list', 'url' => 'https://iec.ch.evil.com/x']),
                FakeResearchModel::finding(['claim' => 'no date', 'url' => 'https://iec.ch/nodate', 'confidence' => 'low']),
            ]),
            ['https://www.prysmian.com/new' => (new \DateTimeImmutable($recent))->format('F j, Y')],
        )));
        $runner = $container->get(ResearchRunner::class);
        self::assertInstanceOf(ResearchRunner::class, $runner);
        self::assertSame('succeeded', $runner->runNext()?->status);

        $detail = $this->data($app, 'GET', "/api/v1/tsd/cablefit/candidates/$candidate/research/$run");

        self::assertSame('succeeded', $detail['status']);
        self::assertSame('claude-sonnet-5', $detail['model']);
        self::assertSame(['inputTokens' => 1000, 'outputTokens' => 200, 'searchRequests' => 1], $detail['cost']);
        self::assertSame(3, $detail['findingCount']);
        self::assertSame(1, $detail['droppedFindings']);
        self::assertSame(['iec.ch', 'prysmian.com'], $detail['sourcesUsed']);
        $findings = self::rows($detail, 'findings');
        self::assertSame(['fresh', 'old standard', 'no date'], array_map(static fn (array $f) => $f['claim'], $findings));
        self::assertSame(
            ['publicId', 'parameter', 'claim', 'relevance', 'url', 'title', 'publisher', 'publishedOn', 'dateBasis', 'withinRecentWindow', 'confidence'],
            array_keys($findings[0]),
        );
        self::assertSame($recent, $findings[0]['publishedOn']);
        self::assertSame('page_age', $findings[0]['dateBasis']);
        self::assertTrue($findings[0]['withinRecentWindow']);
        self::assertSame('Prysmian', $findings[0]['publisher']);
        self::assertSame(['stated_on_page', false, '2015-03-01'], [$findings[1]['dateBasis'], $findings[1]['withinRecentWindow'], $findings[1]['publishedOn']]);
        self::assertSame(['undated', false, null], [$findings[2]['dateBasis'], $findings[2]['withinRecentWindow'], $findings[2]['publishedOn']]);

        $list = self::rows($this->data($app, 'GET', "/api/v1/tsd/cablefit/candidates/$candidate/research"), 'runs');
        self::assertSame(['succeeded', 3], [$list[0]['status'], $list[0]['findingCount']]);
    }

    public function test_a_failed_run_shows_its_error(): void
    {
        $app = $this->app();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $candidate = $this->candidate($app);
        $this->source($app);
        $run = $this->queueRun($app, $candidate, ['application' => 'a']);

        // With Foundry not configured the default model fails the run cleanly.
        $runner = $container->get(ResearchRunner::class);
        self::assertInstanceOf(ResearchRunner::class, $runner);
        self::assertSame('failed', $runner->runNext()?->status);

        $detail = $this->data($app, 'GET', "/api/v1/tsd/cablefit/candidates/$candidate/research/$run");
        self::assertSame('failed', $detail['status']);
        self::assertSame('The research model is not configured.', $detail['error']);
        self::assertNotNull($detail['finishedAt']);
    }
}
