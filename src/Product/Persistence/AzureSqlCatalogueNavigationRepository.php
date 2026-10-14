<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\CatalogueNodeProductRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeProductStyleRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeTreeNode;
use TriCab\Core\Product\Application\DTO\CatalogueNodeUsage;
use TriCab\Core\Product\Application\DTO\CatalogueProductPlacementRecord;
use TriCab\Core\Product\Application\DTO\CatalogueTabRecord;
use TriCab\Core\Product\Application\DTO\PublicCatalogueProductCard;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTab;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode;
use TriCab\Core\Product\Application\Exception\CatalogueConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\CatalogueNotFound;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;

/**
 * Azure SQL persistence for customer catalogue navigation.
 */
final class AzureSqlCatalogueNavigationRepository implements CatalogueNavigationRepository
{
    private const PUBLIC_PRODUCT_SQL = <<<'SQL'
  AND p.[DeletedAtUtc] IS NULL
  AND p.[IsActive] = 1
  AND ps.[StatusCode] = N'published'
  AND (p.[PublishAtUtc] IS NULL OR p.[PublishAtUtc] <= SYSUTCDATETIME())
  AND (p.[UnpublishAtUtc] IS NULL OR p.[UnpublishAtUtc] > SYSUTCDATETIME())
SQL;

    private const PUBLIC_PRODUCT_THUMBNAIL_APPLY = <<<'SQL'
OUTER APPLY (
    SELECT TOP (1) COALESCE(img.[ThumbnailPublicUrl], img.[PublicUrl]) AS [ThumbnailUrl]
    FROM [product].[ProductImages] img
    WHERE img.[ProductId] = p.[ProductId]
      AND img.[DeletedAtUtc] IS NULL
      AND img.[IsActive] = 1
      AND COALESCE(img.[ThumbnailPublicUrl], img.[PublicUrl]) IS NOT NULL
      AND (img.[UploadStatusCode] IS NULL OR img.[UploadStatusCode] = N'active')
      AND (
            img.[ScanStatusCode] IS NULL
            OR img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
      )
    ORDER BY
        CASE WHEN EXISTS (
            SELECT 1
            FROM [product].[ProductImageRoleAssignments] a
            WHERE a.[ProductImagesId] = img.[ProductImagesId]
              AND a.[RoleCode] = N'product-thumbnail'
        ) THEN 0 ELSE 1 END,
        CASE LOWER(ISNULL(img.[ImageRoleCode], N''))
            WHEN N'product-thumbnail' THEN 0
            WHEN N'primary' THEN 1
            ELSE 2
        END,
        CASE WHEN img.[IsPrimary] = 1 THEN 0 ELSE 1 END,
        img.[DisplayOrder],
        img.[ProductImagesId]
) productThumb
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listTabsAdmin(array $filters = []): array
    {
        return $this->queryTabs([
            'includeDeleted' => $filters['includeDeleted'] ?? false,
            'activeOnly' => $filters['activeOnly'] ?? null,
            'search' => $filters['search'] ?? null,
        ]);
    }

    public function findTabByPublicId(string $publicId, bool $includeDeleted = false): ?CatalogueTabRecord
    {
        $tabs = $this->queryTabs([
            'includeDeleted' => $includeDeleted,
            'publicId' => $publicId,
        ]);

        return $tabs[0] ?? null;
    }

    public function findTabBySlug(string $slug, bool $includeDeleted = false): ?CatalogueTabRecord
    {
        $tabs = $this->queryTabs([
            'includeDeleted' => $includeDeleted,
            'slug' => strtolower(trim($slug)),
        ]);

        return $tabs[0] ?? null;
    }

    public function createTab(array $data, UserId $actorUserId): CatalogueTabRecord
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[CatalogueTabs] (
    [TabCode], [TabName], [Description], [Slug], [DisplayOrder], [IsActive], [IsPublic],
    [CreatedByUserId], [UpdatedByUserId]
)
OUTPUT
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :tabCode, :tabName, :description, :slug, :displayOrder, :isActive, :isPublic,
    :createdByUserId, :updatedByUserId
)
SQL,
            [
                ':tabCode' => strtoupper(trim((string) $data['tabCode'])),
                ':tabName' => trim((string) $data['tabName']),
                ':description' => $data['description'] ?? null,
                ':slug' => strtolower(trim((string) $data['slug'])),
                ':displayOrder' => (int) ($data['displayOrder'] ?? 0),
                ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                ':isPublic' => ($data['isPublic'] ?? true) ? 1 : 0,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('CatalogueTab');
        }

        return $this->findTabByPublicId((string) $rows[0]['PublicId'], true)
            ?? throw CatalogueNotFound::forTab((string) $rows[0]['PublicId']);
    }

    public function updateTab(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord
    {
        $sets = ['[UpdatedAtUtc] = SYSUTCDATETIME()', '[UpdatedByUserId] = :updatedByUserId'];
        $params = [
            ':publicId' => $publicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ];
        if (isset($data['tabCode'])) {
            $sets[] = '[TabCode] = :tabCode';
            $params[':tabCode'] = strtoupper(trim((string) $data['tabCode']));
        }
        if (isset($data['tabName'])) {
            $sets[] = '[TabName] = :tabName';
            $params[':tabName'] = trim((string) $data['tabName']);
        }
        if (array_key_exists('description', $data)) {
            $sets[] = '[Description] = :description';
            $params[':description'] = $data['description'];
        }
        if (isset($data['slug'])) {
            $sets[] = '[Slug] = :slug';
            $params[':slug'] = strtolower(trim((string) $data['slug']));
        }
        if (isset($data['displayOrder'])) {
            $sets[] = '[DisplayOrder] = :displayOrder';
            $params[':displayOrder'] = (int) $data['displayOrder'];
        }
        if (isset($data['isActive'])) {
            $sets[] = '[IsActive] = :isActive';
            $params[':isActive'] = $data['isActive'] ? 1 : 0;
        }
        if (isset($data['isPublic'])) {
            $sets[] = '[IsPublic] = :isPublic';
            $params[':isPublic'] = $data['isPublic'] ? 1 : 0;
        }

        return $this->mutateTab($publicId, $sets, $params);
    }

    public function setTabActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord
    {
        return $this->updateTab($publicId, ['isActive' => $isActive], $expectedRowVersion, $actorUserId);
    }

    public function softDeleteTab(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord
    {
        $actorId = $actorUserId->toInt();
        $params = [
            ':publicId' => $publicId,
            ':deletedByUserId' => $actorId,
            ':updatedByUserId' => $actorId,
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ];
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[CatalogueTabs]
SET [DeletedAtUtc] = SYSUTCDATETIME(),
    [DeletedByUserId] = :deletedByUserId,
    [IsActive] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion
SQL,
            $params,
        );
        if ($rows === []) {
            $this->assertTabExistsForMutation($publicId);
            throw CatalogueConcurrencyConflict::forTab();
        }

        return $this->findTabByPublicId($publicId, true)
            ?? throw CatalogueNotFound::forTab($publicId);
    }

    public function restoreTab(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord
    {
        $params = [
            ':publicId' => $publicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ];
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[CatalogueTabs]
SET [DeletedAtUtc] = NULL,
    [DeletedByUserId] = NULL,
    [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion
SQL,
            $params,
        );
        if ($rows === []) {
            $this->assertTabExistsForMutation($publicId, true);
            throw CatalogueConcurrencyConflict::forTab();
        }

        return $this->findTabByPublicId($publicId, true)
            ?? throw CatalogueNotFound::forTab($publicId);
    }

    public function reorderTabs(array $orderedPublicIds, UserId $actorUserId): void
    {
        unset($actorUserId);
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $this->connection->execute(
                'UPDATE [product].[CatalogueTabs] SET [DisplayOrder] = :displayOrder, [UpdatedAtUtc] = SYSUTCDATETIME() WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
                [':displayOrder' => $order, ':publicId' => $publicId],
            );
            $order += 10;
        }
    }

    public function tabCodeExists(string $tabCode, ?string $excludePublicId = null): bool
    {
        $sql = 'SELECT 1 AS [ExistsFlag] FROM [product].[CatalogueTabs] WHERE [DeletedAtUtc] IS NULL AND [TabCode] = :tabCode';
        $params = [':tabCode' => strtoupper(trim($tabCode))];
        if ($excludePublicId !== null) {
            $sql .= ' AND [PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function tabSlugExists(string $slug, ?string $excludePublicId = null): bool
    {
        $sql = 'SELECT 1 AS [ExistsFlag] FROM [product].[CatalogueTabs] WHERE [DeletedAtUtc] IS NULL AND [Slug] = :slug';
        $params = [':slug' => strtolower(trim($slug))];
        if ($excludePublicId !== null) {
            $sql .= ' AND [PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function listNodesAdmin(array $filters = []): array
    {
        return $this->fetchNodeRecords($filters);
    }

    public function findNodeByPublicId(string $publicId, bool $includeDeleted = false): ?CatalogueNodeRecord
    {
        $rows = $this->fetchNodeRecords(['publicId' => $publicId, 'includeDeleted' => $includeDeleted]);

        return $rows[0] ?? null;
    }

    public function findNodeBySlug(string $tabPublicId, string $slug, ?string $parentPublicId = null, bool $includeDeleted = false): ?CatalogueNodeRecord
    {
        foreach ($this->fetchNodeRecords([
            'tabPublicId' => $tabPublicId,
            'parentPublicId' => $parentPublicId,
            'slug' => strtolower(trim($slug)),
            'includeDeleted' => $includeDeleted,
        ]) as $node) {
            return $node;
        }

        return null;
    }

    public function listNodesForTab(string $tabPublicId, bool $includeDeleted = false): array
    {
        return $this->fetchNodeRecords(['tabPublicId' => $tabPublicId, 'includeDeleted' => $includeDeleted]);
    }

    public function buildAdminTree(string $tabPublicId, bool $includeDeleted = false): CatalogueNodeTreeNode|null
    {
        $roots = array_values(array_filter(
            $this->listNodesForTab($tabPublicId, $includeDeleted),
            static fn (CatalogueNodeRecord $n): bool => $n->parentPublicId === null,
        ));
        if (count($roots) !== 1) {
            return null;
        }

        return $this->buildAdminTreeNode($roots[0], $tabPublicId, $includeDeleted);
    }

    public function buildAdminForest(string $tabPublicId, bool $includeDeleted = false): array
    {
        $nodes = $this->listNodesForTab($tabPublicId, $includeDeleted);
        $forest = [];
        foreach ($nodes as $node) {
            if ($node->parentPublicId !== null) {
                continue;
            }
            $forest[] = $this->buildAdminTreeNode($node, $tabPublicId, $includeDeleted, $nodes);
        }
        usort(
            $forest,
            static fn (CatalogueNodeTreeNode $a, CatalogueNodeTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return $forest;
    }

    public function createNode(array $data, UserId $actorUserId): CatalogueNodeRecord
    {
        $tabId = $this->requireTabId((string) $data['tabPublicId']);
        $parentNodeId = null;
        if (($data['parentPublicId'] ?? null) !== null) {
            $parentNodeId = $this->requireNodeId((string) $data['parentPublicId']);
        }
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[CatalogueNodes] (
    [CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug], [Description],
    [NodeTypeCode], [ImageUrl], [ImageAltText], [ImageMediaPublicId], [DisplayOrder],
    [IsActive], [IsPublic], [SeoTitle], [SeoDescription], [CreatedByUserId], [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :tabId, :parentNodeId, :nodeCode, :nodeName, :slug, :description,
    :nodeTypeCode, :imageUrl, :imageAltText, :imageMediaPublicId, :displayOrder,
    :isActive, :isPublic, :seoTitle, :seoDescription, :createdByUserId, :updatedByUserId
)
SQL,
            [
                ':tabId' => $tabId,
                ':parentNodeId' => $parentNodeId,
                ':nodeCode' => strtoupper(trim((string) $data['nodeCode'])),
                ':nodeName' => trim((string) $data['nodeName']),
                ':slug' => strtolower(trim((string) $data['slug'])),
                ':description' => $data['description'] ?? null,
                ':nodeTypeCode' => strtolower(trim((string) ($data['nodeTypeCode'] ?? 'category'))),
                ':imageUrl' => $data['imageUrl'] ?? null,
                ':imageAltText' => $data['imageAltText'] ?? null,
                ':imageMediaPublicId' => $data['imageMediaPublicId'] ?? null,
                ':displayOrder' => (int) ($data['displayOrder'] ?? 0),
                ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                ':isPublic' => ($data['isPublic'] ?? true) ? 1 : 0,
                ':seoTitle' => $data['seoTitle'] ?? null,
                ':seoDescription' => $data['seoDescription'] ?? null,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('CatalogueNode');
        }
        $publicId = (string) $rows[0]['PublicId'];
        $this->replaceNodeRegionCodes($publicId, $data['regionCodes'] ?? [], $actorUserId);

        return $this->findNodeByPublicId($publicId, true)
            ?? throw CatalogueNotFound::forNode($publicId);
    }

    public function updateNode(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord
    {
        if (array_key_exists('regionCodes', $data) && $data['regionCodes'] !== null) {
            $this->replaceNodeRegionCodes($publicId, $data['regionCodes'], $actorUserId);
            unset($data['regionCodes']);
        }
        $sets = ['[UpdatedAtUtc] = SYSUTCDATETIME()', '[UpdatedByUserId] = :updatedByUserId'];
        $params = [
            ':publicId' => $publicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ];
        if (array_key_exists('parentPublicId', $data)) {
            $sets[] = '[ParentNodeId] = :parentNodeId';
            $params[':parentNodeId'] = $data['parentPublicId'] !== null
                ? $this->requireNodeId((string) $data['parentPublicId'])
                : null;
        }
        foreach ([
            'nodeCode' => 'NodeCode',
            'nodeName' => 'NodeName',
            'slug' => 'Slug',
            'description' => 'Description',
            'nodeTypeCode' => 'NodeTypeCode',
            'imageUrl' => 'ImageUrl',
            'imageAltText' => 'ImageAltText',
            'imageMediaPublicId' => 'ImageMediaPublicId',
            'displayOrder' => 'DisplayOrder',
            'seoTitle' => 'SeoTitle',
            'seoDescription' => 'SeoDescription',
        ] as $key => $column) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $sets[] = sprintf('[%s] = :%s', $column, $key);
            $params[':' . $key] = $data[$key];
        }
        if (isset($data['isActive'])) {
            $sets[] = '[IsActive] = :isActive';
            $params[':isActive'] = $data['isActive'] ? 1 : 0;
        }
        if (isset($data['isPublic'])) {
            $sets[] = '[IsPublic] = :isPublic';
            $params[':isPublic'] = $data['isPublic'] ? 1 : 0;
        }

        return $this->mutateNode($publicId, $sets, $params);
    }

    public function moveNode(string $publicId, ?string $newParentPublicId, string $expectedRowVersion, UserId $actorUserId, ?int $displayOrder = null): CatalogueNodeRecord
    {
        $data = ['parentPublicId' => $newParentPublicId];
        if ($displayOrder !== null) {
            $data['displayOrder'] = $displayOrder;
        }

        return $this->updateNode($publicId, $data, $expectedRowVersion, $actorUserId);
    }

    public function setNodeActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord
    {
        return $this->updateNode($publicId, ['isActive' => $isActive], $expectedRowVersion, $actorUserId);
    }

    public function softDeleteNode(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord
    {
        $actorId = $actorUserId->toInt();
        $params = [
            ':publicId' => $publicId,
            ':deletedByUserId' => $actorId,
            ':updatedByUserId' => $actorId,
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ];
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[CatalogueNodes]
SET [DeletedAtUtc] = SYSUTCDATETIME(),
    [DeletedByUserId] = :deletedByUserId,
    [IsActive] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion
SQL,
            $params,
        );
        if ($rows === []) {
            $this->assertNodeExistsForMutation($publicId);
            throw CatalogueConcurrencyConflict::forNode();
        }

        return $this->findNodeByPublicId($publicId, true)
            ?? throw CatalogueNotFound::forNode($publicId);
    }

    public function restoreNode(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord
    {
        $params = [
            ':publicId' => $publicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                \TriCab\Core\Product\Domain\ValueObject\RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ];
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[CatalogueNodes]
SET [DeletedAtUtc] = NULL,
    [DeletedByUserId] = NULL,
    [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion
SQL,
            $params,
        );
        if ($rows === []) {
            $this->assertNodeExistsForMutation($publicId, true);
            throw CatalogueConcurrencyConflict::forNode();
        }

        return $this->findNodeByPublicId($publicId, true)
            ?? throw CatalogueNotFound::forNode($publicId);
    }

    public function reorderNodes(string $tabPublicId, ?string $parentPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        unset($actorUserId);
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            // sqlsrv PDO rejects reused named parameters; branch parent matching in PHP.
            $parentSql = $parentPublicId === null
                ? ' AND n.[ParentNodeId] IS NULL'
                : ' AND p.[PublicId] = :parentPublicId';
            $params = [
                ':displayOrder' => $order,
                ':publicId' => $publicId,
                ':tabPublicId' => $tabPublicId,
            ];
            if ($parentPublicId !== null) {
                $params[':parentPublicId'] = $parentPublicId;
            }
            $this->connection->execute(
                <<<SQL
UPDATE n
SET n.[DisplayOrder] = :displayOrder, n.[UpdatedAtUtc] = SYSUTCDATETIME()
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
LEFT JOIN [product].[CatalogueNodes] p ON p.[CatalogueNodeId] = n.[ParentNodeId]
WHERE n.[PublicId] = :publicId
  AND t.[PublicId] = :tabPublicId
{$parentSql}
SQL,
                $params,
            );
            $order += 10;
        }
    }

    public function nodeCodeExists(string $tabPublicId, string $nodeCode, ?string $excludePublicId = null): bool
    {
        $sql = <<<'SQL'
SELECT 1 AS [ExistsFlag]
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
WHERE n.[DeletedAtUtc] IS NULL
  AND t.[PublicId] = :tabPublicId
  AND n.[NodeCode] = :nodeCode
SQL;
        $params = [
            ':tabPublicId' => $tabPublicId,
            ':nodeCode' => strtoupper(trim($nodeCode)),
        ];
        if ($excludePublicId !== null) {
            $sql .= ' AND n.[PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function siblingSlugExists(string $tabPublicId, ?string $parentPublicId, string $slug, ?string $excludePublicId = null): bool
    {
        // sqlsrv PDO rejects reused named parameters; branch parent matching in PHP.
        $sql = <<<'SQL'
SELECT 1 AS [ExistsFlag]
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
WHERE n.[DeletedAtUtc] IS NULL
  AND t.[PublicId] = :tabPublicId
  AND n.[Slug] = :slug
SQL;
        $params = [
            ':tabPublicId' => $tabPublicId,
            ':slug' => strtolower(trim($slug)),
        ];
        if ($parentPublicId === null) {
            $sql .= ' AND n.[ParentNodeId] IS NULL';
        } else {
            $sql .= ' AND EXISTS (
                SELECT 1
                FROM [product].[CatalogueNodes] p
                WHERE p.[CatalogueNodeId] = n.[ParentNodeId]
                  AND p.[PublicId] = :parentPublicId
            )';
            $params[':parentPublicId'] = $parentPublicId;
        }
        if ($excludePublicId !== null) {
            $sql .= ' AND n.[PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function nodeDepth(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
WITH [Ancestors] AS (
    SELECT n.[CatalogueNodeId], n.[ParentNodeId], 0 AS [Depth]
    FROM [product].[CatalogueNodes] n
    WHERE n.[PublicId] = :publicId
    UNION ALL
    SELECT p.[CatalogueNodeId], p.[ParentNodeId], a.[Depth] + 1
    FROM [product].[CatalogueNodes] p
    INNER JOIN [Ancestors] a ON p.[CatalogueNodeId] = a.[ParentNodeId]
)
SELECT MAX([Depth]) AS [Depth] FROM [Ancestors]
SQL,
            [':publicId' => $publicId],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Depth'] ?? 0);
    }

    public function wouldCreateCycle(string $nodePublicId, ?string $newParentPublicId): bool
    {
        if ($newParentPublicId === null) {
            return false;
        }
        if ($newParentPublicId === $nodePublicId) {
            return true;
        }
        $rows = $this->connection->fetchAll(
            <<<'SQL'
WITH [Walk] AS (
    SELECT n.[CatalogueNodeId], n.[ParentNodeId], CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId]
    FROM [product].[CatalogueNodes] n
    WHERE n.[PublicId] = :newParentPublicId
    UNION ALL
    SELECT p.[CatalogueNodeId], p.[ParentNodeId], CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId]
    FROM [product].[CatalogueNodes] p
    INNER JOIN [Walk] w ON p.[CatalogueNodeId] = w.[ParentNodeId]
)
SELECT 1 AS [CycleFlag]
FROM [Walk]
WHERE [PublicId] = :nodePublicId
SQL,
            [
                ':newParentPublicId' => $newParentPublicId,
                ':nodePublicId' => $nodePublicId,
            ],
        );

        return $rows !== [];
    }

    public function getNodeUsage(string $publicId): CatalogueNodeUsage
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    SUM(CASE WHEN c.[CatalogueNodeId] IS NOT NULL AND c.[DeletedAtUtc] IS NULL THEN 1 ELSE 0 END) AS [ChildCount],
    SUM(CASE WHEN c.[CatalogueNodeId] IS NOT NULL AND c.[DeletedAtUtc] IS NULL AND c.[IsActive] = 1 THEN 1 ELSE 0 END) AS [ActiveChildCount],
    (
        SELECT COUNT(*)
        FROM [product].[CatalogueNodeProducts] cnp
        INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
        WHERE n.[PublicId] = :productCountPublicId
    ) AS [ProductCount],
    (
        SELECT COUNT(*)
        FROM [product].[CatalogueNodeRegions] cnr
        INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnr.[CatalogueNodeId]
        WHERE n.[PublicId] = :regionCountPublicId
    ) AS [RegionCount]
FROM [product].[CatalogueNodes] p
LEFT JOIN [product].[CatalogueNodes] c ON c.[ParentNodeId] = p.[CatalogueNodeId]
WHERE p.[PublicId] = :publicId
GROUP BY p.[PublicId]
SQL,
            [
                ':publicId' => $publicId,
                ':productCountPublicId' => $publicId,
                ':regionCountPublicId' => $publicId,
            ],
        );
        $row = $rows[0] ?? [];
        $childCount = AzureSqlValueMapper::intValue($row['ChildCount'] ?? 0);
        $productCount = AzureSqlValueMapper::intValue($row['ProductCount'] ?? 0);

        return new CatalogueNodeUsage(
            publicId: $publicId,
            childCount: $childCount,
            activeChildCount: AzureSqlValueMapper::intValue($row['ActiveChildCount'] ?? 0),
            productCount: $productCount,
            regionCount: AzureSqlValueMapper::intValue($row['RegionCount'] ?? 0),
            summary: sprintf('%d child categories, %d products', $childCount, $productCount),
        );
    }

    public function listNodeProducts(string $nodePublicId, bool $activeOnly = false): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    p.[ProductName],
    COALESCE(primarySlug.[Slug], CONVERT(NVARCHAR(36), p.[PublicId])) AS [ProductSlug],
    p.[InternalCode],
    cnp.[DisplayOrder],
    cnp.[IsFeatured],
    cnp.[IsActive],
    ps.[StatusCode] AS [PublicationStatus]
FROM [product].[CatalogueNodeProducts] cnp
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
OUTER APPLY (
    SELECT TOP (1) s.[Slug]
    FROM [product].[ProductSlugs] s
    WHERE s.[ProductId] = p.[ProductId]
      AND s.[IsPrimary] = 1
      AND s.[DeletedAtUtc] IS NULL
) primarySlug
WHERE n.[PublicId] = :nodePublicId
SQL;
        if ($activeOnly) {
            $sql .= ' AND cnp.[IsActive] = 1';
        }
        $sql .= ' ORDER BY cnp.[DisplayOrder], p.[ProductName]';

        return array_map(
            static fn (array $row): CatalogueNodeProductRecord => new CatalogueNodeProductRecord(
                productPublicId: AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
                productName: AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
                productSlug: AzureSqlValueMapper::stringValue($row['ProductSlug'] ?? ''),
                internalCode: AzureSqlValueMapper::nullableString($row['InternalCode'] ?? null),
                thumbnailUrl: null,
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                isFeatured: AzureSqlValueMapper::boolValue($row['IsFeatured'] ?? false),
                isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
                publicationStatus: AzureSqlValueMapper::stringValue($row['PublicationStatus'] ?? ''),
            ),
            $this->connection->fetchAll($sql, [':nodePublicId' => $nodePublicId]),
        );
    }

    public function listNodeProductStyles(string $nodePublicId, bool $activeOnly = false): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), ps.[PublicId]) AS [ProductStylePublicId],
    ps.[StyleCode],
    ps.[DisplayName],
    cnps.[DisplayOrder],
    cnps.[IsActive],
    (
        SELECT COUNT(*)
        FROM [product].[ProductStyles] child
        WHERE child.[ParentStyleId] = ps.[ProductStyleId]
          AND child.[DeletedAtUtc] IS NULL
          AND child.[IsActive] = 1
    ) AS [ChildStyleCount],
    (
        SELECT COUNT(*)
        FROM [product].[ProductStyleAssignments] psa
        INNER JOIN [product].[Products] p ON p.[ProductId] = psa.[ProductId]
        WHERE psa.[ProductStyleId] = ps.[ProductStyleId]
          AND p.[DeletedAtUtc] IS NULL
    ) AS [AssignedProductCount]
FROM [product].[CatalogueNodeProductStyles] cnps
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnps.[CatalogueNodeId]
INNER JOIN [product].[ProductStyles] ps ON ps.[ProductStyleId] = cnps.[ProductStyleId]
WHERE n.[PublicId] = :nodePublicId
  AND ps.[DeletedAtUtc] IS NULL
SQL;
        if ($activeOnly) {
            $sql .= ' AND cnps.[IsActive] = 1 AND ps.[IsActive] = 1';
        }
        $sql .= ' ORDER BY cnps.[DisplayOrder], ps.[StyleCode]';

        return array_map(
            static fn (array $row): CatalogueNodeProductStyleRecord => new CatalogueNodeProductStyleRecord(
                productStylePublicId: AzureSqlValueMapper::stringValue($row['ProductStylePublicId'] ?? ''),
                styleCode: AzureSqlValueMapper::stringValue($row['StyleCode'] ?? ''),
                displayName: AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
                childStyleCount: AzureSqlValueMapper::intValue($row['ChildStyleCount'] ?? 0),
                assignedProductCount: AzureSqlValueMapper::intValue($row['AssignedProductCount'] ?? 0),
            ),
            $this->connection->fetchAll($sql, [':nodePublicId' => $nodePublicId]),
        );
    }

    public function addNodeProductStyle(
        string $nodePublicId,
        string $productStylePublicId,
        int $displayOrder,
        UserId $actorUserId,
    ): void {
        $nodeId = $this->requireNodeId($nodePublicId);
        $styleId = $this->requireProductStyleId($productStylePublicId);
        $existing = $this->connection->fetchAll(
            <<<'SQL'
SELECT [IsActive]
FROM [product].[CatalogueNodeProductStyles]
WHERE [CatalogueNodeId] = :nodeId AND [ProductStyleId] = :styleId
SQL,
            [':nodeId' => $nodeId, ':styleId' => $styleId],
        );
        if ($existing !== []) {
            if (AzureSqlValueMapper::boolValue($existing[0]['IsActive'] ?? false)) {
                return;
            }
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[CatalogueNodeProductStyles]
SET [IsActive] = 1,
    [DisplayOrder] = :displayOrder,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [CatalogueNodeId] = :nodeId AND [ProductStyleId] = :styleId
SQL,
                [
                    ':nodeId' => $nodeId,
                    ':styleId' => $styleId,
                    ':displayOrder' => $displayOrder,
                    ':updatedByUserId' => $actorUserId->toInt(),
                ],
            );

            return;
        }
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [product].[CatalogueNodeProductStyles] (
    [CatalogueNodeId], [ProductStyleId], [DisplayOrder], [IsActive],
    [CreatedByUserId], [UpdatedByUserId]
) VALUES (
    :nodeId, :styleId, :displayOrder, 1,
    :createdByUserId, :updatedByUserId
)
SQL,
            [
                ':nodeId' => $nodeId,
                ':styleId' => $styleId,
                ':displayOrder' => $displayOrder,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
    }

    public function deactivateNodeProductStyle(
        string $nodePublicId,
        string $productStylePublicId,
        UserId $actorUserId,
    ): void {
        $nodeId = $this->requireNodeId($nodePublicId);
        $styleId = $this->requireProductStyleId($productStylePublicId);
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[CatalogueNodeProductStyles]
SET [IsActive] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [CatalogueNodeId] = :nodeId AND [ProductStyleId] = :styleId AND [IsActive] = 1
SQL,
            [
                ':nodeId' => $nodeId,
                ':styleId' => $styleId,
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
    }

    public function listNodePublicIdsWithActiveStyleLinks(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT DISTINCT CONVERT(NVARCHAR(36), n.[PublicId]) AS [NodePublicId]
FROM [product].[CatalogueNodeProductStyles] cnps
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnps.[CatalogueNodeId]
INNER JOIN [product].[ProductStyles] ps ON ps.[ProductStyleId] = cnps.[ProductStyleId]
WHERE cnps.[IsActive] = 1
  AND n.[DeletedAtUtc] IS NULL
  AND n.[IsActive] = 1
  AND ps.[DeletedAtUtc] IS NULL
  AND ps.[IsActive] = 1
SQL,
        );

        return array_values(array_map(
            static fn (array $row): string => AzureSqlValueMapper::stringValue($row['NodePublicId'] ?? ''),
            $rows,
        ));
    }

    public function replaceNodeProducts(string $nodePublicId, array $placements, UserId $actorUserId): void
    {
        $nodeId = $this->requireNodeId($nodePublicId);
        $this->connection->beginTransaction();
        try {
            $this->connection->execute(
                'DELETE FROM [product].[CatalogueNodeProducts] WHERE [CatalogueNodeId] = :nodeId',
                [':nodeId' => $nodeId],
            );
            $order = 10;
            foreach ($placements as $placement) {
                $productPublicId = (string) ($placement['productPublicId'] ?? '');
                if ($productPublicId === '') {
                    continue;
                }
                $productId = $this->requireProductId($productPublicId);
                $this->connection->execute(
                    <<<'SQL'
INSERT INTO [product].[CatalogueNodeProducts] (
    [CatalogueNodeId], [ProductId], [DisplayOrder], [IsFeatured], [IsActive],
    [CreatedByUserId], [UpdatedByUserId]
) VALUES (
    :nodeId, :productId, :displayOrder, :isFeatured, :isActive,
    :createdByUserId, :updatedByUserId
)
SQL,
                    [
                        ':nodeId' => $nodeId,
                        ':productId' => $productId,
                        ':displayOrder' => (int) ($placement['displayOrder'] ?? $order),
                        ':isFeatured' => ($placement['isFeatured'] ?? false) ? 1 : 0,
                        ':isActive' => ($placement['isActive'] ?? true) ? 1 : 0,
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );
                $order += 10;
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function listProductPlacements(string $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [NodePublicId],
    n.[NodeName],
    n.[Slug] AS [NodeSlug],
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [TabPublicId],
    t.[TabName],
    t.[Slug] AS [TabSlug],
    cnp.[DisplayOrder],
    cnp.[IsFeatured],
    cnp.[IsActive]
FROM [product].[CatalogueNodeProducts] cnp
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
WHERE p.[PublicId] = :productPublicId
ORDER BY t.[TabName], cnp.[DisplayOrder], n.[NodeName]
SQL,
            [':productPublicId' => $productPublicId],
        );
        $out = [];
        foreach ($rows as $row) {
            $nodePublicId = AzureSqlValueMapper::stringValue($row['NodePublicId'] ?? '');
            $out[] = new CatalogueProductPlacementRecord(
                nodePublicId: $nodePublicId,
                nodeName: AzureSqlValueMapper::stringValue($row['NodeName'] ?? ''),
                nodeSlug: AzureSqlValueMapper::stringValue($row['NodeSlug'] ?? ''),
                tabPublicId: AzureSqlValueMapper::stringValue($row['TabPublicId'] ?? ''),
                tabName: AzureSqlValueMapper::stringValue($row['TabName'] ?? ''),
                tabSlug: AzureSqlValueMapper::stringValue($row['TabSlug'] ?? ''),
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                isFeatured: AzureSqlValueMapper::boolValue($row['IsFeatured'] ?? false),
                isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
                breadcrumbNames: $this->breadcrumbNames($nodePublicId),
            );
        }

        return $out;
    }

    public function replaceProductPlacements(string $productPublicId, array $placements, UserId $actorUserId): void
    {
        $productId = $this->requireProductId($productPublicId);
        $this->connection->beginTransaction();
        try {
            $this->connection->execute(
                'DELETE FROM [product].[CatalogueNodeProducts] WHERE [ProductId] = :productId',
                [':productId' => $productId],
            );
            foreach ($placements as $placement) {
                $nodePublicId = (string) ($placement['nodePublicId'] ?? '');
                if ($nodePublicId === '') {
                    continue;
                }
                $nodeId = $this->requireNodeId($nodePublicId);
                $this->connection->execute(
                    <<<'SQL'
INSERT INTO [product].[CatalogueNodeProducts] (
    [CatalogueNodeId], [ProductId], [DisplayOrder], [IsFeatured], [IsActive],
    [CreatedByUserId], [UpdatedByUserId]
) VALUES (
    :nodeId, :productId, :displayOrder, :isFeatured, :isActive,
    :createdByUserId, :updatedByUserId
)
SQL,
                    [
                        ':nodeId' => $nodeId,
                        ':productId' => $productId,
                        ':displayOrder' => (int) ($placement['displayOrder'] ?? 0),
                        ':isFeatured' => ($placement['isFeatured'] ?? false) ? 1 : 0,
                        ':isActive' => ($placement['isActive'] ?? true) ? 1 : 0,
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function listPublicTabs(?string $regionCode = null): array
    {
        unset($regionCode);
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId],
    t.[TabCode],
    t.[TabName],
    t.[Description],
    t.[Slug],
    t.[DisplayOrder]
FROM [product].[CatalogueTabs] t
WHERE t.[DeletedAtUtc] IS NULL
  AND t.[IsActive] = 1
  AND t.[IsPublic] = 1
ORDER BY t.[DisplayOrder], t.[TabName]
SQL,
        );

        return array_map(
            static fn (array $row): PublicCatalogueTab => new PublicCatalogueTab(
                publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                tabCode: AzureSqlValueMapper::stringValue($row['TabCode'] ?? ''),
                tabName: AzureSqlValueMapper::stringValue($row['TabName'] ?? ''),
                description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
                slug: AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            ),
            $rows,
        );
    }

    public function getPublicTabTree(string $tabSlug, ?string $regionCode = null, bool $includeProducts = false): array
    {
        $tab = $this->findTabBySlug($tabSlug);
        if ($tab === null || !$tab->isActive || !$tab->isPublic || $tab->isDeleted) {
            return [];
        }
        $nodes = $this->fetchPublicNodes($tab->publicId, $regionCode);
        $forest = [];
        foreach ($nodes as $node) {
            if ($node['parentPublicId'] !== null) {
                continue;
            }
            $forest[] = $this->buildPublicTreeNode($node, $nodes, $regionCode, $includeProducts, 0);
        }
        usort(
            $forest,
            static fn (PublicCatalogueTreeNode $a, PublicCatalogueTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return $forest;
    }

    public function getPublicNodeBySlug(string $nodeSlug, ?string $tabSlug = null, ?string $regionCode = null): ?PublicCatalogueTreeNode
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    n.[NodeCode],
    n.[NodeName],
    n.[Slug],
    n.[Description],
    n.[NodeTypeCode],
    n.[ImageUrl],
    n.[ImageAltText],
    n.[DisplayOrder],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentPublicId],
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [TabPublicId]
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
LEFT JOIN [product].[CatalogueNodes] parent ON parent.[CatalogueNodeId] = n.[ParentNodeId]
WHERE n.[DeletedAtUtc] IS NULL
  AND n.[IsActive] = 1
  AND n.[IsPublic] = 1
  AND n.[Slug] = :slug
SQL;
        $params = [':slug' => strtolower(trim($nodeSlug))];
        if ($tabSlug !== null && trim($tabSlug) !== '') {
            $sql .= ' AND t.[Slug] = :tabSlug';
            $params[':tabSlug'] = strtolower(trim($tabSlug));
        }
        $rows = $this->connection->fetchAll($sql, $params);
        foreach ($rows as $row) {
            if (!$this->nodeVisibleForRegion((string) $row['PublicId'], $regionCode)) {
                continue;
            }
            $nodes = $this->fetchPublicNodes((string) $row['TabPublicId'], $regionCode);
            $node = $this->findNodeInFlatList($nodes, (string) $row['PublicId']);
            if ($node === null) {
                continue;
            }

            return $this->buildRequestedPublicNode($node, $nodes, $regionCode);
        }

        return null;
    }

    public function getPublicNodeByPublicId(string $nodePublicId, ?string $regionCode = null): ?PublicCatalogueTreeNode
    {
        $node = $this->findNodeByPublicId($nodePublicId);
        if ($node === null || !$node->isActive || !$node->isPublic || $node->isDeleted) {
            return null;
        }
        if (!$this->nodeVisibleForRegion($nodePublicId, $regionCode)) {
            return null;
        }
        $nodes = $this->fetchPublicNodes($node->tabPublicId, $regionCode);
        $flat = $this->findNodeInFlatList($nodes, $nodePublicId);
        if ($flat === null) {
            return null;
        }

        return $this->buildRequestedPublicNode($flat, $nodes, $regionCode);
    }

    public function listPublicChildren(string $nodePublicId, ?string $regionCode = null): array
    {
        $nodes = $this->fetchPublicChildren($nodePublicId, $regionCode);
        $out = [];
        foreach ($nodes as $node) {
            $out[] = $this->buildPublicTreeNode($node, $nodes, $regionCode, false, $this->nodeDepth($node['publicId']));
        }

        return $out;
    }

    public function listPublicNodeProducts(string $nodePublicId, ?string $regionCode = null): array
    {
        $regionFilter = '';
        $params = [':nodePublicId' => $nodePublicId];
        if ($regionCode !== null && trim($regionCode) !== '') {
            $regionFilter = <<<'SQL'

  AND EXISTS (
        SELECT 1
        FROM [product].[ProductRegions] prv
        INNER JOIN [product].[Regions] rv ON rv.[RegionId] = prv.[RegionId]
        WHERE prv.[ProductId] = p.[ProductId]
          AND prv.[DeletedAtUtc] IS NULL
          AND prv.[IsVisible] = 1
          AND rv.[RegionCode] = :regionCode
          AND (prv.[AvailableFromUtc] IS NULL OR prv.[AvailableFromUtc] <= SYSUTCDATETIME())
          AND (prv.[AvailableToUtc] IS NULL OR prv.[AvailableToUtc] > SYSUTCDATETIME())
  )
SQL;
            $params[':regionCode'] = strtoupper(trim($regionCode));
        }
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName] AS [Name],
    COALESCE(primarySlug.[Slug], CONVERT(NVARCHAR(36), p.[PublicId])) AS [Slug],
    p.[InternalCode],
    cnp.[DisplayOrder],
    cnp.[IsFeatured],
    productThumb.[ThumbnailUrl]
FROM [product].[CatalogueNodeProducts] cnp
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
OUTER APPLY (
    SELECT TOP (1) s.[Slug]
    FROM [product].[ProductSlugs] s
    WHERE s.[ProductId] = p.[ProductId]
      AND s.[IsPrimary] = 1
      AND s.[DeletedAtUtc] IS NULL
) primarySlug
SQL;
        $sql .= "\n" . self::PUBLIC_PRODUCT_THUMBNAIL_APPLY . <<<'SQL'

WHERE n.[PublicId] = :nodePublicId
  AND cnp.[IsActive] = 1
SQL;
        $sql .= $regionFilter . self::PUBLIC_PRODUCT_SQL . ' ORDER BY cnp.[DisplayOrder], p.[ProductName]';

        return array_map(
            static fn (array $row): PublicCatalogueProductCard => new PublicCatalogueProductCard(
                publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
                slug: AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
                internalCode: AzureSqlValueMapper::nullableString($row['InternalCode'] ?? null),
                thumbnailUrl: AzureSqlValueMapper::nullableString($row['ThumbnailUrl'] ?? null),
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                isFeatured: AzureSqlValueMapper::boolValue($row['IsFeatured'] ?? false),
            ),
            $this->connection->fetchAll($sql, $params),
        );
    }

    public function listPublicProductThumbnailUrls(array $productPublicIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $productPublicIds,
            static fn (string $id): bool => $id !== '',
        )));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $index => $id) {
                $name = ':pid' . $index;
                $placeholders[] = $name;
                $params[$name] = $id;
            }
            $inList = implode(', ', $placeholders);
            $apply = self::PUBLIC_PRODUCT_THUMBNAIL_APPLY;
            $sql = <<<SQL
SELECT CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId], productThumb.[ThumbnailUrl]
FROM [product].[Products] p
{$apply}
WHERE p.[PublicId] IN ({$inList})
  AND p.[DeletedAtUtc] IS NULL
SQL;
            foreach ($this->connection->fetchAll($sql, $params) as $row) {
                $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
                $url = AzureSqlValueMapper::nullableString($row['ThumbnailUrl'] ?? null);
                if ($publicId !== '' && $url !== null) {
                    $out[$publicId] = $url;
                }
            }
        }

        return $out;
    }

    public function searchAdmin(string $query, int $limit = 50): array
    {
        $search = '%' . trim($query) . '%';

        return [
            'tabs' => array_slice($this->listTabsAdmin(['search' => $query]), 0, $limit),
            'nodes' => array_slice($this->listNodesAdmin(['search' => $query]), 0, $limit),
        ];
    }

    /**
     * @param list<string> $sets
     * @param array<string, mixed> $params
     */
    private function mutateTab(string $publicId, array $sets, array $params): CatalogueTabRecord
    {
        $sql = sprintf(
            'UPDATE [product].[CatalogueTabs] SET %s OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion',
            implode(', ', $sets),
        );
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            $this->assertTabExistsForMutation($publicId);
            throw CatalogueConcurrencyConflict::forTab();
        }

        return $this->findTabByPublicId($publicId, true)
            ?? throw CatalogueNotFound::forTab($publicId);
    }

    /**
     * @param list<string> $sets
     * @param array<string, mixed> $params
     */
    private function mutateNode(string $publicId, array $sets, array $params): CatalogueNodeRecord
    {
        $sql = sprintf(
            'UPDATE [product].[CatalogueNodes] SET %s OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion',
            implode(', ', $sets),
        );
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            $this->assertNodeExistsForMutation($publicId);
            throw CatalogueConcurrencyConflict::forNode();
        }

        return $this->findNodeByPublicId($publicId, true)
            ?? throw CatalogueNotFound::forNode($publicId);
    }

    /** @param array<string, mixed> $filters
     * @return list<CatalogueNodeRecord>
     */
    private function fetchNodeRecords(array $filters = []): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [TabPublicId],
    t.[Slug] AS [TabSlug],
    t.[TabName],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentPublicId],
    parent.[NodeName] AS [ParentName],
    n.[NodeCode],
    n.[NodeName],
    n.[Slug],
    n.[Description],
    n.[NodeTypeCode],
    n.[ImageUrl],
    n.[ImageAltText],
    CONVERT(NVARCHAR(36), n.[ImageMediaPublicId]) AS [ImageMediaPublicId],
    n.[DisplayOrder],
    n.[IsActive],
    n.[IsPublic],
    CASE WHEN n.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
    n.[SeoTitle],
    n.[SeoDescription],
    CONVERT(VARBINARY(8), n.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), n.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), n.[UpdatedAtUtc], 126) AS [UpdatedAtUtc],
    (
        SELECT COUNT(*)
        FROM [product].[CatalogueNodes] c
        WHERE c.[ParentNodeId] = n.[CatalogueNodeId] AND c.[DeletedAtUtc] IS NULL
    ) AS [ChildCount],
    (
        SELECT COUNT(*)
        FROM [product].[CatalogueNodeProducts] cnp
        WHERE cnp.[CatalogueNodeId] = n.[CatalogueNodeId]
    ) AS [ProductCount]
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
LEFT JOIN [product].[CatalogueNodes] parent ON parent.[CatalogueNodeId] = n.[ParentNodeId]
WHERE 1 = 1
SQL;
        $params = [];
        if (!($filters['includeDeleted'] ?? false)) {
            $sql .= ' AND n.[DeletedAtUtc] IS NULL';
        }
        if (isset($filters['publicId']) && is_string($filters['publicId'])) {
            $sql .= ' AND n.[PublicId] = :publicId';
            $params[':publicId'] = $filters['publicId'];
        }
        if (isset($filters['tabPublicId']) && is_string($filters['tabPublicId'])) {
            $sql .= ' AND t.[PublicId] = :tabPublicId';
            $params[':tabPublicId'] = $filters['tabPublicId'];
        }
        if (array_key_exists('parentPublicId', $filters)) {
            if ($filters['parentPublicId'] === null) {
                $sql .= ' AND n.[ParentNodeId] IS NULL';
            } else {
                $sql .= ' AND parent.[PublicId] = :parentPublicId';
                $params[':parentPublicId'] = $filters['parentPublicId'];
            }
        }
        if (isset($filters['slug']) && is_string($filters['slug'])) {
            $sql .= ' AND n.[Slug] = :slug';
            $params[':slug'] = $filters['slug'];
        }
        if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null) {
            $sql .= ' AND n.[IsActive] = :isActive';
            $params[':isActive'] = $filters['activeOnly'] ? 1 : 0;
        }
        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $sql .= ' AND (n.[NodeName] LIKE :search OR n.[NodeCode] LIKE :search OR n.[Slug] LIKE :search)';
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }
        $sql .= ' ORDER BY n.[DisplayOrder], n.[NodeName]';

        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            return [];
        }

        $parentsByPublicId = [];
        $publicIds = [];
        foreach ($rows as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            $publicIds[] = $publicId;
            $parentsByPublicId[$publicId] = AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null);
        }

        $depths = $this->depthsFromParentMap($parentsByPublicId);
        $missingDepthIds = [];
        foreach ($publicIds as $publicId) {
            if (!array_key_exists($publicId, $depths)) {
                $missingDepthIds[] = $publicId;
            }
        }
        if ($missingDepthIds !== []) {
            foreach ($this->loadDepthsForPublicIds($missingDepthIds) as $publicId => $depth) {
                $depths[$publicId] = $depth;
            }
        }

        $regionsByNode = $this->loadRegionCodesForPublicIds($publicIds);

        $out = [];
        foreach ($rows as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            $out[] = new CatalogueNodeRecord(
                publicId: $publicId,
                tabPublicId: AzureSqlValueMapper::stringValue($row['TabPublicId'] ?? ''),
                tabSlug: AzureSqlValueMapper::stringValue($row['TabSlug'] ?? ''),
                tabName: AzureSqlValueMapper::stringValue($row['TabName'] ?? ''),
                parentPublicId: AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
                parentName: AzureSqlValueMapper::nullableString($row['ParentName'] ?? null),
                nodeCode: AzureSqlValueMapper::stringValue($row['NodeCode'] ?? ''),
                nodeName: AzureSqlValueMapper::stringValue($row['NodeName'] ?? ''),
                slug: AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
                description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
                nodeTypeCode: AzureSqlValueMapper::stringValue($row['NodeTypeCode'] ?? 'category'),
                imageUrl: AzureSqlValueMapper::nullableString($row['ImageUrl'] ?? null),
                imageAltText: AzureSqlValueMapper::nullableString($row['ImageAltText'] ?? null),
                imageMediaPublicId: AzureSqlValueMapper::nullableString($row['ImageMediaPublicId'] ?? null),
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
                isPublic: AzureSqlValueMapper::boolValue($row['IsPublic'] ?? true),
                isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
                seoTitle: AzureSqlValueMapper::nullableString($row['SeoTitle'] ?? null),
                seoDescription: AzureSqlValueMapper::nullableString($row['SeoDescription'] ?? null),
                rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? '')->toBase64(),
                depth: $depths[$publicId] ?? 0,
                childCount: AzureSqlValueMapper::intValue($row['ChildCount'] ?? 0),
                productCount: AzureSqlValueMapper::intValue($row['ProductCount'] ?? 0),
                regionCodes: $regionsByNode[$publicId] ?? [],
                createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
                updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<CatalogueTabRecord>
     */
    private function queryTabs(array $filters): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId],
    t.[TabCode],
    t.[TabName],
    t.[Description],
    t.[Slug],
    t.[DisplayOrder],
    t.[IsActive],
    t.[IsPublic],
    CASE WHEN t.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
    CONVERT(VARBINARY(8), t.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), t.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), t.[UpdatedAtUtc], 126) AS [UpdatedAtUtc],
    (
        SELECT COUNT(*)
        FROM [product].[CatalogueNodes] n
        WHERE n.[CatalogueTabId] = t.[CatalogueTabId]
          AND n.[ParentNodeId] IS NULL
          AND n.[DeletedAtUtc] IS NULL
    ) AS [RootNodeCount]
FROM [product].[CatalogueTabs] t
WHERE 1 = 1
SQL;
        $params = [];
        if (!($filters['includeDeleted'] ?? false)) {
            $sql .= ' AND t.[DeletedAtUtc] IS NULL';
        }
        if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null) {
            $sql .= ' AND t.[IsActive] = :isActive';
            $params[':isActive'] = $filters['activeOnly'] ? 1 : 0;
        }
        $publicId = $filters['publicId'] ?? null;
        if (is_string($publicId) && $publicId !== '') {
            $sql .= ' AND t.[PublicId] = :publicId';
            $params[':publicId'] = $publicId;
        }
        $slug = $filters['slug'] ?? null;
        if (is_string($slug) && $slug !== '') {
            $sql .= ' AND t.[Slug] = :slug';
            $params[':slug'] = strtolower(trim($slug));
        }
        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $sql .= ' AND (t.[TabName] LIKE :search OR t.[TabCode] LIKE :search OR t.[Slug] LIKE :search)';
            $params[':search'] = '%' . trim($search) . '%';
        }
        $sql .= ' ORDER BY t.[DisplayOrder], t.[TabName]';

        return array_map(
            fn (array $row): CatalogueTabRecord => $this->hydrateTab($row),
            $this->connection->fetchAll($sql, $params),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateTab(array $row): CatalogueTabRecord
    {
        return new CatalogueTabRecord(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            tabCode: AzureSqlValueMapper::stringValue($row['TabCode'] ?? ''),
            tabName: AzureSqlValueMapper::stringValue($row['TabName'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            slug: AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isPublic: AzureSqlValueMapper::boolValue($row['IsPublic'] ?? true),
            isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? '')->toBase64(),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
            rootNodeCount: AzureSqlValueMapper::intValue($row['RootNodeCount'] ?? 0),
        );
    }

    /**
     * @param list<CatalogueNodeRecord>|null $allNodes
     */
    private function buildAdminTreeNode(
        CatalogueNodeRecord $node,
        string $tabPublicId,
        bool $includeDeleted,
        ?array $allNodes = null,
    ): CatalogueNodeTreeNode {
        $allNodes ??= $this->listNodesForTab($tabPublicId, $includeDeleted);
        $children = [];
        foreach ($allNodes as $candidate) {
            if ($candidate->parentPublicId !== $node->publicId) {
                continue;
            }
            $children[] = $this->buildAdminTreeNode($candidate, $tabPublicId, $includeDeleted, $allNodes);
        }
        usort(
            $children,
            static fn (CatalogueNodeTreeNode $a, CatalogueNodeTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return new CatalogueNodeTreeNode(
            publicId: $node->publicId,
            nodeCode: $node->nodeCode,
            nodeName: $node->nodeName,
            slug: $node->slug,
            description: $node->description,
            nodeTypeCode: $node->nodeTypeCode,
            imageUrl: $node->imageUrl,
            imageAltText: $node->imageAltText,
            displayOrder: $node->displayOrder,
            isActive: $node->isActive,
            isPublic: $node->isPublic,
            depth: $node->depth,
            childCount: $node->childCount,
            productCount: $node->productCount,
            children: $children,
            regionCodes: $node->regionCodes,
            rowVersion: $node->rowVersion,
            parentPublicId: $node->parentPublicId,
        );
    }

    /**
     * @return list<array{
     *   publicId: string,
     *   nodeCode: string,
     *   nodeName: string,
     *   slug: string,
     *   description: ?string,
     *   nodeTypeCode: string,
     *   imageUrl: ?string,
     *   imageAltText: ?string,
     *   displayOrder: int,
     *   parentPublicId: ?string,
     *   productCount: int
     * }>
     */
    private function fetchPublicNodes(string $tabPublicId, ?string $regionCode): array
    {
        $productCountSelect = $this->publicNodeProductCountSelect($regionCode);
        $params = [':tabPublicId' => $tabPublicId];
        if ($regionCode !== null && trim($regionCode) !== '') {
            $params[':regionCode'] = strtoupper(trim($regionCode));
        }

        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    n.[NodeCode],
    n.[NodeName],
    n.[Slug],
    n.[Description],
    n.[NodeTypeCode],
    n.[ImageUrl],
    n.[ImageAltText],
    n.[DisplayOrder],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentPublicId],
    leafThumb.[DedicatedUrl] AS [ProductThumbnailUrl],
    {$productCountSelect}
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
LEFT JOIN [product].[CatalogueNodes] parent ON parent.[CatalogueNodeId] = n.[ParentNodeId]
OUTER APPLY (
    SELECT TOP (1) COALESCE(img.[PublicUrl], img.[ThumbnailPublicUrl]) AS [DedicatedUrl]
    FROM [product].[CatalogueNodeProducts] cnp
    INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
    INNER JOIN [product].[ProductImages] img ON img.[ProductId] = p.[ProductId]
    WHERE cnp.[CatalogueNodeId] = n.[CatalogueNodeId]
      AND cnp.[IsActive] = 1
      AND p.[DeletedAtUtc] IS NULL
      AND img.[DeletedAtUtc] IS NULL
      AND img.[IsActive] = 1
      AND COALESCE(img.[PublicUrl], img.[ThumbnailPublicUrl]) IS NOT NULL
      AND (img.[UploadStatusCode] IS NULL OR img.[UploadStatusCode] = N'active')
      AND (
            img.[ScanStatusCode] IS NULL
            OR img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
      )
      AND (
            EXISTS (
                SELECT 1
                FROM [product].[ProductImageRoleAssignments] a
                WHERE a.[ProductImagesId] = img.[ProductImagesId]
                  AND a.[RoleCode] = N'product-thumbnail'
            )
            OR LOWER(ISNULL(img.[ImageRoleCode], N'')) = N'product-thumbnail'
      )
      AND NOT EXISTS (
            SELECT 1
            FROM [product].[CatalogueNodes] child
            WHERE child.[ParentNodeId] = n.[CatalogueNodeId]
              AND child.[DeletedAtUtc] IS NULL
              AND child.[IsActive] = 1
              AND child.[IsPublic] = 1
      )
    ORDER BY cnp.[DisplayOrder], p.[ProductId], img.[DisplayOrder], img.[ProductImagesId]
) leafThumb
WHERE t.[PublicId] = :tabPublicId
  AND n.[DeletedAtUtc] IS NULL
  AND n.[IsActive] = 1
  AND n.[IsPublic] = 1
ORDER BY n.[DisplayOrder], n.[NodeName]
SQL,
            $params,
        );
        $publicIds = [];
        foreach ($rows as $row) {
            $publicIds[] = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        }
        $regionsByNode = $regionCode !== null && trim($regionCode) !== ''
            ? $this->loadRegionCodesForPublicIds($publicIds)
            : [];

        $out = [];
        foreach ($rows as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            if (!$this->nodeVisibleForRegionCodes($regionsByNode[$publicId] ?? [], $regionCode)) {
                continue;
            }
            $out[] = [
                'publicId' => $publicId,
                'nodeCode' => AzureSqlValueMapper::stringValue($row['NodeCode'] ?? ''),
                'nodeName' => AzureSqlValueMapper::stringValue($row['NodeName'] ?? ''),
                'slug' => AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
                'description' => AzureSqlValueMapper::nullableString($row['Description'] ?? null),
                'nodeTypeCode' => AzureSqlValueMapper::stringValue($row['NodeTypeCode'] ?? 'category'),
                'imageUrl' => AzureSqlValueMapper::nullableString($row['ImageUrl'] ?? null),
                'productThumbnailUrl' => AzureSqlValueMapper::nullableString($row['ProductThumbnailUrl'] ?? null),
                'imageAltText' => AzureSqlValueMapper::nullableString($row['ImageAltText'] ?? null),
                'displayOrder' => AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                'parentPublicId' => AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
                'productCount' => AzureSqlValueMapper::intValue($row['ProductCount'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Build the requested node the same way as the panel tree (set-based descendants,
     * no per-child product-card query), then attach product cards for this node only.
     *
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $nodes
     */
    private function buildRequestedPublicNode(
        array $node,
        array $nodes,
        ?string $regionCode,
    ): PublicCatalogueTreeNode {
        $tree = $this->buildPublicTreeNode(
            $node,
            $nodes,
            $regionCode,
            false,
            $this->depthInFlatList($nodes, (string) $node['publicId']),
        );
        $products = $this->listPublicNodeProducts($tree->publicId, $regionCode);

        return new PublicCatalogueTreeNode(
            publicId: $tree->publicId,
            nodeCode: $tree->nodeCode,
            nodeName: $tree->nodeName,
            slug: $tree->slug,
            description: $tree->description,
            nodeTypeCode: $tree->nodeTypeCode,
            imageUrl: $tree->imageUrl,
            imageAltText: $tree->imageAltText,
            displayOrder: $tree->displayOrder,
            depth: $tree->depth,
            hasChildren: $tree->hasChildren,
            hasProducts: $tree->hasProducts || $products !== [],
            children: $tree->children,
            products: $products,
            parentPublicId: $tree->parentPublicId,
            productThumbnailUrl: $tree->productThumbnailUrl,
        );
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function depthInFlatList(array $nodes, string $publicId): int
    {
        $byId = [];
        foreach ($nodes as $candidate) {
            $id = (string) ($candidate['publicId'] ?? '');
            if ($id !== '') {
                $byId[$id] = $candidate;
            }
        }
        $depth = 0;
        $current = $byId[$publicId] ?? null;
        while ($current !== null) {
            $parentId = $current['parentPublicId'] ?? null;
            if (!is_string($parentId) || $parentId === '') {
                break;
            }
            $current = $byId[$parentId] ?? null;
            ++$depth;
            if ($depth > 100) {
                break;
            }
        }

        return $depth;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed> $node
     */
    private function buildPublicTreeNode(
        array $node,
        array $nodes,
        ?string $regionCode,
        bool $includeProducts,
        int $depth,
    ): PublicCatalogueTreeNode {
        $publicId = (string) $node['publicId'];
        $children = [];
        foreach ($nodes as $candidate) {
            if (($candidate['parentPublicId'] ?? null) !== $publicId) {
                continue;
            }
            $children[] = $this->buildPublicTreeNode($candidate, $nodes, $regionCode, $includeProducts, $depth + 1);
        }
        usort(
            $children,
            static fn (PublicCatalogueTreeNode $a, PublicCatalogueTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return new PublicCatalogueTreeNode(
            publicId: $publicId,
            nodeCode: (string) $node['nodeCode'],
            nodeName: (string) $node['nodeName'],
            slug: (string) $node['slug'],
            description: $node['description'] ?? null,
            nodeTypeCode: (string) $node['nodeTypeCode'],
            imageUrl: $node['imageUrl'] ?? null,
            imageAltText: $node['imageAltText'] ?? null,
            displayOrder: (int) $node['displayOrder'],
            depth: $depth,
            hasChildren: $children !== [],
            hasProducts: ((int) ($node['productCount'] ?? 0)) > 0,
            children: $children,
            products: $includeProducts ? $this->listPublicNodeProducts($publicId, $regionCode) : [],
            parentPublicId: $node['parentPublicId'] ?? null,
            productThumbnailUrl: isset($node['productThumbnailUrl']) && is_string($node['productThumbnailUrl'])
                ? $node['productThumbnailUrl']
                : null,
        );
    }

    /** @param list<array<string, mixed>> $nodes
     * @return array<string, mixed>|null
     */
    private function findNodeInFlatList(array $nodes, string $publicId): ?array
    {
        foreach ($nodes as $node) {
            if (($node['publicId'] ?? null) === $publicId) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function fetchPublicChildren(string $nodePublicId, ?string $regionCode): array
    {
        $productCountSelect = $this->publicNodeProductCountSelect($regionCode);
        $params = [':nodePublicId' => $nodePublicId];
        if ($regionCode !== null && trim($regionCode) !== '') {
            $params[':regionCode'] = strtoupper(trim($regionCode));
        }

        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    n.[NodeCode],
    n.[NodeName],
    n.[Slug],
    n.[Description],
    n.[NodeTypeCode],
    n.[ImageUrl],
    n.[ImageAltText],
    n.[DisplayOrder],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentPublicId],
    {$productCountSelect}
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueNodes] parent ON parent.[CatalogueNodeId] = n.[ParentNodeId]
WHERE parent.[PublicId] = :nodePublicId
  AND n.[DeletedAtUtc] IS NULL
  AND n.[IsActive] = 1
  AND n.[IsPublic] = 1
ORDER BY n.[DisplayOrder], n.[NodeName]
SQL,
            $params,
        );
        $publicIds = [];
        foreach ($rows as $row) {
            $publicIds[] = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        }
        $regionsByNode = $regionCode !== null && trim($regionCode) !== ''
            ? $this->loadRegionCodesForPublicIds($publicIds)
            : [];

        $out = [];
        foreach ($rows as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            if (!$this->nodeVisibleForRegionCodes($regionsByNode[$publicId] ?? [], $regionCode)) {
                continue;
            }
            $out[] = [
                'publicId' => $publicId,
                'nodeCode' => AzureSqlValueMapper::stringValue($row['NodeCode'] ?? ''),
                'nodeName' => AzureSqlValueMapper::stringValue($row['NodeName'] ?? ''),
                'slug' => AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
                'description' => AzureSqlValueMapper::nullableString($row['Description'] ?? null),
                'nodeTypeCode' => AzureSqlValueMapper::stringValue($row['NodeTypeCode'] ?? 'category'),
                'imageUrl' => AzureSqlValueMapper::nullableString($row['ImageUrl'] ?? null),
                'productThumbnailUrl' => AzureSqlValueMapper::nullableString($row['ProductThumbnailUrl'] ?? null),
                'imageAltText' => AzureSqlValueMapper::nullableString($row['ImageAltText'] ?? null),
                'displayOrder' => AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                'parentPublicId' => AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
                'productCount' => AzureSqlValueMapper::intValue($row['ProductCount'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $codes
     */
    private function nodeVisibleForRegionCodes(array $codes, ?string $regionCode): bool
    {
        if ($regionCode === null || trim($regionCode) === '') {
            return true;
        }
        if ($codes === []) {
            return true;
        }
        $needle = strtoupper(trim($regionCode));

        return in_array($needle, $codes, true);
    }

    private function nodeVisibleForRegion(string $nodePublicId, ?string $regionCode): bool
    {
        if ($regionCode === null || trim($regionCode) === '') {
            return true;
        }

        return $this->nodeVisibleForRegionCodes($this->loadRegionCodes($nodePublicId), $regionCode);
    }

    private function publicNodeProductCountSelect(?string $regionCode): string
    {
        if ($regionCode === null || trim($regionCode) === '') {
            return <<<'SQL'
(
    SELECT COUNT(*)
    FROM [product].[CatalogueNodeProducts] cnp
    WHERE cnp.[CatalogueNodeId] = n.[CatalogueNodeId] AND cnp.[IsActive] = 1
) AS [ProductCount]
SQL;
        }

        return <<<'SQL'
(
    SELECT COUNT(*)
    FROM [product].[CatalogueNodeProducts] cnp
    INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
    INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
    WHERE cnp.[CatalogueNodeId] = n.[CatalogueNodeId]
      AND cnp.[IsActive] = 1
      AND p.[DeletedAtUtc] IS NULL
      AND p.[IsActive] = 1
      AND ps.[StatusCode] = N'published'
      AND (p.[PublishAtUtc] IS NULL OR p.[PublishAtUtc] <= SYSUTCDATETIME())
      AND (p.[UnpublishAtUtc] IS NULL OR p.[UnpublishAtUtc] > SYSUTCDATETIME())
      AND EXISTS (
          SELECT 1
          FROM [product].[ProductRegions] prv
          INNER JOIN [product].[Regions] rv ON rv.[RegionId] = prv.[RegionId]
          WHERE prv.[ProductId] = p.[ProductId]
            AND prv.[DeletedAtUtc] IS NULL
            AND prv.[IsVisible] = 1
            AND rv.[RegionCode] = :regionCode
            AND (prv.[AvailableFromUtc] IS NULL OR prv.[AvailableFromUtc] <= SYSUTCDATETIME())
            AND (prv.[AvailableToUtc] IS NULL OR prv.[AvailableToUtc] > SYSUTCDATETIME())
      )
) AS [ProductCount]
SQL;
    }

    /**
     * @param array<string, string|null> $parentsByPublicId
     * @return array<string, int>
     */
    private function depthsFromParentMap(array $parentsByPublicId): array
    {
        $depths = [];
        $resolving = [];
        $resolve = function (string $publicId) use (&$resolve, &$depths, &$resolving, $parentsByPublicId): ?int {
            if (array_key_exists($publicId, $depths)) {
                return $depths[$publicId];
            }
            if (!array_key_exists($publicId, $parentsByPublicId)) {
                return null;
            }
            if (isset($resolving[$publicId])) {
                return 0;
            }
            $resolving[$publicId] = true;
            $parent = $parentsByPublicId[$publicId];
            if ($parent === null || $parent === '') {
                $depths[$publicId] = 0;

                return 0;
            }
            $parentDepth = $resolve($parent);
            unset($resolving[$publicId]);
            if ($parentDepth === null) {
                return null;
            }
            $depths[$publicId] = $parentDepth + 1;

            return $depths[$publicId];
        };

        foreach (array_keys($parentsByPublicId) as $publicId) {
            $resolve($publicId);
        }

        return $depths;
    }

    /**
     * @param list<string> $publicIds
     * @return array<string, int>
     */
    private function loadDepthsForPublicIds(array $publicIds): array
    {
        $ids = array_values(array_unique(array_filter($publicIds, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $name = ':id' . $index;
            $placeholders[] = $name;
            $params[$name] = $id;
        }

        $inList = implode(', ', $placeholders);
        $rows = $this->connection->fetchAll(
            <<<SQL
WITH [Ancestors] AS (
    SELECT n.[CatalogueNodeId], n.[ParentNodeId], n.[PublicId] AS [StartPublicId], 0 AS [Depth]
    FROM [product].[CatalogueNodes] n
    WHERE n.[PublicId] IN ({$inList})
    UNION ALL
    SELECT p.[CatalogueNodeId], p.[ParentNodeId], a.[StartPublicId], a.[Depth] + 1
    FROM [product].[CatalogueNodes] p
    INNER JOIN [Ancestors] a ON p.[CatalogueNodeId] = a.[ParentNodeId]
)
SELECT CONVERT(NVARCHAR(36), [StartPublicId]) AS [PublicId], MAX([Depth]) AS [Depth]
FROM [Ancestors]
GROUP BY [StartPublicId]
SQL,
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            $out[$publicId] = AzureSqlValueMapper::intValue($row['Depth'] ?? 0);
        }

        return $out;
    }

    /**
     * @param list<string> $publicIds
     * @return array<string, list<string>>
     */
    private function loadRegionCodesForPublicIds(array $publicIds): array
    {
        $ids = array_values(array_unique(array_filter($publicIds, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $name = ':id' . $index;
            $placeholders[] = $name;
            $params[$name] = $id;
        }

        $inList = implode(', ', $placeholders);
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT CONVERT(NVARCHAR(36), n.[PublicId]) AS [NodePublicId], r.[RegionCode]
FROM [product].[CatalogueNodeRegions] cnr
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnr.[CatalogueNodeId]
INNER JOIN [product].[Regions] r ON r.[RegionId] = cnr.[RegionId]
WHERE n.[PublicId] IN ({$inList})
ORDER BY n.[PublicId], r.[RegionCode]
SQL,
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $nodePublicId = AzureSqlValueMapper::stringValue($row['NodePublicId'] ?? '');
            $code = AzureSqlValueMapper::nullableString($row['RegionCode'] ?? null);
            if ($nodePublicId === '' || $code === null) {
                continue;
            }
            $out[$nodePublicId][] = strtoupper($code);
        }

        return $out;
    }

    /** @return list<string> */
    private function loadRegionCodes(string $nodePublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT r.[RegionCode]
FROM [product].[CatalogueNodeRegions] cnr
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnr.[CatalogueNodeId]
INNER JOIN [product].[Regions] r ON r.[RegionId] = cnr.[RegionId]
WHERE n.[PublicId] = :nodePublicId
ORDER BY r.[RegionCode]
SQL,
            [':nodePublicId' => $nodePublicId],
        );
        $out = [];
        foreach ($rows as $row) {
            $code = AzureSqlValueMapper::nullableString($row['RegionCode'] ?? null);
            if ($code !== null) {
                $out[] = strtoupper($code);
            }
        }

        return $out;
    }

    /** @param list<string> $regionCodes */
    private function replaceNodeRegionCodes(string $nodePublicId, array $regionCodes, UserId $actorUserId): void
    {
        $nodeId = $this->requireNodeId($nodePublicId);
        $this->connection->execute(
            'DELETE FROM [product].[CatalogueNodeRegions] WHERE [CatalogueNodeId] = :nodeId',
            [':nodeId' => $nodeId],
        );
        foreach ($regionCodes as $code) {
            if (!is_string($code) || trim($code) === '') {
                continue;
            }
            $regionRows = $this->connection->fetchAll(
                'SELECT [RegionId] FROM [product].[Regions] WHERE [RegionCode] = :regionCode',
                [':regionCode' => strtoupper(trim($code))],
            );
            if ($regionRows === []) {
                continue;
            }
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [product].[CatalogueNodeRegions] ([CatalogueNodeId], [RegionId], [CreatedByUserId])
VALUES (:nodeId, :regionId, :createdByUserId)
SQL,
                [
                    ':nodeId' => $nodeId,
                    ':regionId' => (int) $regionRows[0]['RegionId'],
                    ':createdByUserId' => $actorUserId->toInt(),
                ],
            );
        }
    }

    /** @return list<string> */
    private function breadcrumbNames(string $nodePublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
WITH [Trail] AS (
    SELECT n.[CatalogueNodeId], n.[ParentNodeId], n.[NodeName], 0 AS [Depth]
    FROM [product].[CatalogueNodes] n
    WHERE n.[PublicId] = :nodePublicId
    UNION ALL
    SELECT p.[CatalogueNodeId], p.[ParentNodeId], p.[NodeName], t.[Depth] + 1
    FROM [product].[CatalogueNodes] p
    INNER JOIN [Trail] t ON p.[CatalogueNodeId] = t.[ParentNodeId]
)
SELECT [NodeName] FROM [Trail] ORDER BY [Depth] DESC
SQL,
            [':nodePublicId' => $nodePublicId],
        );
        $names = [];
        foreach ($rows as $row) {
            $names[] = AzureSqlValueMapper::stringValue($row['NodeName'] ?? '');
        }

        return $names;
    }

    private function requireTabId(string $tabPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [CatalogueTabId] FROM [product].[CatalogueTabs] WHERE [PublicId] = :publicId',
            [':publicId' => $tabPublicId],
        );
        if ($rows === []) {
            throw CatalogueNotFound::forTab($tabPublicId);
        }

        return (int) $rows[0]['CatalogueTabId'];
    }

    private function requireNodeId(string $nodePublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [CatalogueNodeId] FROM [product].[CatalogueNodes] WHERE [PublicId] = :publicId',
            [':publicId' => $nodePublicId],
        );
        if ($rows === []) {
            throw CatalogueNotFound::forNode($nodePublicId);
        }

        return (int) $rows[0]['CatalogueNodeId'];
    }

    private function requireProductId(string $productPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId',
            [':publicId' => $productPublicId],
        );
        if ($rows === []) {
            throw CatalogueNotFound::forNode($productPublicId);
        }

        return (int) $rows[0]['ProductId'];
    }

    private function requireProductStyleId(string $productStylePublicId): int
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [ProductStyleId]
FROM [product].[ProductStyles]
WHERE [PublicId] = :publicId
  AND [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
SQL,
            [':publicId' => $productStylePublicId],
        );
        if ($rows === []) {
            throw CatalogueNotFound::forNode($productStylePublicId);
        }

        return (int) $rows[0]['ProductStyleId'];
    }

    private function assertTabExistsForMutation(string $publicId, bool $includeDeleted = false): void
    {
        if ($this->findTabByPublicId($publicId, $includeDeleted) === null) {
            throw CatalogueNotFound::forTab($publicId);
        }
    }

    private function assertNodeExistsForMutation(string $publicId, bool $includeDeleted = false): void
    {
        if ($this->findNodeByPublicId($publicId, $includeDeleted) === null) {
            throw CatalogueNotFound::forNode($publicId);
        }
    }
}
