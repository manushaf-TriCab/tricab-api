<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\ValueObject\LineApproval;
use TriCab\Core\Rfq\Domain\ValueObject\LineDisposition;
use TriCab\Core\Rfq\Domain\ValueObject\LineInputType;
use TriCab\Core\Rfq\Domain\ValueObject\LineMatchStatus;

/**
 * One line's approval, and the line-level half of the sync gate.
 *
 * **This is not readiness.** Readiness (plan 4, 5.3) also covers the quote
 * header, prices, quantities and the reader state, and it is recomputed on
 * the server from the saved revision. This class answers only "does this line,
 * on its own, stop the sync?", ported from `row_push_block_reason` and
 * `exclusion_reason` in `quote_readiness.py`.
 */
final class LineApprovalPolicy
{
    private const MACHINE = 'line approval';

    /**
     * Only a person approves. The parser, a reader and the agent may reset a
     * line to `unreviewed` — when they replace its content — and never set
     * `approved` (invariant 2).
     *
     * @throws RfqTransitionNotPermitted
     */
    public static function assertMaySet(LineApproval $to, bool $byPerson): void
    {
        if ($to->isApproved() && !$byPerson) {
            throw RfqTransitionNotPermitted::forActor(self::MACHINE, 'the system', $to->toString());
        }
    }

    /**
     * Why the line is left out of the quote, or null if it is in.
     *
     * A heading or note with no product code is non-commercial and excluded,
     * not blocking. A coded heading is not excluded: it is a contradiction a
     * person has to look at.
     */
    public static function exclusionReason(
        LineDisposition $disposition,
        LineMatchStatus $matchStatus,
        bool $hasProductCode,
        string $statedReason = '',
    ): ?string {
        if ($disposition->is(LineDisposition::EXCLUDED)) {
            return trim($statedReason) !== '' ? trim($statedReason) : 'Intentionally excluded from this quote';
        }

        if ($matchStatus->isNonCommercial() && !$hasProductCode) {
            return 'Non-commercial source content';
        }

        return null;
    }

    /**
     * Why this line stops the sync, or null.
     *
     * `$reviewedInEditor` is the source's `reviewed=True`: a draft saved
     * through the review editor counts as reviewed for ungrounded lines, and
     * the source's draft sync always passes it. The mail path does not. This
     * is preserved as found and is worth an owner look: it means an image- or
     * scan-read line syncs from the editor without anybody approving it.
     */
    public static function syncBlockReason(
        LineMatchStatus $matchStatus,
        LineApproval $approval,
        LineDisposition $disposition,
        LineInputType $inputType,
        bool $hasProductCode,
        bool $reviewedInEditor,
    ): ?string {
        $excluded = self::exclusionReason($disposition, $matchStatus, $hasProductCode);
        if ($excluded !== null) {
            return null;
        }

        if (!$reviewedInEditor && $inputType->isUngrounded() && !$approval->isApproved()) {
            return $inputType->is(LineInputType::VISION_OCR)
                ? 'vision OCR row needs approval before syncing'
                : 'read from a picture or scan; needs approval before syncing';
        }

        if ($matchStatus->blocksSync() && !$approval->isApproved()) {
            return sprintf('status is %s; resolve the line before syncing', $matchStatus->toString());
        }

        return null;
    }
}
