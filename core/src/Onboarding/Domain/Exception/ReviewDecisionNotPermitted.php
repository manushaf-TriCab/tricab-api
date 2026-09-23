<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * A reviewer decision the item's current state does not admit.
 *
 * Maps to 409, as {@see AllocationNotPermitted} does and for the same reason:
 * the reviewer holds the permission, and the answer is about where the work is
 * rather than about who is asking. The reason is carried separately so the
 * console can say which remedy applies — reload, or pick another outcome.
 */
final class ReviewDecisionNotPermitted extends DomainException
{
    public const REASON_NOT_AWAITING_REVIEW = 'not_awaiting_review';
    public const REASON_OUTCOME_NOT_OFFERED = 'outcome_not_offered';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * The item has left the queue — the trainee withdrew it into another state,
     * another reviewer decided it, or it was never there. A reload shows which.
     */
    public static function notAwaitingReview(AssignmentStatus $status): self
    {
        return new self(
            sprintf('This item is not waiting on a review — it is "%s". Reload to see where it is now.', $status->label()),
            self::REASON_NOT_AWAITING_REVIEW,
        );
    }

    public static function outcomeNotOffered(AssignmentStatus $from, AssignmentStatus $to): self
    {
        return new self(
            sprintf('An item that is "%s" cannot be reviewed to "%s".', $from->label(), $to->label()),
            self::REASON_OUTCOME_NOT_OFFERED,
        );
    }
}
