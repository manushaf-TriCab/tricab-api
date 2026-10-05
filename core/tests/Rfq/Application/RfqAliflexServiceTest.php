<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\Service\RfqAliflexService;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Contract\RfqDefaultMargins;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqAliflexNotEligible;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Service\AliflexEligibility;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingCustomerDirectory;

/**
 * ALIFLEX (parity plan E7.4): who may convert what, that the source draft is read
 * and never written, and what the new draft is. The mapping itself is the parser's
 * and is proved in Python; the parser here is a recording stand-in.
 */
final class RfqAliflexServiceTest extends TestCase
{
    private const DRAFT_ID = '0123456789ab4def8123456789abcdef';
    public const NEW_ID = 'fedcba9876544def8123456789abcdef';

    private FixtureRfqParserClient $parser;

    /** @var list<NewDraft> */
    private array $created = [];

    private string $status = 'synced';

    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    protected function setUp(): void
    {
        $this->parser = new FixtureRfqParserClient();
        $this->parser->costs = ['KL-PAXN/1C25OR' => 6.0];
        $this->created = [];
        $this->status = 'synced';
        $this->meta = [];
        $this->rows = null;
    }

    private static function row(string $uid, string $code, string $note = 'Site A'): array
    {
        return [
            'line_uid' => $uid,
            'raw_text' => $code,
            'source' => ['original_text' => $code, 'input_type' => 'pasted_text'],
            'final_product_code' => $code,
            'selected_product_code' => $code,
            'length' => ['number_of_lengths' => 2, 'length_each' => 25, 'total_length' => 50],
            'status' => 'matched',
            'final_status' => 'matched',
            'unit_price' => 4.0,
            'final_unit_price' => 8.0,
            'margin_percent' => 50,
            'line_note' => $note,
            'pricing' => ['status' => 'available', 'unit_price' => 4.0, 'region' => 'australia', 'quote_currency' => 'AUD'],
        ];
    }

    private function service(?RfqActor $unused = null): RfqAliflexService
    {
        $stored = new StoredDraft(
            self::DRAFT_ID,
            4,
            $this->status,
            ['label' => 'Westelec RFQ', 'refno' => '721999', 'refver' => '2', 'assignee_email' => 'someone@tricab.com'],
            ['customer_id' => '123', 'contact' => 'Buyer', 'author' => 'OLDREP', 'salesoffice' => 'PER', 'payterms' => 'EOM30', 'region' => 'australia', 'currency' => 'AUD', 'comment' => 'Hello'],
            $this->meta,
            $this->rows ?? [self::row('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'XL-PAXN/1C25OR'), self::row('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2', 'FR-PAXN/1C240RE', '')],
            [],
        );
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

                return RfqAliflexServiceTest::NEW_ID;
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
                return 'REPCODE';
            }
        };
        $margins = new class () implements RfqDefaultMargins {
            public function forCustomer(int $customerId, array $styles): array
            {
                return ['KL-PAXN/1C25OR' => 40];
            }
        };
        $repository = (new InMemoryRfqRepository())->withRecord(ReadRfqQueueServiceTest::record(StoreProjection::publicId(self::DRAFT_ID), 7, 'VIC'));
        $review = new RfqReviewService($this->parser, $drafts, $repository, $operators, new RecordingCustomerDirectory(), FixedClock::utc('2026-10-02T02:00:00'), null, false, $margins);

        return new RfqAliflexService($this->parser, $repository, $drafts, $review);
    }

    private static function rep(): RfqActor
    {
        return new RfqActor(7, 'user-7', Module::ROLE_PERMISSIONS[Module::ROLE_SALES], 'VIC');
    }

    private function convert(?RfqAliflexService $service = null, int $revision = 4, ?RfqActor $actor = null): ?\TriCab\Core\Rfq\Application\DTO\AliflexResult
    {
        return ($service ?? $this->service())->create($actor ?? self::rep(), 'rep@tricab.com', StoreProjection::publicId(self::DRAFT_ID), $revision, CorrelationId::fromHeaderOrGenerate('corr-aliflex-001'));
    }

    public function test_a_synced_quote_becomes_a_new_alternative_draft_and_the_source_is_not_touched(): void
    {
        $result = $this->convert();

        self::assertNotNull($result);
        self::assertSame(StoreProjection::publicId(self::NEW_ID), $result->publicId);
        self::assertSame('ALI-FLEX alternative · Westelec RFQ', $result->label);
        self::assertSame(['changed' => 1, 'unchanged' => 1, 'review' => 0], $result->counts);
        self::assertStringStartsWith('1 line(s) changed to ALI-FLEX', $result->message);
        self::assertSame(1, $result->toArray()['revision']);

        // The parser was given the saved rows, with the store's bookkeeping left off, and the source's label.
        $call = $this->parser->aliflexCalls[0];
        self::assertSame('Westelec RFQ', $call['label']);
        self::assertSame('XL-PAXN/1C25OR', $call['rows'][0]['final_product_code']);
        self::assertSame(['XL-PAXN/1C25OR', 'FR-PAXN/1C240RE'], array_column($call['rows'], 'final_product_code'));

        self::assertCount(1, $this->created);
        $draft = $this->created[0];
        self::assertSame('alternative', $draft->source);
        self::assertSame('ALI-FLEX alternative · Westelec RFQ', $draft->label);
        self::assertSame('rep@tricab.com', $draft->owner());
        self::assertSame('rep@tricab.com', $draft->assignee());
        self::assertSame('VIC', $draft->ownerBranch());
        self::assertSame('REPCODE', $draft->quoteDetails['author'], 'the worker is the author of what they made');
        self::assertSame('123', $draft->quoteDetails['customer_id']);
        self::assertStringContainsString('ALI-FLEX alternatives have been proposed', (string) $draft->quoteDetails['comment']);
        $meta = $draft->emailMeta ?? [];
        self::assertSame(['policy_version' => 'aliflex-map/2', 'source_draft_id' => self::DRAFT_ID, 'source_status' => 'synced', 'source_refno' => '721999', 'source_refver' => '2', 'counts' => ['changed' => 1, 'unchanged' => 1, 'review' => 0]], $meta['aliflex']);
        self::assertSame('rep@tricab.com', $meta['owner_email']);
        self::assertSame(['inputRows' => 2, 'keptRows' => 2, 'excludedRows' => 0, 'skippedRows' => 0, 'measured' => true], $meta['parse']['coverage'], 'a conversion has nothing to read, so it is complete, not unmeasured');
        self::assertSame(['changed', 'unchanged'], array_map(static fn (array $r): string => $r['aliflex']['status'], $draft->rows));
        self::assertArrayNotHasKey('line_uid', $draft->rows[0], 'the new draft assigns its own line identity');
    }

    public function test_the_changed_lines_are_priced_afresh_with_the_customers_margin_and_unchanged_ones_keep_theirs(): void
    {
        $this->convert();

        $rows = $this->created[0]->rows;
        self::assertSame('KL-PAXN/1C25OR', $rows[0]['final_product_code']);
        self::assertSame('available', $rows[0]['pricing']['status']);
        self::assertSame(6.0, $rows[0]['unit_price'], 'cost from the Cost API for the new product, not the copper source');
        self::assertSame(40, $rows[0]['margin_percent'], 'the customer\'s default margin for the new product');
        self::assertSame(4.0, $rows[1]['unit_price'], 'an unchanged line is the source line and keeps its price');
        self::assertNotEmpty($this->parser->priceCalls);
        self::assertSame('australia', $this->parser->priceCalls[0]['region']);
        self::assertSame('AUD', $this->parser->priceCalls[0]['currency']);
    }

    public function test_prices_that_cannot_be_had_never_lose_the_draft(): void
    {
        $this->parser->priceFailure = RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'down');

        $result = $this->convert();

        self::assertNotNull($result);
        self::assertNotNull($result->publicId);
        self::assertSame('Prices are not available right now. Retry in a minute.', $result->pricingError);
        self::assertCount(1, $this->created);
    }

    public function test_with_no_line_to_change_no_draft_is_made(): void
    {
        $this->rows = [self::row('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'FR-PAXN/1C240RE')];

        $result = $this->convert();

        self::assertNotNull($result);
        self::assertNull($result->publicId);
        self::assertSame('No line matches the ALI-FLEX rule, so no alternative was made.', $result->message);
        self::assertSame([], $this->created);
        self::assertNull($result->toArray()['revision']);
    }

    /** @return iterable<string, array{string, list<array<string, mixed>>|null, array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'not finished' => ['in_progress', null, [], AliflexEligibility::NOT_FINAL];
        yield 'failed sync' => ['failed', null, [], AliflexEligibility::NOT_FINAL];
        yield 'no lines' => ['synced', [], [], AliflexEligibility::NO_LINES];
        yield 'already an alternative by meta' => ['synced', null, ['aliflex' => ['policy_version' => 'aliflex-map/2']], AliflexEligibility::ALREADY];
        yield 'already an alternative by a line' => ['synced', [['line_uid' => 'a', 'final_product_code' => 'KL-PAXN/1C25OR', 'aliflex' => ['status' => 'changed']]], [], AliflexEligibility::ALREADY];
        yield 'only some lines imported' => ['in_progress', [['line_uid' => 'a', 'imported_line' => ['product_code' => 'X']], ['line_uid' => 'b']], [], AliflexEligibility::NOT_FINAL];
    }

    /**
     * @param list<array<string, mixed>>|null $rows
     * @param array<string, mixed>            $meta
     *
     * @dataProvider refusals
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_quote_that_is_not_finished_or_is_already_an_alternative_is_refused(string $status, ?array $rows, array $meta, string $reason): void
    {
        $this->status = $status;
        $this->rows = $rows;
        $this->meta = $meta;

        try {
            $this->convert();
            self::fail('expected a refusal');
        } catch (RfqAliflexNotEligible $refused) {
            self::assertSame($reason, $refused->getMessage());
        }
        self::assertSame([], $this->created);
        self::assertSame([], $this->parser->aliflexCalls, 'the parser is not asked about a quote that cannot be converted');
    }

    public function test_a_wholly_imported_quote_is_finished_without_a_sync(): void
    {
        $this->status = 'in_progress';
        $this->rows = [self::row('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'XL-PAXN/1C25OR') + ['imported_line' => ['product_code' => 'XL-PAXN/1C25OR']]];

        self::assertNotNull($this->convert()?->publicId);
    }

    public function test_a_stale_revision_is_a_conflict_and_nothing_is_made(): void
    {
        try {
            $this->convert(null, 3);
            self::fail('expected a conflict');
        } catch (RfqRevisionConflict $conflict) {
            self::assertSame(4, $conflict->currentRevision);
        }
        self::assertSame([], $this->created);
    }

    public function test_it_needs_the_create_permission_and_an_rfq_the_actor_can_see(): void
    {
        $viewer = new RfqActor(8, 'user-8', [Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ], 'VIC');
        try {
            $this->convert(null, 4, $viewer);
            self::fail('a reader must not create');
        } catch (RfqActionForbidden) {
        }
        self::assertNull($this->service()->create(self::rep(), 'rep@tricab.com', StoreProjection::publicId(str_repeat('b', 32)), 4, CorrelationId::generate()));
        self::assertSame([], $this->created);
    }

    public function test_a_parser_that_cannot_convert_stops_the_whole_thing(): void
    {
        $this->parser->aliflexFailure = RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'down');

        $this->expectException(RfqParserFailure::class);
        try {
            $this->convert();
        } finally {
            self::assertSame([], $this->created);
        }
    }
}
