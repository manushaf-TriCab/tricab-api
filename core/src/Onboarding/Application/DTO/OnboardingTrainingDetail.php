<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\Service\PresentationProgressPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;

/**
 * One training module as the person allocated it sees it.
 *
 * Carries the slide manifest, where they got to, and the shape of the
 * assessment — never the assessment itself. {@see $questionCount} says how many
 * questions there are; the questions arrive only when an attempt is started, and
 * arrive redacted. A detail object that held them would be a projection with
 * answer keys in it, reachable from a GET.
 *
 * {@see $versionPublicId} is not decoration. A client echoes it back on every
 * write, and a write naming a version that is no longer the allocated one is
 * refused rather than applied — see
 * {@see \TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded}.
 */
final readonly class OnboardingTrainingDetail
{
    /**
     * @param list<string> $reachedSlideIds in manifest order
     */
    public function __construct(
        public string $assignmentPublicId,
        public string $title,
        public ?string $summary,
        public string $versionPublicId,
        public int $versionNumber,
        public AssignmentStatus $status,
        public PresentationManifest $manifest,
        public int $requiredSlideCount,
        public ?int $passMarkPercent,
        public int $questionCount,
        public array $reachedSlideIds,
        public ?OnboardingAttemptDetail $latestAttempt,
    ) {
    }

    public function hasAssessment(): bool
    {
        return $this->questionCount > 0;
    }

    public function isPresentationComplete(): bool
    {
        return PresentationProgressPolicy::isPresentationComplete(
            $this->manifest,
            $this->reachedSlideIds,
            $this->requiredSlideCount,
        );
    }

    /**
     * Whether an attempt may be started.
     *
     * The presentation gates the assessment: somebody who has not reached the
     * end of the deck has not been shown the material the questions are about,
     * and letting them sit the quiz turns the module into a guessing game whose
     * evidence says they were trained.
     *
     * Re-attempts are allowed after a failure, which is the source's behaviour
     * and is kept: an induction assessment exists to get people to the right
     * answer, not to rank them. A passed attempt closes the module, so there is
     * nothing to re-sit.
     */
    public function mayStartAttempt(): bool
    {
        if (!$this->hasAssessment() || !$this->isPresentationComplete()) {
            return false;
        }

        return !$this->status->isFinal();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assignmentPublicId' => $this->assignmentPublicId,
            'itemType' => 'training',
            'title' => $this->title,
            'summary' => $this->summary,
            'versionPublicId' => $this->versionPublicId,
            'versionNumber' => $this->versionNumber,
            'status' => $this->status->toString(),
            'statusLabel' => $this->status->label(),
            'slides' => $this->manifest->toArray(),
            'reachedSlideIds' => $this->reachedSlideIds,
            'requiredSlideCount' => $this->requiredSlideCount,
            'presentationPercent' => PresentationProgressPolicy::percent(
                $this->manifest,
                $this->reachedSlideIds,
            ),
            'presentationComplete' => $this->isPresentationComplete(),
            'questionCount' => $this->questionCount,
            'passMarkPercent' => $this->passMarkPercent,
            'mayStartAttempt' => $this->mayStartAttempt(),
            'latestAttempt' => $this->latestAttempt?->toArray(),
        ];
    }
}
