<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Core\Rfq\Application\DTO\SyncOutcome;
use TriCab\Core\Rfq\Application\Service\RfqSyncService;
use TriCab\Core\Rfq\Contract\RfqDefaultMargins;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncBlocked;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncNeedsReconciliation;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncUnavailable;
use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Rfq\Sync\GatewayHeaderResult;
use TriCab\Core\Rfq\Sync\GatewayLineResult;
use TriCab\Core\Rfq\Sync\GatewayPdfResult;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\InMemoryRfqSyncStore;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingQuoteGateway;

/**
 * Sync to 4js (parity plan E7.3): the sequence, what is recorded before 4js is
 * contacted, and what each way a sync can end looks like. Every request goes to a
 * recording fake; nothing here can reach 4js. The SQL ledger is proved in
 * `tests/Rfq/RfqSyncLedgerTest.php`.
 */
final class RfqSyncServiceTest extends TestCase
{
    private const DRAFT_ID = '0123456789ab4def8123456789abcdef';
    private const UID_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const UID_B = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2';

    private FixtureRfqParserClient $parser;
    private RecordingQuoteGateway $gateway;
    private InMemoryRfqSyncStore $store;
    private FixedClock $clock;

    private ExtractionState $extraction;

    protected function setUp(): void
    {
        $this->parser = new FixtureRfqParserClient();
        $this->gateway = new RecordingQuoteGateway();
        $this->store = new InMemoryRfqSyncStore();
        // 23:00 UTC on 1 October is 10:00 on 2 October in Melbourne.
        $this->clock = FixedClock::utc('2026-10-01T23:00:00');
        $this->extraction = ExtractionState::complete();
    }

    private static function row(string $uid, string $code, float $sell, int $cuts = 2, float $each = 25.0): array
    {
        return [
            'line_uid' => $uid,
            'raw_text' => '3C 2.5 ' . $code,
            'source' => ['original_text' => '3C 2.5', 'input_type' => 'pasted_text'],
            'final_product_code' => $code,
            'selected_product_code' => $code,
            'length' => ['number_of_lengths' => $cuts, 'length_each' => $each, 'total_length' => $cuts * $each],
            'status' => 'matched',
            'final_status' => 'matched',
            'unit_price' => 4.0,
            'final_unit_price' => $sell,
            'margin_percent' => 50,
            'line_note' => 'Site A',
            'pricing' => ['status' => 'available', 'unit_price' => 4.0],
        ];
    }

    /** @param list<array<string, mixed>>|null $rows */
    private function service(bool $enabled = true, ?array $rows = null): RfqSyncService
    {
        // The draft as the store holds it now: its revision, status and quote number move as syncs finish.
        $stored = fn (): StoredDraft => new StoredDraft(
            self::DRAFT_ID,
            (int) $this->store->draft['revision'],
            (string) $this->store->draft['status'],
            ['assignee_email' => 'someone.else@tricab.com', 'source' => 'web', 'refno' => $this->store->draft['refno'], 'refver' => $this->store->draft['refver']],
            ['customer_id' => '123', 'contact' => 'Buyer', 'author' => 'OLDREP', 'salesoffice' => 'PER', 'payterms' => 'EOM30', 'region' => 'australia', 'currency' => 'AUD', 'comment' => 'Hello'],
            [],
            $rows ?? [self::row(self::UID_A, 'TPS3C25', 8.0), self::row(self::UID_B, 'XL-PAXN/4C16OR', 12.5, 1, 40.0)],
            [],
        );
        $drafts = new class ($stored) implements RfqDraftStore {
            /** @param \Closure(): StoredDraft $stored */
            public function __construct(private readonly \Closure $stored)
            {
            }

            public function create(NewDraft $draft): string
            {
                throw new \LogicException('a sync creates nothing');
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): int
            {
                throw new \LogicException('a sync saves no content');
            }

            public function load(string $draftId): ?StoredDraft
            {
                $stored = ($this->stored)();

                return $draftId === $stored->id ? $stored : null;
            }
        };
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): ?string
            {
                return 'REPCODE';
            }
        };
        $margins = new class () implements RfqDefaultMargins {
            public function forCustomer(int $customerId, array $styles): array
            {
                return ['TPS3C25' => 40];
            }
        };
        $record = ReadRfqQueueServiceTest::record(StoreProjection::publicId(self::DRAFT_ID), 7, 'VIC');
        $record = new \TriCab\Core\Rfq\Application\DTO\RfqRecord(
            $record->publicId, $record->reference, $record->title, $record->customerCode, $record->customerName, $record->ownership,
            $record->ownerDisplayName, $record->assigneeDisplayName, $record->workflowStatus, $record->priority, $record->dueAtUtc,
            $record->channel, $this->extraction, $record->latestSyncState, $record->quoteNumber, $record->lineCount, $record->revision,
            $record->receivedAtUtc, $record->updatedAtUtc,
        );

        return new RfqSyncService(
            $this->parser,
            (new InMemoryRfqRepository())->withRecord($record),
            $drafts,
            $this->store,
            $this->gateway,
            $operators,
            $margins,
            $this->clock,
            $enabled,
        );
    }

    private static function rep(string $permission = Module::ROLE_SALES): RfqActor
    {
        return new RfqActor(7, 'user-7', Module::ROLE_PERMISSIONS[$permission], 'VIC');
    }

    private static function holder(): RfqActor
    {
        return new RfqActor(7, 'user-7', [...Module::ROLE_PERMISSIONS[Module::ROLE_SALES], Module::PERMISSION_PRICING_VIEW], 'VIC');
    }

    private function press(?RfqSyncService $service = null, int $revision = 3, ?RfqActor $actor = null): ?SyncOutcome
    {
        return ($service ?? $this->service())->sync($actor ?? self::rep(), 'rep@tricab.com', StoreProjection::publicId(self::DRAFT_ID), $revision, CorrelationId::fromHeaderOrGenerate('corr-sync-000001'));
    }

    private function outcome(?SyncOutcome $outcome): SyncOutcome
    {
        self::assertNotNull($outcome);

        return $outcome;
    }

    // ------------------------------------------------------------------ off and unconnected

    public function test_switched_off_it_answers_503_before_reading_or_sending_anything(): void
    {
        try {
            $this->press($this->service(false));
            self::fail('expected the switch to refuse');
        } catch (RfqSyncUnavailable $unavailable) {
            self::assertSame(RfqSyncUnavailable::DISABLED, $unavailable->reason);
            self::assertStringContainsString('switched off', $unavailable->getMessage());
        }
        self::assertSame([], $this->gateway->calls);
        self::assertSame([], $this->store->log, 'no sweep, no attempt');
        self::assertSame([], $this->parser->syncPlanCalls, 'the parser was not even asked');
        self::assertSame(['available' => false, 'reason' => 'Sending quotes to 4js is switched off on this server. Nothing was sent.', 'code' => 'disabled'], $this->service(false)->availability());
    }

    public function test_switched_on_with_nothing_connected_it_still_sends_nothing(): void
    {
        $this->gateway->connected = false;
        try {
            $this->press();
            self::fail('expected the missing connection to refuse');
        } catch (RfqSyncUnavailable $unavailable) {
            self::assertSame(RfqSyncUnavailable::NOT_CONNECTED, $unavailable->reason);
            self::assertStringContainsString('Nothing was sent.', $unavailable->getMessage());
        }
        self::assertSame([], $this->gateway->calls);
        self::assertSame([], $this->store->log);
        self::assertFalse($this->service()->availability()['available'] && !$this->gateway->connected);
    }

    // ------------------------------------------------------------------ the sequence

    public function test_a_sync_records_its_intent_before_the_header_and_every_step_before_the_next(): void
    {
        $seen = [];
        $this->gateway->onCall = function (string $type) use (&$seen): void {
            // What was already on the ledger the moment this request was made.
            $seen[] = [$type, $this->store->log];
        };

        $outcome = $this->outcome($this->press());

        self::assertSame(['header', 'line', 'line', 'pdf'], $this->gateway->sequence());
        self::assertSame(['sweep', 'begin', 'header_intent', 'header_ok', 'line:0', 'line:1', 'finish:succeeded'], $this->store->log);
        self::assertSame(['sweep', 'begin', 'header_intent'], $seen[0][1], 'the header request leaves only after begin and header_intent are recorded');
        self::assertSame(['sweep', 'begin', 'header_intent', 'header_ok'], $seen[1][1], 'the quote number is recorded before the first line is sent');
        self::assertSame(['sweep', 'begin', 'header_intent', 'header_ok', 'line:0'], $seen[2][1], 'a line is recorded before the next');
        self::assertSame(['sweep', 'begin', 'header_intent', 'header_ok', 'line:0', 'line:1'], $seen[3][1]);

        self::assertSame(SyncOutcome::SUCCEEDED, $outcome->outcome);
        self::assertTrue($outcome->isSuccess());
        self::assertSame('721999', $outcome->quoteNumber);
        self::assertSame('1', $outcome->quoteVersion);
        self::assertFalse($outcome->isUpdate);
        self::assertSame(2, $outcome->linesSent);
        self::assertSame('Quote 721999 created - 2 line(s) synced', $outcome->message);
        self::assertSame('synced', $this->store->draft['status']);
        self::assertFalse($this->store->draft['locked']);
        self::assertSame('succeeded', $outcome->attempt->state->toString());
    }

    public function test_the_header_and_lines_are_what_the_parser_planned_from_the_saved_revision(): void
    {
        $this->press();

        $call = $this->parser->syncPlanCalls[0];
        self::assertSame('2026-10-02', $call['today'], 'the quote date is the Melbourne date, not UTC');
        self::assertNull($call['existingRefno']);
        self::assertNull($call['version']);
        self::assertSame('portal', $call['origin']);
        self::assertSame(['TPS3C25' => 40], $call['margins']);
        self::assertSame('REPCODE', $call['quoteDetails']['author'], 'the worker is the quote author, as on a save');
        self::assertArrayNotHasKey('_draft_row_id', $call['rows'][0]);

        $header = $this->gateway->calls[0]['args']['header'];
        self::assertIsArray($header);
        self::assertSame('0', $header['ref']);
        self::assertSame('REPCODE', $header['author']);
        self::assertSame('02/10/26', $header['date']);
        self::assertArrayNotHasKey('type', $header, 'the transport adds its own parameters; the plan holds none');
        $first = $this->gateway->calls[1]['args'];
        self::assertSame('721999', $first['refno']);
        self::assertSame('0', $first['version']);
        self::assertSame(['code' => 'TPS3C25', 'quantity' => 50.0, 'price' => 8.0, 'cprc' => 4.0, 'comment' => 'Site A', 'unitsys' => 'M'], $first['line']);
    }

    public function test_the_frozen_plan_is_compact_sorted_and_hashed_with_the_correlation_id_kept(): void
    {
        $this->press();

        $payload = (string) $this->store->payload;
        self::assertStringStartsWith('{"cusref":"","customer_id":"123","excluded":[],"existing_refno":"","fallback_margin":[],"hdr_params":{"author":"REPCODE"', $payload);
        self::assertStringNotContainsString(', ', $payload);
        self::assertStringNotContainsString(': ', $payload);
        self::assertSame(hash('sha256', $payload), $this->store->sha);
        self::assertSame($this->store->sha, array_values($this->store->attempts)[0]['sha']);
        self::assertSame('corr-sync-000001', array_values($this->store->attempts)[0]['correlation_id']);
        self::assertSame('rep@tricab.com', array_values($this->store->attempts)[0]['actor']);
    }

    public function test_the_worker_becomes_the_sales_rep_when_the_attempt_is_created(): void
    {
        $this->press();

        self::assertSame('rep@tricab.com', $this->store->rep['assignee']);
        self::assertSame('REPCODE', $this->store->rep['quoteDetails']['author']);
        self::assertSame('operator', $this->store->rep['authorSource']);
        self::assertSame(['from' => 'someone.else@tricab.com', 'to' => 'rep@tricab.com'], $this->store->rep['assigneeChange']);
    }

    // ------------------------------------------------------------------ ways it ends

    public function test_a_refused_header_is_a_certain_failure_with_no_lines_and_no_pdf(): void
    {
        $this->gateway->header = GatewayHeaderResult::rejected('bad customer');

        $outcome = $this->outcome($this->press());

        self::assertSame(['header'], $this->gateway->sequence());
        self::assertSame(SyncOutcome::FAILED, $outcome->outcome);
        self::assertFalse($outcome->isSuccess());
        self::assertSame('QOTHDR rejected: bad customer', $outcome->message);
        self::assertSame(['sweep', 'begin', 'header_intent', 'header_rejected', 'finish:failed'], $this->store->log);
        self::assertSame('failed', $this->store->draft['status']);
        self::assertSame('rejected', $outcome->attempt->headerState->toString());
        self::assertFalse($outcome->attempt->toArray()['needsReconciliation']);
    }

    public function test_a_header_that_did_not_complete_is_uncertain_never_failed_and_never_retried(): void
    {
        $this->gateway->header = GatewayHeaderResult::unknown('timed out');

        $outcome = $this->outcome($this->press());

        self::assertSame(['header'], $this->gateway->sequence());
        self::assertSame(SyncOutcome::UNCERTAIN, $outcome->outcome);
        self::assertSame('uncertain', $outcome->attempt->state->toString());
        self::assertTrue($outcome->attempt->toArray()['needsReconciliation']);
        self::assertStringContainsString('may or may not hold a quote', (string) $outcome->attempt->reconcileNote);
        self::assertSame(['sweep', 'begin', 'header_intent', 'header_unknown', 'finish:uncertain'], $this->store->log);

        // Pressing Sync again must not reach 4js: the uncertain attempt holds the RFQ.
        $calls = count($this->gateway->calls);
        try {
            $this->press(null, 4);
            self::fail('a second sync must wait for a person to check 4js');
        } catch (RfqSyncNeedsReconciliation $held) {
            self::assertTrue($held->attempt->toArray()['needsReconciliation']);
        }
        self::assertCount($calls, $this->gateway->calls);
    }

    public function test_an_adapter_that_throws_has_not_said_no_so_the_outcome_is_unknown(): void
    {
        $this->gateway->throws['header'] = new RuntimeException('https://4js.example.invalid/?password=hunter2');

        $outcome = $this->outcome($this->press());

        self::assertSame(SyncOutcome::UNCERTAIN, $outcome->outcome);
        self::assertStringNotContainsString('hunter2', json_encode($outcome->toArray(), JSON_THROW_ON_ERROR), 'the exception text never reaches the browser');
        self::assertStringContainsString('RuntimeException', $outcome->message);
    }

    public function test_an_accepted_header_with_no_quote_number_is_unknown_not_a_success(): void
    {
        $this->gateway->header = GatewayHeaderResult::accepted('  ', '1');

        $outcome = $this->outcome($this->press());

        self::assertSame(SyncOutcome::UNCERTAIN, $outcome->outcome);
        self::assertSame(['header'], $this->gateway->sequence());
    }

    public function test_some_lines_rejected_is_a_partial_that_names_them_and_keeps_the_quote_number(): void
    {
        $this->gateway->lineAnswers[1] = GatewayLineResult::rejected('Unknown product XL-PAXN/4C16OR');

        $outcome = $this->outcome($this->press());

        self::assertSame(['header', 'line', 'line', 'pdf'], $this->gateway->sequence(), 'the lines are sent one at a time, in order, and the PDF is still tried');
        self::assertSame(SyncOutcome::PARTIAL, $outcome->outcome);
        self::assertSame('721999', $outcome->quoteNumber);
        self::assertSame(1, $outcome->linesSent);
        self::assertSame([['lineNumber' => 2, 'lineUid' => self::UID_B, 'code' => 'XL-PAXN/4C16OR', 'reason' => 'Unknown product XL-PAXN/4C16OR']], $outcome->failures);
        self::assertStringStartsWith('Quote 721999 created - 1 of 2 line(s) synced. 1 of 2 line(s) were rejected by 4js (first: Unknown product XL-PAXN/4C16OR). 1 line(s) did land on quote 721999 v1;', $outcome->message);
        self::assertSame(['sweep', 'begin', 'header_intent', 'header_ok', 'line:0', 'line:1:rejected', 'finish:failed'], $this->store->log);
        self::assertSame('failed', $this->store->draft['status']);
        self::assertSame('721999', $this->store->draft['refno'], 'a retry versions this quote instead of raising a second');
    }

    public function test_every_line_rejected_is_a_failure_with_the_quote_number_kept(): void
    {
        $this->gateway->lineAnswers = [0 => GatewayLineResult::rejected('no'), 1 => GatewayLineResult::rejected('no')];

        $outcome = $this->outcome($this->press());

        self::assertSame(SyncOutcome::FAILED, $outcome->outcome);
        self::assertStringContainsString('No lines were added to the quote.', $outcome->message);
        self::assertSame('721999', $outcome->quoteNumber);
        self::assertCount(2, $outcome->failures);
    }

    public function test_a_line_whose_request_throws_is_a_rejected_line_and_the_rest_are_still_sent(): void
    {
        $this->gateway->throws['line:0'] = new RuntimeException('secret-token');

        $outcome = $this->outcome($this->press());

        self::assertSame(['header', 'line', 'line', 'pdf'], $this->gateway->sequence());
        self::assertSame(SyncOutcome::PARTIAL, $outcome->outcome);
        self::assertStringNotContainsString('secret-token', json_encode($outcome->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_a_pdf_that_cannot_be_made_never_fails_the_sync(): void
    {
        $this->gateway->pdf = GatewayPdfResult::failed('PDF request failed');

        $outcome = $this->outcome($this->press());

        self::assertTrue($outcome->isSuccess());
        self::assertNull($outcome->toArray()['quote']['pdfUrl']);
        self::assertSame('PDF request failed', $outcome->toArray()['quote']['pdfError']);
    }

    public function test_only_an_https_pdf_link_leaves_the_server(): void
    {
        $this->gateway->pdf = GatewayPdfResult::rendered('javascript:alert(1)');

        self::assertNull($this->outcome($this->press())->toArray()['quote']['pdfUrl']);
    }

    public function test_the_plans_exclusions_and_fallback_margins_are_reported(): void
    {
        $this->parser->syncPlan = [
            'is_update' => false, 'existing_refno' => '', 'push_version' => '0',
            'hdr_params' => ['ref' => '0', 'cust' => '123', 'author' => 'REPCODE', 'date' => '02/10/26', 'version' => '0'],
            'line_items' => [['code' => 'TPS3C25', 'quantity' => 50.0, 'price' => 8.0, 'cprc' => 4.0, 'comment' => '', 'unitsys' => 'M', 'row_number' => 1, 'row_id' => self::UID_A]],
            'excluded' => [['row_number' => 2, 'row_id' => self::UID_B, 'code' => 'XL-PAXN/4C16OR', 'reason' => 'excluded by a directive: not wanted']],
            'fallback_margin' => [['row_number' => 1, 'row_id' => self::UID_A, 'code' => 'TPS3C25', 'margin' => 30, 'reason' => 'No customer margin']],
            'customer_id' => '123', 'cusref' => '',
        ];

        $outcome = $this->outcome($this->press());

        self::assertSame([['lineNumber' => 2, 'lineUid' => self::UID_B, 'code' => 'XL-PAXN/4C16OR', 'reason' => 'excluded by a directive: not wanted']], $outcome->excluded);
        self::assertSame([['lineNumber' => 1, 'lineUid' => self::UID_A, 'code' => 'TPS3C25', 'margin' => 30]], $outcome->fallbackMargins);
        self::assertSame(1, $outcome->linesTotal);
        // The line is named for everyone; its percentage only for a holder of RFQ.PRICING.VIEW.
        self::assertSame(30, $outcome->toArray(true)['fallbackMargins'][0]['margin']);
        self::assertNull($outcome->toArray(false)['fallbackMargins'][0]['margin']);
        self::assertSame('TPS3C25', $outcome->toArray(false)['fallbackMargins'][0]['code']);
    }

    public function test_a_new_version_of_a_quote_already_in_4js_targets_it(): void
    {
        $this->store->draft = ['revision' => 3, 'status' => 'failed', 'refno' => '721999', 'refver' => '1', 'locked' => false];
        $this->gateway->header = GatewayHeaderResult::accepted('721999', '2');

        $outcome = $this->outcome($this->press());

        $call = $this->parser->syncPlanCalls[0];
        self::assertSame('721999', $call['existingRefno']);
        self::assertSame(2, $call['version'], 'one past the current, never below 2');
        self::assertSame('721999', $this->gateway->calls[0]['args']['header']['ref']);
        self::assertSame('2', $this->gateway->calls[1]['args']['version']);
        self::assertTrue($outcome->isUpdate);
        self::assertSame('Quote 721999 updated to v2 - 2 line(s) synced', $outcome->message);
    }

    // ------------------------------------------------------------------ refused before anything is recorded or sent

    public function test_a_quote_that_is_not_ready_is_refused_with_its_issues_and_nothing_is_recorded_or_sent(): void
    {
        $this->parser->syncProblem = ['status' => 400, 'payload' => [
            'ok' => false,
            'error' => 'Fix the listed issues before syncing. No quote was sent to 4js.',
            'blocking_issues' => [
                ['issue' => 'invalid_quantity_or_price', 'row_number' => 2, 'row_id' => self::UID_B, 'code' => 'XL-PAXN/4C16OR', 'reason' => 'quantity and price must both be greater than 0 (check length and pricing)'],
                ['issue' => 'missing_header', 'field' => 'contact', 'reason' => 'Missing required field: contact'],
            ],
        ]];

        try {
            $this->press();
            self::fail('expected the quote to be refused');
        } catch (RfqSyncBlocked $blocked) {
            self::assertFalse($blocked->pricesChanged);
            self::assertSame('Fix the listed issues before syncing. No quote was sent to 4js.', $blocked->getMessage());
            self::assertSame(['lineNumber' => 2, 'lineUid' => self::UID_B, 'code' => 'XL-PAXN/4C16OR', 'reason' => 'quantity and price must both be greater than 0 (check length and pricing)'], $blocked->issues[0]);
            self::assertNull($blocked->issues[1]['lineNumber'], 'a header issue has no line');
        }
        self::assertSame([], $this->gateway->calls);
        self::assertNotContains('begin', $this->store->log, 'no attempt for a quote that was never going to be sent');
    }

    public function test_a_price_the_customers_rule_would_change_is_a_409_with_both_prices(): void
    {
        $this->parser->syncProblem = ['status' => 409, 'payload' => [
            'ok' => false,
            'error' => 'Prices have changed. Refresh customer margins and review the updated quote before syncing. Nothing was sent to 4js.',
            'price_changes' => [['row_number' => 1, 'row_id' => self::UID_A, 'code' => 'TPS3C25', 'reviewed_price' => 8.0, 'required_price' => 9.5, 'reason' => 'Customer pricing differs from this draft.']],
        ]];

        try {
            $this->press(null, 3, self::holder());
            self::fail('expected the changed price to be refused');
        } catch (RfqSyncBlocked $blocked) {
            self::assertTrue($blocked->pricesChanged);
            self::assertSame(8.0, $blocked->issues[0]['reviewedPrice'] ?? null);
            self::assertSame(9.5, $blocked->issues[0]['requiredPrice'] ?? null);
        }
        // Without RFQ.PRICING.VIEW the customer's required price (their margin by another name) is not named.
        try {
            $this->press();
            self::fail('expected the changed price to be refused');
        } catch (RfqSyncBlocked $blocked) {
            self::assertTrue($blocked->pricesChanged);
            self::assertArrayNotHasKey('reviewedPrice', $blocked->issues[0]);
            self::assertArrayNotHasKey('requiredPrice', $blocked->issues[0]);
            self::assertSame('Customer pricing differs from this draft.', $blocked->issues[0]['reason']);
        }
        self::assertSame([], $this->gateway->calls);
    }

    public function test_a_parser_that_cannot_plan_stops_the_sync_before_anything_is_recorded(): void
    {
        $this->parser->syncPlanFailure = RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'down');

        $this->expectException(RfqParserFailure::class);
        try {
            $this->press();
        } finally {
            self::assertSame([], $this->gateway->calls);
            self::assertNotContains('begin', $this->store->log);
        }
    }

    public function test_a_stale_revision_is_a_conflict(): void
    {
        try {
            $this->press(null, 2);
            self::fail('expected a conflict');
        } catch (RfqRevisionConflict $conflict) {
            self::assertSame(3, $conflict->currentRevision);
        }
        self::assertSame([], $this->gateway->calls);
        self::assertNotContains('begin', $this->store->log);
    }

    public function test_a_quote_already_in_4js_is_not_sent_again(): void
    {
        $this->store->draft = ['revision' => 3, 'status' => 'synced', 'refno' => '721999', 'refver' => '2', 'locked' => false];

        try {
            $this->press();
            self::fail('expected the synced quote to be refused');
        } catch (RfqDraftNotEditable $refused) {
            self::assertSame(RfqDraftNotEditable::ALREADY_SYNCED, $refused->reason);
            self::assertStringStartsWith('Quote 721999 (v2) is in 4js. Further changes belong in 4js', $refused->getMessage());
        }
        self::assertSame([], $this->gateway->calls);
    }

    public function test_a_second_click_while_one_is_running_is_refused_and_sends_nothing(): void
    {
        $this->store->draft['locked'] = true;

        $this->expectException(RfqDraftNotEditable::class);
        try {
            $this->press();
        } finally {
            self::assertSame([], $this->gateway->calls);
        }
    }

    public function test_a_reader_whose_lines_are_not_all_accounted_for_blocks_the_sync(): void
    {
        foreach ([ExtractionState::partial(), ExtractionState::reading(), ExtractionState::attention()] as $state) {
            $this->extraction = $state;
            try {
                $this->press();
                self::fail('expected ' . $state->toString() . ' to block');
            } catch (RfqSyncBlocked $blocked) {
                self::assertNotSame('', $blocked->getMessage());
                self::assertFalse($blocked->pricesChanged);
            }
        }
        self::assertSame([], $this->gateway->calls);
    }

    public function test_it_needs_the_sync_permission_and_an_rfq_the_actor_can_see(): void
    {
        $viewer = new RfqActor(8, 'user-8', [Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ], 'VIC');
        try {
            $this->service()->sync($viewer, 'viewer@tricab.com', StoreProjection::publicId(self::DRAFT_ID), 3, CorrelationId::generate());
            self::fail('a reader must not sync');
        } catch (RfqActionForbidden) {
        }
        self::assertNull($this->service()->sync(self::rep(), 'rep@tricab.com', StoreProjection::publicId(str_repeat('b', 32)), 3, CorrelationId::generate()));
        self::assertSame([], $this->gateway->calls);
    }

    public function test_availability_says_so_when_it_can_run(): void
    {
        self::assertSame(['available' => true, 'reason' => null, 'code' => null], $this->service()->availability());
    }
}
