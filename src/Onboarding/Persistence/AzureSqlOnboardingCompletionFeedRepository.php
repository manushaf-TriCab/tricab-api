<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Onboarding\Application\DTO\CompletionFeed\CompletionFeedEntry;
use TriCab\Core\Onboarding\Contract\OnboardingCompletionFeedRepository;

/**
 * The completion feed (Phase 6), read from `[audit].[OnboardingStatusEvents]`.
 *
 * The event table is the source rather than `Assignments`, because it is
 * append-only and its identity value is monotonic: a cursor over it can be
 * replayed, and a completion that is later reopened shows as a second event
 * instead of silently changing an earlier answer. The clustered primary key is
 * the cursor, so a page is a range seek.
 *
 * Read only. Selects no note, score, answer or email address — the columns are
 * listed, and `OnboardingCompletionFeedEndpointsTest` holds the wire shape.
 */
final class AzureSqlOnboardingCompletionFeedRepository implements OnboardingCompletionFeedRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function after(int $afterSequence, int $limit, DateTimeImmutable $settledBefore): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT TOP (:limit)
                se.[OnboardingStatusEventId] AS [Sequence],
                CONVERT(NVARCHAR(36), se.[PublicId]) AS [EventPublicId],
                se.[FromStatus],
                se.[ToStatus],
                CONVERT(VARCHAR(33), SWITCHOFFSET(se.[CreatedAtUtc], '+00:00'), 127) AS [OccurredAtUtc],
                CONVERT(NVARCHAR(36), u.[PublicId]) AS [UserPublicId],
                a.[ItemType],
                COALESCE(CONVERT(NVARCHAR(36), tm.[PublicId]), CONVERT(NVARCHAR(36), p.[PublicId]), CONVERT(NVARCHAR(36), fd.[PublicId])) AS [ItemPublicId],
                COALESCE(tm.[Slug], p.[Slug], fd.[Slug]) AS [ItemSlug],
                COALESCE(tm.[Title], p.[Title], fd.[Title]) AS [ItemTitle],
                COALESCE(tmv.[VersionNumber], pv.[VersionNumber], fdv.[VersionNumber]) AS [VersionNumber],
                CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssignmentPublicId]
            FROM [audit].[OnboardingStatusEvents] se
            JOIN [onboarding].[Assignments] a ON a.[PublicId] = se.[AssignmentPublicId]
            JOIN [identity].[Users] u ON u.[UserId] = se.[SubjectUserId]
            LEFT JOIN [onboarding].[TrainingModuleVersions] tmv ON tmv.[TrainingModuleVersionId] = a.[TrainingModuleVersionId]
            LEFT JOIN [onboarding].[TrainingModules] tm ON tm.[TrainingModuleId] = tmv.[TrainingModuleId]
            LEFT JOIN [onboarding].[PolicyVersions] pv ON pv.[PolicyVersionId] = a.[PolicyVersionId]
            LEFT JOIN [onboarding].[Policies] p ON p.[PolicyId] = pv.[PolicyId]
            LEFT JOIN [onboarding].[FormDefinitionVersions] fdv ON fdv.[FormDefinitionVersionId] = a.[FormDefinitionVersionId]
            LEFT JOIN [onboarding].[FormDefinitions] fd ON fd.[FormDefinitionId] = fdv.[FormDefinitionId]
            WHERE se.[OnboardingStatusEventId] > :afterSequence
              AND (se.[ToStatus] = N'completed' OR se.[FromStatus] = N'completed')
              AND se.[CreatedAtUtc] < CONVERT(DATETIMEOFFSET, :settledBefore, 127)
            ORDER BY se.[OnboardingStatusEventId]
            SQL,
            [
                ':limit' => $limit,
                ':afterSequence' => $afterSequence,
                ':settledBefore' => $settledBefore->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ],
        );

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = new CompletionFeedEntry(
                sequence: AzureSqlValueMapper::intValue($row['Sequence'] ?? 0),
                eventPublicId: strtolower(AzureSqlValueMapper::stringValue($row['EventPublicId'] ?? '')),
                type: AzureSqlValueMapper::stringValue($row['ToStatus'] ?? '') === 'completed'
                    ? CompletionFeedEntry::TYPE_RECORDED
                    : CompletionFeedEntry::TYPE_REOPENED,
                occurredAtUtc: AzureSqlValueMapper::stringValue($row['OccurredAtUtc'] ?? ''),
                userPublicId: strtolower(AzureSqlValueMapper::stringValue($row['UserPublicId'] ?? '')),
                itemType: AzureSqlValueMapper::stringValue($row['ItemType'] ?? ''),
                itemPublicId: strtolower(AzureSqlValueMapper::stringValue($row['ItemPublicId'] ?? '')),
                itemSlug: AzureSqlValueMapper::stringValue($row['ItemSlug'] ?? ''),
                itemTitle: AzureSqlValueMapper::stringValue($row['ItemTitle'] ?? ''),
                versionNumber: AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
                assignmentPublicId: strtolower(AzureSqlValueMapper::stringValue($row['AssignmentPublicId'] ?? '')),
            );
        }

        return $entries;
    }
}
