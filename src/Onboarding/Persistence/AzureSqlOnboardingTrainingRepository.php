<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\UniqueConstraintTranslator;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptDetail;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAttemptRecord;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTrainingDetail;
use TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentVersionSuperseded;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentGrade;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\PresentationManifest;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * Training, against `[onboarding]`.
 *
 * ## The answer key is selected exactly once, into the snapshot
 *
 * {@see startAttempt()} reads `Questions.CorrectOptionKey` and writes it to
 * `Attempts.QuestionSnapshotJson`. Nothing else in this class selects that
 * column, and the reads that serve a trainee — {@see findTraining()} and
 * {@see findAttempt()} — project the snapshot rather than the authoring table.
 * Work order invariant 1 is therefore a property of which columns appear in
 * which statement, which is visible in a diff, rather than of a filter somebody
 * has to remember to apply.
 *
 * ## Writes are conditional in SQL, never checked in PHP
 *
 * Every write joins through {@see AzureSqlOnboardingAssignmentLocator}, whose
 * single statement carries ownership, item type, withdrawal and content version.
 * A write that matches nothing is refused; there is no read-then-check pair for
 * a concurrent publish to slip between.
 */
final class AzureSqlOnboardingTrainingRepository implements OnboardingTrainingRepository
{
    use AzureSqlTransaction;
    use AzureSqlOnboardingStatusWriter;
    use UniqueConstraintTranslator;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly AzureSqlOnboardingAssignmentLocator $locator,
    ) {
    }

    public function findTraining(int $userId, string $assignmentPublicId): ?OnboardingTrainingDetail
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssignmentPublicId],
                tm.[Title],
                tm.[Summary],
                tm.[Slug],
                CONVERT(NVARCHAR(36), tmv.[PublicId]) AS [VersionPublicId],
                tmv.[VersionNumber],
                tmv.[PresentationManifestJson],
                tmv.[RequiredSlideCount],
                tmv.[PassMarkPercent],
                a.[Status],
                pp.[SlideIdsJson],
                (
                    SELECT COUNT(*)
                    FROM [onboarding].[Questions] q
                    WHERE q.[TrainingModuleVersionId] = tmv.[TrainingModuleVersionId]
                ) AS [QuestionCount]
            FROM [onboarding].[Assignments] a
            JOIN [onboarding].[TrainingModuleVersions] tmv
                ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
            JOIN [onboarding].[TrainingModules] tm
                ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
            /* Progress is joined on the VERSION as well as the assignment. A row
               left behind by a superseded version is not this version's progress,
               and showing it would credit somebody for slides they saw in a deck
               that has been replaced. */
            LEFT JOIN [onboarding].[PresentationProgress] pp
                ON pp.[AssignmentId] = a.[AssignmentId]
               AND pp.[TrainingModuleVersionId] = tmv.[TrainingModuleVersionId]
            WHERE a.[UserId] = :userId
              AND CONVERT(NVARCHAR(36), a.[PublicId]) = :assignmentPublicId
              AND a.[ItemType] = N'training'
              AND a.[WithdrawnAtUtc] IS NULL
            SQL,
            [':userId' => $userId, ':assignmentPublicId' => $assignmentPublicId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $manifest = PresentationManifest::fromJson(
            AzureSqlValueMapper::stringValue($row['PresentationManifestJson'] ?? '[]'),
        );

        return new OnboardingTrainingDetail(
            assignmentPublicId: AzureSqlValueMapper::stringValue($row['AssignmentPublicId'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            summary: AzureSqlValueMapper::nullableString($row['Summary'] ?? null),
            versionPublicId: AzureSqlValueMapper::stringValue($row['VersionPublicId'] ?? ''),
            versionNumber: AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
            status: AssignmentStatus::fromStorage($row['Status'] ?? null),
            manifest: $manifest,
            requiredSlideCount: AzureSqlValueMapper::intValue($row['RequiredSlideCount'] ?? 0),
            passMarkPercent: AzureSqlValueMapper::nullableInt($row['PassMarkPercent'] ?? null),
            questionCount: AzureSqlValueMapper::intValue($row['QuestionCount'] ?? 0),
            // Ordered through the manifest rather than trusted as stored, so a
            // row hand-edited into a different order still renders sensibly.
            reachedSlideIds: $manifest->inManifestOrder(
                self::decodeSlideIds($row['SlideIdsJson'] ?? null),
            ),
            latestAttempt: $this->latestAttemptSummary($userId, $assignmentPublicId),
            moduleSlug: AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
        );
    }

    public function savePresentationProgress(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        array $mergedSlideIds,
        bool $reachedFinalSlide,
        ?StatusTransition $transition,
    ): OnboardingTrainingDetail {
        $located = $this->locate($userId, $assignmentPublicId, $expectedVersionPublicId);

        $this->runInTransaction($this->connection, function () use (
            $located,
            $assignmentPublicId,
            $userId,
            $mergedSlideIds,
            $reachedFinalSlide,
            $transition,
        ): void {
            $count = count($mergedSlideIds);
            $slides = json_encode($mergedSlideIds, JSON_THROW_ON_ERROR);

            /* The monotonic guard, in the statement. `[ReachedSlideCount] <=
               :guardCount` means a stale writer whose set is smaller matches
               nothing and quietly does nothing — which is the right answer for a
               union, and is why this path raises no conflict. Two parameter names
               for the same value, because PDO_SQLSRV binds each occurrence of a
               named parameter separately and leaves the second unbound. */
            $updated = $this->connection->fetchAll(
                <<<'SQL'
                UPDATE [onboarding].[PresentationProgress]
                SET [SlideIdsJson] = :slides,
                    [ReachedSlideCount] = :slideCount,
                    [ReachedFinalSlide] = :reachedFinal,
                    [LastSeenAtUtc] = SYSUTCDATETIME(),
                    [UpdatedAtUtc] = SYSUTCDATETIME()
                OUTPUT INSERTED.[PresentationProgressId]
                WHERE [AssignmentId] = :assignmentId
                  AND [TrainingModuleVersionId] = :versionId
                  AND [ReachedSlideCount] <= :guardCount
                SQL,
                [
                    ':slides' => $slides,
                    ':slideCount' => $count,
                    ':reachedFinal' => $reachedFinalSlide ? 1 : 0,
                    ':assignmentId' => $located['assignmentId'],
                    ':versionId' => $located['versionId'],
                    ':guardCount' => $count,
                ],
            );

            if ($updated === []) {
                $this->insertProgressIfAbsent($located, $slides, $count, $reachedFinalSlide);
            }

            $this->applyTransition(
                $this->connection,
                $located['assignmentId'],
                $assignmentPublicId,
                $userId,
                $transition,
            );
        });

        return $this->reread($userId, $assignmentPublicId);
    }

    public function startAttempt(
        int $userId,
        string $assignmentPublicId,
        string $expectedVersionPublicId,
        ?StatusTransition $transition,
    ): OnboardingAttemptRecord {
        $located = $this->locate($userId, $assignmentPublicId, $expectedVersionPublicId);
        $questions = $this->questionsFor($located['versionId']);

        return $this->runInTransaction($this->connection, function () use (
            $located,
            $assignmentPublicId,
            $userId,
            $questions,
            $transition,
        ): OnboardingAttemptRecord {
            $snapshot = json_encode(
                array_map(static fn (AssessmentQuestion $q): array => $q->toSnapshot(), $questions),
                JSON_THROW_ON_ERROR,
            );

            /* The attempt number is allocated inside the INSERT, from the rows
               that exist at that moment. Computing it in PHP and passing it in
               would race every concurrent start for the same assignment, and
               UQ_onboarding_Attempts_Number would turn that race into a 500. */
            $rows = $this->connection->fetchAll(
                <<<'SQL'
                INSERT INTO [onboarding].[Attempts] ([AssignmentId], [AttemptNumber], [QuestionSnapshotJson])
                OUTPUT
                    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
                    INSERTED.[AttemptNumber],
                    CONVERT(VARCHAR(33), INSERTED.[StartedAtUtc], 127) AS [StartedAtUtc]
                SELECT
                    :assignmentId,
                    COALESCE(MAX(prior.[AttemptNumber]), 0) + 1,
                    :snapshot
                FROM [onboarding].[Attempts] prior
                WHERE prior.[AssignmentId] = :assignmentIdForNumber
                SQL,
                [
                    ':assignmentId' => $located['assignmentId'],
                    ':snapshot' => $snapshot,
                    ':assignmentIdForNumber' => $located['assignmentId'],
                ],
            );

            $this->applyTransition(
                $this->connection,
                $located['assignmentId'],
                $assignmentPublicId,
                $userId,
                $transition,
            );

            $row = $rows[0] ?? [];

            return new OnboardingAttemptRecord(
                publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                attemptNumber: AzureSqlValueMapper::intValue($row['AttemptNumber'] ?? 1),
                startedAtUtc: AzureSqlValueMapper::stringValue($row['StartedAtUtc'] ?? ''),
                submittedAtUtc: null,
                scorePercent: null,
                isPass: null,
                questions: $questions,
            );
        });
    }

    public function findAttempt(
        int $userId,
        string $assignmentPublicId,
        string $attemptPublicId,
    ): ?OnboardingAttemptRecord {
        $row = $this->attemptRow($userId, $assignmentPublicId, $attemptPublicId);

        if ($row === null) {
            return null;
        }

        return new OnboardingAttemptRecord(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            attemptNumber: AzureSqlValueMapper::intValue($row['AttemptNumber'] ?? 1),
            startedAtUtc: AzureSqlValueMapper::stringValue($row['StartedAtUtc'] ?? ''),
            submittedAtUtc: AzureSqlValueMapper::nullableString($row['SubmittedAtUtc'] ?? null),
            scorePercent: AzureSqlValueMapper::nullableInt($row['ScorePercent'] ?? null),
            isPass: $row['IsPass'] === null ? null : AzureSqlValueMapper::boolValue($row['IsPass']),
            questions: self::decodeSnapshot($row['QuestionSnapshotJson'] ?? null),
            recordedAnswers: $this->recordedAnswers(AzureSqlValueMapper::intValue($row['AttemptId'] ?? 0)),
        );
    }

    public function submitAttempt(
        int $userId,
        string $assignmentPublicId,
        string $attemptPublicId,
        array $answers,
        AssessmentGrade $grade,
        ?StatusTransition $transition,
    ): OnboardingAttemptRecord {
        $row = $this->attemptRow($userId, $assignmentPublicId, $attemptPublicId);

        if ($row === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        $attemptId = AzureSqlValueMapper::intValue($row['AttemptId'] ?? 0);
        $assignmentId = AzureSqlValueMapper::intValue($row['AssignmentId'] ?? 0);
        $questions = self::decodeSnapshot($row['QuestionSnapshotJson'] ?? null);

        $this->runInTransaction($this->connection, function () use (
            $attemptId,
            $assignmentId,
            $assignmentPublicId,
            $userId,
            $questions,
            $answers,
            $grade,
            $transition,
        ): void {
            /* `[SubmittedAtUtc] IS NULL` is the conflict check, and it is in the
               statement rather than in a preceding SELECT. An empty OUTPUT means
               somebody submitted between the read and here — the same shape
               Phase 2.5 established for optimistic concurrency, minus the token,
               because "already submitted" is a one-way door rather than a
               version. */
            $marked = $this->connection->fetchAll(
                <<<'SQL'
                UPDATE [onboarding].[Attempts]
                SET [SubmittedAtUtc] = SYSUTCDATETIME(),
                    [ScorePercent] = :scorePercent,
                    [IsPass] = :isPass
                OUTPUT INSERTED.[AttemptId]
                WHERE [AttemptId] = :attemptId
                  AND [SubmittedAtUtc] IS NULL
                SQL,
                [
                    ':scorePercent' => $grade->scorePercent,
                    ':isPass' => $grade->isPass ? 1 : 0,
                    ':attemptId' => $attemptId,
                ],
            );

            if ($marked === []) {
                throw OnboardingWriteConflict::alreadyRecorded('assessment attempt');
            }

            $this->insertAnswers($attemptId, $questions, $answers, $grade);

            $this->applyTransition(
                $this->connection,
                $assignmentId,
                $assignmentPublicId,
                $userId,
                $transition,
            );
        });

        $submitted = $this->findAttempt($userId, $assignmentPublicId, $attemptPublicId);

        if ($submitted === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        return $submitted;
    }

    // ------------------------------------------------------------- internals

    /**
     * @return array{assignmentId: int, versionId: int, status: string, versionPublicId: string}
     *
     * @throws OnboardingAssignmentNotFound when it is not theirs, or not there
     * @throws ContentVersionSuperseded     when it is theirs but has been replaced
     */
    private function locate(int $userId, string $assignmentPublicId, string $expectedVersionPublicId): array
    {
        $located = $this->locator->locate($userId, $assignmentPublicId, 'training', $expectedVersionPublicId);

        if ($located !== null) {
            return $located;
        }

        // The one thing a failure is allowed to disclose, and only because
        // telling somebody whose tab was open across a publish that their
        // induction does not exist would have them ringing HR about a record
        // that is on the screen in front of them.
        if ($this->locator->existsForPerson($userId, $assignmentPublicId, 'training')) {
            throw ContentVersionSuperseded::reload();
        }

        throw OnboardingAssignmentNotFound::forThisPerson();
    }

    private function reread(int $userId, string $assignmentPublicId): OnboardingTrainingDetail
    {
        $detail = $this->findTraining($userId, $assignmentPublicId);

        if ($detail === null) {
            throw OnboardingAssignmentNotFound::forThisPerson();
        }

        return $detail;
    }

    /**
     * @param array{assignmentId: int, versionId: int, status: string, versionPublicId: string} $located
     */
    private function insertProgressIfAbsent(
        array $located,
        string $slides,
        int $count,
        bool $reachedFinalSlide,
    ): void {
        /* Two writers can reach here at once, and `UQ_onboarding_PresentationProgress_Assignment`
           decides between them. The loser's write is discarded rather than
           retried: its slides are a subset of the winner's by the time the next
           save runs, because progress is a union and the client still holds them. */
        $this->translatingUniqueConstraintViolation(
            function () use ($located, $slides, $count, $reachedFinalSlide): void {
                $this->connection->execute(
                    <<<'SQL'
                    INSERT INTO [onboarding].[PresentationProgress]
                        ([AssignmentId], [TrainingModuleVersionId], [SlideIdsJson], [ReachedSlideCount], [ReachedFinalSlide])
                    SELECT :assignmentId, :versionId, :slides, :slideCount, :reachedFinal
                    WHERE NOT EXISTS (
                        SELECT 1 FROM [onboarding].[PresentationProgress]
                        WHERE [AssignmentId] = :assignmentIdForGuard
                    )
                    SQL,
                    [
                        ':assignmentId' => $located['assignmentId'],
                        ':versionId' => $located['versionId'],
                        ':slides' => $slides,
                        ':slideCount' => $count,
                        ':reachedFinal' => $reachedFinalSlide ? 1 : 0,
                        ':assignmentIdForGuard' => $located['assignmentId'],
                    ],
                );
            },
            static fn (): \Throwable => OnboardingWriteConflict::staleToken(),
        );
    }

    /**
     * @return list<AssessmentQuestion>
     */
    private function questionsFor(int $versionId): array
    {
        /* The only statement in this class that selects [CorrectOptionKey], and
           it writes what it reads straight into the attempt snapshot. */
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT
                CONVERT(NVARCHAR(36), q.[PublicId]) AS [PublicId],
                q.[Ordinal],
                q.[Prompt],
                q.[OptionsJson],
                q.[CorrectOptionKey],
                q.[CorrectReason]
            FROM [onboarding].[Questions] q
            WHERE q.[TrainingModuleVersionId] = :versionId
            ORDER BY q.[Ordinal]
            SQL,
            [':versionId' => $versionId],
        );

        $questions = [];
        foreach ($rows as $row) {
            $questions[] = AssessmentQuestion::of(
                AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                AzureSqlValueMapper::intValue($row['Ordinal'] ?? 0),
                AzureSqlValueMapper::stringValue($row['Prompt'] ?? ''),
                self::decodeOptions($row['OptionsJson'] ?? null),
                AzureSqlValueMapper::stringValue($row['CorrectOptionKey'] ?? ''),
                AzureSqlValueMapper::nullableString($row['CorrectReason'] ?? null),
            );
        }

        return $questions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attemptRow(int $userId, string $assignmentPublicId, string $attemptPublicId): ?array
    {
        /* The user id is in this statement even though the attempt public id is
           already unique. Ownership is not an optimisation to be skipped once an
           id looks specific enough. */
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                att.[AttemptId],
                a.[AssignmentId],
                CONVERT(NVARCHAR(36), att.[PublicId]) AS [PublicId],
                att.[AttemptNumber],
                att.[QuestionSnapshotJson],
                CONVERT(VARCHAR(33), att.[StartedAtUtc], 127) AS [StartedAtUtc],
                CONVERT(VARCHAR(33), att.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
                att.[ScorePercent],
                att.[IsPass]
            FROM [onboarding].[Attempts] att
            JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = att.[AssignmentId]
            WHERE a.[UserId] = :userId
              AND CONVERT(NVARCHAR(36), a.[PublicId]) = :assignmentPublicId
              AND CONVERT(NVARCHAR(36), att.[PublicId]) = :attemptPublicId
              AND a.[WithdrawnAtUtc] IS NULL
            SQL,
            [
                ':userId' => $userId,
                ':assignmentPublicId' => $assignmentPublicId,
                ':attemptPublicId' => $attemptPublicId,
            ],
        );

        return $rows === [] ? null : $rows[0];
    }

    /**
     * @return array<string, string|null>
     */
    private function recordedAnswers(int $attemptId): array
    {
        if ($attemptId === 0) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT
                CONVERT(NVARCHAR(36), q.[PublicId]) AS [QuestionPublicId],
                ans.[SelectedOptionKey]
            FROM [onboarding].[AttemptAnswers] ans
            JOIN [onboarding].[Questions] q ON q.[QuestionId] = ans.[QuestionId]
            WHERE ans.[AttemptId] = :attemptId
            SQL,
            [':attemptId' => $attemptId],
        );

        $answers = [];
        foreach ($rows as $row) {
            $answers[AzureSqlValueMapper::stringValue($row['QuestionPublicId'] ?? '')]
                = AzureSqlValueMapper::nullableString($row['SelectedOptionKey'] ?? null);
        }

        return $answers;
    }

    /**
     * @param list<AssessmentQuestion> $questions
     * @param array<string, string>    $answers
     */
    private function insertAnswers(
        int $attemptId,
        array $questions,
        array $answers,
        AssessmentGrade $grade,
    ): void {
        foreach ($questions as $question) {
            $selected = $answers[$question->publicId] ?? null;

            /* One row per question in the snapshot, including the ones left
               blank. An absent row would be indistinguishable from an answer
               nobody stored, and "did they skip it or did we lose it" is not a
               question anybody can answer six months later. */
            $this->connection->execute(
                <<<'SQL'
                INSERT INTO [onboarding].[AttemptAnswers]
                    ([AttemptId], [QuestionId], [Ordinal], [SelectedOptionKey], [IsCorrect])
                SELECT :attemptId, q.[QuestionId], :ordinal, :selected, :isCorrect
                FROM [onboarding].[Questions] q
                WHERE CONVERT(NVARCHAR(36), q.[PublicId]) = :questionPublicId
                SQL,
                [
                    ':attemptId' => $attemptId,
                    ':ordinal' => $question->ordinal,
                    ':selected' => $selected,
                    ':isCorrect' => ($grade->correctnessByQuestion[$question->publicId] ?? false) ? 1 : 0,
                    ':questionPublicId' => $question->publicId,
                ],
            );
        }
    }

    private function latestAttemptSummary(int $userId, string $assignmentPublicId): ?OnboardingAttemptDetail
    {
        /* The summary carries no questions, so it carries no answer key. That is
           why the detail object's `latestAttempt` can be rendered on a page
           somebody reaches without starting anything. */
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (1)
                CONVERT(NVARCHAR(36), att.[PublicId]) AS [PublicId],
                att.[AttemptNumber],
                CONVERT(VARCHAR(33), att.[StartedAtUtc], 127) AS [StartedAtUtc],
                CONVERT(VARCHAR(33), att.[SubmittedAtUtc], 127) AS [SubmittedAtUtc],
                att.[ScorePercent],
                att.[IsPass]
            FROM [onboarding].[Attempts] att
            JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = att.[AssignmentId]
            WHERE a.[UserId] = :userId
              AND CONVERT(NVARCHAR(36), a.[PublicId]) = :assignmentPublicId
            ORDER BY att.[AttemptNumber] DESC
            SQL,
            [':userId' => $userId, ':assignmentPublicId' => $assignmentPublicId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return new OnboardingAttemptDetail(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            attemptNumber: AzureSqlValueMapper::intValue($row['AttemptNumber'] ?? 1),
            startedAtUtc: AzureSqlValueMapper::stringValue($row['StartedAtUtc'] ?? ''),
            submittedAtUtc: AzureSqlValueMapper::nullableString($row['SubmittedAtUtc'] ?? null),
            scorePercent: AzureSqlValueMapper::nullableInt($row['ScorePercent'] ?? null),
            isPass: $row['IsPass'] === null ? null : AzureSqlValueMapper::boolValue($row['IsPass']),
        );
    }

    /**
     * @return list<string>
     */
    private static function decodeSlideIds(mixed $json): array
    {
        $text = AzureSqlValueMapper::nullableString($json);

        if ($text === null) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, string>
     */
    private static function decodeOptions(mixed $json): array
    {
        $text = AzureSqlValueMapper::nullableString($json);
        /** @var mixed $decoded */
        $decoded = $text === null ? [] : json_decode($text, true);

        if (!is_array($decoded)) {
            return [];
        }

        $options = [];
        foreach ($decoded as $key => $entry) {
            if (is_array($entry)) {
                $optionKey = isset($entry['key']) && is_string($entry['key']) ? $entry['key'] : '';
                $label = isset($entry['label']) && is_string($entry['label']) ? $entry['label'] : '';
            } else {
                $optionKey = (string) $key;
                $label = is_string($entry) ? $entry : '';
            }

            if ($optionKey !== '') {
                $options[$optionKey] = $label;
            }
        }

        return $options;
    }

    /**
     * @return list<AssessmentQuestion>
     */
    private static function decodeSnapshot(mixed $json): array
    {
        $text = AzureSqlValueMapper::nullableString($json);
        /** @var mixed $decoded */
        $decoded = $text === null ? [] : json_decode($text, true);

        if (!is_array($decoded)) {
            return [];
        }

        $questions = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $questions[] = AssessmentQuestion::fromSnapshot($row);
            }
        }

        return $questions;
    }
}
