<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use TriCab\Core\Onboarding\Domain\Service\TrainingReviewPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * What a review decision needs to know about the item before it writes: whose
 * it is, where it is, and the token that says nobody has moved it since.
 *
 * The write-side twin of {@see ReviewItemDetail}, as
 * {@see OnboardingPersonRecord} is of {@see OnboardingPersonDetail}. It never
 * leaves the service.
 */
final readonly class ReviewItemRecord
{
    public function __construct(
        public int $assignmentId,
        public string $assignmentPublicId,
        public int $subjectUserId,
        public string $itemType,
        public string $itemPublicId,
        public string $title,
        public AssignmentStatus $status,
        public RowVersionToken $rowVersion,
        public ?int $latestRevisionNumber,
        /** 5.4: a training item's module slug, '' otherwise — whether HR signs it off. */
        public string $moduleSlug = '',
    ) {
    }

    /** Accepting it needs the supervisor's feedback, and stamps the sheet with it (5.4). */
    public function isSignOff(): bool
    {
        return $this->itemType === 'training' && TrainingReviewPolicy::requiresSignOff($this->moduleSlug);
    }
}
