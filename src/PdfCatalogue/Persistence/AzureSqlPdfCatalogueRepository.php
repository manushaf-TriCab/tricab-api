<?php

declare(strict_types=1);

namespace TriCab\Api\PdfCatalogue\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\PdfCatalogue\Application\DTO\PdfCatalogueItem;
use TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository;

final class AzureSqlPdfCatalogueRepository implements PdfCatalogueRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listActive(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId],
    c.[SeedKey],
    c.[Title],
    c.[Issue],
    c.[DocumentBlobPath],
    c.[ThumbnailBlobPath],
    c.[FileName],
    c.[ContentType],
    c.[SortOrder],
    c.[DocumentAvailable],
    r.[RegionCode],
    r.[SortOrder] AS [RegionSortOrder]
FROM [catalogue].[Catalogues] c
INNER JOIN [catalogue].[CatalogueRegions] r ON r.[CatalogueId] = c.[CatalogueId]
WHERE c.[IsActive] = 1
  AND c.[DeletedAtUtc] IS NULL
ORDER BY c.[SortOrder] ASC, c.[Title] ASC, r.[SortOrder] ASC, r.[RegionCode] ASC
SQL
        );

        return $this->hydrate($rows);
    }

    public function findActiveByPublicId(string $publicId): ?PdfCatalogueItem
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId],
    c.[SeedKey],
    c.[Title],
    c.[Issue],
    c.[DocumentBlobPath],
    c.[ThumbnailBlobPath],
    c.[FileName],
    c.[ContentType],
    c.[SortOrder],
    c.[DocumentAvailable],
    r.[RegionCode],
    r.[SortOrder] AS [RegionSortOrder]
FROM [catalogue].[Catalogues] c
INNER JOIN [catalogue].[CatalogueRegions] r ON r.[CatalogueId] = c.[CatalogueId]
WHERE c.[PublicId] = :publicId
  AND c.[IsActive] = 1
  AND c.[DeletedAtUtc] IS NULL
ORDER BY r.[SortOrder] ASC, r.[RegionCode] ASC
SQL
            ,
            ['publicId' => $publicId],
        );

        $items = $this->hydrate($rows);

        return $items[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<PdfCatalogueItem>
     */
    private function hydrate(array $rows): array
    {
        /** @var array<string, array{item: PdfCatalogueItem, regions: list<string>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? null);
            if ($publicId === '') {
                continue;
            }
            $region = strtoupper(AzureSqlValueMapper::stringValue($row['RegionCode'] ?? null));
            if (!isset($grouped[$publicId])) {
                $documentBlobPath = AzureSqlValueMapper::stringValue($row['DocumentBlobPath'] ?? null);
                $fileName = AzureSqlValueMapper::stringValue($row['FileName'] ?? null);
                $contentType = AzureSqlValueMapper::stringValue($row['ContentType'] ?? null);
                $grouped[$publicId] = [
                    'item' => new PdfCatalogueItem(
                        publicId: $publicId,
                        title: AzureSqlValueMapper::stringValue($row['Title'] ?? null),
                        issue: AzureSqlValueMapper::stringValue($row['Issue'] ?? null),
                        regions: [],
                        sortOrder: AzureSqlValueMapper::intValue($row['SortOrder'] ?? null),
                        available: AzureSqlValueMapper::intValue($row['DocumentAvailable'] ?? null) === 1
                            && $documentBlobPath !== '',
                        seedKey: AzureSqlValueMapper::stringValue($row['SeedKey'] ?? null),
                        documentBlobPath: $documentBlobPath,
                        thumbnailBlobPath: AzureSqlValueMapper::nullableString($row['ThumbnailBlobPath'] ?? null),
                        fileName: $fileName !== '' ? $fileName : 'catalogue.pdf',
                        contentType: $contentType !== '' ? $contentType : 'application/pdf',
                    ),
                    'regions' => [],
                ];
            }
            if ($region !== '' && !in_array($region, $grouped[$publicId]['regions'], true)) {
                $grouped[$publicId]['regions'][] = $region;
            }
        }

        $items = [];
        foreach ($grouped as $bundle) {
            $base = $bundle['item'];
            $items[] = new PdfCatalogueItem(
                publicId: $base->publicId,
                title: $base->title,
                issue: $base->issue,
                regions: $bundle['regions'],
                sortOrder: $base->sortOrder,
                available: $base->available,
                seedKey: $base->seedKey,
                documentBlobPath: $base->documentBlobPath,
                thumbnailBlobPath: $base->thumbnailBlobPath,
                fileName: $base->fileName,
                contentType: $base->contentType,
            );
        }

        return $items;
    }
}
