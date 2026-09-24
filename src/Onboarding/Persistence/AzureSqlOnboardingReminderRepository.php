<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\UniqueConstraintTranslator;
use TriCab\Core\Onboarding\Application\DTO\Reminder\OutstandingReminderItem;
use TriCab\Core\Onboarding\Contract\OnboardingReminderRepository;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * Reminder candidates and `onboarding.NotificationLog` (5.2).
 *
 * `NotificationLog` has no trigger, so `OUTPUT` without `INTO` is allowed and
 * both claims report their row id directly.
 *
 * ## Claiming a row
 *
 * `UX_onboarding_NotificationLog_OncePerDay` is the guarantee; the statements
 * only try to reach it politely. A `failed` row for today is re-claimed first,
 * so a same-day retry sends again. Otherwise a row is inserted where none
 * exists, under `UPDLOCK, HOLDLOCK` so two runs serialise on the key range —
 * and if one still loses the race, the unique index refuses it and that is
 * read as "already claimed".
 */
final class AzureSqlOnboardingReminderRepository implements OnboardingReminderRepository
{
    use UniqueConstraintTranslator;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function listOutstanding(array $statuses, string $horizonDate, DateTimeImmutable $nowUtc): array
    {
        if ($statuses === []) {
            return [];
        }
        // Literals rather than parameters, as the report repository does: each
        // one has been through AssignmentStatus, which accepts only the six codes.
        $in = implode(', ', array_map(
            static fn (string $status): string => "N'" . AssignmentStatus::fromString($status)->toString() . "'",
            $statuses,
        ));

        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT
                a.[UserId],
                u.[EmailAddress],
                COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), u.[EmailAddress]) AS [DisplayName],
                CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssignmentPublicId],
                a.[ItemType],
                COALESCE(tm.[Title], p.[Title], fd.[Title]) AS [Title],
                a.[Status],
                CONVERT(VARCHAR(10), a.[DueDate], 23) AS [DueDate]
            FROM [onboarding].[Assignments] a
            JOIN [onboarding].[TraineeProfiles] t ON t.[UserId] = a.[UserId]
            JOIN [identity].[Users] u ON u.[UserId] = a.[UserId]
            LEFT JOIN [onboarding].[TrainingModuleVersions] tmv ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
            LEFT JOIN [onboarding].[TrainingModules] tm ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
            LEFT JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
            LEFT JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
            LEFT JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
            LEFT JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
            WHERE a.[WithdrawnAtUtc] IS NULL
              AND a.[Status] IN ({$in})
              AND (a.[DueDate] IS NULL OR a.[DueDate] <= CONVERT(DATE, :horizon, 23))
              AND t.[InvitedAtUtc] IS NOT NULL
              AND (t.[DepartedAtUtc] IS NULL OR t.[DepartedAtUtc] > CONVERT(DATETIME2(7), :now, 126))
            ORDER BY a.[UserId],
                CASE WHEN a.[DueDate] IS NULL THEN 1 ELSE 0 END,
                a.[DueDate],
                COALESCE(tm.[Title], p.[Title], fd.[Title]),
                a.[AssignmentId]
            SQL,
            [
                ':horizon' => $horizonDate,
                ':now' => $nowUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u'),
            ],
        );

        $items = [];
        foreach ($rows as $row) {
            $itemType = AzureSqlValueMapper::stringValue($row['ItemType'] ?? '');
            if (!in_array($itemType, ['training', 'policy', 'form'], true)) {
                continue;
            }
            $items[] = new OutstandingReminderItem(
                AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
                AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? ''),
                AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
                strtolower(AzureSqlValueMapper::stringValue($row['AssignmentPublicId'] ?? '')),
                $itemType,
                AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
                AzureSqlValueMapper::stringValue($row['Status'] ?? ''),
                AzureSqlValueMapper::nullableString($row['DueDate'] ?? null),
            );
        }

        return $items;
    }

    public function claim(int $userId, string $notificationType, string $subjectType, string $subjectPublicId, string $sentOn): ?int
    {
        $reclaimed = $this->connection->fetchAll(
            <<<'SQL'
            UPDATE [onboarding].[NotificationLog]
            SET [Outcome] = N'sent', [FailureReason] = NULL, [SentAtUtc] = SYSUTCDATETIME()
            OUTPUT INSERTED.[NotificationLogId]
            WHERE [UserId] = :userId
              AND [NotificationType] = :type
              AND [SubjectType] = :subjectType
              AND [SubjectPublicId] = CONVERT(UNIQUEIDENTIFIER, :subject)
              AND [SentOn] = CONVERT(DATE, :sentOn, 23)
              AND [Outcome] = N'failed'
            SQL,
            [':userId' => $userId, ':type' => $notificationType, ':subjectType' => $subjectType, ':subject' => $subjectPublicId, ':sentOn' => $sentOn],
        );
        if ($reclaimed !== []) {
            return AzureSqlValueMapper::intValue($reclaimed[0]['NotificationLogId'] ?? 0);
        }

        try {
            $inserted = $this->connection->fetchAll(
                <<<'SQL'
                INSERT INTO [onboarding].[NotificationLog]
                    ([UserId], [NotificationType], [SubjectType], [SubjectPublicId], [SentOn], [Channel], [Outcome])
                OUTPUT INSERTED.[NotificationLogId]
                SELECT :userId, :type, :subjectType, CONVERT(UNIQUEIDENTIFIER, :subject), CONVERT(DATE, :sentOn, 23), N'email', N'sent'
                WHERE NOT EXISTS (
                    SELECT 1 FROM [onboarding].[NotificationLog] WITH (UPDLOCK, HOLDLOCK)
                    WHERE [UserId] = :userIdCheck
                      AND [NotificationType] = :typeCheck
                      AND [SubjectType] = :subjectTypeCheck
                      AND [SubjectPublicId] = CONVERT(UNIQUEIDENTIFIER, :subjectCheck)
                      AND [SentOn] = CONVERT(DATE, :sentOnCheck, 23)
                )
                SQL,
                [
                    ':userId' => $userId, ':type' => $notificationType, ':subjectType' => $subjectType, ':subject' => $subjectPublicId, ':sentOn' => $sentOn,
                    ':userIdCheck' => $userId, ':typeCheck' => $notificationType, ':subjectTypeCheck' => $subjectType, ':subjectCheck' => $subjectPublicId, ':sentOnCheck' => $sentOn,
                ],
            );
        } catch (DatabaseUnavailable $exception) {
            if (self::isUniqueConstraintViolation($exception)) {
                return null;
            }

            throw $exception;
        }

        return $inserted === [] ? null : AzureSqlValueMapper::intValue($inserted[0]['NotificationLogId'] ?? 0);
    }

    public function markFailed(array $notificationLogIds, string $reason): void
    {
        $ids = array_values(array_filter(array_map('intval', $notificationLogIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }

        $this->connection->execute(
            sprintf(
                "UPDATE [onboarding].[NotificationLog] SET [Outcome] = N'failed', [FailureReason] = :reason WHERE [NotificationLogId] IN (%s)",
                implode(', ', $ids),
            ),
            [':reason' => mb_substr($reason, 0, 400)],
        );
    }
}
