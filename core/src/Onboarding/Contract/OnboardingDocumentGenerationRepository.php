<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * The write side of `onboarding.DocumentReferences` (5.4), kept apart from
 * {@see OnboardingDocumentRepository}, which is the person's own read path.
 */
interface OnboardingDocumentGenerationRepository
{
    /** The latest submitted revision of this person's form assignment, or null. */
    public function findLatestFormSubmission(int $userId, string $assignmentPublicId): ?FormSubmissionForDocument;

    public function hasDocumentForFormSubmission(int $formSubmissionId): bool;

    /**
     * Writes the reference row and returns its public id.
     */
    public function recordFormDocument(
        FormSubmissionForDocument $submission,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string;
}
