<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\ValueObject;

/**
 * What the matcher concluded about one line. Machine output only — a person's
 * decision is {@see LineApproval}, and the two are kept apart because the
 * source overwrote the first with the second (`final_status = 'approved'`),
 * which lost the record of what the parser had said.
 *
 * Vocabulary and meaning from `quote_readiness.STATUS_CODES` in the pinned
 * source.
 *
 * ## `needs_clarification` — owner question Q1
 *
 * Production has run it as **advisory** since 25 Sept 2026: the row shows an
 * amber check and can be synced. That change is uncommitted in the source
 * (`rfq-phase0/0.2-source-selection.md` §5). {@see blocksSync()} encodes what
 * production does today. If the owner reverts production, this is the one line
 * to change, and `LineMatchStatusTest` says which assertion moves with it.
 */
final class LineMatchStatus extends Vocabulary
{
    public const MATCHED = 'matched';
    public const NEEDS_CLARIFICATION = 'needs_clarification';
    public const NEEDS_REVIEW = 'needs_review';
    public const AMBIGUOUS = 'ambiguous';
    public const UNRESOLVED = 'unresolved';
    public const NO_MATCH = 'no_match';
    public const ACCESSORY = 'accessory';
    public const NOT_CABLE = 'not_cable';
    public const ADMIN = 'admin';

    /** Heading or note text: excluded from the quote when it carries no product code. */
    public const NON_COMMERCIAL = [self::NOT_CABLE, self::ADMIN];

    /**
     * A person must choose or confirm the product before this line may sync.
     * `accessory` is here: accessories are quoted for review, not matched.
     */
    private const BLOCKING = [
        self::NEEDS_REVIEW,
        self::AMBIGUOUS,
        self::UNRESOLVED,
        self::NO_MATCH,
        self::ACCESSORY,
    ];

    public static function values(): array
    {
        return [
            self::MATCHED,
            self::NEEDS_CLARIFICATION,
            self::NEEDS_REVIEW,
            self::AMBIGUOUS,
            self::UNRESOLVED,
            self::NO_MATCH,
            self::ACCESSORY,
            self::NOT_CABLE,
            self::ADMIN,
        ];
    }

    /**
     * `unresolved`: the source treats any status it does not recognise as a
     * contradiction to resolve, never as a match.
     */
    protected static function fallback(): string
    {
        return self::UNRESOLVED;
    }

    protected static function label(): string
    {
        return 'line match status';
    }

    /**
     * Whether this status alone stops the line syncing before a person
     * approves it. Approval clears it; see `LineApprovalPolicy`.
     */
    public function blocksSync(): bool
    {
        return $this->isOneOf(self::BLOCKING);
    }

    public function isNonCommercial(): bool
    {
        return $this->isOneOf(self::NON_COMMERCIAL);
    }
}
