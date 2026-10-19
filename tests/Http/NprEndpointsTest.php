<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Npr\NprFixtures;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Npr\Infrastructure\InMemoryNprRequestRepository;
use TriCab\Core\Npr\Module;

/** The NPR endpoints end to end over the in-memory repository: shapes, ownership and the TSD view. */
final class NprEndpointsTest extends TestCase
{
    private const ME = '/api/v1/npr/me/requests';
    private const TSD = '/api/v1/npr/tsd/requests';

    /** The id InMemoryApiUserRepository authenticates as. */
    private const SIGNED_IN_USER_ID = 1;

    private const COLLEAGUES = '22222222-2222-4222-8222-222222222222';
    private const WITH_TSD = '33333333-3333-4333-8333-333333333333';
    private const STILL_DRAFT = '44444444-4444-4444-8444-444444444444';

    public function test_a_requester_creates_saves_and_reads_a_draft(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);

        $created = $app->handle(TestAppFactory::request('POST', self::ME)->withParsedBody([
            'answers' => ['job_title' => ['state' => 'provided', 'value' => 'Mining Trailing Cable 33kV']],
            'requester' => ['email' => 'someoneelse@tricab.com'],
        ]));
        $draft = $this->data($created);

        self::assertSame(201, $created->getStatusCode());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', self::str($draft, 'publicId'));
        self::assertArrayNotHasKey('id', $draft, 'no numeric id leaves the API');
        self::assertSame('draft', $draft['status']);
        self::assertSame(1, $draft['revision']);
        self::assertSame('Mining Trailing Cable 33kV', $draft['title']);
        self::assertSame(['read', 'edit', 'submit', 'delete'], $draft['allowedActions']);
        self::assertSame('thilanh@tricab.com', self::get($draft, 'requester', 'email'), 'the body cannot name the requester');
        self::assertIsArray($draft['variants']);

        $saved = $app->handle(TestAppFactory::request('PUT', self::ME . '/' . self::str($draft, 'publicId'))->withParsedBody([
            'version' => $draft['version'],
            'answers' => NprFixtures::validRequestAnswers(),
            'variants' => NprFixtures::validVariants(),
            'changeSummary' => 'Completed all requirements',
        ]));
        $after = $this->data($saved);

        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame('BHP', $after['customerName']);
        self::assertSame('Standard 3x70mm2', self::get($after, 'variants', 0, 'label'));
        self::assertNotSame($draft['version'], $after['version']);

        $read = $this->data($app->handle(TestAppFactory::request('GET', self::ME . '/' . self::str($draft, 'publicId'))));
        self::assertSame($after['version'], $read['version']);
        self::assertSame('urgent', self::get($read, 'answers', 'urgency', 'value'));

        $list = $this->data($app->handle(TestAppFactory::request('GET', self::ME)));
        self::assertCount(1, self::items($list, 'items'));
        self::assertArrayNotHasKey('answers', self::items($list, 'items', 0), 'the list is headers only');
        self::assertSame($draft['publicId'], self::get($list, 'items', 0, 'publicId'));
    }

    public function test_an_empty_draft_encodes_answers_as_an_object(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);

        $created = $app->handle(TestAppFactory::request('POST', self::ME)->withParsedBody([]));

        self::assertStringContainsString('"answers":{}', (string) $created->getBody());
    }

    public function test_a_stale_version_is_409_with_the_current_version(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
        $draft = $this->data($app->handle(TestAppFactory::request('POST', self::ME)->withParsedBody([])));
        $body = ['version' => $draft['version'], 'answers' => [], 'variants' => []];
        $first = $app->handle(TestAppFactory::request('PUT', self::ME . '/' . self::str($draft, 'publicId'))->withParsedBody($body));
        $stale = $app->handle(TestAppFactory::request('PUT', self::ME . '/' . self::str($draft, 'publicId'))->withParsedBody($body));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(409, $stale->getStatusCode());
        $error = self::items(TestAppFactory::json($stale), 'error');
        self::assertSame('NPR_VERSION_CONFLICT', self::get($error, 'code'));
        self::assertSame($this->data($first)['version'], self::get($error, 'currentVersion'));
    }

    public function test_a_save_without_a_version_or_with_a_bad_body_is_422(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
        $draft = $this->data($app->handle(TestAppFactory::request('POST', self::ME)->withParsedBody([])));
        $path = self::ME . '/' . self::str($draft, 'publicId');

        $noVersion = $app->handle(TestAppFactory::request('PUT', $path)->withParsedBody(['answers' => []]));
        $badAnswers = $app->handle(TestAppFactory::request('PUT', $path)->withParsedBody(['version' => $draft['version'], 'answers' => ['x']]));

        self::assertSame(422, $noVersion->getStatusCode());
        self::assertSame(422, $badAnswers->getStatusCode());
        self::assertSame('NPR_INPUT_INVALID', self::get(TestAppFactory::json($badAnswers), 'error', 'code'));
    }

    public function test_a_request_past_sales_answers_409_not_editable(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
        $this->seed($app);

        $response = $app->handle(TestAppFactory::request('PUT', self::ME . '/' . self::WITH_TSD)->withParsedBody(['version' => '0x00000000000000AA', 'answers' => []]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('NPR_NOT_EDITABLE', self::get(TestAppFactory::json($response), 'error', 'code'));
    }

    public function test_somebody_elses_request_is_404_to_read_and_to_save(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
        $this->seed($app);

        $read = $app->handle(TestAppFactory::request('GET', self::ME . '/' . self::COLLEAGUES));
        $save = $app->handle(TestAppFactory::request('PUT', self::ME . '/' . self::COLLEAGUES)->withParsedBody(['version' => '0x00000000000000AA', 'answers' => []]));
        $missing = $app->handle(TestAppFactory::request('GET', self::ME . '/55555555-5555-4555-8555-555555555555'));

        self::assertSame(404, $read->getStatusCode());
        self::assertSame(404, $save->getStatusCode());
        self::assertSame((string) $read->getBody(), (string) $missing->getBody(), 'someone else\'s is indistinguishable from missing');
        self::assertNotContains(self::COLLEAGUES, array_column(self::items($this->data($app->handle(TestAppFactory::request('GET', self::ME))), 'items'), 'publicId'));
    }

    public function test_tsd_sees_only_requests_that_reached_it(): void
    {
        $app = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER]);
        $this->seed($app);

        $list = $this->data($app->handle(TestAppFactory::request('GET', self::TSD)));
        $detail = $app->handle(TestAppFactory::request('GET', self::TSD . '/' . self::WITH_TSD));
        $draft = $app->handle(TestAppFactory::request('GET', self::TSD . '/' . self::STILL_DRAFT));

        self::assertSame([self::WITH_TSD], array_column(self::items($list, 'items'), 'publicId'));
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame(['read', 'acknowledge', 'assign', 'assess', 'tsd_return', 'request_screening'], $this->data($detail)['allowedActions']);
        self::assertSame('var_1', self::get($this->data($detail), 'variants', 0, 'id'));
        self::assertSame(404, $draft->getStatusCode());
    }

    public function test_a_requester_cannot_use_the_tsd_routes_and_tsd_cannot_use_the_requester_ones(): void
    {
        $requester = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_REQUESTER]);
        self::assertSame(403, $requester->handle(TestAppFactory::request('GET', self::TSD))->getStatusCode());

        $tsd = $this->app(Module::ROLE_PERMISSIONS[Module::ROLE_TSD_ENGINEER]);
        self::assertSame(403, $tsd->handle(TestAppFactory::request('GET', self::ME))->getStatusCode());
        self::assertSame(403, $tsd->handle(TestAppFactory::request('POST', self::ME)->withParsedBody([]))->getStatusCode());
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
        $users->replacePermissions(self::SIGNED_IN_USER_ID, ['account.login', ...$permissions], ['EMPLOYEE']);

        return $app;
    }

    /** @param App<ContainerInterface> $app */
    private function seed(App $app): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(NprRequestRepository::class);
        self::assertInstanceOf(InMemoryNprRequestRepository::class, $repository);

        $repository
            ->withRequest(NprFixtures::record(self::COLLEAGUES, 99, RequestStatus::Draft))
            ->withRequest(NprFixtures::record(self::WITH_TSD, self::SIGNED_IN_USER_ID, RequestStatus::WithTsd, 1, [], NprFixtures::validVariants()))
            ->withRequest(NprFixtures::record(self::STILL_DRAFT, self::SIGNED_IN_USER_ID, RequestStatus::Draft));
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
        self::assertIsString($value);

        return $value;
    }

    /** @return array<mixed> */
    private static function items(mixed $data, string|int ...$path): array
    {
        $value = self::get($data, ...$path);
        self::assertIsArray($value);

        return $value;
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
