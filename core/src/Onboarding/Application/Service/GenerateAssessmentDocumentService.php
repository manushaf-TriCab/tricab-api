<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use Throwable;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentDocumentInput;
use TriCab\Core\Onboarding\Application\DTO\Document\AssessmentSignOff;
use TriCab\Core\Onboarding\Contract\OnboardingAssessmentDocumentRenderer;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentStore;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;

/**
 * Generates the paper assessment sheet for a submitted attempt (5.4), passed or
 * not, as the source did.
 *
 * Same rules as {@see GenerateFormSubmissionDocumentService}: after the
 * attempt commits, never part of it; a failure is logged by class and swallowed;
 * once per attempt. `DocumentReferences` has no attempt column, so "once" is
 * held by the blob path, which is the attempt's own random public id — unique
 * per container, and nothing a listing could read a name from.
 *
 * The sheet holds answers and a result, which are workflow records (C1).
 */
final readonly class GenerateAssessmentDocumentService
{
    public function __construct(
        private OnboardingDocumentGenerationRepository $repository,
        private OnboardingTrainingRepository $training,
        private OnboardingAssessmentDocumentRenderer $renderer,
        private OnboardingDocumentStore $store,
    ) {
    }

    public function generate(int $userId, string $assignmentPublicId, string $attemptPublicId): ?string
    {
        return $this->make(
            $userId,
            $assignmentPublicId,
            $attemptPublicId,
            sprintf('%d/attempt-%s.pdf', $userId, strtolower($attemptPublicId)),
            null,
        );
    }

    /**
     * The WHSE sheet again, once HR has accepted it (5.4): the passed attempt,
     * with the supervisor's or manager's feedback, who received it for HR and
     * the review date — the source's reviewed PDF. A second document beside
     * the one made at submission, which is kept as it was.
     *
     * After the decision commits, never part of it, like every copy here. Once
     * per attempt, held by its own blob path.
     */
    public function generateSignedOff(int $userId, string $assignmentPublicId, int $reviewerUserId, string $feedback, string $reviewedOn): ?string
    {
        try {
            $attempt = $this->training->findTraining($userId, $assignmentPublicId)?->latestAttempt;
            if ($attempt === null || $attempt->isPass !== true) {
                return null;
            }

            return $this->make(
                $userId,
                $assignmentPublicId,
                $attempt->publicId,
                sprintf('%d/attempt-%s-signed-off.pdf', $userId, strtolower($attempt->publicId)),
                new AssessmentSignOff($feedback, $this->repository->userDisplayName($reviewerUserId), $reviewedOn),
            );
        } catch (Throwable $failure) {
            self::logFailure($assignmentPublicId, $failure);

            return null;
        }
    }

    private function make(int $userId, string $assignmentPublicId, string $attemptPublicId, string $path, ?AssessmentSignOff $signOff): ?string
    {
        try {
            if ($this->repository->hasDocumentAtPath($path)) {
                return null;
            }
            $context = $this->repository->findAssessmentContext($userId, $assignmentPublicId);
            $training = $this->training->findTraining($userId, $assignmentPublicId);
            $attempt = $this->training->findAttempt($userId, $assignmentPublicId, $attemptPublicId);
            if ($context === null || $training === null || $attempt === null || !$attempt->isSubmitted()) {
                return null;
            }

            $questions = [];
            foreach ($attempt->questions as $question) {
                $questions[] = [
                    'optionKeys' => array_map('strval', array_keys($question->options())),
                    'chosenKey' => $attempt->recordedAnswers[$question->publicId] ?? null,
                ];
            }

            $document = $this->renderer->render(new AssessmentDocumentInput(
                $context->moduleSlug,
                $context->moduleTitle,
                $questions,
                $attempt->scorePercent ?? 0,
                $attempt->isPass ?? false,
                $training->passMarkPercent ?? \TriCab\Core\Onboarding\Domain\Service\TrainingAuthoringRules::DEFAULT_PASS_MARK,
                (string) $attempt->submittedAtUtc,
                $context->personName,
                $context->position,
                $context->department,
                $context->branch,
                $context->managerName,
                $signOff,
            ));
            if ($document === null) {
                return null;
            }

            $stored = $this->store->put($path, $document);

            return $this->repository->recordAssessmentDocument($context, $document, DataClass::workflow(), $stored['container'], $stored['path']);
        } catch (Throwable $failure) {
            self::logFailure($attemptPublicId, $failure);

            return null;
        }
    }

    private static function logFailure(string $reference, Throwable $failure): void
    {
        error_log(sprintf(
            'onboarding.document.assessment_generation_failed {"attempt":"%s","error":"%s"}',
            $reference,
            (new \ReflectionClass($failure))->getShortName(),
        ));
    }
}
