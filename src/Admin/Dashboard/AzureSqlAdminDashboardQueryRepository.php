<?php

declare(strict_types=1);

namespace TriCab\Api\Admin\Dashboard;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/**
 * Azure SQL aggregates for GET /api/v1/admin/dashboard.
 * Soft-deleted products (DeletedAtUtc IS NOT NULL) are excluded.
 * Attention / catalogue-health heuristics mirror CrossAggregateProductPublishReadinessEvaluator
 * (classification OR category; at least one region; media/SEO/specs as advisory counts).
 */
final class AzureSqlAdminDashboardQueryRepository implements AdminDashboardQueryRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function fetchSnapshot(string $activityRange = '30d'): array
    {
        $range = $this->normalizeRange($activityRange);

        $status = $this->safe(
            fn (): array => $this->fetchProductStatusCounts(),
            [
                'total' => 0,
                'published' => 0,
                'draft' => 0,
                'archived' => 0,
                'inactive' => 0,
            ],
            'productStatus',
        );
        $attention = $this->safe(fn (): array => $this->fetchAttention(), [], 'attention');
        $health = $this->safe(
            fn (): array => $this->fetchCatalogueHealth($status['total']),
            [
                'classification' => 0,
                'markets' => 0,
                'media' => 0,
                'specifications' => 0,
                'publicationReady' => 0,
            ],
            'catalogueHealth',
        );

        $blockerCount = 0;
        foreach ($attention as $item) {
            if (($item['code'] ?? '') === 'publication_blockers') {
                $blockerCount = (int) ($item['count'] ?? 0);
                break;
            }
        }

        $userCount = $this->safe(fn (): int => $this->fetchUserCount(), 0, 'userCount');
        $contactStats = $this->safe(
            fn (): array => $this->fetchContactStats(),
            ['total' => 0, 'newLast7Days' => 0],
            'contactStats',
        );

        $healthValues = array_values($health);
        $catalogueHealthPercent = $healthValues === []
            ? 0
            : (int) round(array_sum($healthValues) / count($healthValues));

        return [
            'summary' => [
                'totalProducts' => $status['total'],
                'publishedProducts' => $status['published'],
                'draftProducts' => $status['draft'],
                'users' => $userCount,
                'contacts' => $contactStats['total'],
                'contactsNew' => $contactStats['newLast7Days'],
                'catalogueHealthPercent' => $catalogueHealthPercent,
                'blockerCount' => $blockerCount,
            ],
            'productStatus' => $status,
            'catalogueHealth' => $health,
            'attention' => $attention,
            'recentProducts' => $this->safe(fn (): array => $this->fetchRecentProducts(5), [], 'recentProducts'),
            'recentActivity' => $this->safe(fn (): array => $this->fetchRecentActivity(5), [], 'recentActivity'),
            'recentUsers' => $this->safe(fn (): array => $this->fetchRecentUsers(4), [], 'recentUsers'),
            'recentContacts' => $this->safe(fn (): array => $this->fetchRecentContacts(5), [], 'recentContacts'),
            'productActivity' => $this->safe(
                fn (): array => $this->fetchProductActivity($range),
                ['range' => $range, 'points' => []],
                'productActivity',
            ),
        ];
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param T $fallback
     * @return T
     */
    private function safe(callable $callback, mixed $fallback, string $section): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            error_log(sprintf('Admin dashboard section "%s" failed: %s', $section, $exception->getMessage()));

            return $fallback;
        }
    }

    public function fetchProductActivity(string $range = '30d'): array
    {
        $normalized = $this->normalizeRange($range);
        $days = match ($normalized) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        $fromUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $days - 1))
            ->setTime(0, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');

        $createdRows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(date, p.[CreatedAtUtc]) AS [BucketDate], COUNT(*) AS [Cnt]
FROM [product].[Products] p
WHERE p.[DeletedAtUtc] IS NULL
  AND p.[CreatedAtUtc] >= :fromUtc
GROUP BY CONVERT(date, p.[CreatedAtUtc])
ORDER BY [BucketDate]
SQL,
            [':fromUtc' => $fromUtc],
        );

        $updatedRows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(date, p.[UpdatedAtUtc]) AS [BucketDate], COUNT(*) AS [Cnt]
FROM [product].[Products] p
WHERE p.[DeletedAtUtc] IS NULL
  AND p.[UpdatedAtUtc] >= :fromUtc
GROUP BY CONVERT(date, p.[UpdatedAtUtc])
ORDER BY [BucketDate]
SQL,
            [':fromUtc' => $fromUtc],
        );

        $publishedRows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(date, ae.[CreatedAtUtc]) AS [BucketDate], COUNT(*) AS [Cnt]
FROM [product].[ProductAuditEvents] ae
INNER JOIN [product].[Products] p ON p.[ProductId] = ae.[ProductId]
WHERE p.[DeletedAtUtc] IS NULL
  AND ae.[ActionCode] = N'published'
  AND ae.[CreatedAtUtc] >= :fromUtc
GROUP BY CONVERT(date, ae.[CreatedAtUtc])
ORDER BY [BucketDate]
SQL,
            [':fromUtc' => $fromUtc],
        );

        $created = $this->bucketMap($createdRows);
        $updated = $this->bucketMap($updatedRows);
        $published = $this->bucketMap($publishedRows);

        $points = [];
        $cursor = new \DateTimeImmutable(substr($fromUtc, 0, 10), new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $points[] = [
                'date' => $key,
                'created' => $created[$key] ?? 0,
                'updated' => $updated[$key] ?? 0,
                'published' => $published[$key] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'range' => $normalized,
            'points' => $points,
        ];
    }

    /**
     * @return array{total: int, published: int, draft: int, archived: int, inactive: int}
     */
    private function fetchProductStatusCounts(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    COUNT(*) AS [Total],
    SUM(CASE WHEN ps.[StatusCode] = N'published' THEN 1 ELSE 0 END) AS [Published],
    SUM(CASE WHEN ps.[StatusCode] IN (N'draft', N'review') THEN 1 ELSE 0 END) AS [Draft],
    SUM(CASE WHEN ps.[StatusCode] = N'archived' THEN 1 ELSE 0 END) AS [Archived],
    SUM(CASE WHEN ps.[StatusCode] IN (N'unpublished', N'discontinued') THEN 1 ELSE 0 END) AS [Inactive]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
WHERE p.[DeletedAtUtc] IS NULL
SQL,
        );

        $row = $rows[0] ?? [];

        return [
            'total' => AzureSqlValueMapper::intValue($row['Total'] ?? 0),
            'published' => AzureSqlValueMapper::intValue($row['Published'] ?? 0),
            'draft' => AzureSqlValueMapper::intValue($row['Draft'] ?? 0),
            'archived' => AzureSqlValueMapper::intValue($row['Archived'] ?? 0),
            'inactive' => AzureSqlValueMapper::intValue($row['Inactive'] ?? 0),
        ];
    }

    /**
     * @return array{classification: int, markets: int, media: int, specifications: int, publicationReady: int}
     */
    private function fetchCatalogueHealth(int $total): array
    {
        if ($total <= 0) {
            return [
                'classification' => 0,
                'markets' => 0,
                'media' => 0,
                'specifications' => 0,
                'publicationReady' => 0,
            ];
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    SUM(CASE WHEN p.[ProductFamilyId] IS NOT NULL OR p.[ProductCategoryId] IS NOT NULL THEN 1 ELSE 0 END) AS [WithClassification],
    SUM(CASE WHEN EXISTS (
        SELECT 1 FROM [product].[ProductRegions] pr
        WHERE pr.[ProductId] = p.[ProductId] AND pr.[DeletedAtUtc] IS NULL
    ) THEN 1 ELSE 0 END) AS [WithRegion],
    SUM(CASE WHEN EXISTS (
        SELECT 1 FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId] AND img.[DeletedAtUtc] IS NULL
    ) THEN 1 ELSE 0 END) AS [WithMedia],
    SUM(CASE WHEN EXISTS (
        SELECT 1 FROM [product].[ProductSpecificationValues] sv
        WHERE sv.[ProductId] = p.[ProductId] AND sv.[DeletedAtUtc] IS NULL
    ) THEN 1 ELSE 0 END) AS [WithSpecs],
    SUM(CASE WHEN
        (p.[ProductFamilyId] IS NOT NULL OR p.[ProductCategoryId] IS NOT NULL)
        AND EXISTS (
            SELECT 1 FROM [product].[ProductRegions] pr
            WHERE pr.[ProductId] = p.[ProductId] AND pr.[DeletedAtUtc] IS NULL
        )
    THEN 1 ELSE 0 END) AS [PublicationReady]
FROM [product].[Products] p
WHERE p.[DeletedAtUtc] IS NULL
SQL,
        );

        $row = $rows[0] ?? [];
        $pct = static fn (int $part): int => (int) round(($part / $total) * 100);

        return [
            'classification' => $pct(AzureSqlValueMapper::intValue($row['WithClassification'] ?? 0)),
            'markets' => $pct(AzureSqlValueMapper::intValue($row['WithRegion'] ?? 0)),
            'media' => $pct(AzureSqlValueMapper::intValue($row['WithMedia'] ?? 0)),
            'specifications' => $pct(AzureSqlValueMapper::intValue($row['WithSpecs'] ?? 0)),
            'publicationReady' => $pct(AzureSqlValueMapper::intValue($row['PublicationReady'] ?? 0)),
        ];
    }

    /**
     * @return list<array{code: string, label: string, count: int, severity: string, href: string}>
     */
    private function fetchAttention(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    SUM(CASE WHEN NOT EXISTS (
        SELECT 1 FROM [product].[ProductRegions] pr
        WHERE pr.[ProductId] = p.[ProductId] AND pr.[DeletedAtUtc] IS NULL
    ) THEN 1 ELSE 0 END) AS [MissingRegion],
    SUM(CASE WHEN NOT EXISTS (
        SELECT 1 FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId] AND img.[DeletedAtUtc] IS NULL
    ) THEN 1 ELSE 0 END) AS [MissingMedia],
    SUM(CASE WHEN NOT EXISTS (
        SELECT 1 FROM [product].[ProductSlugs] slug
        WHERE slug.[ProductId] = p.[ProductId] AND slug.[IsPrimary] = 1 AND slug.[DeletedAtUtc] IS NULL
    ) THEN 1 ELSE 0 END) AS [MissingSeo],
    SUM(CASE WHEN NOT EXISTS (
        SELECT 1 FROM [product].[ProductSpecificationValues] sv
        WHERE sv.[ProductId] = p.[ProductId] AND sv.[DeletedAtUtc] IS NULL
    ) THEN 1 ELSE 0 END) AS [MissingSpecs],
    SUM(CASE WHEN
        (p.[ProductFamilyId] IS NULL AND p.[ProductCategoryId] IS NULL)
        OR NOT EXISTS (
            SELECT 1 FROM [product].[ProductRegions] pr
            WHERE pr.[ProductId] = p.[ProductId] AND pr.[DeletedAtUtc] IS NULL
        )
    THEN 1 ELSE 0 END) AS [PublicationBlockers]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
WHERE p.[DeletedAtUtc] IS NULL
  AND ps.[StatusCode] <> N'archived'
SQL,
        );

        $row = $rows[0] ?? [];

        $items = [
            [
                'code' => 'missing_market_region',
                'label' => 'Missing Market Region',
                'count' => AzureSqlValueMapper::intValue($row['MissingRegion'] ?? 0),
                'severity' => 'warning',
                'href' => '/admin/products?missingRegion=true',
            ],
            [
                'code' => 'missing_media',
                'label' => 'Missing Media',
                'count' => AzureSqlValueMapper::intValue($row['MissingMedia'] ?? 0),
                'severity' => 'warning',
                'href' => '/admin/products?hasMedia=false',
            ],
            [
                'code' => 'missing_seo',
                'label' => 'Missing SEO',
                'count' => AzureSqlValueMapper::intValue($row['MissingSeo'] ?? 0),
                'severity' => 'warning',
                'href' => '/admin/products?missingSeo=true',
            ],
            [
                'code' => 'missing_specifications',
                'label' => 'Missing Specifications',
                'count' => AzureSqlValueMapper::intValue($row['MissingSpecs'] ?? 0),
                'severity' => 'warning',
                'href' => '/admin/products?missingSpecifications=true',
            ],
            [
                'code' => 'publication_blockers',
                'label' => 'Publication Blockers',
                'count' => AzureSqlValueMapper::intValue($row['PublicationBlockers'] ?? 0),
                'severity' => 'error',
                'href' => '/admin/products?publicationBlockers=true',
            ],
        ];

        return array_values(array_filter($items, static fn (array $item): bool => $item['count'] > 0));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecentProducts(int $limit): array
    {
        $limit = max(1, min($limit, 20));
        $rows = $this->connection->fetchAll(
            sprintf(
                <<<'SQL'
SELECT TOP (%d)
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName],
    COALESCE((
        SELECT TOP 1 pc.[CodeValue]
        FROM [product].[ProductCodes] pc
        WHERE pc.[ProductId] = p.[ProductId]
          AND pc.[IsPrimary] = 1
          AND pc.[DeletedAtUtc] IS NULL
        ORDER BY pc.[ProductCodeId]
    ), p.[InternalCode]) AS [ProductCode],
    (
        SELECT TOP 1 img.[PublicUrl]
        FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId]
          AND img.[DeletedAtUtc] IS NULL
          AND img.[UploadStatusCode] = N'active'
          AND img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
        ORDER BY img.[IsPrimary] DESC, img.[DisplayOrder] ASC, img.[ProductImagesId] ASC
    ) AS [ThumbnailUrl],
    (
        SELECT TOP 1 CONVERT(NVARCHAR(36), img.[PublicId])
        FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId]
          AND img.[DeletedAtUtc] IS NULL
          AND img.[UploadStatusCode] = N'active'
          AND img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
        ORDER BY img.[IsPrimary] DESC, img.[DisplayOrder] ASC, img.[ProductImagesId] ASC
    ) AS [ThumbnailImagePublicId],
    (
        SELECT TOP 1 img.[StorageContainer]
        FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId]
          AND img.[DeletedAtUtc] IS NULL
          AND img.[UploadStatusCode] = N'active'
          AND img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
        ORDER BY img.[IsPrimary] DESC, img.[DisplayOrder] ASC, img.[ProductImagesId] ASC
    ) AS [ThumbnailStorageContainer],
    (
        SELECT TOP 1 img.[StoragePath]
        FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId]
          AND img.[DeletedAtUtc] IS NULL
          AND img.[UploadStatusCode] = N'active'
          AND img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
        ORDER BY img.[IsPrimary] DESC, img.[DisplayOrder] ASC, img.[ProductImagesId] ASC
    ) AS [ThumbnailStoragePath],
    ps.[StatusCode],
    CONVERT(VARCHAR(33), p.[UpdatedAtUtc], 127) AS [UpdatedAtUtc]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
WHERE p.[DeletedAtUtc] IS NULL
ORDER BY p.[UpdatedAtUtc] DESC, p.[ProductId] DESC
SQL,
                $limit,
            ),
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'productName' => AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
                'productCode' => AzureSqlValueMapper::nullableString($row['ProductCode'] ?? null),
                'thumbnailUrl' => AzureSqlValueMapper::nullableString($row['ThumbnailUrl'] ?? null),
                'thumbnailImagePublicId' => AzureSqlValueMapper::nullableString($row['ThumbnailImagePublicId'] ?? null),
                'thumbnailStorageContainer' => AzureSqlValueMapper::nullableString($row['ThumbnailStorageContainer'] ?? null),
                'thumbnailStoragePath' => AzureSqlValueMapper::nullableString($row['ThumbnailStoragePath'] ?? null),
                'statusCode' => AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
                'updatedAtUtc' => AzureSqlValueMapper::stringValue($row['UpdatedAtUtc'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecentActivity(int $limit): array
    {
        $limit = max(1, min($limit, 20));
        $productRows = $this->connection->fetchAll(
            sprintf(
                <<<'SQL'
SELECT TOP (%d)
    CONVERT(NVARCHAR(36), ae.[PublicId]) AS [PublicId],
    ae.[ActionCode],
    ae.[EntityTypeCode],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    p.[ProductName] AS [Description],
    CONVERT(VARCHAR(33), ae.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    N'product' AS [Source]
FROM [product].[ProductAuditEvents] ae
LEFT JOIN [product].[Products] p ON p.[ProductId] = ae.[ProductId]
WHERE ae.[ProductId] IS NULL OR p.[DeletedAtUtc] IS NULL
ORDER BY ae.[CreatedAtUtc] DESC, ae.[ProductAuditEventId] DESC
SQL,
                $limit,
            ),
        );

        $userRows = $this->connection->fetchAll(
            sprintf(
                <<<'SQL'
SELECT TOP (%d)
    CONVERT(NVARCHAR(36), e.[PublicId]) AS [PublicId],
    e.[EventType] AS [ActionCode],
    N'user' AS [EntityTypeCode],
    CONVERT(NVARCHAR(36), tu.[PublicId]) AS [ProductPublicId],
    COALESCE(tu.[DisplayName], e.[EventSummary]) AS [Description],
    CONVERT(VARCHAR(33), e.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    N'user' AS [Source]
FROM [audit].[UserManagementEvents] e
LEFT JOIN [identity].[Users] tu ON tu.[UserId] = e.[TargetUserId]
WHERE e.[EventType] = N'USER_CREATED'
ORDER BY e.[CreatedAtUtc] DESC
SQL,
                $limit,
            ),
        );

        $merged = array_merge($productRows, $userRows);
        usort(
            $merged,
            static function (array $a, array $b): int {
                return strcmp(
                    AzureSqlValueMapper::stringValue($b['CreatedAtUtc'] ?? ''),
                    AzureSqlValueMapper::stringValue($a['CreatedAtUtc'] ?? ''),
                );
            },
        );

        $items = [];
        foreach (array_slice($merged, 0, $limit) as $row) {
            $source = AzureSqlValueMapper::stringValue($row['Source'] ?? 'product');
            $action = AzureSqlValueMapper::stringValue($row['ActionCode'] ?? '');
            if ($source === 'user') {
                $items[] = [
                    'id' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                    'type' => 'user_created',
                    'title' => 'User created',
                    'description' => AzureSqlValueMapper::nullableString($row['Description'] ?? null) ?? $action,
                    'productPublicId' => null,
                    'occurredAtUtc' => AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
                ];
                continue;
            }

            $items[] = [
                'id' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'type' => $this->mapActivityType($action, AzureSqlValueMapper::stringValue($row['EntityTypeCode'] ?? '')),
                'title' => $this->mapActivityTitle($action, AzureSqlValueMapper::stringValue($row['EntityTypeCode'] ?? '')),
                'description' => AzureSqlValueMapper::nullableString($row['Description'] ?? null) ?? $action,
                'productPublicId' => AzureSqlValueMapper::nullableString($row['ProductPublicId'] ?? null),
                'occurredAtUtc' => AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecentUsers(int $limit): array
    {
        $limit = max(1, min($limit, 20));
        $rows = $this->connection->fetchAll(
            sprintf(
                <<<'SQL'
SELECT TOP (%d)
    CONVERT(NVARCHAR(36), u.[PublicId]) AS [PublicId],
    u.[DisplayName],
    u.[EmailAddress],
    (
        SELECT TOP 1 r.[RoleCode]
        FROM [identity].[UserRoles] ur
        INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
        WHERE ur.[UserId] = u.[UserId]
          AND ur.[IsActive] = 1
          AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
        ORDER BY ur.[AssignedAt] DESC
    ) AS [PrimaryRoleCode],
    CONVERT(VARCHAR(33), u.[CreatedAt], 127) AS [CreatedAtUtc]
FROM [identity].[Users] u
ORDER BY u.[CreatedAt] DESC, u.[UserId] DESC
SQL,
                $limit,
            ),
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'displayName' => AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
                'emailAddress' => AzureSqlValueMapper::stringValue($row['EmailAddress'] ?? ''),
                'roleCode' => AzureSqlValueMapper::nullableString($row['PrimaryRoleCode'] ?? null),
                'createdAtUtc' => AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecentContacts(int $limit): array
    {
        $limit = max(1, min($limit, 20));
        $rows = $this->connection->fetchAll(
            sprintf(
                <<<'SQL'
SELECT TOP (%d)
    CONVERT(NVARCHAR(36), cc.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [CustomerPublicId],
    cc.[DisplayName],
    c.[CustomerName],
    cc.[IsActive],
    cc.[PortalAccessStatusCode],
    CONVERT(VARCHAR(33), cc.[CreatedAtUtc], 127) AS [CreatedAtUtc]
FROM [customer].[CustomerContacts] cc
INNER JOIN [customer].[Customers] c ON c.[CustomerId] = cc.[CustomerId]
ORDER BY cc.[CreatedAtUtc] DESC, cc.[CustomerContactId] DESC
SQL,
                $limit,
            ),
        );

        $items = [];
        foreach ($rows as $row) {
            $isActive = AzureSqlValueMapper::boolValue($row['IsActive'] ?? false);
            $items[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'customerPublicId' => AzureSqlValueMapper::stringValue($row['CustomerPublicId'] ?? ''),
                'displayName' => AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
                'customerName' => AzureSqlValueMapper::stringValue($row['CustomerName'] ?? ''),
                'status' => $isActive ? 'active' : 'inactive',
                'createdAtUtc' => AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            ];
        }

        return $items;
    }

    private function fetchUserCount(): int
    {
        $rows = $this->connection->fetchAll('SELECT COUNT(*) AS [Total] FROM [identity].[Users]');

        return AzureSqlValueMapper::intValue(($rows[0] ?? [])['Total'] ?? 0);
    }

    /**
     * @return array{total: int, newLast7Days: int}
     */
    private function fetchContactStats(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    COUNT(*) AS [Total],
    SUM(CASE WHEN cc.[CreatedAtUtc] >= DATEADD(day, -7, SYSUTCDATETIME()) THEN 1 ELSE 0 END) AS [NewLast7Days]
FROM [customer].[CustomerContacts] cc
SQL,
        );

        $row = $rows[0] ?? [];

        return [
            'total' => AzureSqlValueMapper::intValue($row['Total'] ?? 0),
            'newLast7Days' => AzureSqlValueMapper::intValue($row['NewLast7Days'] ?? 0),
        ];
    }

    private function normalizeRange(string $range): string
    {
        $normalized = strtolower(trim($range));

        return in_array($normalized, ['7d', '30d', '90d'], true) ? $normalized : '30d';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function bucketMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $raw = $row['BucketDate'] ?? null;
            if ($raw instanceof \DateTimeInterface) {
                $key = $raw->format('Y-m-d');
            } else {
                $key = substr(AzureSqlValueMapper::stringValue($raw ?? ''), 0, 10);
            }
            if ($key === '') {
                continue;
            }
            $map[$key] = AzureSqlValueMapper::intValue($row['Cnt'] ?? 0);
        }

        return $map;
    }

    private function mapActivityType(string $actionCode, string $entityType): string
    {
        $action = strtolower($actionCode);
        $entity = strtolower($entityType);

        if ($action === 'published') {
            return 'product_published';
        }
        if (str_contains($entity, 'media') || str_contains($action, 'media')) {
            return 'media_uploaded';
        }
        if (str_contains($entity, 'categor') || str_contains($entity, 'family') || str_contains($entity, 'series')) {
            return 'category_changed';
        }
        if ($action === 'created' || str_ends_with($action, '.created')) {
            return 'product_created';
        }

        return 'product_updated';
    }

    private function mapActivityTitle(string $actionCode, string $entityType): string
    {
        return match ($this->mapActivityType($actionCode, $entityType)) {
            'product_published' => 'Product published',
            'media_uploaded' => 'Media uploaded',
            'category_changed' => 'Category changed',
            'product_created' => 'Product created',
            default => 'Product updated',
        };
    }
}
