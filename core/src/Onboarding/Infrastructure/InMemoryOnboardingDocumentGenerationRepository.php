<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\FormSubmissionForDocument;
use TriCab\Core\Onboarding\Application\DTO\Document\PolicyDocumentContext;
use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Application\DTO\OnboardingDocumentRef;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Submissions are seeded by a test; a recorded document is added to the
 * in-memory read repository, so it shows on the person's own list as it would.
 */
final class InMemoryOnboardingDocumentGenerationRepository implements OnboardingDocumentGenerationRepository
{
    /** @var array<string, FormSubmissionForDocument> keyed "userId|assignmentPublicId" */
    public array $submissions = [];

    /** @var array<int, string> formSubmissionId => document public id */
    public array $recorded = [];

    /** @var array<string, AssessmentDocumentContext> keyed "userId|assignmentPublicId" */
    public array $assessments = [];

    /** @var array<string, string> blob path => document public id */
    public array $paths = [];

    /** @var array<string, PolicyDocumentContext> keyed by `userId:assignment` */
    public array $policies = [];

    /** @var array<string, int> photo public id => assignment id */
    public array $photos = [];

    /** @var array<int, array<string, mixed>> formSubmissionId => ordinary answers, flat */
    public array $ordinary = [];

    private int $next = 1;

    public function __construct(private readonly ?InMemoryOnboardingDocumentRepository $documents = null)
    {
    }

    public function withSubmission(string $assignmentPublicId, FormSubmissionForDocument $submission): void
    {
        $this->submissions[$submission->userId . '|' . strtolower($assignmentPublicId)] = $submission;
    }

    public function findLatestFormSubmission(int $userId, string $assignmentPublicId): ?FormSubmissionForDocument
    {
        return $this->submissions[$userId . '|' . strtolower($assignmentPublicId)] ?? null;
    }

    public function hasDocumentForFormSubmission(int $formSubmissionId): bool
    {
        return isset($this->recorded[$formSubmissionId]);
    }

    public function recordFormDocument(
        FormSubmissionForDocument $submission,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string {
        $publicId = sprintf('00000000-0000-4000-8000-%012d', $this->next++);
        $this->recorded[$submission->formSubmissionId] = $publicId;
        $this->paths[$blobPath] = $publicId;
        $this->documents?->withDocument($submission->userId, new OnboardingDocumentRef(
            $publicId,
            $submission->userId,
            $document->documentKind,
            $dataClass,
            $container,
            $blobPath,
            $document->contentType,
            $document->byteSize(),
            $document->sha256,
            gmdate('Y-m-d\TH:i:s\Z'),
        ));

        return $publicId;
    }

    public function withAssessment(string $assignmentPublicId, AssessmentDocumentContext $context): void
    {
        $this->assessments[$context->userId . '|' . strtolower($assignmentPublicId)] = $context;
    }

    public function findAssessmentContext(int $userId, string $assignmentPublicId): ?AssessmentDocumentContext
    {
        return $this->assessments[$userId . '|' . strtolower($assignmentPublicId)] ?? null;
    }

    public function hasDocumentAtPath(string $blobPath): bool
    {
        return isset($this->paths[$blobPath]);
    }

    public function recordAssessmentDocument(
        AssessmentDocumentContext $context,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string {
        $publicId = sprintf('00000000-0000-4000-8000-%012d', $this->next++);
        $this->paths[$blobPath] = $publicId;
        $this->documents?->withDocument($context->userId, new OnboardingDocumentRef(
            $publicId,
            $context->userId,
            $document->documentKind,
            $dataClass,
            $container,
            $blobPath,
            $document->contentType,
            $document->byteSize(),
            $document->sha256,
            gmdate('Y-m-d\TH:i:s\Z'),
        ));

        return $publicId;
    }

    public function findLatestFormSubmissionByAssignment(string $assignmentPublicId): ?FormSubmissionForDocument
    {
        foreach ($this->submissions as $key => $submission) {
            if (str_ends_with($key, '|' . strtolower($assignmentPublicId))) {
                return $submission;
            }
        }

        return null;
    }

    public function ordinaryAnswers(int $formSubmissionId): array
    {
        return $this->ordinary[$formSubmissionId] ?? [];
    }

    public function recordPhoto(FormContributionContext $form, RenderedDocument $photo, string $container, string $blobPath): string
    {
        $publicId = sprintf('00000000-0000-4000-9000-%012d', $this->next++);
        $this->photos[$publicId] = $form->assignmentId;
        $this->paths[$blobPath] = $publicId;
        $this->documents?->withDocument($form->subjectUserId, new OnboardingDocumentRef(
            $publicId,
            $form->subjectUserId,
            $photo->documentKind,
            DataClass::ordinaryHr(),
            $container,
            $blobPath,
            $photo->contentType,
            $photo->byteSize(),
            $photo->sha256,
            gmdate('Y-m-d\TH:i:s\Z'),
        ));

        return $publicId;
    }

    public function photosOf(int $assignmentId, array $documentPublicIds): array
    {
        return array_values(array_filter($documentPublicIds, fn (string $id): bool => ($this->photos[strtolower($id)] ?? null) === $assignmentId));
    }

    public function findPolicyDocumentContext(int $userId, string $assignmentPublicId): ?PolicyDocumentContext
    {
        return $this->policies[$userId . ':' . $assignmentPublicId] ?? null;
    }

    public function recordPolicyDocument(
        PolicyDocumentContext $context,
        RenderedDocument $document,
        DataClass $dataClass,
        string $container,
        string $blobPath,
    ): string {
        $publicId = sprintf('00000000-0000-4000-8000-%012d', $this->next++);
        $this->paths[$blobPath] = $publicId;
        $this->documents?->withDocument($context->userId, new OnboardingDocumentRef(
            $publicId,
            $context->userId,
            $document->documentKind,
            $dataClass,
            $container,
            $blobPath,
            $document->contentType,
            $document->byteSize(),
            $document->sha256,
            gmdate('Y-m-d\TH:i:s\Z'),
        ));

        return $publicId;
    }
}
