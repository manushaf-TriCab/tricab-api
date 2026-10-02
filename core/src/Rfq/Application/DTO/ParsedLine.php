<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use TriCab\Core\Rfq\Domain\ValueObject\LineApproval;
use TriCab\Core\Rfq\Domain\ValueObject\LineDisposition;
use TriCab\Core\Rfq\Domain\ValueObject\LineInputType;
use TriCab\Core\Rfq\Domain\ValueObject\LineMatchStatus;

/**
 * One quote line, as saved in a revision. Contract `rfq.line.v1`.
 *
 * Three questions, three fields, never folded together:
 *
 *  - what the **parser** concluded — `matchStatus`;
 *  - what a **person** decided — `approval`;
 *  - whether the line is **in the quote** — `disposition` (+ `exclusionReason`).
 *
 * Quantities (invariant 4). `quantity` is what is quoted, in `unit` (`m` for
 * cable metres, `U` for units and accessory packs — the two 4js unit systems).
 * `cutLengthMetres` and `cuts` are kept when the request was expressed as cuts,
 * so "4 × 250 m" survives as that and not only as 1000 m. All are decimal
 * strings: a float cannot carry a quantity through save, price, export and
 * handoff unchanged.
 *
 * `inferredFields` names every field a model or a default supplied rather than
 * the customer (invariant 2: provenance and inference stay visible). `lineUid`
 * is stable across revisions and is what correction memory and edit history
 * key on.
 *
 * `warnings` is the parser's own prose for the rep, as the source shows it.
 * `warningCodes` is its structured form and is filled when readiness moves
 * to the server (plan 5.3); until then it is empty rather than guessed from
 * the prose.
 *
 * `entry` is what the sales grid edits and shows ({@see LineEntry}). `pricing` is
 * its price ({@see LinePricing}), sent only to those who may see cost and margin.
 * `confidence` is the parser's own, 0 to 1, as the detailed view's bar shows it.
 *
 * The row detail (parity plan E5.4): `status` is the line's status select as
 * stored (`final_status`, else the parser's `status`; `approved` included),
 * with the `correctionReason` and `reviewerNote` a reviewer gave, and
 * `normalizedText`, the text the parser matched on after normalising it.
 */
final class ParsedLine
{
    /**
     * @param list<string> $warningCodes codes from `quote_readiness.WARNING_CODES` / `STATUS_CODES`
     * @param list<string> $inferredFields
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $lineUid,
        public readonly ?SourceRowRef $source,
        public readonly LineInputType $inputType,
        public readonly string $requestedText,
        public readonly LineMatchStatus $matchStatus,
        public readonly ?string $productCode,
        public readonly ?string $description,
        public readonly ?string $quantity,
        public readonly string $unit,
        public readonly ?string $cutLengthMetres,
        public readonly ?int $cuts,
        public readonly ?string $comment,
        public readonly LineApproval $approval,
        public readonly LineDisposition $disposition,
        public readonly ?string $exclusionReason,
        public readonly array $warningCodes,
        public readonly array $inferredFields,
        public readonly array $warnings = [],
        public readonly ?LineEntry $entry = null,
        public readonly ?LinePricing $pricing = null,
        public readonly ?float $confidence = null,
        public readonly ?string $status = null,
        public readonly string $correctionReason = '',
        public readonly string $reviewerNote = '',
        public readonly ?string $normalizedText = null,
    ) {
    }

    /**
     * @param bool $withPricing whether the caller may see cost and margin (`RFQ.PRICING.VIEW`);
     *                          when not, `pricing` is null: withheld, not merely hidden
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withPricing = false): array
    {
        return [
            'lineUid' => $this->lineUid,
            'source' => $this->source?->toArray(),
            'inputType' => $this->inputType->toString(),
            'requestedText' => $this->requestedText,
            'matchStatus' => $this->matchStatus->toString(),
            'productCode' => $this->productCode,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'cutLengthMetres' => $this->cutLengthMetres,
            'cuts' => $this->cuts,
            'comment' => $this->comment,
            'approval' => $this->approval->toString(),
            'disposition' => $this->disposition->toString(),
            'exclusionReason' => $this->exclusionReason,
            'warningCodes' => $this->warningCodes,
            'inferredFields' => $this->inferredFields,
            'warnings' => $this->warnings,
            'entry' => $this->entry?->toArray(),
            'pricing' => $withPricing ? $this->pricing?->toArray() : null,
            'confidence' => $this->confidence,
            'status' => $this->status,
            'correctionReason' => $this->correctionReason,
            'reviewerNote' => $this->reviewerNote,
            'normalizedText' => $this->normalizedText,
        ];
    }
}
