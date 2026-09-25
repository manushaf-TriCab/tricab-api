<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingProfileFields;
use TriCab\Core\Onboarding\Application\Service\CreateOnboardingPersonService;
use TriCab\Core\Onboarding\Application\Service\GeneratePolicyDocumentService;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter;
use TriCab\Core\Onboarding\Domain\ValueObject\AssessmentQuestion;
use TriCab\Core\Onboarding\Domain\ValueObject\DataClass;
use TriCab\Core\Onboarding\Domain\ValueObject\PolicyContent;
use TriCab\Core\Onboarding\Migration\Form\SourceFormTranslator;
use TriCab\Core\Onboarding\Migration\OnboardingMigrationRefused;
use TriCab\Core\Onboarding\Migration\Plan\MigrationPlan;
use TriCab\Core\Onboarding\Migration\Plan\MigrationPlanner;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Onboarding\Migration\Target\TargetCatalogue;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Carries out an approved migration plan (Phase 7.5).
 *
 * ## One person, one transaction
 *
 * Each person's induction record, assignments, progress, attempts, answers,
 * acknowledgements, submissions, drafts, contributions and document references
 * are written in one {@see ApplicationTransaction}, together with the ledger
 * row for every one of them. A failure part-way through a person leaves
 * nothing of theirs behind — the blobs uploaded for them are deleted — and the
 * next person is still attempted. A batch with any failure ends `failed`; a
 * fresh preview picks up whoever did not go, because what did go is recorded
 * and the planner skips it.
 *
 * ## As the source recorded it
 *
 * Statuses, dates, scores, pass flags, declarations and answers are written as
 * the plan carries them. Nothing is regraded and no rule the platform applies
 * to a live action is re-run — the plan already checked each record against
 * those rules and reported where they would disagree.
 *
 * ## What it never does
 *
 * Send email, change an existing account, or touch a record the plan did not
 * name. The accounts it creates hold only GUEST, through 4.1's own audited
 * path ({@see CreateOnboardingPersonService}).
 */
final class AzureSqlOnboardingMigrationExecutor
{
    public const SOURCE_NOTE = 'Migrated from tricab-training-vercel';

    /** @var list<array{container: string, path: string}> blobs written for the person in progress */
    private array $uploaded = [];
    /** @var array<int, list<array<string, mixed>>> version id => question rows */
    private array $questionRows = [];
    /** Whose rows are being written: every ledger row names its person. */
    private int $subjectUserId = 0;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly ApplicationTransaction $transaction,
        private readonly AzureSqlOnboardingMigrationLedger $ledger,
        private readonly CreateOnboardingPersonService $people,
        private readonly OnboardingAccountDirectory $accounts,
        private readonly ProductBlobStore $blobs,
        private readonly string $container,
        private readonly ?GeneratePolicyDocumentService $policyDocuments = null,
    ) {
    }

    /**
     * @return array{migrated: list<string>, failed: array<string, string>, policyDocuments: int}
     */
    public function execute(MigrationPlan $plan, SourceSnapshot $snapshot, TargetCatalogue $catalogue, string $batch, ActorContext $operator): array
    {
        $migrated = [];
        $failed = [];
        $policyDocuments = 0;

        foreach ($plan->people as $person) {
            if (!in_array($person['action'] ?? null, [MigrationPlan::PERSON_LINK, MigrationPlan::PERSON_CREATE_ACCOUNT], true)) {
                continue;
            }
            $email = self::str($person['email'] ?? null);
            $this->uploaded = [];
            try {
                $acknowledged = $this->transaction->run(fn (): array => $this->person($person, $snapshot, $catalogue, $batch, $operator));
                $migrated[] = $email;
            } catch (Throwable $e) {
                foreach ($this->uploadedForPerson() as $blob) {
                    try {
                        $this->blobs->deleteObject($blob['container'], $blob['path']);
                    } catch (Throwable) {
                        // Reported below with the failure; an orphan blob holds no record of anyone.
                    }
                }
                $failed[$email] = $e->getMessage();
                $this->ledger->log($batch, $operator, 'execute.person', 'failed', null, null, ['sourceId' => $person['sourceId'] ?? null, 'error' => substr($e->getMessage(), 0, 500)]);
                continue;
            }

            // The platform makes the signed policy PDF after the acknowledgement
            // commits, and so does this. A failure is reported, not fatal.
            foreach ($acknowledged as $ack) {
                $policyDocuments += $this->policyDocument($ack, $batch, $operator);
            }
        }

        return ['migrated' => $migrated, 'failed' => $failed, 'policyDocuments' => $policyDocuments];
    }

    // ---------------------------------------------------------------- person

    /**
     * @param array<string, mixed> $person
     *
     * @return list<array{userId: int, assignmentPublicId: string, sourceId: int}> signed acknowledgements, for their PDFs
     */
    private function person(array $person, SourceSnapshot $snapshot, TargetCatalogue $catalogue, string $batch, ActorContext $operator): array
    {
        $sourceId = self::int($person['sourceId'] ?? null);
        $email = self::str($person['email'] ?? null);
        $userId = is_int($person['userId'] ?? null) ? $person['userId'] : null;

        if (($person['profileAction'] ?? null) === MigrationPlan::PROFILE_CREATE) {
            /** @var array<string, mixed> $profile */
            $profile = is_array($person['profile'] ?? null) ? $person['profile'] : [];
            $created = $this->people->create($operator, $email, self::str($person['displayName'] ?? null), OnboardingProfileFields::of(
                self::nullableStr($profile['position'] ?? null),
                self::nullableStr($profile['department'] ?? null),
                self::nullableStr($profile['branch'] ?? null),
                self::nullableStr($profile['startDate'] ?? null),
            ));
            $userId = $this->accounts->findUserIdByEmail(EmailAddress::fromString($email))
                ?? throw OnboardingMigrationRefused::because('ACCOUNT_MISSING', 'the account for ' . $email . ' was not found after it was created');
            $this->subjectUserId = $userId;
            if ($created['accountCreated']) {
                $this->record($batch, 'trainees', $sourceId, 'account', ['table' => 'identity.Users', 'id' => $userId]);
            }
            $row = $this->connection->fetchAll(
                'SELECT [TraineeProfileId], LOWER(CONVERT(NVARCHAR(36), [PublicId])) AS [PublicId] FROM [onboarding].[TraineeProfiles] WHERE [UserId] = :userId',
                [':userId' => $userId],
            );
            $this->record($batch, 'trainees', $sourceId, 'profile', [
                'table' => 'onboarding.TraineeProfiles',
                'id' => AzureSqlValueMapper::intValue($row[0]['TraineeProfileId'] ?? 0),
                'publicId' => AzureSqlValueMapper::stringValue($row[0]['PublicId'] ?? ''),
            ], self::hash($snapshot->row('trainees', $sourceId)));
        }
        if ($userId === null) {
            throw OnboardingMigrationRefused::because('NO_USER', $email . ' has no platform account to migrate into');
        }
        $this->subjectUserId = $userId;

        $acknowledged = [];
        foreach (is_array($person['items'] ?? null) ? $person['items'] : [] as $item) {
            if (!is_array($item) || ($item['action'] ?? null) !== MigrationPlan::ITEM_CREATE) {
                continue;
            }
            /** @var array<string, mixed> $item */
            $kind = $item['kind'] ?? null;
            if ($kind === MigrationPlanner::TRAINING) {
                $this->training($item, $userId, $snapshot, $batch, $operator);
            } elseif ($kind === MigrationPlanner::POLICY) {
                $ack = $this->policy($item, $userId, $snapshot, $batch, $operator);
                if ($ack !== null) {
                    $acknowledged[] = $ack;
                }
            } elseif ($kind === MigrationPlanner::FORM) {
                $this->form($item, $userId, $snapshot, $catalogue, $batch, $operator);
            } else {
                throw OnboardingMigrationRefused::because('PLAN_ITEM', 'unknown item kind');
            }
        }

        return $acknowledged;
    }

    // -------------------------------------------------------------- training

    /** @param array<string, mixed> $item */
    private function training(array $item, int $userId, SourceSnapshot $snapshot, string $batch, ActorContext $operator): void
    {
        /** @var list<array<string, mixed>> $attempts */
        $attempts = is_array($item['attempts'] ?? null) ? $item['attempts'] : [];
        $progress = is_array($item['progress'] ?? null) ? $item['progress'] : null;
        $status = self::str($item['status'] ?? null);
        $changedAt = self::nullableStr($item['statusChangedAtUtc'] ?? null);
        $passed = array_values(array_filter($attempts, static fn (array $a): bool => ($a['isPass'] ?? false) === true));
        $lastPass = $passed === [] ? null : $passed[count($passed) - 1];
        $reviewed = $status === 'completed' && ($item['statusSetBy'] ?? null) === 'reviewer';
        $reviewer = $reviewed ? (is_int($lastPass['reviewerUserId'] ?? null) ? $lastPass['reviewerUserId'] : $operator->userId) : null;

        [$assignmentId, $assignmentPublicId] = $this->assignment($item, $userId, 'training', 'TrainingModuleVersionId', $operator, [
            'started' => $status === 'not_started' ? null : (self::nullableStr($attempts[0]['startedAtUtc'] ?? null) ?? self::nullableStr($progress['lastSeenAtUtc'] ?? null) ?? $changedAt),
            'submitted' => in_array($status, ['submitted', 'completed'], true) ? (self::nullableStr($lastPass['submittedAtUtc'] ?? null) ?? $changedAt) : null,
            'completed' => $status === 'completed' ? $changedAt : null,
            'reviewedBy' => $reviewer,
            'reviewedAt' => $reviewed ? (self::nullableStr($lastPass['reviewedAtUtc'] ?? null) ?? $changedAt) : null,
        ], $snapshot, $batch);

        $feedback = self::nullableStr($lastPass['supervisorFeedback'] ?? null);
        $this->statusEvent($batch, $item, 'status', $assignmentPublicId, $userId, null, $status, $this->actorFor($item, $userId, $reviewer ?? $operator->userId), $changedAt, $feedback === null ? null : 'Supervisor feedback: ' . $feedback);

        $versionId = self::int($item['versionId'] ?? null);
        if ($progress !== null) {
            $slides = is_array($progress['slideIds'] ?? null) ? array_values($progress['slideIds']) : [];
            $rows = $this->connection->fetchAll(
                <<<'SQL'
                INSERT INTO [onboarding].[PresentationProgress] ([AssignmentId], [TrainingModuleVersionId], [SlideIdsJson], [ReachedSlideCount], [ReachedFinalSlide], [LastSeenAtUtc])
                OUTPUT INSERTED.[PresentationProgressId] AS [Id]
                VALUES (:assignmentId, :versionId, :slides, :count, :final, :lastSeen)
                SQL,
                [
                    ':assignmentId' => $assignmentId,
                    ':versionId' => $versionId,
                    ':slides' => json_encode($slides, JSON_THROW_ON_ERROR),
                    ':count' => count($slides),
                    ':final' => ($progress['reachedFinal'] ?? false) === true ? 1 : 0,
                    ':lastSeen' => self::sqlTime($progress['lastSeenAtUtc'] ?? null),
                ],
            );
            $this->record($batch, 'presentation_progress', self::int($progress['sourceId'] ?? null), 'progress', ['table' => 'onboarding.PresentationProgress', 'id' => self::id($rows)], self::hash($snapshot->row('presentation_progress', self::int($progress['sourceId'] ?? null))));
        }

        $snapshotJson = json_encode($this->questionSnapshot($versionId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($attempts as $attempt) {
            $attemptSourceId = self::int($attempt['sourceId'] ?? null);
            $rows = $this->connection->fetchAll(
                <<<'SQL'
                INSERT INTO [onboarding].[Attempts] ([AssignmentId], [AttemptNumber], [QuestionSnapshotJson], [StartedAtUtc], [SubmittedAtUtc], [ScorePercent], [IsPass])
                OUTPUT INSERTED.[AttemptId] AS [Id], LOWER(CONVERT(NVARCHAR(36), INSERTED.[PublicId])) AS [PublicId]
                VALUES (:assignmentId, :number, :snapshot, :started, :submitted, :score, :pass)
                SQL,
                [
                    ':assignmentId' => $assignmentId,
                    ':number' => self::int($attempt['number'] ?? null),
                    ':snapshot' => $snapshotJson,
                    ':started' => self::sqlTime($attempt['startedAtUtc'] ?? null),
                    ':submitted' => self::sqlTime($attempt['submittedAtUtc'] ?? null),
                    ':score' => self::int($attempt['scorePercent'] ?? null),
                    ':pass' => ($attempt['isPass'] ?? false) === true ? 1 : 0,
                ],
            );
            $attemptId = self::id($rows);
            $attemptPublicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
            $this->record($batch, 'attempts', $attemptSourceId, 'attempt', ['table' => 'onboarding.Attempts', 'id' => $attemptId, 'publicId' => $attemptPublicId], self::hash($snapshot->row('attempts', $attemptSourceId)));

            foreach (is_array($attempt['answers'] ?? null) ? $attempt['answers'] : [] as $answer) {
                if (!is_array($answer)) {
                    continue;
                }
                $answerRows = $this->connection->fetchAll(
                    <<<'SQL'
                    INSERT INTO [onboarding].[AttemptAnswers] ([AttemptId], [QuestionId], [Ordinal], [SelectedOptionKey], [IsCorrect], [AnsweredAtUtc])
                    OUTPUT INSERTED.[AttemptAnswerId] AS [Id]
                    VALUES (:attemptId, :questionId, :ordinal, :selected, :correct, :answeredAt)
                    SQL,
                    [
                        ':attemptId' => $attemptId,
                        ':questionId' => self::int($answer['questionId'] ?? null),
                        ':ordinal' => self::int($answer['ordinal'] ?? null),
                        ':selected' => self::nullableStr($answer['selectedOptionKey'] ?? null),
                        ':correct' => ($answer['isCorrect'] ?? false) === true ? 1 : 0,
                        ':answeredAt' => self::sqlTime($attempt['submittedAtUtc'] ?? null),
                    ],
                );
                $this->record($batch, 'attempts', $attemptSourceId, 'answer:' . self::int($answer['ordinal'] ?? null), ['table' => 'onboarding.AttemptAnswers', 'id' => self::id($answerRows)]);
            }

            foreach (is_array($attempt['documents'] ?? null) ? $attempt['documents'] : [] as $document) {
                if (!is_array($document)) {
                    continue;
                }
                $signedOff = ($document['column'] ?? null) === 'reviewed_pdf_base64';
                $this->document($document, $snapshot, $batch, $userId, $assignmentId, null, DataClass::workflow(), sprintf('%d/attempt-%s%s.pdf', $userId, $attemptPublicId, $signedOff ? '-signed-off' : ''));
            }
        }
    }

    /**
     * The attempt's question snapshot, in the shape the platform writes when an
     * attempt starts ({@see AssessmentQuestion::toSnapshot()}).
     *
     * @return list<array<string, mixed>>
     */
    private function questionSnapshot(int $versionId): array
    {
        $this->questionRows[$versionId] ??= $this->connection->fetchAll(
            'SELECT LOWER(CONVERT(NVARCHAR(36), [PublicId])) AS [PublicId], [Ordinal], [Prompt], [OptionsJson], [CorrectOptionKey], [CorrectReason] FROM [onboarding].[Questions] WHERE [TrainingModuleVersionId] = :versionId ORDER BY [Ordinal]',
            [':versionId' => $versionId],
        );

        return array_map(static function (array $row): array {
            $options = [];
            $decoded = json_decode(AzureSqlValueMapper::stringValue($row['OptionsJson'] ?? '[]'), true);
            foreach (is_array($decoded) ? $decoded : [] as $option) {
                if (is_array($option) && is_string($option['key'] ?? null)) {
                    $options[$option['key']] = is_string($option['label'] ?? null) ? $option['label'] : '';
                }
            }

            return AssessmentQuestion::of(
                AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                AzureSqlValueMapper::intValue($row['Ordinal'] ?? 0),
                AzureSqlValueMapper::stringValue($row['Prompt'] ?? ''),
                $options,
                AzureSqlValueMapper::stringValue($row['CorrectOptionKey'] ?? ''),
                AzureSqlValueMapper::nullableString($row['CorrectReason'] ?? null),
            )->toSnapshot();
        }, $this->questionRows[$versionId]);
    }

    // ---------------------------------------------------------------- policy

    /**
     * @param array<string, mixed> $item
     *
     * @return array{userId: int, assignmentPublicId: string, sourceId: int}|null a signed acknowledgement needing its PDF
     */
    private function policy(array $item, int $userId, SourceSnapshot $snapshot, string $batch, ActorContext $operator): ?array
    {
        $ack = is_array($item['acknowledgement'] ?? null) ? $item['acknowledgement'] : null;
        $status = self::str($item['status'] ?? null);
        [$assignmentId, $assignmentPublicId] = $this->assignment($item, $userId, 'policy', 'PolicyVersionId', $operator, [
            'started' => self::nullableStr($item['startedAtUtc'] ?? null) ?? ($status === 'not_started' ? null : self::nullableStr($item['statusChangedAtUtc'] ?? null)),
            'submitted' => null,
            'completed' => $status === 'completed' ? self::nullableStr($ack['acknowledgedAtUtc'] ?? null) : null,
            'reviewedBy' => null,
            'reviewedAt' => null,
        ], $snapshot, $batch);
        $this->statusEvent($batch, $item, 'status', $assignmentPublicId, $userId, null, $status, $this->actorFor($item, $userId, $operator->userId), self::nullableStr($item['statusChangedAtUtc'] ?? null), null);

        if ($ack === null) {
            return null;
        }
        $versionId = self::int($item['versionId'] ?? null);
        $content = $this->connection->fetchAll(
            'SELECT p.[Title], pv.[ContentJson] FROM [onboarding].[PolicyVersions] pv JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId] WHERE pv.[PolicyVersionId] = :versionId',
            [':versionId' => $versionId],
        );
        $declarationText = PolicyContent::fromJson(AzureSqlValueMapper::stringValue($content[0]['ContentJson'] ?? '[]'), AzureSqlValueMapper::stringValue($content[0]['Title'] ?? ''))->declaration();
        $kind = self::nullableStr($ack['declarationKind'] ?? null);
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            INSERT INTO [onboarding].[PolicyAcknowledgements] ([AssignmentId], [UserId], [PolicyVersionId], [AcknowledgedAtUtc], [DeclarationText], [DeclarationJson])
            OUTPUT INSERTED.[PolicyAcknowledgementId] AS [Id], LOWER(CONVERT(NVARCHAR(36), INSERTED.[PublicId])) AS [PublicId]
            VALUES (:assignmentId, :userId, :versionId, :at, :text, :declaration)
            SQL,
            [
                ':assignmentId' => $assignmentId,
                ':userId' => $userId,
                ':versionId' => $versionId,
                ':at' => self::sqlTime($ack['acknowledgedAtUtc'] ?? null),
                ':text' => $declarationText,
                ':declaration' => $kind === null ? null : json_encode(['kind' => $kind, 'values' => $ack['declaration'] ?? []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        );
        $ackSourceId = self::int($ack['sourceId'] ?? null);
        $this->record($batch, 'policy_acknowledgements', $ackSourceId, 'acknowledgement', ['table' => 'onboarding.PolicyAcknowledgements', 'id' => self::id($rows), 'publicId' => AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '')], self::hash($snapshot->row('policy_acknowledgements', $ackSourceId)));

        return $kind === null ? null : ['userId' => $userId, 'assignmentPublicId' => $assignmentPublicId, 'sourceId' => $ackSourceId];
    }

    /** @param array{userId: int, assignmentPublicId: string, sourceId: int} $ack */
    private function policyDocument(array $ack, string $batch, ActorContext $operator): int
    {
        if ($this->policyDocuments === null) {
            return 0;
        }
        $this->subjectUserId = $ack['userId'];
        try {
            $documentPublicId = $this->policyDocuments->generate($ack['userId'], $ack['assignmentPublicId']);
        } catch (Throwable $e) {
            $this->ledger->log($batch, $operator, 'execute.policy-document', 'failed', null, null, ['sourceId' => $ack['sourceId'], 'error' => substr($e->getMessage(), 0, 300)]);

            return 0;
        }
        if ($documentPublicId === null) {
            return 0;
        }
        $row = $this->connection->fetchAll(
            'SELECT [DocumentReferenceId], [BlobContainer], [BlobPath] FROM [onboarding].[DocumentReferences] WHERE [PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :id)',
            [':id' => $documentPublicId],
        );
        $this->record($batch, 'policy_acknowledgements', $ack['sourceId'], 'document:policy_declaration', [
            'table' => 'onboarding.DocumentReferences',
            'id' => AzureSqlValueMapper::intValue($row[0]['DocumentReferenceId'] ?? 0),
            'publicId' => $documentPublicId,
            'container' => AzureSqlValueMapper::stringValue($row[0]['BlobContainer'] ?? ''),
            'path' => AzureSqlValueMapper::stringValue($row[0]['BlobPath'] ?? ''),
        ]);

        return 1;
    }

    // ------------------------------------------------------------------ form

    /** @param array<string, mixed> $item */
    private function form(array $item, int $userId, SourceSnapshot $snapshot, TargetCatalogue $catalogue, string $batch, ActorContext $operator): void
    {
        $slug = self::str($item['slug'] ?? null);
        $target = $catalogue->form($slug) ?? throw OnboardingMigrationRefused::because('PLAN_ITEM', 'form ' . $slug . ' is no longer published');
        /** @var list<array<string, mixed>> $submissions */
        $submissions = is_array($item['submissions'] ?? null) ? array_values(array_filter($item['submissions'], 'is_array')) : [];
        $status = self::str($item['status'] ?? null);
        $changedAt = self::nullableStr($item['statusChangedAtUtc'] ?? null);
        $last = $submissions === [] ? null : $submissions[count($submissions) - 1];
        $reviewed = array_values(array_filter($submissions, static fn (array $s): bool => isset($s['reviewedAtUtc'])));
        $lastReview = $reviewed === [] ? null : $reviewed[count($reviewed) - 1];
        $draft = is_array($item['draft'] ?? null) ? $item['draft'] : null;

        [$assignmentId, $assignmentPublicId] = $this->assignment($item, $userId, 'form', 'FormDefinitionVersionId', $operator, [
            'started' => $status === 'not_started' ? null : (self::nullableStr($submissions[0]['submittedAtUtc'] ?? null) ?? self::nullableStr($draft['savedAtUtc'] ?? null) ?? $changedAt),
            'submitted' => self::nullableStr($last['submittedAtUtc'] ?? null),
            'completed' => $status === 'completed' ? $changedAt : null,
            'reviewedBy' => $lastReview === null ? null : (is_int($lastReview['reviewerUserId'] ?? null) ? $lastReview['reviewerUserId'] : $operator->userId),
            'reviewedAt' => self::nullableStr($lastReview['reviewedAtUtc'] ?? null),
        ], $snapshot, $batch);

        // The history as events: each hand-in, each review, then where it stands.
        $n = 0;
        $current = null;
        foreach ($submissions as $submission) {
            $this->statusEvent($batch, $item, 'status:' . ++$n, $assignmentPublicId, $userId, $current, 'submitted', ['trainee', $userId], self::nullableStr($submission['submittedAtUtc'] ?? null), 'Revision ' . self::int($submission['revision'] ?? null) . ' handed in.');
            $current = 'submitted';
            $outcome = self::str($submission['reviewOutcome'] ?? null);
            if (($submission['reviewedAtUtc'] ?? null) !== null && in_array($outcome, ['fix_and_resubmit', 'completed'], true)) {
                $reviewer = is_int($submission['reviewerUserId'] ?? null) ? $submission['reviewerUserId'] : $operator->userId;
                $this->statusEvent($batch, $item, 'status:' . ++$n, $assignmentPublicId, $userId, $current, $outcome, ['reviewer', $reviewer], self::nullableStr($submission['reviewedAtUtc']), self::nullableStr($submission['reviewNote'] ?? null));
                $current = $outcome;
            }
        }
        if ($current !== $status) {
            $this->statusEvent($batch, $item, 'status:' . ++$n, $assignmentPublicId, $userId, $current, $status, $this->actorFor($item, $userId, $operator->userId), $changedAt, null);
        }

        $documentClass = match ($target['accessGroup']) {
            'payroll' => DataClass::payroll(),
            'restricted_hr' => DataClass::restrictedHr(),
            default => DataClass::ordinaryHr(),
        };
        foreach ($submissions as $submission) {
            /** @var array<string, mixed> $answers */
            $answers = is_array($submission['answers'] ?? null) ? $submission['answers'] : [];
            $split = FormPayloadSplitter::split($answers, $target['classifications']);
            $rows = $this->connection->fetchAll(
                <<<'SQL'
                INSERT INTO [onboarding].[FormSubmissions] ([AssignmentId], [UserId], [FormDefinitionVersionId], [RevisionNumber], [SubmittedDataJson], [HasRestrictedData], [SubmittedAtUtc])
                OUTPUT INSERTED.[FormSubmissionId] AS [Id], LOWER(CONVERT(NVARCHAR(36), INSERTED.[PublicId])) AS [PublicId]
                VALUES (:assignmentId, :userId, :versionId, :revision, :data, :restricted, :at)
                SQL,
                [
                    ':assignmentId' => $assignmentId,
                    ':userId' => $userId,
                    ':versionId' => self::int($item['versionId'] ?? null),
                    ':revision' => self::int($submission['revision'] ?? null),
                    ':data' => json_encode((object) $split['ordinary'], JSON_THROW_ON_ERROR),
                    ':restricted' => FormPayloadSplitter::hasRestrictedData($split) ? 1 : 0,
                    ':at' => self::sqlTime($submission['submittedAtUtc'] ?? null),
                ],
            );
            $submissionId = self::id($rows);
            $submissionPublicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
            $submissionSourceId = self::int($submission['sourceId'] ?? null);
            $this->record($batch, 'form_submissions', $submissionSourceId, 'submission', ['table' => 'onboarding.FormSubmissions', 'id' => $submissionId, 'publicId' => $submissionPublicId], self::hash($snapshot->row('form_submissions', $submissionSourceId)));

            foreach ([DataClass::PAYROLL => $split['payroll'], DataClass::RESTRICTED_HR => $split['restricted']] as $classId => $fields) {
                if ($fields === []) {
                    continue;
                }
                $restricted = $this->connection->fetchAll(
                    <<<'SQL'
                    INSERT INTO [onboarding_hr].[RestrictedSubmissionData] ([FormSubmissionPublicId], [UserId], [RetentionClassId], [PayloadJson], [FieldCount], [WrittenAtUtc])
                    OUTPUT INSERTED.[RestrictedSubmissionDataId] AS [Id]
                    VALUES (CONVERT(UNIQUEIDENTIFIER, :submission), :userId, :classId, :payload, :count, :at)
                    SQL,
                    [
                        ':submission' => $submissionPublicId,
                        ':userId' => $userId,
                        ':classId' => $classId,
                        ':payload' => json_encode((object) $fields, JSON_THROW_ON_ERROR),
                        ':count' => count($fields),
                        ':at' => self::sqlTime($submission['submittedAtUtc'] ?? null),
                    ],
                );
                $this->record($batch, 'form_submissions', $submissionSourceId, 'restricted:' . $classId, ['table' => 'onboarding_hr.RestrictedSubmissionData', 'id' => self::id($restricted)]);
            }

            foreach (is_array($submission['documents'] ?? null) ? $submission['documents'] : [] as $document) {
                if (is_array($document)) {
                    $this->document($document, $snapshot, $batch, $userId, $assignmentId, $submissionId, $documentClass, sprintf('%d/%s.pdf', $userId, self::uuid()));
                }
            }
        }

        if ($draft !== null) {
            /** @var array<string, mixed> $draftAnswers */
            $draftAnswers = is_array($draft['answers'] ?? null) ? $draft['answers'] : [];
            $rows = $this->connection->fetchAll(
                'INSERT INTO [onboarding].[FormDrafts] ([AssignmentId], [DraftDataJson], [UpdatedAtUtc]) OUTPUT INSERTED.[FormDraftId] AS [Id] VALUES (:assignmentId, :draft, :at)',
                [
                    ':assignmentId' => $assignmentId,
                    ':draft' => json_encode((object) FormPayloadSplitter::nest($draftAnswers), JSON_THROW_ON_ERROR),
                    ':at' => self::sqlTime($draft['savedAtUtc'] ?? null),
                ],
            );
            $this->record($batch, 'form_assignments', self::int($item['sourceId'] ?? null), 'draft', ['table' => 'onboarding.FormDrafts', 'id' => self::id($rows)]);
        }

        /** @var array<string, array<string, mixed>> $contributions */
        $contributions = is_array($item['contributions'] ?? null) ? $item['contributions'] : [];
        $photos = is_array($item['photos'] ?? null) ? $item['photos'] : [];
        if ($photos !== []) {
            $contributions['hr'] = ($contributions['hr'] ?? []) + ['photos' => $this->photos($item, $photos, $slug, $snapshot, $batch, $userId, $assignmentId)];
        }
        foreach ($contributions as $role => $answers) {
            $rows = $this->connection->fetchAll(
                'INSERT INTO [onboarding].[FormContributions] ([AssignmentId], [Role], [ContributorUserId], [AnswersJson], [ContributedAtUtc]) OUTPUT INSERTED.[FormContributionId] AS [Id] VALUES (:assignmentId, :role, :contributor, :answers, :at)',
                [
                    ':assignmentId' => $assignmentId,
                    ':role' => $role,
                    // The source did not record who filled each part; the operator
                    // who ran the migration is who put it here.
                    ':contributor' => $operator->userId,
                    ':answers' => json_encode((object) $answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':at' => self::sqlTime($changedAt),
                ],
            );
            $this->record($batch, 'form_assignments', self::int($item['sourceId'] ?? null), 'contribution:' . $role, ['table' => 'onboarding.FormContributions', 'id' => self::id($rows)]);
        }
    }

    /**
     * The vehicle report's condition photos: each stored as the platform
     * stores an uploaded one, named in the HR part by document id.
     *
     * @param array<string, mixed> $item
     * @param array<array-key, mixed> $photos
     *
     * @return list<string> document public ids
     */
    private function photos(array $item, array $photos, string $slug, SourceSnapshot $snapshot, string $batch, int $userId, int $assignmentId): array
    {
        $source = $snapshot->row('form_assignments', self::int($item['sourceId'] ?? null));
        $translated = SourceFormTranslator::translate($slug, is_array($source['draft_data'] ?? null) ? $source['draft_data'] : []);
        $bytesById = [];
        foreach ($translated->photos as $photo) {
            $bytesById[$photo['sourceId']] = (string) base64_decode($photo['base64'], true);
        }
        $ids = [];
        foreach ($photos as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $bytes = $bytesById[self::str($photo['sourceId'] ?? null)] ?? '';
            if ($bytes === '' || hash('sha256', $bytes) !== ($photo['sha256'] ?? null)) {
                throw OnboardingMigrationRefused::because('PHOTO_CHANGED', 'a vehicle photo no longer matches the previewed one');
            }
            $ids[] = $this->storeDocument($batch, 'form_assignments', self::int($item['sourceId'] ?? null), 'photo:' . self::str($photo['sourceId'] ?? null), $userId, $assignmentId, null, 'photo', 'image/jpeg', $bytes, DataClass::ordinaryHr(), sprintf('%d/photo-%s.jpg', $userId, self::uuid()), null);
        }

        return $ids;
    }

    // ---------------------------------------------------------------- shared

    /**
     * @param array<string, mixed> $item
     * @param array{started: ?string, submitted: ?string, completed: ?string, reviewedBy: ?int, reviewedAt: ?string} $times
     *
     * @return array{0: int, 1: string} assignment id and public id
     */
    private function assignment(array $item, int $userId, string $type, string $versionColumn, ActorContext $operator, array $times, SourceSnapshot $snapshot, string $batch): array
    {
        $rows = $this->connection->fetchAll(
            sprintf(
                <<<'SQL'
                INSERT INTO [onboarding].[Assignments]
                    ([UserId], [ItemType], [%s], [Status], [AllocationSource], [AllocatedByUserId], [AllocatedAtUtc], [DueDate],
                     [StartedAtUtc], [SubmittedAtUtc], [CompletedAtUtc], [ReviewedByUserId], [ReviewedAtUtc])
                OUTPUT INSERTED.[AssignmentId] AS [Id], LOWER(CONVERT(NVARCHAR(36), INSERTED.[PublicId])) AS [PublicId]
                VALUES (:userId, :type, :versionId, :status, N'explicit', :allocatedBy, :allocatedAt, :due, :started, :submitted, :completed, :reviewedBy, :reviewedAt)
                SQL,
                $versionColumn,
            ),
            [
                ':userId' => $userId,
                ':type' => $type,
                ':versionId' => self::int($item['versionId'] ?? null),
                ':status' => self::str($item['status'] ?? null),
                // The source kept no allocator; the operator allocated it here.
                ':allocatedBy' => $operator->userId,
                ':allocatedAt' => self::sqlTime($item['allocatedAtUtc'] ?? null),
                ':due' => self::nullableStr($item['dueDate'] ?? null),
                ':started' => self::sqlTime($times['started']),
                ':submitted' => self::sqlTime($times['submitted']),
                ':completed' => self::sqlTime($times['completed']),
                ':reviewedBy' => $times['reviewedBy'],
                ':reviewedAt' => self::sqlTime($times['reviewedAt']),
            ],
        );
        $sourceTable = self::str($item['sourceTable'] ?? null);
        $sourceId = self::int($item['sourceId'] ?? null);
        $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
        $this->record($batch, $sourceTable, $sourceId, 'assignment', ['table' => 'onboarding.Assignments', 'id' => self::id($rows), 'publicId' => $publicId], self::hash($snapshot->row($sourceTable, $sourceId)));

        return [self::id($rows), $publicId];
    }

    /**
     * @param array<string, mixed> $item
     * @param array{0: string, 1: ?int} $actor type and user
     */
    private function statusEvent(string $batch, array $item, string $role, string $assignmentPublicId, int $userId, ?string $from, string $to, array $actor, ?string $at, ?string $note): void
    {
        $source = sprintf('%s (batch %s, %s#%d)', self::SOURCE_NOTE, substr($batch, 0, 8), self::str($item['sourceTable'] ?? null), self::int($item['sourceId'] ?? null));
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            INSERT INTO [audit].[OnboardingStatusEvents] ([AssignmentPublicId], [SubjectUserId], [ActorUserId], [ActorType], [FromStatus], [ToStatus], [Note], [CreatedAtUtc])
            OUTPUT INSERTED.[OnboardingStatusEventId] AS [Id]
            VALUES (CONVERT(UNIQUEIDENTIFIER, :assignment), :subject, :actorUser, :actorType, :from, :to, :note, COALESCE(TODATETIMEOFFSET(:at, '+00:00'), SYSDATETIMEOFFSET()))
            SQL,
            [
                ':assignment' => $assignmentPublicId,
                ':subject' => $userId,
                ':actorUser' => $actor[0] === 'system' ? null : $actor[1],
                ':actorType' => $actor[0],
                ':from' => $from,
                ':to' => $to,
                ':note' => $note === null ? $source . '.' : $source . ': ' . $note,
                ':at' => self::sqlTime($at),
            ],
        );
        $this->record($batch, self::str($item['sourceTable'] ?? null), self::int($item['sourceId'] ?? null), $role, ['table' => 'audit.OnboardingStatusEvents', 'id' => self::id($rows)]);
    }

    /**
     * Who the source said set the status, as the audit table's attribution rule
     * wants it: a person's action names the person; the system's names nobody.
     *
     * @param array<string, mixed> $item
     *
     * @return array{0: string, 1: ?int}
     */
    private function actorFor(array $item, int $subjectUserId, int $reviewerUserId): array
    {
        return match ($item['statusSetBy'] ?? 'system') {
            'trainee' => ['trainee', $subjectUserId],
            'reviewer' => ['reviewer', $reviewerUserId],
            default => ['system', null],
        };
    }

    /** @param array<array-key, mixed> $document */
    private function document(array $document, SourceSnapshot $snapshot, string $batch, int $userId, int $assignmentId, ?int $submissionId, DataClass $class, string $path): void
    {
        $sourceTable = self::str($document['sourceTable'] ?? null);
        $sourceId = self::int($document['sourceId'] ?? null);
        $column = self::str($document['column'] ?? null);
        $row = $snapshot->row($sourceTable, $sourceId);
        $bytes = (string) base64_decode(self::str($row[$column] ?? null), true);
        if (hash('sha256', $bytes) !== ($document['sha256'] ?? null)) {
            throw OnboardingMigrationRefused::because('DOCUMENT_CHANGED', $sourceTable . '#' . $sourceId . ' ' . $column . ' no longer matches the previewed document');
        }
        $generatedColumn = $column === 'reviewed_pdf_base64' ? 'reviewed_pdf_generated_at' : 'pdf_generated_at';
        $this->storeDocument($batch, $sourceTable, $sourceId, 'document:' . $column, $userId, $assignmentId, $submissionId, self::str($document['kind'] ?? null), 'application/pdf', $bytes, $class, $path, self::nullableStr($row[$generatedColumn] ?? null));
    }

    /** @return string the document's public id */
    private function storeDocument(string $batch, string $sourceTable, int $sourceId, string $role, int $userId, int $assignmentId, ?int $submissionId, string $kind, string $contentType, string $bytes, DataClass $class, string $path, ?string $generatedAt): string
    {
        $this->blobs->putObject($this->container, $path, $contentType, $bytes);
        $this->uploaded[] = ['container' => $this->container, 'path' => $path];
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            INSERT INTO [onboarding].[DocumentReferences]
                ([UserId], [AssignmentId], [FormSubmissionId], [DocumentKind], [RetentionClassId], [BlobContainer], [BlobPath], [ContentType], [ByteSize], [ContentSha256], [GeneratedAtUtc])
            OUTPUT INSERTED.[DocumentReferenceId] AS [Id], LOWER(CONVERT(NVARCHAR(36), INSERTED.[PublicId])) AS [PublicId]
            VALUES (:userId, :assignmentId, :submissionId, :kind, :class, :container, :path, :contentType, :size, :sha, COALESCE(:generatedAt, SYSUTCDATETIME()))
            SQL,
            [
                ':userId' => $userId,
                ':assignmentId' => $assignmentId,
                ':submissionId' => $submissionId,
                ':kind' => $kind,
                ':class' => $class->toInt(),
                ':container' => $this->container,
                ':path' => $path,
                ':contentType' => $contentType,
                ':size' => strlen($bytes),
                ':sha' => hash('sha256', $bytes),
                ':generatedAt' => self::sqlTime($generatedAt),
            ],
        );
        $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
        $this->record($batch, $sourceTable, $sourceId, $role, ['table' => 'onboarding.DocumentReferences', 'id' => self::id($rows), 'publicId' => $publicId, 'container' => $this->container, 'path' => $path], hash('sha256', $bytes));

        return $publicId;
    }

    /**
     * The blobs written for the person whose transaction just failed. A method,
     * because they are collected by the methods the transaction called.
     *
     * @return list<array{container: string, path: string}>
     */
    private function uploadedForPerson(): array
    {
        return $this->uploaded;
    }

    /**
     * @param array{table: string, id?: int|null, publicId?: string|null, container?: string|null, path?: string|null} $target
     */
    private function record(string $batch, string $sourceTable, int $sourceId, string $role, array $target, ?string $sourceHash = null): void
    {
        $this->ledger->record($batch, $this->subjectUserId, $sourceTable, $sourceId, $role, $target, $sourceHash);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function id(array $rows): int
    {
        return AzureSqlValueMapper::intValue($rows[0]['Id'] ?? 0);
    }

    /**
     * SHA-256 of a source row, for verification's per-record comparison.
     *
     * @param array<string, mixed>|null $row
     */
    public static function hash(?array $row): ?string
    {
        return $row === null ? null : hash('sha256', (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** `2026-08-01T00:00:00.000000Z` → `2026-08-01 00:00:00.000000`, which DATETIME2 takes. */
    private static function sqlTime(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2}(?:\.\d+)?)Z$/', $value, $m) === 1 ? $m[1] . ' ' . $m[2] : null;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableStr(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
