<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\Service\DeleteOnboardingPersonService;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Undoes one migration batch, and only that batch (Phase 7.7; work order §7.6:
 * "rollback batch-scoped so it cannot delete manually created or later records").
 *
 * ## Only what the ledger says the batch created
 *
 * Every row removed is named in `migration.OnboardingMigrationRows` for this
 * batch. Nothing is found by searching; nothing another batch, HR or a trainee
 * created can be reached.
 *
 * ## A person with later work is refused
 *
 * Before anything of a person's is removed, their migrated records are checked
 * for work that came after: an attempt, acknowledgement, submission, draft,
 * contribution, document or status change the batch did not write, a migrated
 * row since updated, or — where the batch made their induction record — any
 * assignment it did not make. Any of those refuses that person, names why, and
 * leaves them exactly as they are; the others are still rolled back. The batch
 * is `rolled-back` only when nobody was refused.
 *
 * ## The evidence tables' triggers
 *
 * Attempt answers, acknowledgements, submissions, their restricted halves and
 * form contributions are append-only by trigger. Rolling back a migration is
 * the one case where rows in them are removed, and it follows the precedent of
 * `remove-onboarding-demo-data.sql`: the triggers are disabled inside the
 * person's transaction, only ledger-named rows are deleted, and they are
 * enabled again before it commits — so a failure restores them with
 * everything else. Audit rows (`audit.*`) are never deleted; the rollback adds
 * one closing status event per removed assignment instead, so a reader of the
 * history — or of the Skill Matrix feed — sees it end.
 *
 * ## Accounts
 *
 * An induction record the batch created is removed through
 * {@see DeleteOnboardingPersonService}, which disables the GUEST account the
 * batch made for it (accounts are never deleted: audit rows name them).
 */
final readonly class AzureSqlOnboardingMigrationRollback
{
    private const TRIGGERS = [
        '[onboarding].[TR_FormSubmissions_Immutable] ON [onboarding].[FormSubmissions]',
        '[onboarding].[TR_PolicyAcknowledgements_Immutable] ON [onboarding].[PolicyAcknowledgements]',
        '[onboarding].[TR_AttemptAnswers_Immutable] ON [onboarding].[AttemptAnswers]',
        '[onboarding_hr].[TR_RestrictedSubmissionData_Immutable] ON [onboarding_hr].[RestrictedSubmissionData]',
        '[onboarding].[TR_FormContributions_AppendOnly] ON [onboarding].[FormContributions]',
    ];

    /** Children before parents. */
    private const DELETE_ORDER = [
        'onboarding.DocumentReferences' => ['[onboarding].[DocumentReferences]', 'DocumentReferenceId'],
        'onboarding_hr.RestrictedSubmissionData' => ['[onboarding_hr].[RestrictedSubmissionData]', 'RestrictedSubmissionDataId'],
        'onboarding.FormContributions' => ['[onboarding].[FormContributions]', 'FormContributionId'],
        'onboarding.FormDrafts' => ['[onboarding].[FormDrafts]', 'FormDraftId'],
        'onboarding.FormSubmissions' => ['[onboarding].[FormSubmissions]', 'FormSubmissionId'],
        'onboarding.AttemptAnswers' => ['[onboarding].[AttemptAnswers]', 'AttemptAnswerId'],
        'onboarding.Attempts' => ['[onboarding].[Attempts]', 'AttemptId'],
        'onboarding.PolicyAcknowledgements' => ['[onboarding].[PolicyAcknowledgements]', 'PolicyAcknowledgementId'],
        'onboarding.PresentationProgress' => ['[onboarding].[PresentationProgress]', 'PresentationProgressId'],
        'onboarding.Assignments' => ['[onboarding].[Assignments]', 'AssignmentId'],
    ];

    public function __construct(
        private SqlConnection $connection,
        private ApplicationTransaction $transaction,
        private AzureSqlOnboardingMigrationLedger $ledger,
        private DeleteOnboardingPersonService $people,
        private ProductBlobStore $blobs,
    ) {
    }

    /**
     * @return array{rolledBack: list<int>, refused: array<int, list<string>>, blobsDeleted: int, blobFailures: list<string>}
     */
    public function rollback(string $batch, ActorContext $operator): array
    {
        $byPerson = [];
        foreach ($this->ledger->rows($batch) as $row) {
            $byPerson[$row['subjectUserId']][] = $row;
        }

        $rolledBack = [];
        $refused = [];
        $blobs = [];
        foreach ($byPerson as $userId => $rows) {
            $reasons = $this->laterWork($userId, $rows);
            if ($reasons !== []) {
                $refused[$userId] = $reasons;
                $this->ledger->log($batch, $operator, 'rollback.person', 'refused', null, null, ['subjectUserId' => $userId, 'reasons' => $reasons]);
                continue;
            }
            $this->transaction->run(function () use ($batch, $userId, $rows, $operator): void {
                $this->removePerson($batch, $userId, $rows, $operator);
            });
            $rolledBack[] = $userId;
            foreach ($rows as $row) {
                if ($row['container'] !== null && $row['path'] !== null) {
                    $blobs[] = [$row['container'], $row['path']];
                }
            }
        }

        // After the database is consistent: a blob left behind names no record.
        $deleted = 0;
        $failures = [];
        foreach ($blobs as [$container, $path]) {
            try {
                $this->blobs->deleteObject($container, $path);
                ++$deleted;
            } catch (Throwable $e) {
                $failures[] = $container . '/' . $path . ': ' . $e->getMessage();
            }
        }

        return ['rolledBack' => $rolledBack, 'refused' => $refused, 'blobsDeleted' => $deleted, 'blobFailures' => $failures];
    }

    /**
     * @param list<array{rowId: int, sourceTable: string, sourceId: int, subjectUserId: int, role: string, targetTable: string, targetId: ?int, targetPublicId: ?string, container: ?string, path: ?string, sourceHash: ?string, createdAtUtc: string}> $rows
     *
     * @return list<string> why this person cannot be rolled back; empty when they can
     */
    private function laterWork(int $userId, array $rows): array
    {
        $owned = self::owned($rows);
        $assignments = $owned['onboarding.Assignments'] ?? [];
        $reasons = [];

        if ($assignments !== []) {
            $children = [
                'an attempt' => ['[onboarding].[Attempts]', 'AttemptId', 'onboarding.Attempts'],
                'slide progress' => ['[onboarding].[PresentationProgress]', 'PresentationProgressId', 'onboarding.PresentationProgress'],
                'an acknowledgement' => ['[onboarding].[PolicyAcknowledgements]', 'PolicyAcknowledgementId', 'onboarding.PolicyAcknowledgements'],
                'a form submission' => ['[onboarding].[FormSubmissions]', 'FormSubmissionId', 'onboarding.FormSubmissions'],
                'a form draft' => ['[onboarding].[FormDrafts]', 'FormDraftId', 'onboarding.FormDrafts'],
                'a form contribution' => ['[onboarding].[FormContributions]', 'FormContributionId', 'onboarding.FormContributions'],
                'a document' => ['[onboarding].[DocumentReferences]', 'DocumentReferenceId', 'onboarding.DocumentReferences'],
            ];
            foreach ($children as $what => [$table, $key, $ledgerTable]) {
                $found = $this->idsWhere(sprintf('SELECT [%s] AS [Id] FROM %s WHERE [AssignmentId] IN (%%s)', $key, $table), $assignments);
                $later = array_diff($found, $owned[$ledgerTable] ?? []);
                if ($later !== []) {
                    $reasons[] = sprintf('%d × %s added after the migration', count($later), $what);
                }
            }
            $events = $this->idsWhere(
                'SELECT e.[OnboardingStatusEventId] AS [Id] FROM [audit].[OnboardingStatusEvents] e JOIN [onboarding].[Assignments] a ON a.[PublicId] = e.[AssignmentPublicId] WHERE a.[AssignmentId] IN (%s)',
                $assignments,
            );
            $laterEvents = array_diff($events, $owned['audit.OnboardingStatusEvents'] ?? []);
            if ($laterEvents !== []) {
                $reasons[] = sprintf('%d status change(s) made after the migration', count($laterEvents));
            }
        }

        // A migrated row updated in place since it was written.
        foreach ([
            'onboarding.Assignments' => ['[onboarding].[Assignments]', 'AssignmentId'],
            'onboarding.PresentationProgress' => ['[onboarding].[PresentationProgress]', 'PresentationProgressId'],
            'onboarding.FormDrafts' => ['[onboarding].[FormDrafts]', 'FormDraftId'],
        ] as $ledgerTable => [$table, $key]) {
            foreach ($rows as $row) {
                if ($row['targetTable'] !== $ledgerTable || $row['targetId'] === null) {
                    continue;
                }
                $changed = $this->connection->fetchAll(
                    sprintf('SELECT 1 AS [X] FROM %s WHERE [%s] = :id AND [UpdatedAtUtc] > CONVERT(DATETIME2(7), :created, 126)', $table, $key),
                    [':id' => $row['targetId'], ':created' => $row['createdAtUtc']],
                );
                if ($changed !== []) {
                    $reasons[] = sprintf('%s #%d was changed after the migration', $ledgerTable, $row['targetId']);
                }
            }
        }

        // An induction record the batch made must hold nothing the batch did not.
        if (($owned['onboarding.TraineeProfiles'] ?? []) !== []) {
            $all = $this->connection->fetchAll('SELECT [AssignmentId] FROM [onboarding].[Assignments] WHERE [UserId] = :userId', [':userId' => $userId]);
            $others = array_diff(array_map(static fn (array $r): int => AzureSqlValueMapper::intValue($r['AssignmentId'] ?? 0), $all), $assignments);
            if ($others !== []) {
                $reasons[] = sprintf('%d assignment(s) allocated after the migration to the induction record it created', count($others));
            }
        }

        return $reasons;
    }

    /**
     * @param list<array{rowId: int, sourceTable: string, sourceId: int, subjectUserId: int, role: string, targetTable: string, targetId: ?int, targetPublicId: ?string, container: ?string, path: ?string, sourceHash: ?string, createdAtUtc: string}> $rows
     */
    private function removePerson(string $batch, int $userId, array $rows, ActorContext $operator): void
    {
        $owned = self::owned($rows);

        // The closing event for each assignment, written while its status is known.
        foreach ($this->connection->fetchAll(
            sprintf('SELECT LOWER(CONVERT(NVARCHAR(36), [PublicId])) AS [PublicId], [Status] FROM [onboarding].[Assignments] WHERE [AssignmentId] IN (%s)', self::placeholders($owned['onboarding.Assignments'] ?? [0])),
            self::params($owned['onboarding.Assignments'] ?? [0]),
        ) as $assignment) {
            $this->connection->execute(
                <<<'SQL'
                INSERT INTO [audit].[OnboardingStatusEvents] ([AssignmentPublicId], [SubjectUserId], [ActorUserId], [ActorType], [FromStatus], [ToStatus], [Note])
                VALUES (CONVERT(UNIQUEIDENTIFIER, :assignment), :subject, NULL, N'system', :from, N'not_started', :note)
                SQL,
                [
                    ':assignment' => AzureSqlValueMapper::stringValue($assignment['PublicId'] ?? ''),
                    ':subject' => $userId,
                    ':from' => AzureSqlValueMapper::stringValue($assignment['Status'] ?? ''),
                    ':note' => sprintf('Migration batch %s rolled back by %s: this migrated assignment was removed.', substr($batch, 0, 8), $operator->emailAddress),
                ],
            );
        }

        foreach (self::TRIGGERS as $trigger) {
            $this->connection->execute('DISABLE TRIGGER ' . $trigger);
        }
        foreach (self::DELETE_ORDER as $ledgerTable => [$table, $key]) {
            $ids = $owned[$ledgerTable] ?? [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $this->connection->execute(sprintf('DELETE FROM %s WHERE [%s] IN (%s)', $table, $key, self::placeholders($chunk)), self::params($chunk));
            }
        }
        foreach (self::TRIGGERS as $trigger) {
            $this->connection->execute('ENABLE TRIGGER ' . $trigger);
        }

        $profiles = $owned['onboarding.TraineeProfiles'] ?? [];
        if ($profiles !== []) {
            $profile = $this->connection->fetchAll(
                'SELECT LOWER(CONVERT(NVARCHAR(36), [PublicId])) AS [PublicId], [RowVersion] FROM [onboarding].[TraineeProfiles] WHERE [TraineeProfileId] = :id',
                [':id' => $profiles[0]],
            );
            if ($profile !== []) {
                $this->people->delete($operator, AzureSqlValueMapper::stringValue($profile[0]['PublicId'] ?? ''), ProductRowVersionMapper::toToken($profile[0]['RowVersion'] ?? null));
            }
        }

        $this->ledger->markRolledBack(array_map(static fn (array $r): int => $r['rowId'], $rows));
        $this->ledger->log($batch, $operator, 'rollback.person', 'ok', null, null, ['subjectUserId' => $userId, 'rows' => count($rows)]);
    }

    /**
     * @param list<array{targetTable: string, targetId: ?int}> $rows
     *
     * @return array<string, list<int>> target table => ids
     */
    private static function owned(array $rows): array
    {
        $owned = [];
        foreach ($rows as $row) {
            if ($row['targetId'] !== null) {
                $owned[$row['targetTable']][] = $row['targetId'];
            }
        }

        return $owned;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function idsWhere(string $sqlWithIn, array $ids): array
    {
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->connection->fetchAll(sprintf($sqlWithIn, self::placeholders($chunk)), self::params($chunk)) as $row) {
                $found[] = AzureSqlValueMapper::intValue($row['Id'] ?? 0);
            }
        }

        return $found;
    }

    /** @param list<int> $ids */
    private static function placeholders(array $ids): string
    {
        return implode(', ', array_map(static fn (int $i): string => ':id' . $i, array_keys($ids)));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, int>
     */
    private static function params(array $ids): array
    {
        $params = [];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }

        return $params;
    }
}
