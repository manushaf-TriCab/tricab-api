<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use TriCab\Core\Rfq\Application\DTO\AliflexResult;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqAliflexNotEligible;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\Service\AliflexEligibility;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\PythonText;
use TriCab\Core\Rfq\Storage\StoreProjection;

/**
 * The ALIFLEX button (parity plan E7.4): a new draft holding the agreed
 * copper-to-aluminium alternative of a finished quote. The source's
 * `create_alternative_draft` (`release/aliflex-prod-20260927`, `ca2f924`).
 *
 * **It converts the quote as saved**, never rows a browser sends: the request names
 * the draft and the revision the person holds, and the conversion is the parser's
 * (`aliflex`, the source's mapping, policy `aliflex-map/2`). The source draft is read,
 * never written. A quote that is not finished (not synced to 4js with no change
 * since, and not wholly imported) or is already an alternative is refused, so a
 * hidden button is not the only guard.
 *
 * The changed lines have their source prices cleared and are priced afresh, in the
 * quote's region and currency, with the customer's default margins, as every save
 * prices them ({@see RfqReviewService::createAlternative()}). The new draft is an
 * ordinary editable draft owned by, assigned to and authored by the person who asked,
 * with `source` `alternative`, and is never synced by this call. With no line to
 * change, no draft is made.
 */
final class RfqAliflexService
{
    public function __construct(
        private readonly RfqParserClient $parser,
        private readonly RfqRepository $rfqs,
        private readonly RfqDraftStore $drafts,
        private readonly RfqReviewService $review,
    ) {
    }

    /**
     * @return AliflexResult|null null when there is no such RFQ for this actor
     *
     * @throws RfqActionForbidden       without `RFQ.DRAFT.CREATE`
     * @throws RfqRevisionConflict      when the revision held is not the saved one
     * @throws RfqAliflexNotEligible    when the quote is not finished, or is already an alternative
     * @throws RfqParserFailure         when the parser cannot convert or price
     * @throws RfqStoreUnavailable      where there is no RFQ store
     * @throws InvalidRfqValue|RfqLookupUnavailable
     */
    public function create(RfqActor $actor, string $email, string $publicId, int $expectedRevision, CorrelationId $id): ?AliflexResult
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_CREATE)) {
            throw new RfqActionForbidden('create RFQs');
        }
        $record = $this->rfqs->findRecord(strtolower(trim($publicId)));
        if ($record === null || !RfqAccessPolicy::canSee($actor, $record->ownership)) {
            return null;
        }
        $draftId = StoreProjection::draftId($publicId);
        $stored = $draftId === null ? null : $this->drafts->load($draftId);
        if ($draftId === null || $stored === null) {
            return null;
        }
        if ($stored->revision !== $expectedRevision) {
            throw new RfqRevisionConflict($stored->revision, $expectedRevision);
        }
        $check = AliflexEligibility::check($stored->status, array_values($stored->rows), $stored->emailMeta);
        if (!$check['eligible']) {
            throw new RfqAliflexNotEligible($check['reason'] ?? AliflexEligibility::NOT_FINAL);
        }

        $rows = array_map(static function (array $row): array {
            unset($row['_draft_row_id'], $row['_system_snapshot_valid']);

            return $row;
        }, array_values($stored->rows));
        $conversion = $this->parser->aliflex($id, $rows, $stored->quoteDetails, PythonText::str($stored->columns['label'] ?? ''));
        if ($conversion->counts['changed'] === 0) {
            return new AliflexResult(null, null, $conversion->counts, $conversion->message, null);
        }

        $meta = ['aliflex' => [
            'policy_version' => $conversion->policyVersion,
            'source_draft_id' => $draftId,
            'source_status' => $stored->status,
            // The 4js quote it was converted from, when the source was synced.
            'source_refno' => PythonText::str($stored->columns['refno'] ?? ''),
            'source_refver' => PythonText::str($stored->columns['refver'] ?? ''),
            'counts' => $conversion->counts,
        ]];
        $created = $this->review->createAlternative($actor, $email, $conversion->quoteDetails, $conversion->rows, $conversion->label, $meta, $id);

        return new AliflexResult($created['publicId'], $conversion->label, $conversion->counts, $conversion->message, $created['pricingError']);
    }
}
