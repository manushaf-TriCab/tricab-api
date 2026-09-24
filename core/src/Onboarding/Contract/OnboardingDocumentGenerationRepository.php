<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentContext;
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

    /** The training assignment and its person, for this person only; null when it is not theirs or not training. */
    public function findAssessmentContext(int $userId, string $assignmentPublicId): ?AssessmentDocumentContext;

    /** Whether a live document already sits at this blob path — how an attempt's sheet stays once-only. */
    public function hasDocumentAtPath(string $blobPath): bool;

    /**
     * Writes the reference row for an assessment sheet and returns its public id.
     */
    public function recordAssessmentDocument(
        AssessmentDocumentContext $context,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string;

    /** The latest submitted revision of a form assignment, whoever it belongs to — for a reviewer's completion (5.4). */
    public function findLatestFormSubmissionByAssignment(string $assignmentPublicId): ?FormSubmissionForDocument;

    /**
     * The ordinary answers of a submission, flat by path. Only an `hr` form is
     * completed this way, so there is no restricted half to fetch.
     *
     * @return array<string, mixed>
     */
    public function ordinaryAnswers(int $formSubmissionId): array;
}
