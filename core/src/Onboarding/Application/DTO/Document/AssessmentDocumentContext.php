<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Document;

/** The assignment and person an assessment sheet is about, as stored. */
final readonly class AssessmentDocumentContext
{
    public function __construct(
        public int $assignmentId,
        public int $userId,
        public string $moduleSlug,
        public string $moduleTitle,
        public string $personName,
        public string $position,
        public string $department,
        public string $branch,
        public string $managerName,
    ) {
    }
}
