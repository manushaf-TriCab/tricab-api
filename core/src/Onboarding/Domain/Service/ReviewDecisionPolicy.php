<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\ReviewDecisionNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * Which decisions the review queue offers for an item, given where it is.
 *
 * Narrower than {@see AssignmentStatusPolicy} on purpose, and the two are
 * applied together rather than one replacing the other. That policy answers
 * "may a reviewer ever set this status" and is the invariant; this one answers
 * "is this a sensible thing to decide about an item in the queue", which is a
 * question about the endpoint. A reviewer may in principle set `completed` from
 * anywhere; accepting a form nobody has handed in is not a review.
 *
 * | Item is      | Reviewer may decide                        |
 * |--------------|--------------------------------------------|
 * | `submitted`  | `completed` (accept), `fix_and_resubmit`   |
 * | `need_help`  | `in_progress` (reply), `fix_and_resubmit`  |
 * | anything else| nothing — it is not in the queue           |
 *
 * The source's form route allowed exactly accept and send back
 * (`INVALID_REVIEW_OUTCOME` otherwise); its module route let a reviewer set any
 * reviewer status from anything. Here both item types follow the form rule,
 * plus a reply to somebody who asked for help, which the source had no way to
 * give. Recorded in the plan's Phase 4 departures table.
 *
 * ## A note is required whenever the work goes back to the person
 *
 * Sending work back without saying why leaves them guessing — the source's
 * `NOTE_REQUIRED` — and a reply to a help request with no words in it is not a
 * reply. Accepting needs no note. The note is the one piece of free text in the
 * review, and it goes to `[audit].[OnboardingStatusEvents].[Note]`, where the
 * trainee's own notes already live; it is never copied into
 * `[audit].[OnboardingAdminEvents]`, whose details are an allow-list.
 *
 * Pure: no database, no request, no clock.
 */
final class ReviewDecisionPolicy
{
    public const NOTE_MAX_LENGTH = 2000;

    /** @var array<string, list<string>> */
    private const OUTCOMES = [
        AssignmentStatus::SUBMITTED => [AssignmentStatus::COMPLETED, AssignmentStatus::FIX_AND_RESUBMIT],
        AssignmentStatus::NEED_HELP => [AssignmentStatus::IN_PROGRESS, AssignmentStatus::FIX_AND_RESUBMIT],
    ];

    /** Outcomes that hand the work back to the person, and so owe them a reason. */
    private const NEEDS_NOTE = [AssignmentStatus::FIX_AND_RESUBMIT, AssignmentStatus::IN_PROGRESS];

    /**
     * The outcomes the console should offer, in the order it should offer them.
     * Empty when the item is not in the queue.
     *
     * @return list<AssignmentStatus>
     */
    public static function outcomesFrom(AssignmentStatus $from): array
    {
        return array_map(
            static fn (string $status): AssignmentStatus => AssignmentStatus::fromString($status),
            self::OUTCOMES[$from->toString()] ?? [],
        );
    }

    public static function requiresNote(AssignmentStatus $to): bool
    {
        return in_array($to->toString(), self::NEEDS_NOTE, true);
    }

    /**
     * Trims the note, and answers null for one that is only whitespace.
     *
     * @throws InvalidOnboardingValue when it is longer than {@see NOTE_MAX_LENGTH}
     */
    public static function normalizeNote(?string $note): ?string
    {
        $trimmed = $note === null ? '' : trim($note);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > self::NOTE_MAX_LENGTH) {
            throw InvalidOnboardingValue::for('note', sprintf('(longer than %d characters)', self::NOTE_MAX_LENGTH));
        }

        return $trimmed;
    }

    /**
     * Order matters, as it does in {@see AssignmentStatusPolicy::assertCanSet()}:
     * "this is not in the queue" is reported before "not that outcome", because
     * a reload is the remedy for the first and would not fix the second.
     *
     * @param ?string $note already normalized
     *
     * @throws ReviewDecisionNotPermitted
     * @throws InvalidOnboardingValue when the outcome needs a note and has none
     */
    public static function assertAllowed(AssignmentStatus $from, AssignmentStatus $to, ?string $note): void
    {
        if (!$from->needsReview()) {
            throw ReviewDecisionNotPermitted::notAwaitingReview($from);
        }

        if (!in_array($to->toString(), self::OUTCOMES[$from->toString()] ?? [], true)) {
            throw ReviewDecisionNotPermitted::outcomeNotOffered($from, $to);
        }

        if ($note === null && self::requiresNote($to)) {
            throw InvalidOnboardingValue::required('note', 'say what the person should do next.');
        }
    }
}
