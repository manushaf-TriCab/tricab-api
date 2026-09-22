<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingTrainingDetail;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Service\PresentationProgressPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * "I have seen these slides."
 *
 * The smallest write in the module and the most frequently called: a client
 * posts as somebody moves through a deck. Three properties follow from that and
 * shape everything below.
 *
 * **It must be idempotent without ceremony.** No idempotency key, no row
 * version. Progress is a union, so posting the same slides twice produces the
 * same row, and a client that retries after a timeout is simply correct. See
 * {@see OnboardingTrainingRepository::savePresentationProgress()} for why a
 * conflict here would be a dialogue with nothing behind it.
 *
 * **It must not be a way to finish a module.** The client says which slides it
 * showed. Completion of the presentation is derived from the manifest, and
 * completion of the *assignment* is not decided here at all — a module with an
 * assessment is finished by passing it, and a module without one is finished by
 * a reviewer. Opening a deck moves the item to `in_progress` and no further.
 *
 * **It must refuse work aimed at replaced wording.** The client echoes the
 * version it is reading; a version superseded since then matches nothing in the
 * `WHERE` clause and the caller is told to reload.
 */
final readonly class RecordPresentationProgressService
{
    public function __construct(
        private OnboardingTrainingRepository $training,
        private OnboardingSubmissionGate $gate,
    ) {
    }

    /**
     * @param list<string> $newlyReachedSlideIds
     *
     * @throws OnboardingAssignmentNotFound
     * @throws ContentVersionSuperseded
     * @throws \TriCab\Core\Onboarding\Domain\Exception\UnknownPresentationSlide
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingSubmissionWindowClosed
     */
    public function record(
        int $userId,
        string $assignmentPublicId,
        string $versionPublicId,
        array $newlyReachedSlideIds,
    ): OnboardingTrainingDetail {
        $this->gate->assertMaySubmit($userId);

        $detail = $this->training->findTraining($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $versionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        $merged = PresentationProgressPolicy::merge(
            $detail->manifest,
            $detail->reachedSlideIds,
            $newlyReachedSlideIds,
        );

        return $this->training->savePresentationProgress(
            $userId,
            $assignmentPublicId,
            $versionPublicId,
            $merged,
            PresentationProgressPolicy::hasReachedFinalSlide($detail->manifest, $merged),
            self::openingTransition($detail->status, $userId),
        );
    }

    /**
     * Moves an untouched item to `in_progress`, and otherwise does nothing.
     *
     * Only from `not_started`. Everything else is left where it is: a
     * `fix_and_resubmit` item whose deck is being reread must not lose the
     * reviewer's request by reverting to a generic "in progress", and a
     * `completed` one must not be reopened by somebody scrolling back through
     * it — which {@see \TriCab\Core\Onboarding\Domain\Service\AssignmentStatusPolicy}
     * would refuse anyway, with a 409 that the person has done nothing to
     * deserve.
     */
    private static function openingTransition(AssignmentStatus $from, int $userId): ?StatusTransition
    {
        if ($from->toString() !== AssignmentStatus::NOT_STARTED) {
            return null;
        }

        return StatusTransition::traineeMovesTo(
            $from,
            AssignmentStatus::inProgress(),
            $userId,
            'Opened the presentation.',
        );
    }
}
