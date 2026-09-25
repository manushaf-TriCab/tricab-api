<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter;
use TriCab\Core\Onboarding\Migration\Plan\MigrationPlan;
use TriCab\Core\Onboarding\Migration\Plan\MigrationPlanner;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshot;
use TriCab\Core\Onboarding\Migration\Target\TargetCatalogue;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Reconciles a batch against the plan that was approved for it (Phase 7.6).
 * Read-only.
 *
 *  1. **Counts** — what the plan said it would create, per kind, against what
 *     the ledger recorded.
 *  2. **Existence** — every recorded row is in its table; every recorded blob
 *     is in its container with the recorded size and SHA-256.
 *  3. **Source hashes** — each recorded source hash is recomputed from the
 *     snapshot: the row migrated is the row that was previewed.
 *  4. **Content** — assignments, attempts, answers, progress, acknowledgements,
 *     submissions (ordinary and restricted halves together) read back and
 *     compared with the plan.
 *  5. **Referential integrity** — answers belong to their attempt's version;
 *     every assignment's person has an induction record; documents belong to
 *     the person and assignment they name.
 *
 * After a rollback the same report says what is left: nothing but the audit
 * rows, which are append-only by design.
 */
final readonly class AzureSqlOnboardingMigrationVerifier
{
    private const TABLES = [
        'identity.Users' => ['[identity].[Users]', 'UserId'],
        'onboarding.TraineeProfiles' => ['[onboarding].[TraineeProfiles]', 'TraineeProfileId'],
        'onboarding.Assignments' => ['[onboarding].[Assignments]', 'AssignmentId'],
        'onboarding.PresentationProgress' => ['[onboarding].[PresentationProgress]', 'PresentationProgressId'],
        'onboarding.Attempts' => ['[onboarding].[Attempts]', 'AttemptId'],
        'onboarding.AttemptAnswers' => ['[onboarding].[AttemptAnswers]', 'AttemptAnswerId'],
        'onboarding.PolicyAcknowledgements' => ['[onboarding].[PolicyAcknowledgements]', 'PolicyAcknowledgementId'],
        'onboarding.FormSubmissions' => ['[onboarding].[FormSubmissions]', 'FormSubmissionId'],
        'onboarding_hr.RestrictedSubmissionData' => ['[onboarding_hr].[RestrictedSubmissionData]', 'RestrictedSubmissionDataId'],
        'onboarding.FormDrafts' => ['[onboarding].[FormDrafts]', 'FormDraftId'],
        'onboarding.FormContributions' => ['[onboarding].[FormContributions]', 'FormContributionId'],
        'onboarding.DocumentReferences' => ['[onboarding].[DocumentReferences]', 'DocumentReferenceId'],
        'audit.OnboardingStatusEvents' => ['[audit].[OnboardingStatusEvents]', 'OnboardingStatusEventId'],
    ];

    public function __construct(
        private SqlConnection $connection,
        private AzureSqlOnboardingMigrationLedger $ledger,
        private ProductBlobStore $blobs,
    ) {
    }

    /**
     * @return array{ok: bool, counts: array<string, array{expected: int, actual: int}>, extra: array<string, int>, problems: list<string>}
     */
    public function verify(string $batch, MigrationPlan $plan, SourceSnapshot $snapshot, TargetCatalogue $catalogue): array
    {
        $rows = $this->ledger->rows($batch);
        $problems = [];

        // 1. counts
        $actual = array_fill_keys(array_keys($plan->counts()['create']), 0);
        $extra = ['policyDocuments' => 0];
        foreach ($rows as $row) {
            $kind = self::kindOf($row['role'], $row['sourceTable']);
            if ($kind === 'policyDocuments') {
                ++$extra['policyDocuments'];
            } elseif ($kind !== null) {
                $actual[$kind] = ($actual[$kind] ?? 0) + 1;
            }
        }
        $counts = [];
        foreach ($plan->counts()['create'] as $kind => $expected) {
            $counts[$kind] = ['expected' => $expected, 'actual' => $actual[$kind] ?? 0];
            if ($expected !== ($actual[$kind] ?? 0)) {
                $problems[] = sprintf('count %s: plan %d, ledger %d', $kind, $expected, $actual[$kind] ?? 0);
            }
        }

        // 2. existence, and 3. source hashes
        $byTable = [];
        foreach ($rows as $row) {
            if ($row['targetId'] !== null) {
                $byTable[$row['targetTable']][] = $row['targetId'];
            }
            if ($row['sourceHash'] !== null && !str_starts_with($row['role'], 'document') && !str_starts_with($row['role'], 'photo')) {
                $expectedHash = AzureSqlOnboardingMigrationExecutor::hash($snapshot->row($row['sourceTable'], $row['sourceId']));
                if ($expectedHash !== $row['sourceHash']) {
                    $problems[] = sprintf('%s#%d: the migrated row is not the snapshot row (source hash differs)', $row['sourceTable'], $row['sourceId']);
                }
            }
            if ($row['container'] !== null && $row['path'] !== null) {
                try {
                    $bytes = $this->blobs->getObjectBinary($row['container'], $row['path']);
                } catch (Throwable) {
                    $bytes = null;
                }
                if ($bytes === null) {
                    $problems[] = sprintf('blob %s/%s is missing', $row['container'], $row['path']);
                } elseif ($row['sourceHash'] !== null && hash('sha256', $bytes) !== $row['sourceHash']) {
                    $problems[] = sprintf('blob %s/%s does not match the recorded SHA-256', $row['container'], $row['path']);
                }
            }
        }
        foreach ($byTable as $table => $ids) {
            if (!isset(self::TABLES[$table])) {
                $problems[] = 'unknown target table ' . $table;
                continue;
            }
            [$sqlTable, $key] = self::TABLES[$table];
            $found = $this->count($sqlTable, $key, $ids);
            if ($found !== count(array_unique($ids))) {
                $problems[] = sprintf('%s: %d recorded, %d present', $table, count(array_unique($ids)), $found);
            }
        }

        // 4. content, and 5. referential integrity
        array_push($problems, ...$this->content($rows, $plan, $catalogue), ...$this->integrity($batch));

        return ['ok' => $problems === [], 'counts' => $counts, 'extra' => $extra, 'problems' => $problems];
    }

    /**
     * After a rollback: what the batch created that is still there, apart from
     * the audit rows the platform keeps for ever.
     *
     * @return array{ok: bool, remaining: array<string, int>, auditRowsKept: int, accountsStillActive: int}
     */
    public function verifyRolledBack(string $batch): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [TargetTable], [TargetId] FROM [migration].[OnboardingMigrationRows] WHERE [BatchPublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :batch) AND [TargetId] IS NOT NULL',
            [':batch' => $batch],
        );
        $byTable = [];
        foreach ($rows as $row) {
            $byTable[AzureSqlValueMapper::stringValue($row['TargetTable'] ?? '')][] = AzureSqlValueMapper::intValue($row['TargetId'] ?? 0);
        }
        $remaining = [];
        $audit = 0;
        $active = 0;
        foreach ($byTable as $table => $ids) {
            if (!isset(self::TABLES[$table])) {
                continue;
            }
            [$sqlTable, $key] = self::TABLES[$table];
            if ($table === 'audit.OnboardingStatusEvents') {
                $audit += $this->count($sqlTable, $key, $ids);
                continue;
            }
            if ($table === 'identity.Users') {
                $active += count($this->select(
                    "SELECT u.[UserId] FROM [identity].[Users] u JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId] WHERE s.[StatusCode] = N'active' AND u.[UserId] IN (%s)",
                    $ids,
                ));
                continue;
            }
            $left = $this->count($sqlTable, $key, $ids);
            if ($left > 0) {
                $remaining[$table] = $left;
            }
        }

        return ['ok' => $remaining === [] && $active === 0, 'remaining' => $remaining, 'auditRowsKept' => $audit, 'accountsStillActive' => $active];
    }

    /**
     * @param list<array{rowId: int, sourceTable: string, sourceId: int, subjectUserId: int, role: string, targetTable: string, targetId: ?int, targetPublicId: ?string, container: ?string, path: ?string, sourceHash: ?string, createdAtUtc: string}> $rows
     *
     * @return list<string>
     */
    private function content(array $rows, MigrationPlan $plan, TargetCatalogue $catalogue): array
    {
        $target = [];
        foreach ($rows as $row) {
            $target[$row['sourceTable'] . '#' . $row['sourceId'] . '#' . $row['role']] = $row['targetId'];
        }
        $problems = [];
        foreach ($plan->people as $person) {
            foreach (is_array($person['items'] ?? null) ? $person['items'] : [] as $item) {
                if (!is_array($item) || ($item['action'] ?? null) !== MigrationPlan::ITEM_CREATE || !in_array($person['action'] ?? null, [MigrationPlan::PERSON_LINK, MigrationPlan::PERSON_CREATE_ACCOUNT], true)) {
                    continue;
                }
                $subject = self::str($item['sourceTable'] ?? null) . '#' . self::int($item['sourceId'] ?? null);
                $assignmentId = $target[$subject . '#assignment'] ?? null;
                if ($assignmentId === null) {
                    $problems[] = $subject . ': no assignment recorded';
                    continue;
                }
                $a = $this->connection->fetchAll(
                    'SELECT [Status], COALESCE([TrainingModuleVersionId], [PolicyVersionId], [FormDefinitionVersionId]) AS [VersionId], CONVERT(VARCHAR(27), [AllocatedAtUtc], 126) AS [AllocatedAt] FROM [onboarding].[Assignments] WHERE [AssignmentId] = :id',
                    [':id' => $assignmentId],
                );
                if ($a === []) {
                    continue;
                }
                if (AzureSqlValueMapper::stringValue($a[0]['Status'] ?? '') !== ($item['status'] ?? null) || AzureSqlValueMapper::intValue($a[0]['VersionId'] ?? 0) !== ($item['versionId'] ?? null)) {
                    $problems[] = $subject . ': status or version differs from the plan';
                }
                if (substr(AzureSqlValueMapper::stringValue($a[0]['AllocatedAt'] ?? ''), 0, 19) !== substr(self::str($item['allocatedAtUtc'] ?? null), 0, 19)) {
                    $problems[] = $subject . ': allocation time differs from the source';
                }
                $events = $this->connection->fetchAll(
                    'SELECT COUNT(*) AS [N] FROM [audit].[OnboardingStatusEvents] WHERE [AssignmentPublicId] = (SELECT [PublicId] FROM [onboarding].[Assignments] WHERE [AssignmentId] = :id)',
                    [':id' => $assignmentId],
                );
                if (AzureSqlValueMapper::intValue($events[0]['N'] ?? 0) !== MigrationPlan::statusEvents($item)) {
                    $problems[] = $subject . ': status history has the wrong number of events';
                }

                array_push($problems, ...match ($item['kind'] ?? null) {
                    MigrationPlanner::TRAINING => $this->trainingContent($item, $target, $subject),
                    MigrationPlanner::POLICY => $this->policyContent($item, $target, $subject),
                    default => $this->formContent($item, $target, $subject, $catalogue),
                });
            }
        }

        return $problems;
    }

    /**
     * @param array<array-key, mixed> $item
     * @param array<string, ?int> $target
     *
     * @return list<string>
     */
    private function trainingContent(array $item, array $target, string $subject): array
    {
        $problems = [];
        $progress = is_array($item['progress'] ?? null) ? $item['progress'] : null;
        if ($progress !== null) {
            $id = $target['presentation_progress#' . self::int($progress['sourceId'] ?? null) . '#progress'] ?? null;
            $row = $id === null ? [] : $this->connection->fetchAll('SELECT [SlideIdsJson] FROM [onboarding].[PresentationProgress] WHERE [PresentationProgressId] = :id', [':id' => $id]);
            if ($row === [] || json_decode(AzureSqlValueMapper::stringValue($row[0]['SlideIdsJson'] ?? '[]'), true) !== $progress['slideIds']) {
                $problems[] = $subject . ': slide progress differs from the plan';
            }
        }
        foreach (is_array($item['attempts'] ?? null) ? $item['attempts'] : [] as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }
            $key = 'attempts#' . self::int($attempt['sourceId'] ?? null);
            $id = $target[$key . '#attempt'] ?? null;
            $row = $id === null ? [] : $this->connection->fetchAll(
                'SELECT [AttemptNumber], [ScorePercent], [IsPass], (SELECT COUNT(*) FROM [onboarding].[AttemptAnswers] aa WHERE aa.[AttemptId] = a.[AttemptId]) AS [Answers], (SELECT COUNT(*) FROM [onboarding].[AttemptAnswers] aa WHERE aa.[AttemptId] = a.[AttemptId] AND aa.[IsCorrect] = 1) AS [Correct] FROM [onboarding].[Attempts] a WHERE a.[AttemptId] = :id',
                [':id' => $id],
            );
            $answers = is_array($attempt['answers'] ?? null) ? $attempt['answers'] : [];
            $correct = count(array_filter($answers, static fn (mixed $a): bool => is_array($a) && ($a['isCorrect'] ?? false) === true));
            if ($row === []
                || AzureSqlValueMapper::intValue($row[0]['AttemptNumber'] ?? 0) !== ($attempt['number'] ?? null)
                || AzureSqlValueMapper::intValue($row[0]['ScorePercent'] ?? -1) !== ($attempt['scorePercent'] ?? null)
                || AzureSqlValueMapper::boolValue($row[0]['IsPass'] ?? false) !== ($attempt['isPass'] ?? null)
                || AzureSqlValueMapper::intValue($row[0]['Answers'] ?? 0) !== count($answers)
                || AzureSqlValueMapper::intValue($row[0]['Correct'] ?? 0) !== $correct) {
                $problems[] = $key . ': attempt, score or answers differ from the plan';
            }
        }

        return $problems;
    }

    /**
     * @param array<array-key, mixed> $item
     * @param array<string, ?int> $target
     *
     * @return list<string>
     */
    private function policyContent(array $item, array $target, string $subject): array
    {
        $ack = is_array($item['acknowledgement'] ?? null) ? $item['acknowledgement'] : null;
        if ($ack === null) {
            return [];
        }
        $key = 'policy_acknowledgements#' . self::int($ack['sourceId'] ?? null);
        $id = $target[$key . '#acknowledgement'] ?? null;
        $row = $id === null ? [] : $this->connection->fetchAll('SELECT [DeclarationJson] FROM [onboarding].[PolicyAcknowledgements] WHERE [PolicyAcknowledgementId] = :id', [':id' => $id]);
        if ($row === []) {
            return [$key . ': acknowledgement missing'];
        }
        $stored = json_decode(AzureSqlValueMapper::stringValue($row[0]['DeclarationJson'] ?? 'null'), true);
        $expected = ($ack['declarationKind'] ?? null) === null ? null : ['kind' => $ack['declarationKind'], 'values' => $ack['declaration']];

        return $stored === $expected ? [] : [$key . ': declaration differs from the plan'];
    }

    /**
     * @param array<array-key, mixed> $item
     * @param array<string, ?int> $target
     *
     * @return list<string>
     */
    private function formContent(array $item, array $target, string $subject, TargetCatalogue $catalogue): array
    {
        $problems = [];
        $form = $catalogue->form(self::str($item['slug'] ?? null));
        foreach (is_array($item['submissions'] ?? null) ? $item['submissions'] : [] as $submission) {
            if (!is_array($submission)) {
                continue;
            }
            $key = 'form_submissions#' . self::int($submission['sourceId'] ?? null);
            $id = $target[$key . '#submission'] ?? null;
            $row = $id === null ? [] : $this->connection->fetchAll(
                'SELECT [RevisionNumber], [SubmittedDataJson], LOWER(CONVERT(NVARCHAR(36), [PublicId])) AS [PublicId] FROM [onboarding].[FormSubmissions] WHERE [FormSubmissionId] = :id',
                [':id' => $id],
            );
            if ($row === []) {
                $problems[] = $key . ': submission missing';
                continue;
            }
            $answers = json_decode(AzureSqlValueMapper::stringValue($row[0]['SubmittedDataJson'] ?? '{}'), true);
            $answers = is_array($answers) ? $answers : [];
            // The restricted half, read only to put the submission back together
            // for this comparison; nothing is printed from it.
            foreach ($this->connection->fetchAll(
                'SELECT [PayloadJson] FROM [onboarding_hr].[RestrictedSubmissionData] WHERE [FormSubmissionPublicId] = CONVERT(UNIQUEIDENTIFIER, :id)',
                [':id' => AzureSqlValueMapper::stringValue($row[0]['PublicId'] ?? '')],
            ) as $restricted) {
                $half = json_decode(AzureSqlValueMapper::stringValue($restricted['PayloadJson'] ?? '{}'), true);
                $answers += is_array($half) ? $half : [];
            }
            $expected = [];
            foreach (is_array($submission['answers'] ?? null) ? $submission['answers'] : [] as $path => $value) {
                $expected[(string) $path] = $value;
            }
            $ordinaryStored = json_decode(AzureSqlValueMapper::stringValue($row[0]['SubmittedDataJson'] ?? '{}'), true);
            $ordinaryStored = is_array($ordinaryStored) ? $ordinaryStored : [];
            ksort($answers);
            ksort($expected);
            if (AzureSqlValueMapper::intValue($row[0]['RevisionNumber'] ?? 0) !== ($submission['revision'] ?? null) || $answers != $expected) {
                $problems[] = $key . ': revision or answers differ from the plan';
            }
            if ($form !== null && $expected !== [] && array_diff(array_keys(FormPayloadSplitter::split($expected, $form['classifications'])['ordinary']), array_keys($ordinaryStored)) !== []) {
                $problems[] = $key . ': ordinary answers were not all stored in the ordinary half';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function integrity(string $batch): array
    {
        $checks = [
            'answers whose question is not in their attempt\'s module version' => <<<'SQL'
                SELECT COUNT(*) AS [N] FROM [onboarding].[AttemptAnswers] aa
                JOIN [onboarding].[Attempts] at ON at.[AttemptId] = aa.[AttemptId]
                JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = at.[AssignmentId]
                JOIN [onboarding].[Questions] q ON q.[QuestionId] = aa.[QuestionId]
                JOIN [migration].[OnboardingMigrationRows] r ON r.[TargetId] = aa.[AttemptAnswerId] AND r.[TargetTable] = N'onboarding.AttemptAnswers'
                WHERE r.[BatchPublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :batch) AND r.[RolledBackAtUtc] IS NULL
                  AND q.[TrainingModuleVersionId] <> a.[TrainingModuleVersionId]
                SQL,
            'assignments whose person has no induction record' => <<<'SQL'
                SELECT COUNT(*) AS [N] FROM [onboarding].[Assignments] a
                JOIN [migration].[OnboardingMigrationRows] r ON r.[TargetId] = a.[AssignmentId] AND r.[TargetTable] = N'onboarding.Assignments'
                WHERE r.[BatchPublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :batch) AND r.[RolledBackAtUtc] IS NULL
                  AND NOT EXISTS (SELECT 1 FROM [onboarding].[TraineeProfiles] tp WHERE tp.[UserId] = a.[UserId])
                SQL,
            'documents naming a different person from their assignment' => <<<'SQL'
                SELECT COUNT(*) AS [N] FROM [onboarding].[DocumentReferences] d
                JOIN [onboarding].[Assignments] a ON a.[AssignmentId] = d.[AssignmentId]
                JOIN [migration].[OnboardingMigrationRows] r ON r.[TargetId] = d.[DocumentReferenceId] AND r.[TargetTable] = N'onboarding.DocumentReferences'
                WHERE r.[BatchPublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :batch) AND r.[RolledBackAtUtc] IS NULL
                  AND d.[UserId] <> a.[UserId]
                SQL,
            'restricted halves with no submission' => <<<'SQL'
                SELECT COUNT(*) AS [N] FROM [onboarding_hr].[RestrictedSubmissionData] rs
                JOIN [migration].[OnboardingMigrationRows] r ON r.[TargetId] = rs.[RestrictedSubmissionDataId] AND r.[TargetTable] = N'onboarding_hr.RestrictedSubmissionData'
                WHERE r.[BatchPublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :batch) AND r.[RolledBackAtUtc] IS NULL
                  AND NOT EXISTS (SELECT 1 FROM [onboarding].[FormSubmissions] s WHERE s.[PublicId] = rs.[FormSubmissionPublicId])
                SQL,
        ];
        $problems = [];
        foreach ($checks as $what => $sql) {
            $n = AzureSqlValueMapper::intValue($this->connection->fetchAll($sql, [':batch' => $batch])[0]['N'] ?? 0);
            if ($n > 0) {
                $problems[] = sprintf('%d %s', $n, $what);
            }
        }

        return $problems;
    }

    private static function kindOf(string $role, string $sourceTable): ?string
    {
        return match (true) {
            $role === 'profile' => 'profiles',
            $role === 'account' => 'accounts',
            $role === 'assignment' => match ($sourceTable) {
                'assignments' => 'training',
                'policy_assignments' => 'policies',
                default => 'forms',
            },
            $role === 'progress' => 'progress',
            $role === 'attempt' => 'attempts',
            str_starts_with($role, 'answer:') => 'answers',
            $role === 'acknowledgement' => 'acknowledgements',
            $role === 'submission' => 'submissions',
            $role === 'draft' => 'drafts',
            str_starts_with($role, 'contribution:') => 'contributions',
            $role === 'document:policy_declaration' => 'policyDocuments',
            str_starts_with($role, 'document:') => 'documents',
            str_starts_with($role, 'photo:') => 'photos',
            str_starts_with($role, 'status') => 'statusEvents',
            default => null,
        };
    }

    /** @param list<int> $ids */
    private function count(string $table, string $key, array $ids): int
    {
        return count($this->select(sprintf('SELECT [%s] FROM %s WHERE [%s] IN (%%s)', $key, $table, $key), array_values(array_unique($ids))));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $sqlWithIn, array $ids): array
    {
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $params = [];
            foreach ($chunk as $i => $id) {
                $params[':id' . $i] = $id;
            }
            array_push($rows, ...$this->connection->fetchAll(sprintf($sqlWithIn, implode(', ', array_keys($params))), $params));
        }

        return $rows;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
