<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
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
 * The Product Summary and CSV exports through the real routes, with a draft
 * behind them (parity plan E7.1): the file's headers and bytes, what a viewer
 * without `RFQ.PRICING.VIEW` can and cannot get, and that a formula a customer
 * typed leaves neutralised.
 */
final class RfqOutputRoutesTest extends TestCase
{
    private const DRAFT_ID = '0123456789ab4def8123456789abcdef';

    private FixtureRfqParserClient $parser;

    protected function setUp(): void
    {
        $this->parser = new FixtureRfqParserClient();
    }

    /**
     * @param list<string> $permissions
     *
     * @return App<ContainerInterface>
     */
    private function app(array $permissions, string $description = '4C 16mm2 10m'): App
    {
        $app = TestAppFactory::create();
        $container = $app->getContainer();
        self::assertInstanceOf(\DI\Container::class, $container);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, $permissions, ['EMPLOYEE']);

        $row = static fn (string $uid, string $text, string $code, float $metres, float $sell): array => [
            'line_uid' => $uid,
            'raw_text' => $text,
            'source' => ['original_text' => $text],
            'item_type' => 'cable',
            'final_product_code' => $code,
            'selected_product_code' => $code,
            'length' => ['number_of_lengths' => 2, 'length_each' => $metres / 2, 'total_length' => $metres],
            'unit_price' => 3.0,
            'margin_percent' => 40,
            'final_unit_price' => $sell,
            'line_total' => $sell * $metres,
            'status' => 'matched',
            'final_status' => 'matched',
            'pricing' => ['status' => 'available', 'region' => 'australia', 'quote_currency' => 'AUD', 'cost_status' => 'STOCK', 'unit_price' => 3.0],
        ];
        $stored = new StoredDraft(self::DRAFT_ID, 3, 'draft', [], ['region' => 'australia', 'currency' => 'AUD'], [], [
            $row('l1', $description, 'TPS3C25', 10.0, 5.0),
            $row('l2', 'second', 'TPS3C25', 20.0, 5.0),
        ], []);
        $store = new class ($stored) implements RfqDraftStore {
            public function __construct(private readonly StoredDraft $stored)
            {
            }

            public function create(NewDraft $draft): string
            {
                throw new \LogicException('not used');
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): ?int
            {
                throw new \LogicException('exports store nothing');
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
            $this->parser,
            $store,
            (new InMemoryRfqRepository())->withRecord(ReadRfqQueueServiceTest::record(StoreProjection::publicId(self::DRAFT_ID), 1, 'VIC')),
            $operators,
            new RecordingCustomerDirectory(),
            FixedClock::utc('2026-10-02T02:00:00'),
            null,
        ));

        return $app;
    }

    private static function path(string $tail): string
    {
        return '/api/v1/rfqs/' . StoreProjection::publicId(self::DRAFT_ID) . $tail;
    }

    public function test_the_file_is_a_csv_download_with_the_sources_name_and_no_money_for_sales(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $response = $app->handle(TestAppFactory::request('POST', self::path('/exports/quote-lines'))->withParsedBody(['header' => []]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="quote-lines.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $body = (string) $response->getBody();
        self::assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
        self::assertSame(
            "\xEF\xBB\xBFSerial Number,Product Description,Product Code,Cuts,Cut Length,Total Length\r\n"
            . "1,4C 16mm2 10m,TPS3C25,2,5,10\r\n2,second,TPS3C25,2,10,20\r\n",
            $body,
        );
    }

    public function test_the_summary_file_is_named_for_the_summary_and_carries_money_only_for_a_holder_who_asks(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]]);
        $asked = $app->handle(TestAppFactory::request('POST', self::path('/exports/summary-lines'))->withParsedBody(['includePrices' => true]));

        self::assertSame(200, $asked->getStatusCode(), (string) $asked->getBody());
        self::assertSame('attachment; filename="summary-quote-lines.csv"', $asked->getHeaderLine('Content-Disposition'));
        self::assertSame(
            "\xEF\xBB\xBFSerial Number,Product Description,Product Code,Cuts,Cut Length,Total Length,Price,Total Price\r\n"
            . "1,Summary quote - TPS3C25,TPS3C25,1,30,30,5,150\r\n",
            (string) $asked->getBody(),
        );
        $plain = $app->handle(TestAppFactory::request('POST', self::path('/exports/summary-lines'))->withParsedBody(['header' => []]));
        self::assertStringNotContainsString('Price', (string) $plain->getBody());
    }

    public function test_sales_asking_for_prices_is_refused_and_no_file_is_made(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        foreach (['/exports/quote-lines', '/exports/summary-lines', '/summary'] as $tail) {
            $response = $app->handle(TestAppFactory::request('POST', self::path($tail))->withParsedBody(['includePrices' => true]));
            self::assertSame(403, $response->getStatusCode(), $tail);
            self::assertStringNotContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        }
        self::assertSame([], $this->parser->exportCalls, 'the parser was never asked for prices');
    }

    public function test_a_formula_a_customer_typed_leaves_the_file_neutralised(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]], '=cmd|\' /C calc\'!A0');
        $response = $app->handle(TestAppFactory::request('POST', self::path('/exports/quote-lines'))->withParsedBody(['header' => []]));

        self::assertStringContainsString("\r\n1,'=cmd|' /C calc'!A0,TPS3C25,", (string) $response->getBody());
    }

    public function test_the_summary_is_json_and_carries_no_cost_margin_or_price(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $response = $app->handle(TestAppFactory::request('POST', self::path('/summary'))->withParsedBody(['header' => []]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = TestAppFactory::json($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(1, $data['exportableProducts']);
        self::assertNull($data['pricingError']);
        $products = $data['products'];
        self::assertIsArray($products);
        self::assertEquals(['productCode' => 'TPS3C25', 'lineCount' => 2, 'requestedTotalLength' => 30.0, 'quotedTotalLength' => 30.0, 'costStatus' => 'STOCK', 'moq' => null, 'moqApplied' => false, 'prepackApplied' => false, 'cutsText' => '(2 x 5), (2 x 10)', 'lineUids' => ['l1', 'l2']], $products[0]);
        $json = (string) $response->getBody();
        foreach (['unit_price', 'margin', 'sell', '"cost"', 'lineTotal'] as $leak) {
            self::assertStringNotContainsString($leak, $json, $leak);
        }
    }

    public function test_a_reader_may_read_the_summary_and_a_person_without_read_may_not(): void
    {
        $reader = $this->app(['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ]);
        self::assertSame(200, $reader->handle(TestAppFactory::request('POST', self::path('/summary'))->withParsedBody(['header' => []]))->getStatusCode());

        $none = $this->app(['account.login', Module::PERMISSION_ACCESS]);
        self::assertSame(403, $none->handle(TestAppFactory::request('POST', self::path('/summary'))->withParsedBody(['header' => []]))->getStatusCode());
    }

    public function test_prices_that_cannot_be_had_are_a_503_never_a_quietly_incomplete_file(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]]);
        $this->parser->priceFailure = \TriCab\Core\Rfq\Parser\RfqParserFailure::of(\TriCab\Core\Rfq\Parser\RfqParserFailure::TIMEOUT, 'slow');
        $response = $app->handle(TestAppFactory::request('POST', self::path('/exports/quote-lines'))->withParsedBody(['header' => ['currency' => 'USD'], 'includePrices' => true]));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('RFQ_PRICING_UNAVAILABLE', self::errorCode($response));
        self::assertStringNotContainsString('text/csv', $response->getHeaderLine('Content-Type'));
    }

    public function test_a_file_with_nothing_in_it_is_refused(): void
    {
        $app = $this->app(['account.login', ...Module::ROLE_PERMISSIONS[Module::ROLE_SALES]]);
        $response = $app->handle(TestAppFactory::request('POST', self::path('/exports/quote-lines'))->withParsedBody(['lines' => []]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('RFQ_EXPORT_EMPTY', self::errorCode($response));
    }

    private static function errorCode(\Psr\Http\Message\ResponseInterface $response): mixed
    {
        $error = TestAppFactory::json($response)['error'] ?? null;
        self::assertIsArray($error);

        return $error['code'] ?? null;
    }
}
