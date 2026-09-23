<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Persistence;

use PDOException;
use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemSummary;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentReissue;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionSummary;
use TriCab\Core\Onboarding\Contract\OnboardingContentRepository;
use TriCab\Core\Onboarding\Domain\Exception\ContentAuthoringNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Content authoring against `tricab-mel` (4.4).
 *
 * The three item types share one shape — an item table, a version table, and
 * assignments pointing at versions — and differ in names and body columns.
 * {@see self::TABLES} holds the names; every table and column interpolated into
 * SQL below comes from it and never from a request.
 *
 * ## Frozen means `WHERE [IsPublished] = 0`
 *
 * Every write to a version's body carries that condition beside its token
 * check, and replaces questions or classifications only after that claim has
 * succeeded in the same transaction — by then the version row is locked, so a
 * publish cannot slip in between. The schema has no trigger behind this (see the
 * plan's 4.4 evidence), so this repository is the lock.
 *
 * Version-table writes report their row count with `SELECT @@ROWCOUNT` rather
 * than an `OUTPUT` clause, which SQL Server refuses on a table with a trigger.
 * Should the freeze trigger be added later, nothing here has to change.
 *
 * Reads `[onboarding_hr]` for one table, `FieldClassifications`, which it also
 * writes: a form's classifications are part of its authored body.
 * `OnboardingContentRepositoryBoundaryTest` holds that line.
 */
final class AzureSqlOnboardingContentRepository implements OnboardingContentRepository
{
    use AzureSqlTransaction;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<string, array{items: string, itemId: string, versions: string, versionId: string, retired: string}> */
    private const TABLES = [
        AuthoredContent::TYPE_TRAINING => [
            'items' => '[onboarding].[TrainingModules]',
            'itemId' => 'TrainingModuleId',
            'versions' => '[onboarding].[TrainingModuleVersions]',
            'versionId' => 'TrainingModuleVersionId',
            'retired' => 'RetiredAtUtc',
        ],
        AuthoredContent::TYPE_POLICY => [
            'items' => '[onboarding].[Policies]',
            'itemId' => 'PolicyId',
            'versions' => '[onboarding].[PolicyVersions]',
            'versionId' => 'PolicyVersionId',
            'retired' => 'SupersededAtUtc',
        ],
        AuthoredContent::TYPE_FORM => [
            'items' => '[onboarding].[FormDefinitions]',
            'itemId' => 'FormDefinitionId',
            'versions' => '[onboarding].[FormDefinitionVersions]',
            'versionId' => 'FormDefinitionVersionId',
            'retired' => 'RetiredAtUtc',
        ],
    ];

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    // ------------------------------------------------------------------- reads

    public function listItems(): array
    {
        $selects = [];
        foreach (self::TABLES as $type => $t) {
            $accessGroup = $type === AuthoredContent::TYPE_FORM ? 'i.[AccessGroup]' : 'CAST(NULL AS NVARCHAR(20))';
            $sortOrder = $type === AuthoredContent::TYPE_POLICY ? 'i.[SortOrder]' : 'CAST(NULL AS INT)';
            $selects[] = <<<SQL
                SELECT N'{$type}' AS [ItemType], CONVERT(NVARCHAR(36), i.[PublicId]) AS [PublicId], i.[Slug], i.[Title],
                    {$accessGroup} AS [AccessGroup], i.[IsActive], {$sortOrder} AS [SortOrder],
                    CASE WHEN {$sortOrder} IS NULL THEN 1 ELSE 0 END AS [Unplaced],
                    (SELECT TOP (1) v.[VersionNumber] FROM {$t['versions']} v
                        WHERE v.[{$t['itemId']}] = i.[{$t['itemId']}] AND v.[IsPublished] = 1 AND v.[{$t['retired']}] IS NULL) AS [LiveVersionNumber],
                    (SELECT TOP (1) CONVERT(NVARCHAR(36), v.[PublicId]) FROM {$t['versions']} v
                        WHERE v.[{$t['itemId']}] = i.[{$t['itemId']}] AND v.[IsPublished] = 0 ORDER BY v.[VersionNumber] DESC) AS [DraftVersionPublicId],
                    (SELECT TOP (1) v.[VersionNumber] FROM {$t['versions']} v
                        WHERE v.[{$t['itemId']}] = i.[{$t['itemId']}] AND v.[IsPublished] = 0 ORDER BY v.[VersionNumber] DESC) AS [DraftVersionNumber],
                    (SELECT COUNT(*) FROM [onboarding].[Assignments] a
                        JOIN {$t['versions']} v ON v.[{$t['versionId']}] = a.[{$t['versionId']}]
                        WHERE v.[{$t['itemId']}] = i.[{$t['itemId']}] AND a.[WithdrawnAtUtc] IS NULL) AS [HolderCount]
                FROM {$t['items']} i
                SQL;
        }

        $rows = $this->connection->fetchAll(implode("\nUNION ALL\n", $selects)
            // Policies in handbook order; anything unplaced after, by title. A
            // UNION's ORDER BY may only name its columns, so [Unplaced] is one.
            . "\nORDER BY [ItemType], [Unplaced], [SortOrder], [Title], [PublicId]");

        return array_map(static fn (array $row): ContentItemSummary => new ContentItemSummary(
            AzureSqlValueMapper::stringValue($row['ItemType'] ?? ''),
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
            AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            AzureSqlValueMapper::nullableString($row['AccessGroup'] ?? null),
            AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            AzureSqlValueMapper::nullableInt($row['LiveVersionNumber'] ?? null),
            self::lowerOrNull(AzureSqlValueMapper::nullableString($row['DraftVersionPublicId'] ?? null)),
            AzureSqlValueMapper::nullableInt($row['DraftVersionNumber'] ?? null),
            AzureSqlValueMapper::intValue($row['HolderCount'] ?? 0),
        ), $rows);
    }

    public function findItem(string $itemType, string $itemPublicId): ?ContentItemRecord
    {
        if (!isset(self::TABLES[$itemType]) || preg_match(self::UUID, $itemPublicId) !== 1) {
            return null;
        }
        $t = self::TABLES[$itemType];
        $summary = $itemType === AuthoredContent::TYPE_FORM ? 'CAST(NULL AS NVARCHAR(1000))' : 'i.[Summary]';
        $accessGroup = $itemType === AuthoredContent::TYPE_FORM ? 'i.[AccessGroup]' : 'CAST(NULL AS NVARCHAR(20))';
        $placement = $itemType === AuthoredContent::TYPE_POLICY
            ? 'i.[ChapterTitle], i.[SortOrder]'
            : 'CAST(NULL AS NVARCHAR(120)) AS [ChapterTitle], CAST(NULL AS INT) AS [SortOrder]';

        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT i.[{$t['itemId']}] AS [ItemId], CONVERT(NVARCHAR(36), i.[PublicId]) AS [PublicId], i.[Slug], i.[Title],
                   {$summary} AS [Summary], {$accessGroup} AS [AccessGroup], i.[IsActive], {$placement},
                   CONVERT(VARBINARY(8), i.[RowVersion]) AS [RowVersion]
            FROM {$t['items']} i
            WHERE i.[PublicId] = CONVERT(UNIQUEIDENTIFIER, :publicId)
            SQL,
            [':publicId' => $itemPublicId],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return new ContentItemRecord(
            AzureSqlValueMapper::intValue($row['ItemId'] ?? 0),
            $itemType,
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
            AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            AzureSqlValueMapper::nullableString($row['Summary'] ?? null),
            AzureSqlValueMapper::nullableString($row['AccessGroup'] ?? null),
            AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
            AzureSqlValueMapper::nullableString($row['ChapterTitle'] ?? null),
            AzureSqlValueMapper::nullableInt($row['SortOrder'] ?? null),
        );
    }

    public function listVersions(ContentItemRecord $item): array
    {
        $t = self::TABLES[$item->itemType];
        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT CONVERT(NVARCHAR(36), v.[PublicId]) AS [PublicId], v.[VersionNumber], v.[IsPublished],
                   CONVERT(VARCHAR(33), v.[PublishedAtUtc], 127) AS [PublishedAtUtc],
                   COALESCE(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), u.[EmailAddress]) AS [PublishedByName],
                   CONVERT(VARCHAR(33), v.[{$t['retired']}], 127) AS [RetiredAtUtc],
                   v.[SupersedesWorkInProgress],
                   (SELECT COUNT(*) FROM [onboarding].[Assignments] a
                       WHERE a.[{$t['versionId']}] = v.[{$t['versionId']}] AND a.[WithdrawnAtUtc] IS NULL AND a.[Status] <> N'completed') AS [UnfinishedCount],
                   (SELECT COUNT(*) FROM [onboarding].[Assignments] a
                       WHERE a.[{$t['versionId']}] = v.[{$t['versionId']}] AND a.[WithdrawnAtUtc] IS NULL AND a.[Status] = N'completed') AS [CompletedCount]
            FROM {$t['versions']} v
            LEFT JOIN [identity].[Users] u ON u.[UserId] = v.[PublishedByUserId]
            WHERE v.[{$t['itemId']}] = :itemId
            ORDER BY v.[VersionNumber] DESC
            SQL,
            [':itemId' => $item->itemId],
        );

        return array_map(static fn (array $row): ContentVersionSummary => new ContentVersionSummary(
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
            self::state($row),
            AzureSqlValueMapper::nullableString($row['PublishedAtUtc'] ?? null),
            AzureSqlValueMapper::nullableString($row['PublishedByName'] ?? null),
            AzureSqlValueMapper::nullableString($row['RetiredAtUtc'] ?? null),
            AzureSqlValueMapper::boolValue($row['SupersedesWorkInProgress'] ?? false),
            AzureSqlValueMapper::intValue($row['UnfinishedCount'] ?? 0),
            AzureSqlValueMapper::intValue($row['CompletedCount'] ?? 0),
        ), $rows);
    }

    public function findVersion(ContentItemRecord $item, string $versionPublicId): ?ContentVersionRecord
    {
        if (preg_match(self::UUID, $versionPublicId) !== 1) {
            return null;
        }
        $t = self::TABLES[$item->itemType];
        $body = match ($item->itemType) {
            AuthoredContent::TYPE_TRAINING => 'v.[PresentationManifestJson] AS [ManifestJson], v.[PassMarkPercent]',
            AuthoredContent::TYPE_POLICY => 'v.[ContentJson], CONVERT(VARCHAR(10), v.[EffectiveFrom], 23) AS [EffectiveFrom], '
                . 'v.[DocumentReference], v.[IssuedLabel], v.[ReviewDueLabel], v.[RequiresAcknowledgement]',
            default => 'v.[SchemaJson]',
        };

        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT v.[{$t['versionId']}] AS [VersionId], CONVERT(NVARCHAR(36), v.[PublicId]) AS [PublicId], v.[VersionNumber],
                   v.[IsPublished], CONVERT(VARCHAR(33), v.[{$t['retired']}], 127) AS [RetiredAtUtc],
                   CONVERT(VARCHAR(33), v.[PublishedAtUtc], 127) AS [PublishedAtUtc], v.[SupersedesWorkInProgress],
                   CONVERT(VARBINARY(8), v.[RowVersion]) AS [RowVersion],
                   {$body}
            FROM {$t['versions']} v
            WHERE v.[{$t['itemId']}] = :itemId
              AND v.[PublicId] = CONVERT(UNIQUEIDENTIFIER, :publicId)
            SQL,
            [':itemId' => $item->itemId, ':publicId' => $versionPublicId],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $versionId = AzureSqlValueMapper::intValue($row['VersionId'] ?? 0);

        return new ContentVersionRecord(
            $versionId,
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            AzureSqlValueMapper::intValue($row['VersionNumber'] ?? 0),
            self::state($row),
            ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
            AzureSqlValueMapper::nullableString($row['PublishedAtUtc'] ?? null),
            AzureSqlValueMapper::boolValue($row['SupersedesWorkInProgress'] ?? false),
            $this->content($item->itemType, $versionId, $row),
        );
    }

    public function latestPublishedVersion(ContentItemRecord $item): ?ContentVersionRecord
    {
        $t = self::TABLES[$item->itemType];
        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT TOP (1) CONVERT(NVARCHAR(36), v.[PublicId]) AS [PublicId]
            FROM {$t['versions']} v
            WHERE v.[{$t['itemId']}] = :itemId AND v.[IsPublished] = 1
            ORDER BY CASE WHEN v.[{$t['retired']}] IS NULL THEN 0 ELSE 1 END, v.[VersionNumber] DESC
            SQL,
            [':itemId' => $item->itemId],
        );

        return $rows === [] ? null : $this->findVersion($item, strtolower(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '')));
    }

    public function slugTaken(string $itemType, string $slug): bool
    {
        $t = self::TABLES[$itemType];

        return $this->connection->fetchAll("SELECT 1 AS [Taken] FROM {$t['items']} WHERE [Slug] = :slug", [':slug' => $slug]) !== [];
    }

    public function allocationCount(ContentItemRecord $item): int
    {
        $t = self::TABLES[$item->itemType];
        $rows = $this->connection->fetchAll(
            <<<SQL
            SELECT COUNT(*) AS [Total]
            FROM [onboarding].[Assignments] a
            JOIN {$t['versions']} v ON v.[{$t['versionId']}] = a.[{$t['versionId']}]
            WHERE v.[{$t['itemId']}] = :itemId
            SQL,
            [':itemId' => $item->itemId],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    // ------------------------------------------------------------------ writes

    public function deleteItem(ContentItemRecord $item, RowVersionToken $expectedRowVersion, array $events, ActorContext $actor): void
    {
        $t = self::TABLES[$item->itemType];

        $this->runInTransaction($this->connection, function () use ($t, $item, $expectedRowVersion, $events, $actor): void {
            // Claims the item: a second HR user deleting or editing it waits
            // here and then finds it gone or changed.
            $claimed = $this->affected(
                "UPDATE {$t['items']} SET [UpdatedAtUtc] = SYSUTCDATETIME() WHERE [{$t['itemId']}] = :itemId AND [RowVersion] = :expectedRowVersion",
                [':itemId' => $item->itemId, ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expectedRowVersion)],
            );
            if ($claimed === 0) {
                throw OnboardingWriteConflict::staleToken();
            }

            // Asked again under a range lock, so an allocation cannot land
            // between this and the DELETE. The foreign keys would refuse it
            // anyway; this turns that into a sentence.
            $held = $this->connection->fetchAll(
                <<<SQL
                SELECT COUNT(*) AS [Total]
                FROM [onboarding].[Assignments] a WITH (UPDLOCK, HOLDLOCK)
                JOIN {$t['versions']} v ON v.[{$t['versionId']}] = a.[{$t['versionId']}]
                WHERE v.[{$t['itemId']}] = :itemId
                SQL,
                [':itemId' => $item->itemId],
            );
            $allocations = AzureSqlValueMapper::intValue($held[0]['Total'] ?? 0);
            if ($allocations > 0) {
                throw ContentAuthoringNotPermitted::inUse($allocations);
            }

            $children = match ($item->itemType) {
                AuthoredContent::TYPE_TRAINING => '[onboarding].[Questions]',
                AuthoredContent::TYPE_FORM => '[onboarding_hr].[FieldClassifications]',
                default => null,
            };

            try {
                if ($children !== null) {
                    $this->connection->execute(
                        "DELETE c FROM {$children} c JOIN {$t['versions']} v ON v.[{$t['versionId']}] = c.[{$t['versionId']}] WHERE v.[{$t['itemId']}] = :itemId",
                        [':itemId' => $item->itemId],
                    );
                }
                $this->connection->execute("DELETE FROM {$t['versions']} WHERE [{$t['itemId']}] = :itemId", [':itemId' => $item->itemId]);
                $this->connection->execute("DELETE FROM {$t['items']} WHERE [{$t['itemId']}] = :itemId", [':itemId' => $item->itemId]);
            } catch (DatabaseUnavailable $failure) {
                if (self::isForeignKeyViolation($failure)) {
                    throw ContentAuthoringNotPermitted::inUse(1);
                }
                throw $failure;
            }

            $this->appendAll($events, $actor);
        });
    }

    public function createItem(
        string $itemType,
        string $itemPublicId,
        string $slug,
        string $title,
        ?string $summary,
        ?string $accessGroup,
        ?string $chapterTitle,
        ?int $sortOrder,
        string $versionPublicId,
        AuthoredContent $initial,
        array $events,
        ActorContext $actor,
    ): void {
        $t = self::TABLES[$itemType];
        [$columns, $values, $params] = match ($itemType) {
            AuthoredContent::TYPE_TRAINING => [', [Summary]', ', :summary', [':summary' => $summary]],
            AuthoredContent::TYPE_POLICY => [
                ', [Summary], [ChapterTitle], [SortOrder]',
                ', :summary, :chapterTitle, :sortOrder',
                [':summary' => $summary, ':chapterTitle' => $chapterTitle, ':sortOrder' => $sortOrder],
            ],
            AuthoredContent::TYPE_FORM => [', [AccessGroup]', ', :accessGroup', [':accessGroup' => $accessGroup]],
            default => ['', '', []],
        };

        $this->runInTransaction($this->connection, function () use ($t, $columns, $values, $params, $itemPublicId, $slug, $title, $itemType, $versionPublicId, $initial, $events, $actor): void {
            try {
                $rows = $this->connection->fetchAll(
                    <<<SQL
                    SET NOCOUNT ON;
                    INSERT INTO {$t['items']} ([PublicId], [Slug], [Title]{$columns})
                    VALUES (CONVERT(UNIQUEIDENTIFIER, :publicId), :slug, :title{$values});
                    SELECT CAST(SCOPE_IDENTITY() AS BIGINT) AS [ItemId];
                    SQL,
                    [':publicId' => $itemPublicId, ':slug' => $slug, ':title' => $title] + $params,
                );
            } catch (PDOException $failure) {
                if (self::isUniqueViolation($failure)) {
                    throw ContentAuthoringNotPermitted::slugTaken($slug);
                }
                throw $failure;
            }

            $this->insertVersion($itemType, AzureSqlValueMapper::intValue($rows[0]['ItemId'] ?? 0), 1, $versionPublicId, $initial, $actor);
            $this->appendAll($events, $actor);
        });
    }

    public function updateItem(
        ContentItemRecord $item,
        RowVersionToken $expectedRowVersion,
        string $title,
        ?string $summary,
        ?string $chapterTitle,
        ?int $sortOrder,
        bool $isActive,
        array $events,
        ActorContext $actor,
    ): void {
        $t = self::TABLES[$item->itemType];
        $summarySet = match ($item->itemType) {
            AuthoredContent::TYPE_TRAINING => '[Summary] = :summary,',
            AuthoredContent::TYPE_POLICY => '[Summary] = :summary, [ChapterTitle] = :chapterTitle, [SortOrder] = :sortOrder,',
            default => '',
        };
        $params = [
            ':title' => $title,
            ':isActive' => $isActive ? 1 : 0,
            ':itemId' => $item->itemId,
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expectedRowVersion),
        ];
        if ($summarySet !== '') {
            $params[':summary'] = $summary;
        }
        if ($item->itemType === AuthoredContent::TYPE_POLICY) {
            $params[':chapterTitle'] = $chapterTitle;
            $params[':sortOrder'] = $sortOrder;
        }

        $this->runInTransaction($this->connection, function () use ($t, $summarySet, $params, $events, $actor): void {
            $affected = $this->affected(
                <<<SQL
                UPDATE {$t['items']}
                SET [Title] = :title, {$summarySet} [IsActive] = :isActive, [UpdatedAtUtc] = SYSUTCDATETIME()
                WHERE [{$t['itemId']}] = :itemId AND [RowVersion] = :expectedRowVersion
                SQL,
                $params,
            );
            if ($affected === 0) {
                throw OnboardingWriteConflict::staleToken();
            }
            $this->appendAll($events, $actor);
        });
    }

    public function createDraft(
        ContentItemRecord $item,
        string $versionPublicId,
        AuthoredContent $content,
        array $events,
        ActorContext $actor,
    ): int {
        $t = self::TABLES[$item->itemType];

        return $this->runInTransaction($this->connection, function () use ($t, $item, $versionPublicId, $content, $events, $actor): int {
            // Locks the item's versions so a second author starting a draft at
            // the same moment waits here and then finds this one.
            $rows = $this->connection->fetchAll(
                <<<SQL
                SELECT COALESCE(MAX(v.[VersionNumber]), 0) AS [MaxNumber],
                       SUM(CASE WHEN v.[IsPublished] = 0 THEN 1 ELSE 0 END) AS [Drafts]
                FROM {$t['versions']} v WITH (UPDLOCK, HOLDLOCK)
                WHERE v.[{$t['itemId']}] = :itemId
                SQL,
                [':itemId' => $item->itemId],
            );
            if (AzureSqlValueMapper::intValue($rows[0]['Drafts'] ?? 0) > 0) {
                throw ContentAuthoringNotPermitted::draftExists();
            }
            $number = AzureSqlValueMapper::intValue($rows[0]['MaxNumber'] ?? 0) + 1;

            try {
                $this->insertVersion($item->itemType, $item->itemId, $number, $versionPublicId, $content, $actor);
            } catch (PDOException $failure) {
                if (self::isUniqueViolation($failure)) {
                    throw ContentAuthoringNotPermitted::draftExists();
                }
                throw $failure;
            }
            $this->appendAll($events, $actor);

            return $number;
        });
    }

    public function saveDraft(
        ContentItemRecord $item,
        ContentVersionRecord $version,
        RowVersionToken $expectedRowVersion,
        AuthoredContent $content,
        array $events,
        ActorContext $actor,
    ): void {
        $t = self::TABLES[$item->itemType];
        [$set, $params] = self::bodyColumns($content);

        $this->runInTransaction($this->connection, function () use ($t, $item, $version, $expectedRowVersion, $content, $set, $params, $events, $actor): void {
            $affected = $this->affected(
                <<<SQL
                UPDATE {$t['versions']}
                SET {$set}, [UpdatedAtUtc] = SYSUTCDATETIME()
                WHERE [{$t['versionId']}] = :versionId
                  AND [IsPublished] = 0
                  AND [RowVersion] = :expectedRowVersion
                SQL,
                $params + [
                    ':versionId' => $version->versionId,
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expectedRowVersion),
                ],
            );
            if ($affected === 0) {
                throw $this->whyNotWritten($item, $version);
            }

            // Only now, with the draft row claimed and locked.
            $this->replaceChildren($item->itemType, $version->versionId, $content, $actor);
            $this->appendAll($events, $actor);
        });
    }

    public function publish(
        ContentItemRecord $item,
        ContentVersionRecord $version,
        RowVersionToken $expectedRowVersion,
        bool $supersedesWorkInProgress,
        callable $eventsFor,
        ActorContext $actor,
    ): array {
        $t = self::TABLES[$item->itemType];

        return $this->runInTransaction($this->connection, function () use ($t, $item, $version, $expectedRowVersion, $supersedesWorkInProgress, $eventsFor, $actor): array {
            // The live version is retired first: UX_..._OneLive admits one live
            // version per item, and the new one cannot be published beside it.
            // If the draft's claim below fails, the transaction puts it back.
            $live = $this->connection->fetchAll(
                <<<SQL
                SELECT v.[{$t['versionId']}] AS [VersionId], v.[VersionNumber]
                FROM {$t['versions']} v WITH (UPDLOCK, HOLDLOCK)
                WHERE v.[{$t['itemId']}] = :itemId AND v.[IsPublished] = 1 AND v.[{$t['retired']}] IS NULL
                SQL,
                [':itemId' => $item->itemId],
            );
            $retiredId = $live === [] ? null : AzureSqlValueMapper::intValue($live[0]['VersionId'] ?? 0);
            $retiredNumber = $live === [] ? null : AzureSqlValueMapper::intValue($live[0]['VersionNumber'] ?? 0);

            if ($retiredId !== null) {
                $this->affected(
                    "UPDATE {$t['versions']} SET [{$t['retired']}] = SYSUTCDATETIME(), [UpdatedAtUtc] = SYSUTCDATETIME() WHERE [{$t['versionId']}] = :versionId",
                    [':versionId' => $retiredId],
                );
            }

            $claimed = $this->affected(
                <<<SQL
                UPDATE {$t['versions']}
                SET [IsPublished] = 1,
                    [PublishedAtUtc] = SYSUTCDATETIME(),
                    [PublishedByUserId] = :actorUserId,
                    [SupersedesWorkInProgress] = :supersedes,
                    [UpdatedAtUtc] = SYSUTCDATETIME()
                WHERE [{$t['versionId']}] = :versionId
                  AND [IsPublished] = 0
                  AND [RowVersion] = :expectedRowVersion
                SQL,
                [
                    ':actorUserId' => $actor->userId,
                    ':supersedes' => $supersedesWorkInProgress ? 1 : 0,
                    ':versionId' => $version->versionId,
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expectedRowVersion),
                ],
            );
            if ($claimed === 0) {
                throw $this->whyNotWritten($item, $version);
            }

            $reissued = $supersedesWorkInProgress && $retiredId !== null
                ? $this->reissue($item->itemType, $t['versionId'], $retiredId, $version, $actor)
                : [];

            $this->appendAll($eventsFor($reissued, $retiredNumber), $actor);

            return ['retiredVersionNumber' => $retiredNumber, 'reissued' => $reissued];
        });
    }

    // ---------------------------------------------------------------- reissue

    /**
     * Moves everybody part-way through the retired version onto the new one.
     *
     * Withdraw-and-reallocate rather than repointing the assignment at the new
     * version: the attempts, progress and draft already recorded belong to the
     * old version's assignment, and a repointed assignment would present them as
     * work done on wording the person never saw. Withdrawal keeps every row of it
     * exactly where it is (2.3), and the new assignment starts clean.
     *
     * Three kinds of holder are left alone, and each is in the WHERE clause:
     *
     *  - completed — finished is finished ({@see \TriCab\Core\Onboarding\Domain\Service\SupersessionPolicy});
     *  - withdrawn — nobody's work in progress;
     *  - departed — a leaver cannot be given new work (AllocationPolicy), and a
     *    reissue is new work. They keep what they had.
     *
     * One statement for the withdrawals, so a trainee finishing at the same
     * moment is either finished before it (and skipped) or not.
     *
     * @return list<ContentReissue>
     */
    private function reissue(string $itemType, string $column, int $fromVersionId, ContentVersionRecord $to, ActorContext $actor): array
    {
        $withdrawn = $this->connection->fetchAll(
            <<<SQL
            UPDATE a
            SET [WithdrawnAtUtc] = SYSUTCDATETIME(),
                [WithdrawnByUserId] = :actorUserId,
                [WithdrawalReason] = :reason,
                [UpdatedAtUtc] = SYSUTCDATETIME()
            OUTPUT INSERTED.[UserId], CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId], INSERTED.[Status],
                   CONVERT(VARCHAR(10), INSERTED.[DueDate], 23) AS [DueDate]
            FROM [onboarding].[Assignments] a
            LEFT JOIN [onboarding].[TraineeProfiles] t ON t.[UserId] = a.[UserId]
            WHERE a.[{$column}] = :fromVersionId
              AND a.[WithdrawnAtUtc] IS NULL
              AND a.[Status] <> N'completed'
              AND (t.[DepartedAtUtc] IS NULL OR t.[DepartedAtUtc] > SYSUTCDATETIME())
            SQL,
            [
                ':actorUserId' => $actor->userId,
                ':reason' => sprintf('Replaced by version %d, published as superseding work in progress.', $to->versionNumber),
                ':fromVersionId' => $fromVersionId,
            ],
        );

        $moves = [];
        foreach ($withdrawn as $row) {
            $userId = AzureSqlValueMapper::intValue($row['UserId'] ?? 0);
            $created = $this->connection->fetchAll(
                <<<SQL
                INSERT INTO [onboarding].[Assignments] ([UserId], [ItemType], [{$column}], [Status], [AllocatedByUserId], [DueDate])
                OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
                VALUES (:userId, :itemType, :toVersionId, N'not_started', :actorUserId, TRY_CONVERT(DATE, :dueDate, 23))
                SQL,
                [
                    ':userId' => $userId,
                    ':itemType' => $itemType,
                    ':toVersionId' => $to->versionId,
                    ':actorUserId' => $actor->userId,
                    ':dueDate' => AzureSqlValueMapper::nullableString($row['DueDate'] ?? null),
                ],
            );
            $moves[] = new ContentReissue(
                $userId,
                strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
                strtolower(AzureSqlValueMapper::stringValue($created[0]['PublicId'] ?? '')),
                AzureSqlValueMapper::stringValue($row['Status'] ?? ''),
            );
        }

        return $moves;
    }

    // ------------------------------------------------------------ the bodies

    private function insertVersion(string $itemType, int $itemId, int $number, string $publicId, AuthoredContent $content, ActorContext $actor): void
    {
        $t = self::TABLES[$itemType];
        [$columns, $values, $params] = self::bodyInsert($content);

        $rows = $this->connection->fetchAll(
            <<<SQL
            SET NOCOUNT ON;
            INSERT INTO {$t['versions']} ([PublicId], [{$t['itemId']}], [VersionNumber], {$columns})
            VALUES (CONVERT(UNIQUEIDENTIFIER, :publicId), :itemId, :versionNumber, {$values});
            SELECT CAST(SCOPE_IDENTITY() AS BIGINT) AS [VersionId];
            SQL,
            [':publicId' => $publicId, ':itemId' => $itemId, ':versionNumber' => $number] + $params,
        );

        $this->replaceChildren($itemType, AzureSqlValueMapper::intValue($rows[0]['VersionId'] ?? 0), $content, $actor);
    }

    /**
     * Questions for a module, classifications for a form. Only ever called on
     * a draft this transaction has just inserted or claimed.
     */
    private function replaceChildren(string $itemType, int $versionId, AuthoredContent $content, ActorContext $actor): void
    {
        if ($itemType === AuthoredContent::TYPE_TRAINING) {
            $this->connection->execute('DELETE FROM [onboarding].[Questions] WHERE [TrainingModuleVersionId] = :versionId', [':versionId' => $versionId]);
            foreach ($content->questions as $index => $question) {
                $this->connection->execute(
                    <<<'SQL'
                    INSERT INTO [onboarding].[Questions] ([TrainingModuleVersionId], [Ordinal], [Prompt], [OptionsJson], [CorrectOptionKey], [CorrectReason])
                    VALUES (:versionId, :ordinal, :prompt, :optionsJson, :correctOptionKey, :correctReason)
                    SQL,
                    [
                        ':versionId' => $versionId,
                        ':ordinal' => $index + 1,
                        ':prompt' => $question['prompt'],
                        ':optionsJson' => json_encode($question['options'], self::JSON),
                        ':correctOptionKey' => $question['correctOptionKey'],
                        ':correctReason' => $question['correctReason'],
                    ],
                );
            }
        }

        if ($itemType === AuthoredContent::TYPE_FORM) {
            $this->connection->execute('DELETE FROM [onboarding_hr].[FieldClassifications] WHERE [FormDefinitionVersionId] = :versionId', [':versionId' => $versionId]);
            foreach ($content->classifications as $path => $class) {
                $this->connection->execute(
                    <<<'SQL'
                    INSERT INTO [onboarding_hr].[FieldClassifications] ([FormDefinitionVersionId], [FieldPath], [RetentionClassId], [ClassifiedByUserId])
                    VALUES (:versionId, :fieldPath, :class, :actorUserId)
                    SQL,
                    [':versionId' => $versionId, ':fieldPath' => (string) $path, ':class' => $class, ':actorUserId' => $actor->userId],
                );
            }
        }
    }

    /**
     * @return array{0: string, 1: array<string, bool|float|int|string|\TriCab\Api\Infrastructure\Database\SqlBinary|null>} the SET list and its parameters
     */
    private static function bodyColumns(AuthoredContent $content): array
    {
        [$columns, , $params] = self::bodyInsert($content);
        $set = implode(', ', array_map(
            static fn (string $column): string => $column . ' = :' . strtolower(trim($column, '[]')),
            explode(', ', $columns),
        ));

        return [$set, $params];
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, bool|float|int|string|\TriCab\Api\Infrastructure\Database\SqlBinary|null>}
     */
    private static function bodyInsert(AuthoredContent $content): array
    {
        return match ($content->itemType) {
            AuthoredContent::TYPE_TRAINING => [
                '[PresentationManifestJson], [RequiredSlideCount], [PassMarkPercent]',
                ':presentationmanifestjson, :requiredslidecount, :passmarkpercent',
                [
                    ':presentationmanifestjson' => json_encode($content->slides, self::JSON),
                    ':requiredslidecount' => count($content->slides),
                    ':passmarkpercent' => $content->passMarkPercent,
                ],
            ],
            AuthoredContent::TYPE_POLICY => [
                '[ContentJson], [EffectiveFrom], [DocumentReference], [IssuedLabel], [ReviewDueLabel], [RequiresAcknowledgement]',
                ':contentjson, TRY_CONVERT(DATE, :effectivefrom, 23), :documentreference, :issuedlabel, :reviewduelabel, :requiresacknowledgement',
                [
                    ':contentjson' => json_encode(
                        $content->declaration === null ? ['blocks' => $content->blocks] : ['blocks' => $content->blocks, 'declaration' => $content->declaration],
                        self::JSON,
                    ),
                    ':effectivefrom' => $content->effectiveFrom,
                    ':documentreference' => $content->documentReference,
                    ':issuedlabel' => $content->issuedLabel,
                    ':reviewduelabel' => $content->reviewDueLabel,
                    ':requiresacknowledgement' => $content->requiresAcknowledgement ? 1 : 0,
                ],
            ],
            default => [
                '[SchemaJson]',
                ':schemajson',
                [':schemajson' => json_encode(['fields' => $content->fields], self::JSON)],
            ],
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function content(string $itemType, int $versionId, array $row): AuthoredContent
    {
        if ($itemType === AuthoredContent::TYPE_TRAINING) {
            return AuthoredContent::fromStorage(
                $itemType,
                slides: self::slides(self::decode($row['ManifestJson'] ?? null)),
                passMarkPercent: AzureSqlValueMapper::nullableInt($row['PassMarkPercent'] ?? null),
                questions: $this->questions($versionId),
            );
        }

        if ($itemType === AuthoredContent::TYPE_POLICY) {
            $decoded = self::decode($row['ContentJson'] ?? null);
            $rawBlocks = array_key_exists('blocks', $decoded) ? $decoded['blocks'] : (array_is_list($decoded) ? $decoded : []);
            $blocks = [];
            foreach (is_array($rawBlocks) ? $rawBlocks : [] as $block) {
                if (is_array($block)) {
                    /** @var array<string, mixed> $block */
                    $blocks[] = $block;
                } elseif (is_string($block)) {
                    $blocks[] = ['type' => 'paragraph', 'text' => $block];
                }
            }

            return AuthoredContent::fromStorage(
                $itemType,
                blocks: $blocks,
                declaration: is_string($decoded['declaration'] ?? null) ? $decoded['declaration'] : null,
                effectiveFrom: AzureSqlValueMapper::nullableString($row['EffectiveFrom'] ?? null),
                documentReference: AzureSqlValueMapper::nullableString($row['DocumentReference'] ?? null),
                issuedLabel: AzureSqlValueMapper::nullableString($row['IssuedLabel'] ?? null),
                reviewDueLabel: AzureSqlValueMapper::nullableString($row['ReviewDueLabel'] ?? null),
                requiresAcknowledgement: AzureSqlValueMapper::boolValue($row['RequiresAcknowledgement'] ?? true),
            );
        }

        $schema = self::decode($row['SchemaJson'] ?? null);
        $fields = [];
        foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $field) {
            if (is_array($field)) {
                /** @var array<string, mixed> $field */
                $fields[] = $field;
            }
        }

        return AuthoredContent::fromStorage($itemType, fields: $fields, classifications: $this->classifications($versionId));
    }

    /**
     * @return list<array{publicId: string, prompt: string, options: list<array{key: string, label: string}>, correctOptionKey: string, correctReason: ?string}>
     */
    private function questions(int $versionId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT CONVERT(NVARCHAR(36), q.[PublicId]) AS [PublicId], q.[Prompt], q.[OptionsJson], q.[CorrectOptionKey], q.[CorrectReason]
            FROM [onboarding].[Questions] q
            WHERE q.[TrainingModuleVersionId] = :versionId
            ORDER BY q.[Ordinal]
            SQL,
            [':versionId' => $versionId],
        );

        $questions = [];
        foreach ($rows as $row) {
            $options = [];
            foreach (self::decode($row['OptionsJson'] ?? null) as $option) {
                if (is_array($option) && is_string($option['key'] ?? null)) {
                    $options[] = ['key' => $option['key'], 'label' => is_string($option['label'] ?? null) ? $option['label'] : ''];
                }
            }
            $questions[] = [
                'publicId' => strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
                'prompt' => AzureSqlValueMapper::stringValue($row['Prompt'] ?? ''),
                'options' => $options,
                'correctOptionKey' => AzureSqlValueMapper::stringValue($row['CorrectOptionKey'] ?? ''),
                'correctReason' => AzureSqlValueMapper::nullableString($row['CorrectReason'] ?? null),
            ];
        }

        return $questions;
    }

    /**
     * @return array<string, int>
     */
    private function classifications(int $versionId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [FieldPath], [RetentionClassId] FROM [onboarding_hr].[FieldClassifications] WHERE [FormDefinitionVersionId] = :versionId',
            [':versionId' => $versionId],
        );
        $map = [];
        foreach ($rows as $row) {
            $map[AzureSqlValueMapper::stringValue($row['FieldPath'] ?? '')] = AzureSqlValueMapper::intValue($row['RetentionClassId'] ?? 0);
        }

        return $map;
    }

    /**
     * Both manifest shapes the schema admits — bare ids and `{id, title}`.
     *
     * @param array<array-key, mixed> $manifest
     *
     * @return list<array{id: string, title: string}>
     */
    private static function slides(array $manifest): array
    {
        $slides = [];
        foreach ($manifest as $slide) {
            if (is_string($slide)) {
                $slides[] = ['id' => $slide, 'title' => $slide];
            } elseif (is_array($slide) && is_string($slide['id'] ?? null)) {
                $slides[] = ['id' => $slide['id'], 'title' => is_string($slide['title'] ?? null) ? $slide['title'] : $slide['id']];
            }
        }

        return $slides;
    }

    // ------------------------------------------------------------------ small

    /**
     * Runs one statement and says how many rows it touched, without OUTPUT.
     *
     * @param array<string, bool|float|int|string|\TriCab\Api\Infrastructure\Database\SqlBinary|null> $params
     */
    private function affected(string $sql, array $params): int
    {
        $rows = $this->connection->fetchAll("SET NOCOUNT ON;\n{$sql};\nSELECT @@ROWCOUNT AS [Affected];", $params);

        return AzureSqlValueMapper::intValue($rows[0]['Affected'] ?? 0);
    }

    /**
     * A draft write matched nothing: either it was published under the author,
     * or somebody saved first. The two have different remedies.
     */
    private function whyNotWritten(ContentItemRecord $item, ContentVersionRecord $version): \DomainException
    {
        $current = $this->findVersion($item, $version->publicId);

        return $current !== null && !$current->isDraft()
            ? ContentAuthoringNotPermitted::versionPublished()
            : OnboardingWriteConflict::staleToken();
    }

    /**
     * @param list<\TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent> $events
     */
    private function appendAll(array $events, ActorContext $actor): void
    {
        foreach ($events as $event) {
            AzureSqlOnboardingAdminAuditLog::appendWithin($this->connection, $actor, $event);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function state(array $row): string
    {
        if (!AzureSqlValueMapper::boolValue($row['IsPublished'] ?? false)) {
            return ContentVersionSummary::STATE_DRAFT;
        }

        return AzureSqlValueMapper::nullableString($row['RetiredAtUtc'] ?? null) === null
            ? ContentVersionSummary::STATE_LIVE
            : ContentVersionSummary::STATE_RETIRED;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function lowerOrNull(?string $value): ?string
    {
        return $value === null ? null : strtolower($value);
    }

    /**
     * SQL Server 547: a row something still points at. The connection wraps
     * the PDOException, so it is looked for underneath.
     */
    private static function isForeignKeyViolation(\Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && is_array($cause->errorInfo) && ($cause->errorInfo[1] ?? null) === 547) {
                return true;
            }
        }

        return false;
    }

    private static function isUniqueViolation(PDOException $failure): bool
    {
        $info = $failure->errorInfo;
        $native = is_array($info) ? ($info[1] ?? null) : null;

        return $native === 2601 || $native === 2627
            || str_contains($failure->getMessage(), 'duplicate key');
    }
}
