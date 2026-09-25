<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptRecord;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Service\AssessmentGrader;
use TriCab\Core\Onboarding\Domain\Service\QuestionRedaction;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentGrade;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\Service\TrainingReviewPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * Marks an attempt and, if it passed, finishes the module.
 *
 * ## The request cannot influence its own mark
 *
 * {@see submit()} takes a map of question id to chosen option key and nothing
 * else. There is no score parameter to forge (work order invariant 2) because
 * there is no score parameter: the grade comes from
 * {@see AssessmentGrader::grade()}, reading the attempt's own snapshot, and a
 * body carrying `scorePercent` or `isPass` is discarded by the HTTP layer
 * before it reaches here — or rather, is never read, which is the stronger
 * version of discarded.
 *
 * Answers naming a question that is not in the snapshot are ignored rather than
 * refused. The snapshot is the authority on what was asked, and a client that
 * sends an extra id is describing a question that does not exist in this
 * attempt; refusing would let somebody else's stale tab lock a person out of
 * submitting. Answers naming an option the question does not have ARE refused —
 * see the grader for why those two are treated differently.
 *
 * ## Passing completes; failing does not fail the person
 *
 * Except on a module HR signs off ({@see TrainingReviewPolicy}): there a pass
 * submits it for review, and HR completes it.
 *
 * A pass moves the item to `completed` as
 * {@see \TriCab\Core\Onboarding\Domain\ValueObject\StatusActor::SYSTEM}. That is
 * the narrow exception work order invariant 7 allows and the reason the actor is
 * `system` rather than `trainee`: the person did the work, the author's pass
 * mark decided it was enough, and the audit row should say so.
 *
 * A failure leaves the item where it is, re-sittable. An induction assessment
 * exists to get people to the right answer, not to rank them, and the source
 * behaves the same way.
 *
 * ## Submitting is once, and is enforced twice
 *
 * The ledger refuses a replay; `TR_AttemptAnswers_Immutable` refuses a rewrite
 * at the database even if the ledger is bypassed. Both are wanted: the first
 * gives the retry a useful answer, the second is what remains true when somebody
 * writes a script.
 */
final readonly class SubmitAssessmentAttemptService
{
    public function __construct(
        private OnboardingTrainingRepository $training,
        private OnboardingSubmissionGate $gate,
        private OnboardingIdempotentWrite $idempotency,
        /** Phase 5.4: the paper sheet, made after the attempt commits. Null in tests that do not need it. */
        private ?GenerateAssessmentDocumentService $documents = null,
    ) {
    }

    /**
     * @param array<string, string> $answers question public id to chosen option key
     *
     * @throws OnboardingAssignmentNotFound
     * @throws ContentVersionSuperseded
     * @throws OnboardingWriteConflict
     * @throws \TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue
     */
    public function submit(
        int $userId,
        string $assignmentPublicId,
        string $attemptPublicId,
        string $versionPublicId,
        array $answers,
        ?string $idempotencyKey,
    ): OnboardingAttemptDetail {
        $this->gate->assertMaySubmit($userId);

        $detail = $this->training->findTraining($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $versionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        $attempt = $this->training->findAttempt($userId, $assignmentPublicId, $attemptPublicId);

        if ($attempt === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        $grade = AssessmentGrader::grade($attempt->questions, $answers, $detail->passMarkPercent);

        $result = $this->idempotency->run(
            $userId,
            OnboardingWriteIdempotencyStore::OPERATION_SUBMIT_ATTEMPT,
            $idempotencyKey,
            // The attempt is in the fingerprint as well as the answers: the same
            // answers submitted to a LATER attempt are a different request, and a
            // client reusing one key across a re-sit should be told so rather
            // than handed the first attempt's result.
            ['attempt' => $attemptPublicId, 'answers' => $answers],
            // The "already submitted" refusal is INSIDE the write, after the
            // ledger has had its chance to recognise a replay. Checked first, a
            // double-clicked Submit would be told on the second click that its
            // own successful submission was a conflict.
            function () use ($userId, $assignmentPublicId, $attemptPublicId, $answers, $grade, $attempt, $detail): array {
                if ($attempt->isSubmitted()) {
                    throw OnboardingWriteConflict::alreadyRecorded('assessment attempt');
                }

                $submitted = $this->training->submitAttempt(
                    $userId,
                    $assignmentPublicId,
                    $attemptPublicId,
                    $answers,
                    $grade,
                    self::outcomeTransition($detail->status, $grade, $userId, TrainingReviewPolicy::requiresSignOff($detail->moduleSlug)),
                );

                return [self::reveal($submitted, $grade), $submitted->publicId];
            },
            function (string $resultPublicId) use ($userId, $assignmentPublicId, $grade): OnboardingAttemptDetail {
                $replayed = $this->training->findAttempt($userId, $assignmentPublicId, $resultPublicId);

                if ($replayed === null) {
                    // The ledger says this was written and it is not there. That
                    // is a database fault rather than a client one, and it must
                    // not be reported as "already recorded" — the person would
                    // be told their work is saved when it is not.
                    throw new \RuntimeException('The idempotency ledger names an attempt that no longer exists.');
                }

                return self::reveal($replayed, $grade);
            },
        );

        // After the write, and on a replay too: once per attempt, so a replay
        // finds the sheet made, and one whose first try failed gets another.
        $this->documents?->generate($userId, $assignmentPublicId, $result->publicId);

        return $result;
    }

    /**
     * What a person sees once their answers are immutable: their choice, the
     * correct option, and why.
     *
     * Allowed precisely because it is after the fact. Work order invariant 1
     * constrains ordering rather than secrecy — knowing the answer now cannot
     * change what was submitted — and somebody who has just failed a question
     * learns nothing from being told only that they failed.
     */
    private static function reveal(OnboardingAttemptRecord $attempt, AssessmentGrade $grade): OnboardingAttemptDetail
    {
        return new OnboardingAttemptDetail(
            publicId: $attempt->publicId,
            attemptNumber: $attempt->attemptNumber,
            startedAtUtc: $attempt->startedAtUtc,
            submittedAtUtc: $attempt->submittedAtUtc,
            scorePercent: $attempt->scorePercent ?? $grade->scorePercent,
            isPass: $attempt->isPass ?? $grade->isPass,
            questions: QuestionRedaction::withRecordedAnswers(
                $attempt->questions,
                $attempt->recordedAnswers,
            ),
        );
    }

    private static function outcomeTransition(
        AssignmentStatus $from,
        AssessmentGrade $grade,
        int $userId,
        bool $signedOffByHr,
    ): ?StatusTransition {
        // 5.4: on a module HR signs off (the WHSE induction), passing is the
        // person handing their work in — so the trainee moves it, to
        // `submitted`, and it joins HR's queue. The system still never submits.
        if ($grade->isPass && $signedOffByHr) {
            return StatusTransition::traineeMovesTo(
                $from,
                AssignmentStatus::submitted(),
                $userId,
                sprintf('Passed the assessment with %d%%; waiting for HR to sign it off.', $grade->scorePercent),
            );
        }

        if ($grade->isPass) {
            return StatusTransition::systemMovesTo(
                $from,
                AssignmentStatus::completed(),
                sprintf('Passed the assessment with %d%%.', $grade->scorePercent),
            );
        }

        // A failed attempt still says the person is working on it, which matters
        // for somebody whose first action was to start the quiz.
        if ($from->toString() !== AssignmentStatus::NOT_STARTED) {
            return null;
        }

        return StatusTransition::traineeMovesTo(
            $from,
            AssignmentStatus::inProgress(),
            $userId,
            'Submitted an assessment attempt.',
        );
    }
}
