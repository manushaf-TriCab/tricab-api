<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use TriCab\Core\Rfq\Application\DTO\ParsedRfq;
use TriCab\Core\Rfq\Application\DTO\RfqPricingView;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Application\DTO\RfqReadinessView;
use TriCab\Core\Rfq\Application\DTO\SavedReview;
use TriCab\Core\Rfq\Contract\RfqCorrectionMemory;
use TriCab\Core\Rfq\Contract\RfqCustomerDirectory;
use TriCab\Core\Rfq\Contract\RfqDefaultMargins;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Application\DTO\CreatedRfq;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\RfqAction;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseSeal;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\DraftChangeLog;
use TriCab\Core\Rfq\Storage\DraftLineEdits;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\PythonText;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;
use TriCab\Core\Rfq\Storage\SalesRepAttribution;
use TriCab\Core\Rfq\Storage\StoreFormat;
use TriCab\Core\Rfq\Storage\StoredRevision;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Shared\Time\Clock;

/**
 * The first vertical slice (plan 3.7) and the editor frame (parity plan E2):
 * pasted text is matched, a person fills in the header and saves it as a
 * draft, and reviews and saves it again. External writes do not exist on
 * this path: no 4js, no mail, no model.
 *
 * ## Match Text stores nothing; Save as Draft creates the draft
 *
 * As in the source (parity plan D3): {@see parse()} answers the lines and a
 * sealed copy of the parser's answer ({@see ParseSeal}), and {@see create()}
 * opens that seal. The draft is owned by, assigned to and authored by the
 * person who saves it, and its system snapshot is the parser's real output
 * rather than the browser's copy of it. The browser sends the header and its
 * decisions, never rows.
 *
 * ## A save is the source's `update_draft`
 *
 * The browser sends decisions, not rows ({@see DraftLineEdits}), and the
 * header as filled in ({@see QuoteHeaderEdit}); the customer's code and name
 * come from the customer book, by its 4js id. The status a save leaves is the
 * source's readiness rule, asked of the parser. When the parser cannot
 * answer, the draft is saved `in_progress`: "ready" is never claimed without
 * the rule that decides it, and a person's save is never lost to it.
 */
final class RfqReviewService
{
    /** The label with no customer yet; the source's form is "<customer> – dd Mon yyyy". */
    private const PASTE_LABEL = 'Pasted text';

    /** The source's `REGION_PROFILE`: the currency a region quotes in unless told otherwise. */
    private const REGION_CURRENCY = ['australia' => 'AUD', 'newzealand' => 'NZD', 'usa' => 'USD', 'asia' => 'USD'];

    public function __construct(
        private readonly RfqParserClient $parser,
        private readonly RfqDraftStore $drafts,
        private readonly RfqRepository $rfqs,
        private readonly RfqOperatorDirectory $operators,
        private readonly RfqCustomerDirectory $customers,
        private readonly Clock $clock,
        private readonly ?ParseSeal $seal,
        private readonly bool $allowModel = false,
        private readonly ?RfqDefaultMargins $margins = null,
        private readonly ?RfqCorrectionMemory $memory = null,
    ) {
    }

    /**
     * Match Text: the lines the parser reads in the text, sealed for the save.
     * Nothing is stored.
     *
     * @throws RfqParserFailure    when the parser cannot read the text
     * @throws InvalidRfqValue     for empty or oversized text, or a parse with no lines
     * @throws RfqActionForbidden  without `RFQ.DRAFT.CREATE`
     */
    public function parse(RfqActor $actor, string $email, string $text, CorrelationId $id): ParsedRfq
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_CREATE)) {
            throw new RfqActionForbidden('create RFQs');
        }
        // Plan 3.5: a model may run only when `RFQ_PARSER_ALLOW_MODEL` says so,
        // whatever the parser's own configuration; the text cannot turn it on.
        $parsed = $this->parser->parse(ParseRequest::forText($id, $text, $this->allowModel)->withCorrections($this->corrections()));
        if ($parsed->lines === []) {
            throw InvalidRfqValue::required('RFQ lines', 'nothing in the text could be read as a line.');
        }
        $seal = $this->seal ?? throw RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'The RFQ parser is not configured on this server.', $id);

        // The uid each line keeps once stored, so a decision made before the
        // save names the same line after it.
        $rows = array_map(static function (array $row): array {
            $row['line_uid'] = StoreFormat::newId();

            return $row;
        }, $parsed->lines);
        // What produced revision 1 (plan 3.6), recorded with the draft. Names
        // and counts only; no text.
        $meta = [
            'correlation_id' => $parsed->correlationId,
            'engine' => $parsed->engine,
            'provider' => $parsed->provider,
            'coverage' => $parsed->coverage,
            'degraded' => $parsed->degraded,
        ];

        return new ParsedRfq(
            $seal->seal($email, $this->clock->now(), $rows, $meta),
            array_map(StoredRevision::line(...), $rows),
            $parsed->coverage,
            $parsed->isCoverageComplete(),
            $parsed->degraded,
            $parsed->notes,
        );
    }

    /**
     * Save as Draft: the sealed parse (or, with no match, nothing), with the
     * header, the decisions made on it and the lines typed in, becomes a new
     * draft.
     *
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, marginPercent?: ?string, sellPrice?: ?string, status?: ?string, correctionReason?: ?string, reviewerNote?: ?string}> $lines every line to keep, in order
     *
     * @throws InvalidRfqValue      for a seal that is not this person's or has expired, an unknown
     *                              line or customer, or nothing left to save
     * @throws RfqActionForbidden   without `RFQ.DRAFT.CREATE`
     * @throws RfqLookupUnavailable when the customer book cannot be read
     * @throws RfqParserFailure     where no parser is configured
     * @throws RfqStoreUnavailable  where there is no RFQ store
     */
    public function create(RfqActor $actor, string $email, ?string $parseToken, QuoteHeaderEdit $header, array $lines, CorrelationId $id): CreatedRfq
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_CREATE)) {
            throw new RfqActionForbidden('create RFQs');
        }
        self::guardPricingChoices($actor, $lines);
        $sealed = $this->openSeal($parseToken, $email, $id);
        $rows = DraftLineEdits::apply($sealed['rows'], $lines, $header->region);
        if ($rows === []) {
            throw new InvalidRfqValue('No lines to save. Match an RFQ first.');
        }
        $customer = $this->customer($header);

        $worker = $this->draftActor($actor, $email);
        $rep = SalesRepAttribution::apply($header->applyTo([], $customer), null, $worker, $worker->operatorCode);
        // Priced in the header's region and currency, with the customer's default margins.
        $rows = $this->priced($rows, $rep['quoteDetails'], $id, true)['rows'];
        $meta = [
            'owner_email' => $worker->email,
            'rep_email' => $worker->email,
        ];
        $login = SalesRepAttribution::login($email);
        if ($login !== '') {
            $meta['owner_login'] = $login;
        }
        if ($worker->branch !== null) {
            $meta['owner_branch'] = $worker->branch;
        }
        if ($rep['authorSource'] !== null) {
            $meta['author_source'] = $rep['authorSource'];
        }
        // The accounting the queue reads (plan 3.4). Lines typed in with no match
        // had nothing to read, which is complete, not unmeasured.
        $meta['parse'] = $sealed['parse'] !== []
            ? $sealed['parse']
            : ['coverage' => ['inputRows' => 0, 'keptRows' => 0, 'excludedRows' => 0, 'skippedRows' => 0, 'measured' => true]];

        $draftId = $this->drafts->create(new NewDraft(
            source: 'web',
            label: ($customer === null ? self::PASTE_LABEL : $customer->code) . " \u{2013} " . $this->clock->now()->format('d M Y'),
            quoteDetails: $rep['quoteDetails'],
            rows: $rows,
            emailMeta: $meta,
            creatorEmail: $email,
            branch: $worker->branch ?? '',
        ));

        /** @var array{inputRows: int, keptRows: int, excludedRows: int, skippedRows: int} $coverage */
        $coverage = $meta['parse']['coverage'];

        return new CreatedRfq(
            StoreProjection::publicId($draftId),
            count($rows),
            $coverage,
            StoreProjection::coverageComplete(json_encode($coverage)),
            ($sealed['parse']['degraded'] ?? false) === true,
            [],
        );
    }

    /**
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, marginPercent?: ?string, sellPrice?: ?string, status?: ?string, correctionReason?: ?string, reviewerNote?: ?string}> $lines every line to keep, in order
     * @param QuoteHeaderEdit|null $header the header as filled in; null keeps the stored one
     *
     * @return int|null the new revision; null when there is no such RFQ for this actor
     *
     * @throws RfqActionForbidden   when the actor may not edit it
     * @throws RfqRevisionConflict  when somebody saved first
     * @throws RfqDraftNotEditable  when it is archived or syncing
     * @throws InvalidRfqValue      for a line that is not on it, an approval it cannot take, or an unknown customer
     * @throws RfqLookupUnavailable when the customer book cannot be read
     * @throws RfqStoreUnavailable  where there is no RFQ store
     */
    public function save(RfqActor $actor, string $email, string $publicId, int $expectedRevision, array $lines, CorrelationId $id, ?QuoteHeaderEdit $header = null): ?int
    {
        $record = $this->rfqs->findRecord(strtolower(trim($publicId)));
        if ($record === null || !RfqAccessPolicy::canSee($actor, $record->ownership)) {
            return null;
        }
        if (!in_array(RfqAction::EDIT, RfqAccessPolicy::allowedActions($actor, $record->ownership), true)) {
            throw new RfqActionForbidden('edit this RFQ');
        }
        $draftId = StoreProjection::draftId($publicId);
        $stored = $draftId === null ? null : $this->drafts->load($draftId);
        if ($draftId === null || $stored === null) {
            return null;
        }
        if ($stored->revision !== $expectedRevision) {
            throw new RfqRevisionConflict($stored->revision, $expectedRevision);
        }

        self::guardPricingChoices($actor, $lines);
        $details = $header === null ? $stored->quoteDetails : $header->applyTo($stored->quoteDetails, $this->customer($header));
        $rows = DraftLineEdits::apply($stored->rows, $lines, self::region($details));
        $rows = $this->priced($rows, $details, $id, true)['rows'];
        $worker = $this->draftActor($actor, $email);
        $currentAssignee = is_string($stored->columns['assignee_email'] ?? null) ? $stored->columns['assignee_email'] : null;
        // Readiness judges the header the store is about to write: the worker's
        // attribution (author) is applied inside the save, so apply it here too.
        $judged = SalesRepAttribution::apply($details, $currentAssignee, $worker, $worker->operatorCode)['quoteDetails'];

        return $this->drafts->saveContent(
            $draftId,
            $expectedRevision,
            $details,
            $rows,
            $this->nextStatus($id, $rows, $judged),
            $worker,
        );
    }

    /**
     * Save Review (parity plan E5.5): log the saved revision as a review
     * session, a line each, and teach the correction memory every approved
     * line's code. Only the revision as saved: unsaved edits would log lines
     * the draft does not hold.
     *
     * The parser keys the lines (the key is its normalisation, not ours); a
     * parser failure stops the save before anything is written.
     *
     * @return SavedReview|null null when there is no such RFQ for this actor
     *
     * @throws RfqActionForbidden  when the actor may not edit it
     * @throws RfqRevisionConflict when the revision is not the saved one
     * @throws RfqParserFailure    when the parser cannot key the lines
     * @throws RfqStoreUnavailable where there is no RFQ store or memory
     */
    public function saveReview(RfqActor $actor, string $email, string $publicId, int $expectedRevision, CorrelationId $id): ?SavedReview
    {
        $record = $this->rfqs->findRecord(strtolower(trim($publicId)));
        if ($record === null || !RfqAccessPolicy::canSee($actor, $record->ownership)) {
            return null;
        }
        if (!in_array(RfqAction::EDIT, RfqAccessPolicy::allowedActions($actor, $record->ownership), true)) {
            throw new RfqActionForbidden('edit this RFQ');
        }
        $memory = $this->memory ?? throw new RfqStoreUnavailable();
        $draftId = StoreProjection::draftId($publicId);
        $stored = $draftId === null ? null : $this->drafts->load($draftId);
        if ($draftId === null || $stored === null) {
            return null;
        }
        if ($stored->revision !== $expectedRevision) {
            throw new RfqRevisionConflict($stored->revision, $expectedRevision);
        }

        // `learn_approved_corrections`: approved lines with a code, keyed on their raw text.
        $approved = [];
        foreach ($stored->rows as $row) {
            if (PythonText::str($row['final_status'] ?? '') !== 'approved') {
                continue;
            }
            $code = strtoupper(trim(PythonText::str($row['final_product_code'] ?? '') ?: PythonText::str($row['selected_product_code'] ?? '')));
            $text = PythonText::str($row['raw_text'] ?? '');
            if ($code !== '' && trim($text) !== '') {
                $approved[] = ['text' => $text, 'productCode' => $code];
            }
        }
        $learned = [];
        foreach ($this->parser->correctionKeys($id, $approved) as $entry) {
            $learned[] = ['key' => $entry['key'], 'productCode' => $entry['productCode']];
        }
        $systemRows = array_values(array_map(
            static fn (mixed $row): array => is_array($row) ? $row : [],
            $stored->systemRows,
        ));
        $sessionId = $memory->recordReview(
            $draftId,
            $stored->revision,
            $stored->quoteDetails,
            array_values($stored->rows),
            $systemRows,
            $email,
            $actor->branchCode,
            $learned,
        );

        return new SavedReview($sessionId, count($stored->rows), count($learned));
    }

    /**
     * The correction memory a parse is sent. A memory that cannot be read
     * costs the parse its corrections, never the parse.
     *
     * @return array<string, array{product_code: string, confirmations: int}>
     */
    private function corrections(): array
    {
        if ($this->memory === null) {
            return [];
        }
        try {
            return $this->memory->resolved();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Readiness of an unsaved match as the editor holds it: the sealed rows
     * with the decisions applied, and the header as filled in. Nothing is
     * stored.
     *
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, marginPercent?: ?string, sellPrice?: ?string, status?: ?string, correctionReason?: ?string, reviewerNote?: ?string}> $lines
     *
     * @throws InvalidRfqValue|RfqActionForbidden|RfqParserFailure
     */
    public function previewNew(RfqActor $actor, string $email, ?string $parseToken, QuoteHeaderEdit $header, array $lines, CorrelationId $id): RfqReadinessView
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_CREATE)) {
            throw new RfqActionForbidden('create RFQs');
        }
        self::guardPricingChoices($actor, $lines);
        $rows = DraftLineEdits::apply($this->openSeal($parseToken, $email, $id)['rows'], $lines, $header->region);
        $worker = $this->draftActor($actor, $email);
        $details = SalesRepAttribution::apply($header->applyTo([], self::namedOnly($header)), null, $worker, $worker->operatorCode)['quoteDetails'];

        return $this->readiness($actor, $rows, $details, $id);
    }

    /**
     * Readiness of a draft as the editor holds it, saved or not. Anyone who
     * can read the RFQ can ask; nothing is stored.
     *
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, marginPercent?: ?string, sellPrice?: ?string, status?: ?string, correctionReason?: ?string, reviewerNote?: ?string}>|null $lines null for the lines as saved
     *
     * @return RfqReadinessView|null null when there is no such RFQ for this actor
     *
     * @throws InvalidRfqValue|RfqParserFailure|RfqStoreUnavailable
     */
    public function previewDraft(RfqActor $actor, string $email, string $publicId, ?QuoteHeaderEdit $header, ?array $lines, CorrelationId $id): ?RfqReadinessView
    {
        $record = $this->rfqs->findRecord(strtolower(trim($publicId)));
        if ($record === null || !RfqAccessPolicy::canSee($actor, $record->ownership)) {
            return null;
        }
        $draftId = StoreProjection::draftId($publicId);
        $stored = $draftId === null ? null : $this->drafts->load($draftId);
        if ($stored === null) {
            return null;
        }
        $details = $header === null ? $stored->quoteDetails : $header->applyTo($stored->quoteDetails, self::namedOnly($header));
        $rows = $lines === null ? $stored->rows : DraftLineEdits::apply($stored->rows, $lines, self::region($details));
        $worker = $this->draftActor($actor, $email);
        $currentAssignee = is_string($stored->columns['assignee_email'] ?? null) ? $stored->columns['assignee_email'] : null;
        $details = SalesRepAttribution::apply($details, $currentAssignee, $worker, $worker->operatorCode)['quoteDetails'];

        return $this->readiness($actor, $rows, $details, $id);
    }

    /**
     * Readiness over the rows as a save would price them. A person who may see
     * prices also gets them, from the same pricing pass, so what the detailed
     * view shows is what readiness judged. Anyone else has lines priced only
     * when a price is missing, because readiness needs one.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $details
     *
     * @throws RfqParserFailure
     */
    private function readiness(RfqActor $actor, array $rows, array $details, CorrelationId $id): RfqReadinessView
    {
        $withPrices = $actor->can(Module::PERMISSION_PRICING_VIEW);
        $priced = $this->priced($rows, $details, $id, $withPrices);
        $view = RfqReadinessView::from($this->parser->readiness($id, self::clean($priced['rows']), $details), $priced['rows']);

        return $withPrices ? $view->withPricing(self::pricingView($priced['rows'], self::currency($details), $priced['error'])) : $view;
    }

    /**
     * Without `RFQ.PRICING.VIEW` nobody sets a margin or a sell price: those are
     * the cost and margin the permission withholds, so they are refused rather
     * than ignored.
     *
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool, marginPercent?: ?string, sellPrice?: ?string, status?: ?string, correctionReason?: ?string, reviewerNote?: ?string}> $lines
     *
     * @throws RfqActionForbidden
     */
    private static function guardPricingChoices(RfqActor $actor, array $lines): void
    {
        if ($actor->can(Module::PERMISSION_PRICING_VIEW)) {
            return;
        }
        foreach ($lines as $line) {
            if (isset($line['marginPercent']) || isset($line['sellPrice'])) {
                throw new RfqActionForbidden('set margins or sell prices');
            }
        }
    }

    /**
     * The rows priced as the source editor prices them (parity plan E5): cost,
     * MOQ, prepack and currency from the Cost API when a line needs a price or
     * the header's region or currency moved, then the customer's default
     * margin, the sell price and the line total. `$always` runs the margin
     * pass even when no price is needed (a save, or a view that shows prices).
     *
     * A parser or cost book that cannot answer never loses a save: the rows
     * come back as they were, with `error` saying why.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $details
     *
     * @return array{rows: list<array<string, mixed>>, error: ?string}
     */
    private function priced(array $rows, array $details, CorrelationId $id, bool $always): array
    {
        $region = self::region($details);
        $currency = self::currency($details);
        $reprice = self::needsPricing($rows, $region, $currency);
        if ($rows === [] || (!$reprice && !$always)) {
            return ['rows' => $rows, 'error' => null];
        }
        try {
            $sent = self::clean($rows);
            $answer = $this->parser->priceRows($id, $sent, $region, $currency, $this->defaultMargins($details, $sent, $id), $reprice);
        } catch (RfqParserFailure $failure) {
            error_log(sprintf('RFQ pricing unavailable (%s, correlation %s); stale prices cleared', $failure->failureCode, $id->toString()));
            $error = 'Prices are not available right now. Retry in a minute.';

            return ['rows' => $reprice ? self::withoutStalePrices($rows, $region, $currency, $error) : $rows, 'error' => $error];
        }
        $out = [];
        foreach ($answer->rows as $i => $row) {
            // The store's bookkeeping is not the parser's to know; it stays with the row.
            foreach (['_draft_row_id', '_system_snapshot_valid'] as $key) {
                if (array_key_exists($key, $rows[$i])) {
                    $row[$key] = $rows[$i][$key];
                }
            }
            $out[] = $row;
        }

        return ['rows' => $out, 'error' => null];
    }

    /**
     * Whether any line with a product has no usable price for this region and
     * currency: still pending, failed, never asked, or priced for another
     * region or currency. A code the Cost API does not carry, or a line with no
     * length yet, stays as it is until something about it changes.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function needsPricing(array $rows, string $region, string $currency): bool
    {
        foreach ($rows as $row) {
            if (self::rowNeedsPricing($row, $region, $currency)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private static function rowNeedsPricing(array $row, string $region, string $currency): bool
    {
        if (DraftLineEdits::productCode($row) === '') {
            return false;
        }
        $pricing = DraftChangeLog::object($row['pricing'] ?? null);
        $status = strtolower(PythonText::str($pricing['status'] ?? ''));
        if ($status === '' || in_array($status, ['pending', 'loading', 'error'], true)) {
            return true;
        }
        if (in_array($status, ['missing_code', 'missing_length'], true)) {
            return false;
        }
        $priced = PythonText::str($pricing['quote_currency'] ?? '');

        return PythonText::str($pricing['region'] ?? '') !== $region || ($priced !== '' && $priced !== $currency);
    }

    /**
     * Pricing was needed and could not be had: every line whose price was for
     * another region or currency, or for a code or quantity since changed,
     * loses its cost, sell price and total and reads `error`, so the view
     * counts it unpriced and a save does not keep the old numbers under the
     * new currency. A typed sell price was typed for the old terms and goes too.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutStalePrices(array $rows, string $region, string $currency, string $error): array
    {
        foreach ($rows as $i => $row) {
            if (!self::rowNeedsPricing($row, $region, $currency)) {
                continue;
            }
            $pricing = DraftChangeLog::object($row['pricing'] ?? null);
            $hasPrice = false;
            foreach (['unit_price', 'final_unit_price', 'line_total'] as $key) {
                $hasPrice = $hasPrice || !in_array($row[$key] ?? null, [null, ''], true);
            }
            if (!$hasPrice && in_array(strtolower(PythonText::str($pricing['status'] ?? '')), ['', 'pending', 'loading', 'error'], true)) {
                // Nothing stale to clear: the line already reads as not priced.
                continue;
            }
            unset($pricing['quote_currency'], $pricing['cost_status']);
            $pricing['status'] = 'error';
            $pricing['error'] = $error;
            $pricing['region'] = $region;
            $row['pricing'] = $pricing;
            $row['unit_price'] = '';
            $row['final_unit_price'] = '';
            $row['line_total'] = '';
            unset($row['sell_price_locked']);
            $rows[$i] = $row;
        }

        return $rows;
    }

    /**
     * The customer's default margin for each product on the rows. Soft: a
     * customer, style or lookup that is missing leaves the margin the line has.
     *
     * @param array<string, mixed>       $details
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int|string>
     *
     * @throws RfqParserFailure
     */
    private function defaultMargins(array $details, array $rows, CorrelationId $id): array
    {
        $customerId = is_numeric($details['customer_id'] ?? null) ? (int) $details['customer_id'] : 0;
        if ($customerId < 1 || $this->margins === null) {
            return [];
        }
        $codes = [];
        foreach ($rows as $row) {
            $code = strtoupper(DraftLineEdits::productCode($row));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return $this->margins->forCustomer($customerId, $this->parser->productStyles($id, array_keys($codes)));
    }

    /**
     * What the detailed view shows of the rows: each line's price, the quote
     * total over the lines being quoted, and how many of them have no price.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function pricingView(array $rows, string $currency, ?string $error): RfqPricingView
    {
        $lines = [];
        $quoted = 0;
        $unpriced = 0;
        $cents = 0;
        foreach ($rows as $row) {
            $line = StoredRevision::line($row);
            $pricing = $line->pricing;
            if ($pricing === null) {
                continue;
            }
            $lines[] = ['lineUid' => $line->lineUid, 'pricing' => $pricing];
            if ($line->disposition->toString() === 'excluded' || ($line->productCode ?? '') === '') {
                continue;
            }
            ++$quoted;
            if ($pricing->lineTotal !== null) {
                $cents += (int) round((float) $pricing->lineTotal * 100);
            }
            if ($pricing->unitSell === null && in_array($pricing->priceStatus, ['not_found', 'error'], true)) {
                ++$unpriced;
            }
        }

        return new RfqPricingView($currency, $quoted === 0 ? null : sprintf('%d.%02d', intdiv($cents, 100), $cents % 100), $unpriced, $quoted, $lines, $error);
    }

    /**
     * The sealed parse, or nothing: without Match Text a new RFQ is only the
     * lines typed into it, as the source's manual entry allows.
     *
     * @return array{rows: list<array<string, mixed>>, parse: array<string, mixed>}
     *
     * @throws InvalidRfqValue|RfqParserFailure
     */
    private function openSeal(?string $parseToken, string $email, CorrelationId $id): array
    {
        if ($parseToken === null) {
            return ['rows' => [], 'parse' => []];
        }
        $seal = $this->seal ?? throw RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'The RFQ parser is not configured on this server.', $id);

        return $seal->open($parseToken, $email, $this->clock->now());
    }

    /**
     * Readiness asks only whether a customer is named, so a preview names it
     * by id without a trip to the customer book; a save still makes that trip.
     */
    private static function namedOnly(QuoteHeaderEdit $header): ?RfqCustomerOption
    {
        return $header->customerId === null ? null : new RfqCustomerOption($header->customerId, '', '', null, '');
    }

    /** @param array<string, mixed> $details */
    private static function region(array $details): string
    {
        $region = $details['region'] ?? null;

        return is_string($region) && isset(self::REGION_CURRENCY[$region]) ? $region : 'australia';
    }

    /**
     * The quote's currency: the header's, else the region's own (the source's
     * `REGION_PROFILE`).
     *
     * @param array<string, mixed> $details
     */
    private static function currency(array $details): string
    {
        $currency = $details['currency'] ?? null;

        return is_string($currency) && in_array($currency, ['AUD', 'NZD', 'USD', 'SGD'], true) ? $currency : self::REGION_CURRENCY[self::region($details)];
    }

    /**
     * The rows as readiness should see them: without the store's bookkeeping.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function clean(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset($row['_draft_row_id'], $row['_system_snapshot_valid']);

            return $row;
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $header
     */
    private function nextStatus(CorrelationId $id, array $rows, array $header): string
    {
        try {
            return $this->parser->readiness($id, self::clean($rows), $header)->ready ? 'ready_to_sync' : 'in_progress';
        } catch (RfqParserFailure $failure) {
            error_log(sprintf('RFQ readiness unavailable (%s, correlation %s); saved as in_progress', $failure->failureCode, $id->toString()));

            return 'in_progress';
        }
    }

    /**
     * The customer the header names, as the customer book has it.
     *
     * @throws InvalidRfqValue      when the book has no such customer
     * @throws RfqLookupUnavailable when it cannot be read
     */
    private function customer(QuoteHeaderEdit $header): ?RfqCustomerOption
    {
        if ($header->customerId === null) {
            return null;
        }

        return $this->customers->find($header->customerId)
            ?? throw new InvalidRfqValue(sprintf('Customer %d is not in the customer book. Search for the customer again.', $header->customerId));
    }

    private function draftActor(RfqActor $actor, string $email): DraftActor
    {
        return new DraftActor(
            $email,
            RfqAccessPolicy::isManager($actor) ? 'admin' : 'sales',
            $actor->branchCode,
            $this->operators->codeFor($email),
        );
    }
}
