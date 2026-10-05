<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\DTO\AccessoryMatch;
use TriCab\Core\Rfq\Application\Service\RfqAccessoryMatcher;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Infrastructure\UnavailableRfqAccessoryEngine;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\AccessorySeal;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseSeal;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingAccessoryEngine;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingCustomerDirectory;

/**
 * Match Accessories (parity plan E7.2) at the service: which lines are asked
 * about, the accessory row a proposal becomes, what is priced and what leaves,
 * and that adding one is the server's row and never the browser's.
 */
final class RfqAccessoriesTest extends TestCase
{
    private const DRAFT_ID = '0123456789ab4def8123456789abcdef';
    private const CABLE_UID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const OTHER_UID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2';

    private FixtureRfqParserClient $parser;
    private RecordingAccessoryEngine $engine;
    private FixedClock $clock;

    /** @var list<list<array<string, mixed>>> */
    private array $savedRows = [];

    protected function setUp(): void
    {
        $this->parser = new FixtureRfqParserClient();
        $this->parser->costs = ['TPS3C25' => 4.0, 'D08-N2G/20BK' => 0.5, 'W01-4.6/250BK' => 0.1];
        $this->engine = new RecordingAccessoryEngine();
        $this->engine->matches['TPS3C25'] = [
            new AccessoryMatch('D08-N2G/20BK', 'Gland, nylon', 10.0, 12.0, 0.0),
            new AccessoryMatch('W01-4.6/250BK', 'Cable tie', 100.0, 0.0, 0.0),
            new AccessoryMatch('TPS3C25', 'the cable itself', 1.0, 1.0),
            new AccessoryMatch('TPS3C70', 'a cable the engine returned', 1.0, 1.0, 0.0, true),
        ];
        $this->clock = FixedClock::utc('2026-10-02T02:00:00');
        $this->savedRows = [];
    }

    /** @param array<string, mixed> $extra */
    private static function cable(string $uid, string $code, array $extra = []): array
    {
        return $extra + [
            'line_uid' => $uid,
            'raw_text' => '3C 2.5 ' . $code,
            'source' => ['original_text' => '3C 2.5', 'input_type' => 'pasted_text'],
            'final_product_code' => $code,
            'selected_product_code' => $code,
            'length' => ['number_of_lengths' => 2, 'length_each' => 25, 'total_length' => 50],
            'status' => 'matched',
            'final_status' => 'matched',
            'pricing' => ['status' => 'available', 'region' => 'australia', 'quote_currency' => 'AUD', 'cost_status' => 'STOCK', 'unit_price' => 4.0],
            'unit_price' => 4.0,
            'final_unit_price' => 8.0,
            'line_total' => 400.0,
            'margin_percent' => 50,
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $rows
     * @param array<string, mixed>            $details
     */
    private function service(?array $rows = null, array $details = ['customer_id' => '12', 'region' => 'australia', 'currency' => 'AUD'], bool $engineConnected = true): RfqReviewService
    {
        $stored = new StoredDraft(self::DRAFT_ID, 3, 'draft', [], $details, [], $rows ?? [self::cable(self::CABLE_UID, 'TPS3C25')], []);
        $saved = function (array $rows): void {
            $this->savedRows[] = $rows;
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

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): ?int
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
            public function codeFor(string $email): ?string
            {
                return 'REP';
            }
        };

        return new RfqReviewService(
            $this->parser,
            $store,
            (new InMemoryRfqRepository())->withRecord(ReadRfqQueueServiceTest::record(StoreProjection::publicId(self::DRAFT_ID), 7, 'VIC')),
            $operators,
            new RecordingCustomerDirectory(),
            $this->clock,
            new ParseSeal('test-parser-token'),
            false,
            null,
            null,
            new RfqAccessoryMatcher($engineConnected ? $this->engine : new UnavailableRfqAccessoryEngine(), new AccessorySeal('test-parser-token')),
        );
    }

    private static function holder(): RfqActor
    {
        return new RfqActor(7, 'user-7', [...Module::ROLE_PERMISSIONS[Module::ROLE_SALES], Module::PERMISSION_PRICING_VIEW], 'VIC');
    }

    private static function publicId(): string
    {
        return StoreProjection::publicId(self::DRAFT_ID);
    }

    public function test_candidates_are_the_engines_non_cable_matches_priced_as_the_lines_would_be(): void
    {
        $found = $this->service()->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate());

        self::assertNotNull($found);
        $answer = $found->toArray();
        self::assertTrue($answer['engineAvailable']);
        self::assertCount(1, $answer['parents']);
        self::assertSame(self::CABLE_UID, $answer['parents'][0]['lineUid']);
        $candidates = $answer['parents'][0]['candidates'];
        self::assertCount(2, $candidates, 'the cable itself and a cable the engine returned are dropped');

        // The engine was asked what the source's PHP asks: the code, the line's total length, its runs, the customer.
        self::assertSame([['code' => 'TPS3C25', 'length' => 50.0, 'runs' => 2, 'customerId' => 12, 'region' => 'australia', 'currency' => 'AUD']], $this->engine->asked);

        $gland = $candidates[0];
        self::assertSame('Gland, nylon', $gland['description']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $gland['line']['lineUid']);
        self::assertSame('D08-N2G/20BK', $gland['line']['productCode']);
        self::assertTrue($gland['line']['entry']['accessory']);
        // 12 units asked for, packs of 10: whole packs, so 20.
        self::assertSame('20', $gland['line']['entry']['cuts']);
        self::assertSame(10, $gland['line']['entry']['packSize']);
        self::assertSame('available', $gland['line']['pricing']['priceStatus']);
        self::assertSame('0.5', $gland['line']['pricing']['unitCost']);
        // No units asked for: one pack.
        self::assertSame('100', $candidates[1]['line']['entry']['cuts']);

        self::assertNotEmpty($this->parser->priceCalls, 'candidates are priced by the pricing pass');
        self::assertSame([], $this->savedRows, 'asking stores nothing');
    }

    public function test_what_is_already_on_the_quote_under_that_cable_is_not_offered_again(): void
    {
        $existing = ['line_kind' => 'accessory', 'parent_product_code' => 'tps3c25'] + self::cable(self::OTHER_UID, 'd08-n2g/20bk');
        $found = $this->service([self::cable(self::CABLE_UID, 'TPS3C25'), $existing])->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate());

        $codes = array_map(static fn (array $c): string => $c['line']['productCode'], $found?->toArray()['parents'][0]['candidates'] ?? []);
        self::assertSame(['W01-4.6/250BK'], $codes);
    }

    public function test_only_the_named_cable_lines_are_asked_and_an_unknown_line_is_refused(): void
    {
        $service = $this->service([self::cable(self::CABLE_UID, 'TPS3C25'), self::cable(self::OTHER_UID, 'TPS3C70')]);
        $service->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, [self::OTHER_UID], CorrelationId::generate());
        self::assertSame(['TPS3C70'], array_column($this->engine->asked, 'code'));

        $this->expectException(InvalidRfqValue::class);
        $service->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], CorrelationId::generate());
    }

    public function test_lines_that_are_not_cables_are_not_asked_about(): void
    {
        $rows = [
            self::cable(self::CABLE_UID, 'TPS3C25', ['line_kind' => 'accessory']),
            self::cable(self::OTHER_UID, 'TPS3C70', ['final_status' => 'not_cable']),
            self::cable('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa3', ''),
        ];
        $found = $this->service($rows)->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate());

        self::assertSame([], $this->engine->asked);
        self::assertSame([], $found?->toArray()['parents']);
    }

    public function test_an_engine_failure_skips_that_line_and_says_why(): void
    {
        $this->engine->failFor = ['TPS3C25'];
        $answer = $this->service()->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate())?->toArray();

        self::assertSame([['lineUid' => self::CABLE_UID, 'code' => 'TPS3C25', 'reason' => 'engine failed for TPS3C25']], $answer['skipped'] ?? null);
        self::assertSame([], $answer['parents']);
    }

    public function test_with_no_engine_connected_the_answer_says_so_and_nothing_is_asked_or_priced(): void
    {
        $answer = $this->service(null, ['customer_id' => '12', 'region' => 'australia', 'currency' => 'AUD'], false)
            ->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate())?->toArray();

        self::assertFalse($answer['engineAvailable'] ?? true);
        self::assertStringContainsString('not connected', (string) ($answer['note'] ?? ''));
        self::assertSame([], $answer['parents']);
        self::assertSame([], $this->parser->priceCalls);
    }

    public function test_a_customer_is_needed(): void
    {
        $this->expectException(InvalidRfqValue::class);
        $this->expectExceptionMessage('Choose the customer');
        $this->service(null, ['region' => 'australia', 'currency' => 'AUD'])->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate());
    }

    public function test_without_pricing_view_nothing_is_matched(): void
    {
        $sales = new RfqActor(7, 'user-7', Module::ROLE_PERMISSIONS[Module::ROLE_SALES], 'VIC');
        try {
            $this->service()->accessoriesDraft($sales, 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate());
            self::fail('expected a refusal');
        } catch (RfqActionForbidden) {
            self::assertSame([], $this->engine->asked);
        }
    }

    public function test_adding_a_candidate_saves_the_servers_row_with_the_units_the_rep_chose(): void
    {
        $service = $this->service();
        $answer = $service->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate())?->toArray();
        $candidate = $answer['parents'][0]['candidates'][0];

        $newUid = $candidate['line']['lineUid'];
        $service->save(self::holder(), 'rep@tricab.com', self::publicId(), 3, [
            ['lineUid' => self::CABLE_UID, 'approved' => null, 'comment' => ''],
            ['lineUid' => $newUid, 'approved' => null, 'comment' => '', 'isNew' => true, 'accessoryToken' => $candidate['token'], 'cuts' => '30'],
        ], CorrelationId::generate());

        self::assertCount(1, $this->savedRows);
        [$cable, $accessory] = $this->savedRows[0];
        self::assertSame('TPS3C25', $cable['final_product_code']);
        self::assertSame($newUid, $accessory['line_uid']);
        self::assertSame('accessory', $accessory['line_kind']);
        self::assertSame('TPS3C25', $accessory['parent_product_code']);
        self::assertSame('D08-N2G/20BK', $accessory['final_product_code']);
        self::assertSame(10, $accessory['units_per_pack']);
        self::assertSame(30, $accessory['length']['number_of_lengths']);
        self::assertSame('available', $accessory['pricing']['status'], 'priced by the save, as every line is');
    }

    public function test_a_token_is_not_taken_from_another_person_or_for_an_existing_line(): void
    {
        $service = $this->service();
        $answer = $service->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate())?->toArray();
        $candidate = $answer['parents'][0]['candidates'][0];
        $lines = static fn (string $uid, bool $new): array => [
            ['lineUid' => self::CABLE_UID, 'approved' => null, 'comment' => ''],
            ['lineUid' => $uid, 'approved' => null, 'comment' => '', 'isNew' => $new, 'accessoryToken' => $candidate['token']],
        ];

        try {
            $service->save(self::holder(), 'someone.else@tricab.com', self::publicId(), 3, $lines($candidate['line']['lineUid'], true), CorrelationId::generate());
            self::fail('a token made for another person must not open');
        } catch (InvalidRfqValue $refused) {
            self::assertStringContainsString('someone else', $refused->getMessage());
        }
        try {
            $service->save(self::holder(), 'rep@tricab.com', self::publicId(), 3, $lines($candidate['line']['lineUid'], false), CorrelationId::generate());
            self::fail('an accessory token on an existing line must be refused');
        } catch (InvalidRfqValue) {
        }
        self::assertSame([], $this->savedRows);
    }

    public function test_an_old_token_is_refused(): void
    {
        $service = $this->service();
        $answer = $service->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), null, null, null, CorrelationId::generate())?->toArray();
        $candidate = $answer['parents'][0]['candidates'][0];
        $this->clock->advance('+13 hours');

        $this->expectException(InvalidRfqValue::class);
        $this->expectExceptionMessage('more than 12 hours');
        $service->save(self::holder(), 'rep@tricab.com', self::publicId(), 3, [
            ['lineUid' => self::CABLE_UID, 'approved' => null, 'comment' => ''],
            ['lineUid' => $candidate['line']['lineUid'], 'approved' => null, 'comment' => '', 'isNew' => true, 'accessoryToken' => $candidate['token']],
        ], CorrelationId::generate());
    }

    public function test_the_header_edit_is_used_for_the_asking(): void
    {
        // The editor's unsaved region/customer decide what the engine is told.
        $header = QuoteHeaderEdit::fromInput(['customerId' => 99, 'region' => 'newzealand', 'currency' => 'NZD']);
        $this->service(null, ['region' => 'australia', 'currency' => 'AUD'])->accessoriesDraft(self::holder(), 'rep@tricab.com', self::publicId(), $header, null, null, CorrelationId::generate());

        self::assertSame(99, $this->engine->asked[0]['customerId']);
        self::assertSame('newzealand', $this->engine->asked[0]['region']);
        self::assertSame('NZD', $this->engine->asked[0]['currency']);
    }
}
