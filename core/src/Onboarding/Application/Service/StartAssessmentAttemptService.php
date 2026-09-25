<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptRecord;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Domain\Exception\AssessmentNotAvailable;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Service\QuestionRedaction;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * Opens an assessment and hands over the questions — without the answers.
 *
 * ## Why starting is a write
 *
 * It would have been simpler to serve questions from a GET and only write on
 * submit. It is a POST because the question set has to be **fixed before the
 * answers arrive**: the attempt row snapshots the questions as they were put to
 * this person, and grading reads that snapshot rather than
 * `onboarding.Questions`. Without the snapshot, an author editing a question
 * between the two requests changes what somebody is marked against, and a
 * regrade of an old attempt would silently use today's answer key.
 *
 * ## Invariant 1, structurally
 *
 * The repository returns {@see OnboardingAttemptRecord}, which holds
 * {@see \TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion} objects
 * with their keys — and has no `toArray()`. The only thing with a `toArray()` is
 * {@see OnboardingAttemptDetail}, and the only way to fill its `questions` is
 * through {@see QuestionRedaction}. There is no route from a started attempt to
 * a response containing a correct option, and it is closed by what the types
 * can do rather than by remembering to strip a field.
 *
 * ## No idempotency key
 *
 * Starting twice produces two attempt rows, and that is the right answer rather
 * than a bug: `UQ_onboarding_Attempts_Number` numbers them, an abandoned attempt
 * is part of the record, and refusing a second start would strand somebody whose
 * browser crashed on question three. Submitting is the once-only act, and that
 * is where the key is required.
 */
final readonly class StartAssessmentAttemptService
{
    public function __construct(
        private OnboardingTrainingRepository $training,
        private OnboardingSubmissionGate $gate,
    ) {
    }

    /**
     * @throws OnboardingAssignmentNotFound
     * @throws ContentVersionSuperseded
     * @throws AssessmentNotAvailable
     */
    public function start(
        int $userId,
        string $assignmentPublicId,
        string $versionPublicId,
    ): OnboardingAttemptDetail {
        $this->gate->assertMaySubmit($userId);

        $detail = $this->training->findTraining($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $versionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        // Three separate refusals rather than one `mayStartAttempt()` check, so
        // the person is told which of the three it is. The detail object knows
        // the same thing; what it cannot do is say it in words they can act on.
        if (!$detail->hasAssessment()) {
            throw AssessmentNotAvailable::moduleHasNoQuestions();
        }

        if ($detail->status->isFinal()) {
            throw AssessmentNotAvailable::alreadyComplete();
        }

        if (!$detail->isPresentationComplete()) {
            throw AssessmentNotAvailable::presentationNotFinished();
        }

        $attempt = $this->training->startAttempt(
            $userId,
            $assignmentPublicId,
            $versionPublicId,
            self::openingTransition($detail->status, $userId),
        );

        return new OnboardingAttemptDetail(
            publicId: $attempt->publicId,
            attemptNumber: $attempt->attemptNumber,
            startedAtUtc: $attempt->startedAtUtc,
            submittedAtUtc: null,
            scorePercent: null,
            isPass: null,
            questions: QuestionRedaction::forUnansweredSet($attempt->questions),
        );
    }

    private static function openingTransition(AssignmentStatus $from, int $userId): ?StatusTransition
    {
        if ($from->toString() !== AssignmentStatus::NOT_STARTED) {
            return null;
        }

        return StatusTransition::traineeMovesTo(
            $from,
            AssignmentStatus::inProgress(),
            $userId,
            'Started the assessment.',
        );
    }
}
