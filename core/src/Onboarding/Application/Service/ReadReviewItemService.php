<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemDetail;
use TriCab\Core\Onboarding\Contract\OnboardingReviewRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;

final readonly class ReadReviewItemService
{
    public function __construct(
        private OnboardingReviewRepository $reviews,
    ) {
    }

    public function read(string $assignmentPublicId): ReviewItemDetail
    {
        return $this->reviews->reviewDetail(strtolower(trim($assignmentPublicId)))
            ?? throw OnboardingAssignmentNotFound::forThisPerson();
    }
}
