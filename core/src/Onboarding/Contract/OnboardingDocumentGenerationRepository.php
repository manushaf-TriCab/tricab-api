<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\PolicyDocumentContext;
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

    /** A platform user's display name ('' when unset) — who received a WHSE sheet for HR (5.4). */
    public function userDisplayName(int $userId): string;

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

    /** Records an uploaded photo (kind `photo`, C2) against its form assignment and returns its public id. */
    public function recordPhoto(FormContributionContext $form, RenderedDocument $photo, string $container, string $blobPath): string;

    /**
     * Which of these ids are live photos uploaded to this assignment. A photo
     * field's answer may name only those.
     *
     * @param list<string> $documentPublicIds
     * @return list<string>
     */
    public function photosOf(int $assignmentId, array $documentPublicIds): array;

    /**
     * This person's signed acknowledgement of a policy assignment; null when it
     * is not theirs, not a policy, not acknowledged, or carries no declaration.
     */
    public function findPolicyDocumentContext(int $userId, string $assignmentPublicId): ?PolicyDocumentContext;

    /** Writes the reference row for a signed policy copy (kind `policy_declaration`) and returns its public id. */
    public function recordPolicyDocument(
        PolicyDocumentContext $context,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string;
}
