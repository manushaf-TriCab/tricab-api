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
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Application\Service\RfqSyncService;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Infrastructure\UnwiredRfqQuoteGateway;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Rfq\Sync\GatewayHeaderResult;
use TriCab\Core\Rfq\Sync\GatewayLineResult;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Application\ReadRfqQueueServiceTest;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\InMemoryRfqSyncStore;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingQuoteGateway;

/**
 * Sync to 4js through the real route (parity plan E7.3): what each outcome
 * answers, and that the default server, with the switch off, sends nothing and
 * says so. The 4js side is a recording fake.
 */
final class RfqSyncRoutesTest extends TestCase
{
    private const DRAFT_ID = '0123456789ab4def8123456789abcdef';

    private RecordingQuoteGateway $gateway;
    private FixtureRfqParserClient $parser;
    private InMemoryRfqSyncStore $store;

    protected function setUp(): void
    {
        $this->gateway = new RecordingQuoteGateway();
        $this->parser = new FixtureRfqParserClient();
        $this->store = new InMemoryRfqSyncStore();
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions, bool $enabled = true, bool $wired = true): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        $repository = (new InMemoryRfqRepository())->withRecord(ReadRfqQueueServiceTest::record(StoreProjection::publicId(self::DRAFT_ID), 1, 'VIC'));
        $row = static fn (string $uid, string $code): array => [
            'line_uid' => $uid, 'raw_text' => $code, 'source' => ['original_text' => $code, 'input_type' => 'pasted_text'],
            'final_product_code' => $code, 'length' => ['number_of_lengths' => 1, 'length_each' => 10, 'total_length' => 10],
            'status' => 'matched', 'final_status' => 'matched', 'unit_price' => 2.0, 'final_unit_price' => 4.0, 'margin_percent' => 50,
            'pricing' => ['status' => 'available', 'unit_price' => 2.0],
        ];
        $drafts = new class ($this->store, $row) implements RfqDraftStore {
            /** @param \Closure(string, string): array<string, mixed> $row */
            public function __construct(private readonly InMemoryRfqSyncStore $store, private readonly \Closure $row)
            {
            }

            private static function int(mixed $value): int
            {
                return is_int($value) ? $value : 1;
            }

            private static function text(mixed $value): string
            {
                return is_string($value) ? $value : '';
            }

            public function create(NewDraft $draft): string
            {
                throw new \LogicException('not used');
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): int
            {
                throw new \LogicException('not used');
            }

            public function load(string $draftId): StoredDraft
            {
                return new StoredDraft(
                    $draftId,
                    self::int($this->store->draft['revision']),
                    self::text($this->store->draft['status']),
                    ['assignee_email' => 'thilanh@tricab.com', 'source' => 'web', 'refno' => $this->store->draft['refno'], 'refver' => $this->store->draft['refver']],
                    ['customer_id' => '123', 'contact' => 'Buyer', 'author' => 'REP', 'salesoffice' => 'PER', 'payterms' => 'EOM30', 'region' => 'australia', 'currency' => 'AUD'],
                    [],
                    [($this->row)('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'TPS3C25'), ($this->row)('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2', 'XL-PAXN/4C16OR')],
                    [],
                );
            }
        };
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): string
            {
                return 'REP';
            }
        };
        $container->set(RfqRepository::class, $repository);
        $container->set(ReadRfqQueueService::class, new ReadRfqQueueService($repository));
        $container->set(RfqSyncService::class, new RfqSyncService(
            $this->parser,
            $repository,
            $drafts,
            $this->store,
            $wired ? $this->gateway : new UnwiredRfqQuoteGateway(),
            $operators,
            null,
            FixedClock::utc('2026-10-02T02:00:00'),
            $enabled,
        ));

        return $app;
    }

    /** @return list<string> */
    private static function seller(): array
    {
        return ['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]];
    }

    private static function path(): string
    {
        return '/api/v1/rfqs/' . StoreProjection::publicId(self::DRAFT_ID) . '/sync';
    }

    /** @return array<array-key, mixed> */
    private static function body(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
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
    private function post(App $app, array $body = ['expectedRevision' => 3]): ResponseInterface
    {
        return $app->handle(TestAppFactory::request('POST', self::path())->withParsedBody($body));
    }

    public function test_the_default_server_has_sync_switched_off_and_sends_nothing(): void
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, self::seller(), ['EMPLOYEE']);

        $response = $this->post($app);

        self::assertSame(503, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('RFQ_SYNC_DISABLED', self::at(self::body($response), 'error', 'code'));
        self::assertStringContainsString('Nothing was sent.', self::str(self::at(self::body($response), 'error', 'message')));

        $me = $app->handle(TestAppFactory::request('GET', '/api/v1/rfqs/me'));
        self::assertSame(200, $me->getStatusCode());
        self::assertFalse(self::at(self::body($me), 'data', 'sync', 'available'));
        self::assertSame('disabled', self::at(self::body($me), 'data', 'sync', 'code'));
    }

    public function test_switched_on_with_no_adapter_it_is_503_unavailable_and_nothing_is_sent(): void
    {
        $response = $this->post($this->app(self::seller(), true, false));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('RFQ_SYNC_UNAVAILABLE', self::at(self::body($response), 'error', 'code'));
        self::assertSame([], $this->gateway->calls);
    }

    public function test_the_access_answer_says_when_sync_can_run(): void
    {
        $me = $this->app(self::seller())->handle(TestAppFactory::request('GET', '/api/v1/rfqs/me'));

        self::assertTrue(self::at(self::body($me), 'data', 'sync', 'available'));
    }

    public function test_a_synced_quote_is_200_with_the_result_and_the_fresh_rfq(): void
    {
        $response = $this->post($this->app(self::seller()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = self::body($response);
        self::assertTrue(self::at($body, 'success'));
        self::assertSame('succeeded', self::at($body, 'data', 'result', 'outcome'));
        self::assertTrue(self::at($body, 'data', 'result', 'ok'));
        self::assertSame('721999', self::at($body, 'data', 'result', 'quote', 'number'));
        self::assertSame('Quote 721999 created - 2 line(s) synced', self::at($body, 'data', 'result', 'message'));
        self::assertSame('https://pdf.example.invalid/quote_721999.pdf', self::at($body, 'data', 'result', 'quote', 'pdfUrl'));
        self::assertSame('succeeded', self::at($body, 'data', 'result', 'attempt', 'state'));
        self::assertSame(StoreProjection::publicId(self::DRAFT_ID), self::at($body, 'data', 'rfq', 'publicId'));
        self::assertStringNotContainsString('@', json_encode($body, JSON_THROW_ON_ERROR), 'no email address leaves the server');
        self::assertSame(['header', 'line', 'line', 'pdf'], $this->gateway->sequence());
    }

    public function test_a_partial_is_502_with_the_lines_named_never_a_2xx(): void
    {
        $this->gateway->lineAnswers[1] = GatewayLineResult::rejected('Unknown product');

        $response = $this->post($this->app(self::seller()));

        self::assertSame(502, $response->getStatusCode());
        $body = self::body($response);
        self::assertFalse(self::at($body, 'success'));
        self::assertSame('RFQ_SYNC_FAILED', self::at($body, 'error', 'code'));
        self::assertSame('partial', self::at($body, 'data', 'result', 'outcome'));
        self::assertFalse(self::at($body, 'data', 'result', 'ok'));
        self::assertSame(2, self::at($body, 'data', 'result', 'failures', 0, 'lineNumber'));
        self::assertSame('XL-PAXN/4C16OR', self::at($body, 'data', 'result', 'failures', 0, 'code'));
        self::assertSame('721999', self::at($body, 'data', 'result', 'quote', 'number'));
    }

    public function test_an_uncertain_outcome_is_502_and_says_to_check_4js(): void
    {
        $this->gateway->header = GatewayHeaderResult::unknown('timed out');

        $response = $this->post($this->app(self::seller()));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('RFQ_SYNC_UNCERTAIN', self::at(self::body($response), 'error', 'code'));
        self::assertTrue(self::at(self::body($response), 'data', 'result', 'attempt', 'needsReconciliation'));
        self::assertStringContainsString('may or may not hold a quote', self::str(self::at(self::body($response), 'data', 'result', 'attempt', 'reconcileNote')));
    }

    public function test_a_quote_that_is_not_ready_is_422_with_its_issues_and_nothing_sent(): void
    {
        $this->parser->syncProblem = ['status' => 400, 'payload' => [
            'ok' => false,
            'error' => 'Fix the listed issues before syncing. No quote was sent to 4js.',
            'blocking_issues' => [['issue' => 'invalid_quantity_or_price', 'row_number' => 2, 'row_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2', 'code' => 'XL-PAXN/4C16OR', 'reason' => 'quantity and price must both be greater than 0 (check length and pricing)']],
        ]];

        $response = $this->post($this->app(self::seller()));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('RFQ_SYNC_BLOCKED', self::at(self::body($response), 'error', 'code'));
        self::assertSame(2, self::at(self::body($response), 'error', 'issues', 0, 'lineNumber'));
        self::assertSame([], $this->gateway->calls);
    }

    public function test_changed_prices_are_409_and_the_required_price_goes_to_a_pricing_holder_only(): void
    {
        $this->parser->syncProblem = ['status' => 409, 'payload' => ['ok' => false, 'error' => 'Prices have changed.', 'price_changes' => [['row_number' => 1, 'row_id' => 'x', 'code' => 'TPS3C25', 'reviewed_price' => 4.0, 'required_price' => 5.0, 'reason' => 'differs']]]];

        $holder = $this->post($this->app([...self::seller(), Module::PERMISSION_PRICING_VIEW]));
        self::assertSame(409, $holder->getStatusCode());
        self::assertSame('RFQ_SYNC_PRICES_CHANGED', self::at(self::body($holder), 'error', 'code'));
        self::assertEquals(5.0, self::at(self::body($holder), 'error', 'issues', 0, 'requiredPrice'));

        $sales = $this->post($this->app(self::seller()));
        self::assertSame(409, $sales->getStatusCode());
        $issue = self::at(self::body($sales), 'error', 'issues', 0);
        self::assertIsArray($issue);
        self::assertArrayNotHasKey('requiredPrice', $issue);
        self::assertArrayNotHasKey('reviewedPrice', $issue);
        self::assertSame('differs', $issue['reason']);
    }

    public function test_a_fallback_margin_is_named_to_a_pricing_holder_only(): void
    {
        $this->parser->syncPlan = [
            'is_update' => false, 'existing_refno' => '', 'push_version' => '0',
            'hdr_params' => ['ref' => '0', 'cust' => '123', 'author' => 'REP', 'date' => '02/10/26', 'version' => '0'],
            'line_items' => [['code' => 'TPS3C25', 'quantity' => 10.0, 'price' => 4.0, 'cprc' => 2.0, 'comment' => '', 'unitsys' => 'M', 'row_number' => 1, 'row_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1']],
            'excluded' => [],
            'fallback_margin' => [['row_number' => 1, 'row_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'code' => 'TPS3C25', 'margin' => 30, 'reason' => 'No customer margin']],
            'customer_id' => '123', 'cusref' => '',
        ];

        $sales = $this->post($this->app(self::seller()));
        self::assertSame(200, $sales->getStatusCode(), (string) $sales->getBody());
        self::assertNull(self::at(self::body($sales), 'data', 'result', 'fallbackMargins', 0, 'margin'));
        self::assertSame('TPS3C25', self::at(self::body($sales), 'data', 'result', 'fallbackMargins', 0, 'code'));

        $this->store = new InMemoryRfqSyncStore();
        $holder = $this->post($this->app([...self::seller(), Module::PERMISSION_PRICING_VIEW]));
        self::assertSame(200, $holder->getStatusCode(), (string) $holder->getBody());
        self::assertSame(30, self::at(self::body($holder), 'data', 'result', 'fallbackMargins', 0, 'margin'));
    }

    public function test_a_stale_revision_an_open_attempt_and_a_quote_already_in_4js_are_409s(): void
    {
        $stale = $this->post($this->app(self::seller()), ['expectedRevision' => 2]);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('RFQ_REVISION_CONFLICT', self::at(self::body($stale), 'error', 'code'));
        self::assertSame(3, self::at(self::body($stale), 'error', 'currentRevision'));

        $this->store->draft['locked'] = true;
        $running = $this->post($this->app(self::seller()));
        self::assertSame(409, $running->getStatusCode());
        self::assertSame('RFQ_NOT_EDITABLE', self::at(self::body($running), 'error', 'code'));
        $this->store->draft['locked'] = false;

        $this->store->draft = ['revision' => 3, 'status' => 'synced', 'refno' => '721999', 'refver' => '1', 'locked' => false];
        $synced = $this->post($this->app(self::seller()));
        self::assertSame(409, $synced->getStatusCode());
        self::assertStringContainsString('Quote 721999 (v1) is in 4js.', self::str(self::at(self::body($synced), 'error', 'message')));
        self::assertSame([], $this->gateway->calls);
    }

    public function test_an_uncertain_attempt_holds_the_rfq_with_409_and_the_attempt(): void
    {
        $this->gateway->header = GatewayHeaderResult::unknown('timed out');
        $app = $this->app(self::seller());
        self::assertSame(502, $this->post($app)->getStatusCode());
        $calls = count($this->gateway->calls);

        $again = $this->post($app, ['expectedRevision' => 4]);

        self::assertSame(409, $again->getStatusCode());
        self::assertSame('RFQ_SYNC_NEEDS_RECONCILIATION', self::at(self::body($again), 'error', 'code'));
        self::assertSame('uncertain', self::at(self::body($again), 'error', 'attempt', 'state'));
        self::assertCount($calls, $this->gateway->calls);
    }

    public function test_the_body_must_carry_the_revision_and_an_unknown_rfq_is_404(): void
    {
        $app = $this->app(self::seller());
        foreach ([['x' => 1], ['expectedRevision' => '3'], ['expectedRevision' => 0], ['expectedRevision' => null]] as $body) {
            self::assertSame(422, $this->post($app, $body)->getStatusCode());
        }
        $missing = $app->handle(TestAppFactory::request('POST', '/api/v1/rfqs/' . StoreProjection::publicId(str_repeat('b', 32)) . '/sync')->withParsedBody(['expectedRevision' => 1]));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame([], $this->gateway->calls);
    }

    public function test_without_the_sync_permission_the_route_is_forbidden(): void
    {
        $response = $this->post($this->app(['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->gateway->calls);
    }
}
