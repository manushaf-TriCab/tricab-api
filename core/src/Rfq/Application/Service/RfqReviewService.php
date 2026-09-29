<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use TriCab\Core\Rfq\Application\DTO\ParsedRfq;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Application\DTO\RfqReadinessView;
use TriCab\Core\Rfq\Contract\RfqCustomerDirectory;
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
use TriCab\Core\Rfq\Storage\DraftLineEdits;
use TriCab\Core\Rfq\Storage\NewDraft;
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

    public function __construct(
        private readonly RfqParserClient $parser,
        private readonly RfqDraftStore $drafts,
        private readonly RfqRepository $rfqs,
        private readonly RfqOperatorDirectory $operators,
        private readonly RfqCustomerDirectory $customers,
        private readonly Clock $clock,
        private readonly ?ParseSeal $seal,
        private readonly bool $allowModel = false,
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
        $parsed = $this->parser->parse(ParseRequest::forText($id, $text, $this->allowModel));
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
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool}> $lines every line to keep, in order
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
        $sealed = $this->openSeal($parseToken, $email, $id);
        $rows = DraftLineEdits::apply($sealed['rows'], $lines, $header->region);
        if ($rows === []) {
            throw new InvalidRfqValue('No lines to save. Match an RFQ first.');
        }
        $customer = $this->customer($header);

        $worker = $this->draftActor($actor, $email);
        $rep = SalesRepAttribution::apply($header->applyTo([], $customer), null, $worker, $worker->operatorCode);
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
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool}> $lines every line to keep, in order
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

        $details = $header === null ? $stored->quoteDetails : $header->applyTo($stored->quoteDetails, $this->customer($header));
        $rows = DraftLineEdits::apply($stored->rows, $lines, self::region($details));
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
     * Readiness of an unsaved match as the editor holds it: the sealed rows
     * with the decisions applied, and the header as filled in. Nothing is
     * stored.
     *
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool}> $lines
     *
     * @throws InvalidRfqValue|RfqActionForbidden|RfqParserFailure
     */
    public function previewNew(RfqActor $actor, string $email, ?string $parseToken, QuoteHeaderEdit $header, array $lines, CorrelationId $id): RfqReadinessView
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_CREATE)) {
            throw new RfqActionForbidden('create RFQs');
        }
        $rows = DraftLineEdits::apply($this->openSeal($parseToken, $email, $id)['rows'], $lines, $header->region);
        $worker = $this->draftActor($actor, $email);
        $details = SalesRepAttribution::apply($header->applyTo([], self::namedOnly($header)), null, $worker, $worker->operatorCode)['quoteDetails'];

        return RfqReadinessView::from($this->parser->readiness($id, self::clean($rows), $details), $rows);
    }

    /**
     * Readiness of a draft as the editor holds it, saved or not. Anyone who
     * can read the RFQ can ask; nothing is stored.
     *
     * @param list<array{lineUid: string, approved: ?bool, comment: string, productCode?: ?string, cuts?: ?string, lengthEach?: ?string, isNew?: bool}>|null $lines null for the lines as saved
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

        return RfqReadinessView::from($this->parser->readiness($id, self::clean($rows), $details), $rows);
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
        return is_string($details['region'] ?? null) && $details['region'] !== '' ? $details['region'] : 'australia';
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
