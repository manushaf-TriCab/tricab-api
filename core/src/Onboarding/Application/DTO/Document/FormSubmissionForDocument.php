<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Document;

/**
 * The latest submission of one allocated form, located for document
 * generation (5.4). Identifiers and metadata only; the answers are passed in by
 * the caller that already holds them.
 */
final readonly class FormSubmissionForDocument
{
    /**
     * `$accessGroup` is as stored — normally hr, payroll or restricted_hr. It is
     * not narrowed here: an unrecognised group must reach
     * {@see \TriCab\Core\Onboarding\Application\Service\GenerateFormSubmissionDocumentService::classFor()},
     * which treats it as the most restricted class rather than refusing.
     */
    public function __construct(
        public int $formSubmissionId,
        public int $assignmentId,
        public int $userId,
        public string $formSlug,
        public string $accessGroup,
        public int $revisionNumber,
        public string $submittedAtUtc,
        public string $personDisplayName,
    ) {
    }
}
