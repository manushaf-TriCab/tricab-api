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
use TriCab\Core\Rfq\Application\Service\RfqAliflexService;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Application\ReadRfqQueueServiceTest;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingCustomerDirectory;

/**
 * ALIFLEX through the real route (parity plan E7.4): what each answer is, that the
 * source draft is never written, and that the default server wires the service.
 */
final class RfqAliflexRoutesTest extends TestCase
{
    private const DRAFT_ID = '0123456789ab4def8123456789abcdef';
    public const NEW_ID = 'fedcba9876544def8123456789abcdef';

    private FixtureRfqParserClient $parser;

    /** @var list<NewDraft> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->parser = new FixtureRfqParserClient();
        $this->created = [];
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions, string $status = 'synced', string $code = 'XL-PAXN/1C25OR'): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        $stored = new StoredDraft(self::DRAFT_ID, 4, $status, ['label' => 'Westelec RFQ', 'refno' => '721999', 'refver' => '1'], ['customer_id' => '123', 'region' => 'australia', 'currency' => 'AUD'], [], [[
            'line_uid' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'raw_text' => $code, 'source' => ['original_text' => $code, 'input_type' => 'pasted_text'],
            'final_product_code' => $code, 'length' => ['number_of_lengths' => 1, 'length_each' => 10, 'total_length' => 10],
            'status' => 'matched', 'final_status' => 'matched', 'unit_price' => 2.0, 'final_unit_price' => 4.0, 'margin_percent' => 50,
            'pricing' => ['status' => 'available', 'unit_price' => 2.0],
        ]], []);
        $create = function (NewDraft $draft): void {
            $this->created[] = $draft;
        };
        $drafts = new class ($stored, $create) implements RfqDraftStore {
            /** @param \Closure(NewDraft): void $create */
            public function __construct(private readonly StoredDraft $stored, private readonly \Closure $create)
            {
            }

            public function create(NewDraft $draft): string
            {
                ($this->create)($draft);

                return RfqAliflexRoutesTest::NEW_ID;
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): int
            {
                throw new \LogicException('ALIFLEX never writes the quote it converts');
            }

            public function load(string $draftId): StoredDraft
            {
                return $this->stored;
            }
        };
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): string
            {
                return 'REP';
            }
        };
        $repository = (new InMemoryRfqRepository())->withRecord(ReadRfqQueueServiceTest::record(StoreProjection::publicId(self::DRAFT_ID), 1, 'VIC'));
        $review = new RfqReviewService($this->parser, $drafts, $repository, $operators, new RecordingCustomerDirectory(), FixedClock::utc('2026-10-02T02:00:00'), null);
        $container->set(RfqAliflexService::class, new RfqAliflexService($this->parser, $repository, $drafts, $review));

        return $app;
    }

    /** @return list<string> */
    private static function seller(): array
    {
        return ['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]];
    }

    private static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed>    $body
     */
    private function post(App $app, array $body = ['expectedRevision' => 4], ?string $id = null): ResponseInterface
    {
        return $app->handle(TestAppFactory::request('POST', '/api/v1/rfqs/' . ($id ?? StoreProjection::publicId(self::DRAFT_ID)) . '/aliflex')->withParsedBody($body));
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function json(ResponseInterface $response): mixed
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function test_a_finished_quote_becomes_a_new_draft_201(): void
    {
        $response = $this->post($this->app(self::seller()));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = self::json($response);
        self::assertSame(StoreProjection::publicId(self::NEW_ID), self::at($body, 'data', 'publicId'));
        self::assertSame(1, self::at($body, 'data', 'revision'));
        self::assertSame('ALI-FLEX alternative · Westelec RFQ', self::at($body, 'data', 'label'));
        self::assertSame(1, self::at($body, 'data', 'counts', 'changed'));
        self::assertStringContainsString('line(s) changed to ALI-FLEX', self::text(self::at($body, 'data', 'message')));
        self::assertCount(1, $this->created);
        self::assertSame('alternative', $this->created[0]->source);
        self::assertStringNotContainsString('@', json_encode($body, JSON_THROW_ON_ERROR), 'no email address leaves the server');
    }

    public function test_with_nothing_to_change_it_is_200_and_no_draft_exists(): void
    {
        $response = $this->post($this->app(self::seller(), 'synced', 'FR-PAXN/1C240RE'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull(self::at(self::json($response), 'data', 'publicId'));
        self::assertSame('No line matches the ALI-FLEX rule, so no alternative was made.', self::at(self::json($response), 'data', 'message'));
        self::assertSame([], $this->created);
    }

    public function test_an_unfinished_quote_is_409_with_the_sources_reason(): void
    {
        $response = $this->post($this->app(self::seller(), 'in_progress'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('RFQ_ALIFLEX_NOT_ELIGIBLE', self::at(self::json($response), 'error', 'code'));
        self::assertStringStartsWith('Sync this quote to 4js first.', self::text(self::at(self::json($response), 'error', 'message')));
        self::assertSame([], $this->created);
    }

    public function test_a_stale_revision_is_409_and_a_bad_body_422_and_an_unknown_rfq_404(): void
    {
        $app = $this->app(self::seller());

        $stale = $this->post($app, ['expectedRevision' => 3]);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('RFQ_REVISION_CONFLICT', self::at(self::json($stale), 'error', 'code'));
        self::assertSame(4, self::at(self::json($stale), 'error', 'currentRevision'));

        foreach ([['x' => 1], ['expectedRevision' => '4'], ['expectedRevision' => 0]] as $body) {
            self::assertSame(422, $this->post($app, $body)->getStatusCode());
        }
        self::assertSame(404, $this->post($app, ['expectedRevision' => 1], StoreProjection::publicId(str_repeat('b', 32)))->getStatusCode());
        self::assertSame([], $this->created);
    }

    public function test_without_the_create_permission_the_route_is_forbidden(): void
    {
        $response = $this->post($this->app(['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->created);
    }

    public function test_the_default_server_wires_the_service(): void
    {
        $container = TestAppFactory::create()->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);

        self::assertInstanceOf(RfqAliflexService::class, $container->get(RfqAliflexService::class));
    }
}
