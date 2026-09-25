<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\ValueObject;

use TriCab\Core\Onboarding\Domain\Service\AssignmentStatusPolicy;

/**
 * A move from one status to another, by somebody, with the audit row it owes.
 *
 * Work order invariant 13 says every state-changing action is audited. That is
 * only true if the audit cannot be skipped, so a transition is not a pair of
 * status strings a repository may act on and then optionally record — it is one
 * object, and the contracts that accept it require the row and the audit event
 * to be written in the same unit of work. A repository that commits the status
 * and then fails to log leaves a change nobody can attribute, and an hour later
 * nobody can tell whether the log or the row is the wrong one.
 *
 * {@see $actorUserId} is null only for {@see StatusActor::SYSTEM}, which is what
 * `CK_audit_OnboardingStatusEvents_Attribution` enforces: a human action with no
 * name attached is refused by the database, not merely discouraged here.
 *
 * Construction goes through {@see attempt()}, which applies
 * {@see AssignmentStatusPolicy} — so a transition object cannot exist for a move
 * its actor is not allowed to make.
 */
final readonly class StatusTransition
{
    private function __construct(
        public AssignmentStatus $from,
        public AssignmentStatus $to,
        public StatusActor $actor,
        public ?int $actorUserId,
        public ?string $note,
    ) {
    }

    /**
     * @throws \TriCab\Core\Onboarding\Domain\Exception\StatusChangeNotPermitted when the actor may never set `$to`
     * @throws \TriCab\Core\Onboarding\Domain\Exception\AssignmentStatusIsFinal  when `$from` is locked to this actor
     */
    public static function attempt(
        AssignmentStatus $from,
        AssignmentStatus $to,
        StatusActor $actor,
        ?int $actorUserId,
        ?string $note = null,
    ): self {
        AssignmentStatusPolicy::assertCanSet($actor, $from, $to);

        return new self($from, $to, $actor, $actor->isSystem() ? null : $actorUserId, $note);
    }

    /**
     * The transition a trainee makes by doing something, or null when the work
     * is already where that would put it.
     *
     * Returning null rather than a no-op transition is what keeps the audit
     * trail readable: opening a presentation you have already opened should not
     * append a row saying you moved from in_progress to in_progress, and fifty
     * such rows is how a real transition gets missed.
     */
    public static function traineeMovesTo(
        AssignmentStatus $from,
        AssignmentStatus $to,
        int $actorUserId,
        ?string $note = null,
    ): ?self {
        if ($from->equals($to)) {
            return null;
        }

        return self::attempt($from, $to, StatusActor::trainee(), $actorUserId, $note);
    }

    /**
     * The system completing an item on passing evidence — the only completion
     * path a trainee's own action can reach, and the reason it is attributed to
     * `system` rather than to them: they did the work, they did not decide it
     * was acceptable. The pass mark did, and the pass mark is the author's.
     */
    public static function systemMovesTo(
        AssignmentStatus $from,
        AssignmentStatus $to,
        ?string $note = null,
    ): ?self {
        if ($from->equals($to)) {
            return null;
        }

        return self::attempt($from, $to, StatusActor::system(), null, $note);
    }
}
