<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Rfq\Application\DTO\AccessoryMatch;
use TriCab\Core\Rfq\Application\Service\RfqAccessoryMatcher;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Infrastructure\UnavailableRfqAccessoryEngine;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\AccessorySeal;
use TriCab\Core\Rfq\Parser\ParseSeal;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Application\ReadRfqQueueServiceTest;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingAccessoryEngine;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingCustomerDirectory;

/**
 * Match Accessories through the real routes (parity plan E7.2): pricing
 * holders only, the engine's absence said plainly, and the add through a save's
 * `accessoryToken`.
 */
final class RfqAccessoryRoutesTest extends TestCase
{
    private const DRAFT_ID = '0123456789ab4def8123456789abcdef';
    private const UID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';

    private RecordingAccessoryEngine $engine;

    /** @var list<mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->engine = new RecordingAccessoryEngine();
        $this->engine->matches['TPS3C25'] = [new AccessoryMatch('D08-N2G/20BK', 'Gland, nylon', 10.0, 12.0)];
        $this->saved = [];
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions, bool $connected = true): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        $parser = new FixtureRfqParserClient();
        $parser->costs = ['TPS3C25' => 4.0, 'D08-N2G/20BK' => 0.5];
        $stored = new StoredDraft(self::DRAFT_ID, 3, 'draft', [], ['customer_id' => '12', 'region' => 'australia', 'currency' => 'AUD'], [], [[
            'line_uid' => self::UID,
            'raw_text' => '3C 2.5',
            'source' => ['original_text' => '3C 2.5', 'input_type' => 'pasted_text'],
            'final_product_code' => 'TPS3C25',
            'selected_product_code' => 'TPS3C25',
            'length' => ['number_of_lengths' => 1, 'length_each' => 50, 'total_length' => 50],
            'status' => 'matched',
            'final_status' => 'matched',
            'pricing' => ['status' => 'available', 'region' => 'australia', 'quote_currency' => 'AUD', 'cost_status' => 'STOCK', 'unit_price' => 4.0],
            'unit_price' => 4.0,
            'final_unit_price' => 8.0,
            'line_total' => 400.0,
            'margin_percent' => 50,
        ]], []);
        $saved = function (array $rows): void {
            $this->saved[] = $rows;
        };
        $store = new class ($stored, $saved) implements RfqDraftStore {
            /** @param \Closure(list<array<string, mixed>>): void $saved */
            public function __construct(private readonly StoredDraft $stored, private readonly \Closure $saved)
            {
            }

            public function create(NewDraft $draft): string
            {
                throw new \LogicException('not used');
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): int
            {
                ($this->saved)($rows);

                return $expectedRevision + 1;
            }

            public function load(string $draftId): ?StoredDraft
            {
                return $draftId === $this->stored->id ? $this->stored : null;
            }
        };
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): string
            {
                return 'REP';
            }
        };
        $container->set(RfqReviewService::class, new RfqReviewService(
            $parser,
            $store,
            (new InMemoryRfqRepository())->withRecord(ReadRfqQueueServiceTest::record(StoreProjection::publicId(self::DRAFT_ID), 1, 'VIC')),
            $operators,
            new RecordingCustomerDirectory(),
            FixedClock::utc('2026-10-02T02:00:00'),
            new ParseSeal('test-parser-token'),
            false,
            null,
            null,
            new RfqAccessoryMatcher($connected ? $this->engine : new UnavailableRfqAccessoryEngine(), new AccessorySeal('test-parser-token')),
        ));

        return $app;
    }

    private static function path(string $tail): string
    {
        return '/api/v1/rfqs/' . StoreProjection::publicId(self::DRAFT_ID) . $tail;
    }

    /** @return list<string> */
    private static function holder(): array
    {
        return ['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES], Module::PERMISSION_PRICING_VIEW];
    }

    /** Walks decoded JSON: `at($body, 'data', 'parents', 0, 'candidates')`. */
    private static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    private static function json(\Psr\Http\Message\ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function test_a_pricing_holder_gets_the_candidates_for_a_draft(): void
    {
        $response = $this->app(self::holder())->handle(TestAppFactory::request('POST', self::path('/accessories'))->withParsedBody(['lineUids' => [self::UID]]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = self::json($response);
        self::assertTrue(self::at($body, 'data', 'engineAvailable'));
        self::assertSame('D08-N2G/20BK', self::at($body, 'data', 'parents', 0, 'candidates', 0, 'line', 'productCode'));
        self::assertSame('0.5', self::at($body, 'data', 'parents', 0, 'candidates', 0, 'line', 'pricing', 'unitCost'));
    }

    public function test_without_pricing_view_the_route_is_forbidden(): void
    {
        $sales = ['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]];
        $response = $this->app($sales)->handle(TestAppFactory::request('POST', self::path('/accessories'))->withParsedBody(['lineUids' => null]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->engine->asked);
    }

    public function test_with_no_engine_the_answer_is_200_and_says_so(): void
    {
        $response = $this->app(self::holder(), false)->handle(TestAppFactory::request('POST', self::path('/accessories'))->withParsedBody(['lineUids' => null]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = self::json($response);
        self::assertFalse(self::at($body, 'data', 'engineAvailable'));
        self::assertNotSame('', self::at($body, 'data', 'note'));
        self::assertSame([], self::at($body, 'data', 'parents'));
    }

    public function test_the_default_container_has_no_engine_and_says_so(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $engine = $container->get(\TriCab\Core\Rfq\Contract\RfqAccessoryEngine::class);
        self::assertInstanceOf(\TriCab\Core\Rfq\Contract\RfqAccessoryEngine::class, $engine);
        self::assertFalse($engine->isAvailable());
    }

    public function test_a_bad_line_list_is_422(): void
    {
        $response = $this->app(self::holder())->handle(TestAppFactory::request('POST', self::path('/accessories'))->withParsedBody(['lineUids' => 'all']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('RFQ_INPUT_INVALID', self::at(self::json($response), 'error', 'code'));
    }

    public function test_a_save_adds_the_accessory_the_server_proposed_and_refuses_a_token_on_an_existing_line(): void
    {
        $app = $this->app(self::holder());
        $asked = $app->handle(TestAppFactory::request('POST', self::path('/accessories'))->withParsedBody(['lineUids' => null]));
        self::assertSame(200, $asked->getStatusCode(), (string) $asked->getBody());
        $found = self::at(self::json($asked), 'data', 'parents', 0, 'candidates', 0);
        $newUid = self::at($found, 'line', 'lineUid');
        $token = self::at($found, 'token');

        $ok = $app->handle(TestAppFactory::request('PATCH', self::path(''))->withParsedBody([
            'expectedRevision' => 3,
            'lines' => [
                ['lineUid' => self::UID, 'comment' => ''],
                ['lineUid' => $newUid, 'comment' => '', 'isNew' => true, 'accessoryToken' => $token, 'cuts' => '20'],
            ],
        ]));
        // The write happened; the answer's detail read is a fixture the in-memory queue does not hold, so only the rows are judged.
        self::assertNotSame(422, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertCount(1, $this->saved);
        self::assertSame('accessory', self::at($this->saved, 0, 1, 'line_kind'));
        self::assertSame(20, self::at($this->saved, 0, 1, 'length', 'number_of_lengths'));

        $bad = $app->handle(TestAppFactory::request('PATCH', self::path(''))->withParsedBody([
            'expectedRevision' => 3,
            'lines' => [['lineUid' => self::UID, 'comment' => '', 'accessoryToken' => $token]],
        ]));
        self::assertSame(422, $bad->getStatusCode());
        self::assertCount(1, $this->saved);
    }
}
