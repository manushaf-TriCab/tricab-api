<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptRecord;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTrainingDetail;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentGrade;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * The default binding, and what the HTTP tests run against.
 *
 * Keyed on `userId` **and** assignment public id in one composite key, for the
 * reason the Azure SQL adapter filters in SQL: a double that resolved an
 * assignment by id alone would let a cross-person test pass against a repository
 * that had lost its `WHERE [UserId] = :userId`, which is the one thing these
 * tests exist to catch.
 *
 * {@see $transitions} records what would have been written to
 * `audit.OnboardingStatusEvents`, so a test can assert that a status change was
 * audited rather than only that it happened.
 */
final class InMemoryOnboardingTrainingRepository implements OnboardingTrainingRepository
{
    /** @var array<string, OnboardingTrainingDetail> */
    private array $details = [];

    /** @var array<string, list<AssessmentQuestion>> */
    private array $questions = [];

    /** @var array<string, array{userId: int, assignment: string, record: OnboardingAttemptRecord}> */
    private array $attempts = [];

    /** @var list<StatusTransition> */
    public array $transitions = [];

    private int $attemptCounter = 0;

    public function findTraining(int $userId, string $assignmentPublicId): ?OnboardingTrainingDetail
    {
        return $this->details[self::key($userId, $assignmentPublicId)] ?? null;
    }

    public function savePresentationProgress(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        array $mergedSlideIds,
        bool $reachedFinalSlide,
        ?StatusTransition $transition,
    ): OnboardingTrainingDetail {
        $detail = $this->require($userId, $assignmentPublicId, $expectedVersionPublicId);

        // The monotonic guard the contract requires: a stale write that would
        // shrink the stored set is a no-op rather than a conflict.
        if (count($mergedSlideIds) < count($detail->reachedSlideIds)) {
            return $detail;
        }

        unset($reachedFinalSlide);

        return $this->replace($userId, $assignmentPublicId, $detail, $transition, $mergedSlideIds);
    }

    public function startAttempt(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        ?StatusTransition $transition,
    ): OnboardingAttemptRecord {
        $detail = $this->require($userId, $assignmentPublicId, $expectedVersionPublicId);
        $this->replace($userId, $assignmentPublicId, $detail, $transition, null);

        $number = 1;
        foreach ($this->attempts as $held) {
            if ($held['assignment'] === $assignmentPublicId && $held['userId'] === $userId) {
                ++$number;
            }
        }

        $publicId = sprintf('attempt-%04d', ++$this->attemptCounter);
        $record = new OnboardingAttemptRecord(
            publicId: $publicId,
            attemptNumber: $number,
            startedAtUtc: '2026-09-22T00:00:00+00:00',
            submittedAtUtc: null,
            scorePercent: null,
            isPass: null,
            questions: $this->questions[$assignmentPublicId] ?? [],
        );

        $this->attempts[$publicId] = [
            'userId' => $userId,
            'assignment' => $assignmentPublicId,
            'record' => $record,
        ];

        return $record;
    }

    public function findAttempt(
        int $userId,
        string $assignmentPublicId,
        string $attemptPublicId,
    ): ?OnboardingAttemptRecord {
        $held = $this->attempts[$attemptPublicId] ?? null;

        if ($held === null || $held['userId'] !== $userId || $held['assignment'] !== $assignmentPublicId) {
            return null;
        }

        return $held['record'];
    }

    public function submitAttempt(
        int $userId,
        string $assignmentPublicId,
        string $attemptPublicId,
        array $answers,
        AssessmentGrade $grade,
        ?StatusTransition $transition,
    ): OnboardingAttemptRecord {
        $existing = $this->findAttempt($userId, $assignmentPublicId, $attemptPublicId);

        if ($existing === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($existing->isSubmitted()) {
            throw OnboardingWriteConflict::alreadyRecorded('assessment attempt');
        }

        $recorded = [];
        foreach ($existing->questions as $question) {
            $recorded[$question->publicId] = $answers[$question->publicId] ?? null;
        }

        $submitted = new OnboardingAttemptRecord(
            publicId: $existing->publicId,
            attemptNumber: $existing->attemptNumber,
            startedAtUtc: $existing->startedAtUtc,
            submittedAtUtc: '2026-09-22T00:05:00+00:00',
            scorePercent: $grade->scorePercent,
            isPass: $grade->isPass,
            questions: $existing->questions,
            recordedAnswers: $recorded,
        );

        $this->attempts[$attemptPublicId]['record'] = $submitted;

        $detail = $this->findTraining($userId, $assignmentPublicId);
        if ($detail !== null) {
            // The summary the SQL side reads live: the latest attempt, no questions.
            $latest = new OnboardingAttemptDetail(
                publicId: $submitted->publicId,
                attemptNumber: $submitted->attemptNumber,
                startedAtUtc: $submitted->startedAtUtc,
                submittedAtUtc: $submitted->submittedAtUtc,
                scorePercent: $submitted->scorePercent,
                isPass: $submitted->isPass,
                questions: [],
            );
            $this->replace($userId, $assignmentPublicId, $detail, $transition, null, $latest);
        }

        return $submitted;
    }

    /**
     * @param list<AssessmentQuestion> $questions
     */
    public function withTraining(
        int $userId,
        OnboardingTrainingDetail $detail,
        array $questions = [],
    ): void {
        $this->details[self::key($userId, $detail->assignmentPublicId)] = $detail;
        $this->questions[$detail->assignmentPublicId] = $questions;
    }

    public function clear(): void
    {
        $this->details = [];
        $this->questions = [];
        $this->attempts = [];
        $this->transitions = [];
        $this->attemptCounter = 0;
    }

    private function require(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
    ): OnboardingTrainingDetail {
        $detail = $this->findTraining($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        if ($detail->versionPublicId !== $expectedVersionPublicId) {
            throw ContentVersionSuperseded::reload();
        }

        return $detail;
    }

    /**
     * @param list<string>|null $reachedSlideIds null leaves the stored progress alone
     */
    private function replace(
        int $userId,
        string $assignmentPublicId,
        OnboardingTrainingDetail $detail,
        ?StatusTransition $transition,
        ?array $reachedSlideIds,
        ?OnboardingAttemptDetail $latestAttempt = null,
    ): OnboardingTrainingDetail {
        if ($transition !== null) {
            $this->transitions[] = $transition;
        }

        $updated = new OnboardingTrainingDetail(
            assignmentPublicId: $detail->assignmentPublicId,
            title: $detail->title,
            summary: $detail->summary,
            versionPublicId: $detail->versionPublicId,
            versionNumber: $detail->versionNumber,
            status: $transition?->to ?? $detail->status,
            manifest: $detail->manifest,
            requiredSlideCount: $detail->requiredSlideCount,
            passMarkPercent: $detail->passMarkPercent,
            questionCount: $detail->questionCount,
            reachedSlideIds: $reachedSlideIds ?? $detail->reachedSlideIds,
            latestAttempt: $latestAttempt ?? $detail->latestAttempt,
            moduleSlug: $detail->moduleSlug,
        );

        $this->details[self::key($userId, $assignmentPublicId)] = $updated;

        return $updated;
    }

    private static function key(int $userId, string $assignmentPublicId): string
    {
        return $userId . ':' . $assignmentPublicId;
    }
}
