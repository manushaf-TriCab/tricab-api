<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingFormDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingPolicyDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTrainingDetail;
use TriCab\Core\Onboarding\Contract\OnboardingFormRepository;
use TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;

/**
 * Opening one allocated item.
 *
 * The three read methods are here rather than on the three write services
 * because they share the only thing that makes them more than a repository call:
 * the leaver **read** window, which is fourteen days wider than the submission
 * window. A departed person can still open the policy they acknowledged; they
 * cannot acknowledge another one.
 *
 * Each method takes a user id and an assignment public id, and returns null for
 * nothing — it throws {@see OnboardingAssignmentNotFound}, the same exception a
 * write throws, so that "not mine", "not there" and "wrong kind of item" are one
 * answer on the read path too. A reader that could tell a policy id from a
 * training id by which route 404s has learned something about somebody else's
 * induction.
 */
final readonly class ReadOnboardingItemService
{
    public function __construct(
        private OnboardingTrainingRepository $training,
        private OnboardingPolicyRepository $policies,
        private OnboardingFormRepository $forms,
        private OnboardingSubmissionGate $gate,
    ) {
    }

    /**
     * @throws OnboardingAssignmentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed
     */
    public function training(int $userId, string $assignmentPublicId): OnboardingTrainingDetail
    {
        $this->gate->assertMayRead($userId);

        return $this->training->findTraining($userId, $assignmentPublicId)
            ?? throw OnboardingAssignmentNotFound::forThisPerson();
    }

    /**
     * @throws OnboardingAssignmentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed
     */
    public function policy(int $userId, string $assignmentPublicId): OnboardingPolicyDetail
    {
        $this->gate->assertMayRead($userId);

        return $this->policies->findPolicy($userId, $assignmentPublicId)
            ?? throw OnboardingAssignmentNotFound::forThisPerson();
    }

    /**
     * @throws OnboardingAssignmentNotFound
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed
     */
    public function form(int $userId, string $assignmentPublicId): OnboardingFormDetail
    {
        $this->gate->assertMayRead($userId);

        return $this->forms->findForm($userId, $assignmentPublicId)
            ?? throw OnboardingAssignmentNotFound::forThisPerson();
    }
}
