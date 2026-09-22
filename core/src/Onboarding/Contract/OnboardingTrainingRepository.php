<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptRecord;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTrainingDetail;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentGrade;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * One person's training: the presentation they are working through and the
 * attempts they have made at its assessment.
 *
 * Every method takes `$userId` first and every implementation must filter on it
 * **in the query**, for the reason {@see OnboardingSelfServiceRepository} gives
 * at length: a check after the fetch is one a later refactor can skip.
 *
 * On the write side there is a second rule that the read side did not need.
 * **A write also carries `$expectedVersionPublicId`, and the implementation puts
 * it in the `WHERE` clause.** Not as a validation before the write — as part of
 * the statement, so that a version superseded between the read and the write
 * matches nothing and the caller is told rather than obeyed. An empty result
 * means {@see \TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded},
 * which is the same shape Phase 2.5 established for optimistic concurrency.
 *
 * ## Status changes belong to the write that caused them
 *
 * Where a method takes a {@see StatusTransition}, the status update and its
 * `audit.OnboardingStatusEvents` row are part of the same unit of work as the
 * write itself. Three things commit together or none of them do. Work order
 * invariant 13 is not satisfied by an audit call that follows a successful
 * commit, because the interesting failures are the ones between the two.
 */
interface OnboardingTrainingRepository
{
    /**
     * Null when this assignment is not this person's, is not training, or has
     * been withdrawn — all indistinguishable on purpose (invariant 4).
     */
    public function findTraining(int $userId, string $assignmentPublicId): ?OnboardingTrainingDetail;

    /**
     * Records where somebody got to in a presentation.
     *
     * `$mergedSlideIds` is the union of stored and newly reported progress,
     * already validated against the manifest and already in manifest order —
     * {@see \TriCab\Core\Onboarding\Domain\Service\PresentationProgressPolicy}
     * does that, and doing it here would put the rule in every adapter.
     *
     * **The write is monotonic and conflicts are not surfaced.** An
     * implementation must not overwrite a stored set that is already larger than
     * `$mergedSlideIds`; that write is a no-op, not an error. Progress only ever
     * grows, so two tabs racing cannot produce a wrong answer — only a stale
     * one — and asking somebody to reload because their phone and their laptop
     * both auto-saved would be a conflict dialogue with nothing behind it. This
     * is exactly why there is no `RowVersionToken` on this method and there is
     * one on {@see OnboardingFormRepository::saveDraft()}: a draft CAN be
     * silently clobbered, and a slide list cannot.
     *
     * @param list<string> $mergedSlideIds
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded
     */
    public function savePresentationProgress(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        array $mergedSlideIds,
        bool $reachedFinalSlide,
        ?StatusTransition $transition,
    ): OnboardingTrainingDetail;

    /**
     * Opens an attempt and snapshots the questions into it.
     *
     * The snapshot is taken **here**, from `onboarding.Questions`, and not passed
     * in. A caller that supplied the questions would be a caller that could
     * supply the answer key, and grading reads the snapshot.
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded
     */
    public function startAttempt(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        ?StatusTransition $transition,
    ): OnboardingAttemptRecord;

    /**
     * The attempt named, if it belongs to this person and this assignment.
     */
    public function findAttempt(
        int $userId,
        string $assignmentPublicId,
        string $attemptPublicId,
    ): ?OnboardingAttemptRecord;

    /**
     * Writes the answers and the grade, once.
     *
     * `$grade` is computed by {@see \TriCab\Core\Onboarding\Domain\Service\AssessmentGrader}
     * from the attempt's own snapshot. An implementation stores it and does not
     * recompute or adjust it.
     *
     * `TR_AttemptAnswers_Immutable` refuses a second write at the database, so
     * an implementation does not need to check first — but it must translate the
     * refusal into {@see \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict},
     * because a trigger's message is not something a browser should ever see.
     *
     * @param array<string, string> $answers question public id to chosen option key
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict
     */
    public function submitAttempt(
        int $userId,
        string $assignmentPublicId,
        string $attemptPublicId,
        array $answers,
        AssessmentGrade $grade,
        ?StatusTransition $transition,
    ): OnboardingAttemptRecord;
}
