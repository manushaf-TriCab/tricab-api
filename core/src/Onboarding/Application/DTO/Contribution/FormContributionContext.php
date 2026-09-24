<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Contribution;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/** One allocated form, as somebody other than its starter sees it to contribute (5.4). */
final readonly class FormContributionContext
{
    /**
     * @param array<string, mixed> $schema the allocated version's schema
     */
    public function __construct(
        public int $assignmentId,
        public string $assignmentPublicId,
        public int $subjectUserId,
        public ?int $managerUserId,
        public string $title,
        public string $accessGroup,
        public AssignmentStatus $status,
        public array $schema,
    ) {
    }
}
