<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;

/**
 * Applies a status change and writes the audit row that goes with it.
 *
 * A trait rather than a service, because the two statements must run inside the
 * caller's transaction and a collaborator with its own connection could not
 * promise that. Work order invariant 13 asks that every state-changing action
 * be audited; that is only true if the audit row and the status cannot be
 * committed apart, and the interesting failures are exactly the ones between
 * two calls.
 *
 * ## The timestamps are set here and nowhere else
 *
 * `StartedAtUtc`, `SubmittedAtUtc` and `CompletedAtUtc` are derived from the
 * status being moved *to*, so they cannot disagree with it. `StartedAtUtc` is
 * only ever set once — `WHERE [StartedAtUtc] IS NULL` inside the `CASE` — because
 * a person who reopens a module after a fortnight did not start it again, and a
 * "started" date that moves is a date nobody can use in a report.
 *
 * ## `SYSUTCDATETIME()` rather than a clock
 *
 * These timestamps are read back by HR reports and compared against each other
 * across rows written by different requests. One server's clock ordering them is
 * the only version of that comparison that is meaningful; a PHP `DateTimeImmutable`
 * from whichever App Service instance handled the request is not.
 */
trait AzureSqlOnboardingStatusWriter
{
    /**
     * @param string $assignmentPublicId the audit table keys on the public id, not the internal one
     */
    private function applyTransition(
        SqlConnection $connection,
        int $assignmentId,
        string $assignmentPublicId,
        int $subjectUserId,
        ?StatusTransition $transition,
    ): void {
        if ($transition === null) {
            return;
        }

        $to = $transition->to->toString();

        $connection->execute(
            <<<'SQL'
            UPDATE [onboarding].[Assignments]
            SET [Status] = :toStatus,
                [StartedAtUtc] = CASE
                    WHEN [StartedAtUtc] IS NOT NULL THEN [StartedAtUtc]
                    WHEN :startsWork = 1 THEN SYSUTCDATETIME()
                    ELSE [StartedAtUtc]
                END,
                [SubmittedAtUtc] = CASE WHEN :isSubmitted = 1 THEN SYSUTCDATETIME() ELSE [SubmittedAtUtc] END,
                [CompletedAtUtc] = CASE WHEN :isCompleted = 1 THEN SYSUTCDATETIME() ELSE [CompletedAtUtc] END,
                [UpdatedAtUtc] = SYSUTCDATETIME()
            WHERE [AssignmentId] = :assignmentId
            SQL,
            [
                ':toStatus' => $to,
                // Each occurrence of a value gets its own parameter name. A named
                // parameter used twice in one statement fails on PDO_SQLSRV with
                // SQLSTATE[07002] — see the plan's trap list; this is the shape of
                // statement that produces it.
                ':startsWork' => $to === AssignmentStatus::NOT_STARTED ? 0 : 1,
                ':isSubmitted' => $to === AssignmentStatus::SUBMITTED ? 1 : 0,
                ':isCompleted' => $to === AssignmentStatus::COMPLETED ? 1 : 0,
                ':assignmentId' => $assignmentId,
            ],
        );

        $connection->execute(
            <<<'SQL'
            INSERT INTO [audit].[OnboardingStatusEvents] (
                [AssignmentPublicId], [SubjectUserId], [ActorUserId], [ActorType],
                [FromStatus], [ToStatus], [Note]
            ) VALUES (
                CONVERT(UNIQUEIDENTIFIER, :assignmentPublicId), :subjectUserId, :actorUserId, :actorType,
                :fromStatus, :toStatus, :note
            )
            SQL,
            [
                ':assignmentPublicId' => $assignmentPublicId,
                ':subjectUserId' => $subjectUserId,
                // Null for `system`, which is what CK_..._Attribution requires:
                // a human action with no name attached is refused by the database
                // rather than merely discouraged in PHP.
                ':actorUserId' => $transition->actorUserId,
                ':actorType' => $transition->actor->toString(),
                ':fromStatus' => $transition->from->toString(),
                ':toStatus' => $to,
                ':note' => $transition->note,
            ],
        );
    }
}
