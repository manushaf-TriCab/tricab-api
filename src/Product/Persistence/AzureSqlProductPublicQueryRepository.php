<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\DTO\ProductPublic3DPayload;
use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Application\DTO\ProductPublicRelationshipItem;
use TriCab\Core\Product\Application\DTO\ProductPublicSpecificationItem;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Contract\ProductPublicQueryRepository;
use TriCab\Core\Product\Application\Service\ProductThumbnailImageResolver;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;
use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

/**
 * Public product reads. Visibility is enforced in SQL:
 * - not soft-deleted
 * - not archived
 * - status published
 * - within publish window
 * - optionally visible in requested region
 * Never returns pricing, ERP, audit, or internal IDs.
 */
final class AzureSqlProductPublicQueryRepository implements ProductPublicQueryRepository
{
    use LoadsProductApprovalCertificates;

    private const PUBLIC_VISIBILITY_SQL = <<<'SQL'
  AND p.[DeletedAtUtc] IS NULL
  AND p.[IsActive] = 1
  AND ps.[StatusCode] = N'published'
  AND (p.[PublishAtUtc] IS NULL OR p.[PublishAtUtc] <= SYSUTCDATETIME())
  AND (p.[UnpublishAtUtc] IS NULL OR p.[UnpublishAtUtc] > SYSUTCDATETIME())
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly Product3DModelRepository $threeDModels,
        private readonly bool $product3dEnabled = true,
    ) {
    }

    public function findBySlug(ProductSlug $slug, ?string $regionCode = null): ?ProductPublicDetail
    {
        $params = [':slug' => $slug->toString()];
        $regionFilter = '';
        if ($regionCode !== null && trim($regionCode) !== '') {
            $trimmedRegion = trim($regionCode);
            // sqlsrv rejects duplicate named parameters; bind region code twice under distinct names.
            $regionFilter = <<<'SQL'

  AND (
        slug.[RegionId] IS NULL
        OR EXISTS (
            SELECT 1 FROM [product].[Regions] slugRegion
            WHERE slugRegion.[RegionId] = slug.[RegionId]
              AND slugRegion.[RegionCode] = :slugRegionCode
        )
  )
  AND EXISTS (
        SELECT 1
        FROM [product].[ProductRegions] prv
        INNER JOIN [product].[Regions] rv ON rv.[RegionId] = prv.[RegionId]
        WHERE prv.[ProductId] = p.[ProductId]
          AND prv.[DeletedAtUtc] IS NULL
          AND prv.[IsVisible] = 1
          AND rv.[RegionCode] = :productRegionCode
          AND (prv.[AvailableFromUtc] IS NULL OR prv.[AvailableFromUtc] <= SYSUTCDATETIME())
          AND (prv.[AvailableToUtc] IS NULL OR prv.[AvailableToUtc] > SYSUTCDATETIME())
  )
SQL;
            $params[':slugRegionCode'] = $trimmedRegion;
            $params[':productRegionCode'] = $trimmedRegion;
        }

        $visibilitySql = self::PUBLIC_VISIBILITY_SQL;
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName],
    p.[ShortDescription],
    p.[FullDescription],
    f.[FamilyName],
    c.[CategoryName],
    s.[SeriesName],
    slug.[Slug]
FROM [product].[ProductSlugs] slug
INNER JOIN [product].[Products] p ON p.[ProductId] = slug.[ProductId]
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductFamilies] f ON f.[ProductFamilyId] = p.[ProductFamilyId]
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = p.[ProductCategoryId]
LEFT JOIN [product].[ProductSeries] s ON s.[ProductSeriesId] = p.[ProductSeriesId]
WHERE slug.[Slug] = :slug
  AND slug.[DeletedAtUtc] IS NULL
  AND slug.[IsActive] = 1
{$visibilitySql}
{$regionFilter}
ORDER BY CASE WHEN slug.[IsPrimary] = 1 THEN 0 ELSE 1 END, slug.[ProductSlugId]
SQL,
            $params,
        );

        if ($rows === []) {
            return null;
        }

        return $this->hydrateDetail($rows[0], $regionCode);
    }

    public function findByPublicId(ProductPublicId $publicId, ?string $regionCode = null): ?ProductPublicDetail
    {
        $params = [':publicId' => $publicId->toString()];
        $regionFilter = '';
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
            $params[':regionCode'] = trim($regionCode);
        }

        $visibilitySql = self::PUBLIC_VISIBILITY_SQL;
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName],
    p.[ShortDescription],
    p.[FullDescription],
    f.[FamilyName],
    c.[CategoryName],
    s.[SeriesName],
    (
        SELECT TOP 1 slug.[Slug]
        FROM [product].[ProductSlugs] slug
        WHERE slug.[ProductId] = p.[ProductId]
          AND slug.[DeletedAtUtc] IS NULL
          AND slug.[IsActive] = 1
          AND slug.[IsPrimary] = 1
        ORDER BY slug.[ProductSlugId]
    ) AS [Slug],
    p.[ThumbnailBackgroundColor]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductFamilies] f ON f.[ProductFamilyId] = p.[ProductFamilyId]
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = p.[ProductCategoryId]
LEFT JOIN [product].[ProductSeries] s ON s.[ProductSeriesId] = p.[ProductSeriesId]
WHERE p.[PublicId] = :publicId
{$visibilitySql}
{$regionFilter}
SQL,
            $params,
        );

        if ($rows === []) {
            return null;
        }

        return $this->hydrateDetail($rows[0], $regionCode);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDetail(array $row, ?string $regionCode): ProductPublicDetail
    {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        $pid = ProductPublicId::fromString($publicId);
        $media = $this->listPublicMedia($pid);
        $thumbnailImageUrl = ProductThumbnailImageResolver::fromMediaSummaries($media);
        $thumbnailUrl = null;
        foreach ($media as $item) {
            if ($item->isPrimary && $item->publicUrl !== null) {
                $thumbnailUrl = $item->publicUrl;
                break;
            }
        }
        if ($thumbnailUrl === null) {
            foreach ($media as $item) {
                if ($item->publicUrl !== null) {
                    $thumbnailUrl = $item->publicUrl;
                    break;
                }
            }
        }

        return new ProductPublicDetail(
            publicId: $publicId,
            productName: AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            productCode: $this->primaryProductCode($pid)
                ?? AzureSqlValueMapper::nullableString($row['InternalCode'] ?? null),
            shortDescription: AzureSqlValueMapper::nullableString($row['ShortDescription'] ?? null),
            fullDescription: AzureSqlValueMapper::nullableString($row['FullDescription'] ?? null),
            familyName: AzureSqlValueMapper::nullableString($row['FamilyName'] ?? null),
            categoryName: AzureSqlValueMapper::nullableString($row['CategoryName'] ?? null),
            seriesName: AzureSqlValueMapper::nullableString($row['SeriesName'] ?? null),
            slug: AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
            thumbnailUrl: $thumbnailUrl,
            thumbnailImageUrl: $thumbnailImageUrl,
            thumbnailBackgroundColor: ThumbnailBackgroundColor::tryFromString(
                AzureSqlValueMapper::nullableString($row['ThumbnailBackgroundColor'] ?? null),
            )?->toString(),
            variants: $this->listPublicVariants($pid),
            media: $media,
            documents: $this->listPublicDocuments($pid, $regionCode),
            applicationNames: $this->listNames($pid, 'applications'),
            industryNames: $this->listNames($pid, 'industries'),
            relationships: $this->listPublicRelationships($pid),
            specifications: $this->listPublicSpecifications($pid),
            approvalCertificates: $this->listApprovalCertificates($pid),
            threeD: $this->product3dEnabled
                ? $this->threeDModels->listPublicByProduct($pid)
                : ProductPublic3DPayload::empty(),
        );
    }

    private function primaryProductCode(ProductPublicId $productPublicId): ?string
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP (1) pc.[CodeValue]
FROM [product].[ProductCodes] pc
INNER JOIN [product].[Products] p ON p.[ProductId] = pc.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND pc.[IsPrimary] = 1
  AND pc.[DeletedAtUtc] IS NULL
ORDER BY pc.[ProductCodeId] ASC
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return AzureSqlValueMapper::nullableString($rows[0]['CodeValue'] ?? null);
    }

    /**
     * @return list<ProductPublicRelationshipItem>
     */
    private function listPublicRelationships(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), rp.[PublicId]) AS [RelatedProductPublicId],
    rp.[ProductName] AS [RelatedProductName],
    slug.[Slug] AS [RelatedSlug],
    l.[DisplayOrder],
    l.[RelationshipTypeCode]
FROM (
    SELECT [ProductId], [RelatedProductId], [DisplayOrder], [DeletedAtUtc], N'accessory' AS [RelationshipTypeCode]
    FROM [product].[ProductAccessories]
    UNION ALL
    SELECT [ProductId], [RelatedProductId], [DisplayOrder], [DeletedAtUtc], N'related'
    FROM [product].[RelatedProducts]
    UNION ALL
    SELECT [ProductId], [RelatedProductId], [DisplayOrder], [DeletedAtUtc], N'replacement'
    FROM [product].[ReplacementProducts]
    UNION ALL
    SELECT [ProductId], [RelatedProductId], [DisplayOrder], [DeletedAtUtc], N'cross-sell'
    FROM [product].[ProductCrossSells]
    UNION ALL
    SELECT [ProductId], [RelatedProductId], [DisplayOrder], [DeletedAtUtc], N'up-sell'
    FROM [product].[ProductUpSells]
) l
INNER JOIN [product].[Products] p ON p.[ProductId] = l.[ProductId]
INNER JOIN [product].[Products] rp ON rp.[ProductId] = l.[RelatedProductId]
INNER JOIN [product].[ProductStatuses] rps ON rps.[ProductStatusId] = rp.[ProductStatusId]
LEFT JOIN [product].[ProductSlugs] slug
    ON slug.[ProductId] = rp.[ProductId]
   AND slug.[IsPrimary] = 1
   AND slug.[DeletedAtUtc] IS NULL
   AND slug.[IsActive] = 1
WHERE p.[PublicId] = :productPublicId
  AND l.[DeletedAtUtc] IS NULL
  AND rp.[DeletedAtUtc] IS NULL
  AND rp.[IsActive] = 1
  AND rps.[StatusCode] = N'published'
ORDER BY l.[RelationshipTypeCode] ASC, l.[DisplayOrder] ASC
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = new ProductPublicRelationshipItem(
                relatedProductPublicId: AzureSqlValueMapper::stringValue($row['RelatedProductPublicId'] ?? ''),
                relatedProductName: AzureSqlValueMapper::stringValue($row['RelatedProductName'] ?? ''),
                relationshipTypeCode: AzureSqlValueMapper::stringValue($row['RelationshipTypeCode'] ?? ''),
                relatedSlug: AzureSqlValueMapper::nullableString($row['RelatedSlug'] ?? null),
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            );
        }

        return $items;
    }

    /**
     * @return list<ProductPublicSpecificationItem>
     */
    private function listPublicSpecifications(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    sd.[DefinitionCode] AS [Code],
    sd.[DefinitionName] AS [Name],
    sg.[GroupName],
    COALESCE(psv.[UnitOverride], sd.[Unit]) AS [Unit],
    psv.[ValueText],
    psv.[ValueLongText],
    psv.[ValueInteger],
    psv.[ValueDecimal],
    psv.[ValueBoolean],
    psv.[ValueMinDecimal],
    psv.[ValueMaxDecimal],
    so.[OptionLabel]
FROM [product].[ProductSpecificationValues] psv
INNER JOIN [product].[Products] p ON p.[ProductId] = psv.[ProductId]
INNER JOIN [product].[SpecificationDefinitions] sd ON sd.[SpecificationDefinitionId] = psv.[SpecificationDefinitionId]
LEFT JOIN [product].[SpecificationGroups] sg ON sg.[SpecificationGroupId] = sd.[SpecificationGroupId]
LEFT JOIN [product].[SpecificationOptions] so ON so.[SpecificationOptionId] = psv.[SpecificationOptionId]
WHERE p.[PublicId] = :productPublicId
  AND psv.[DeletedAtUtc] IS NULL
  AND sd.[DeletedAtUtc] IS NULL
  AND sd.[IsActive] = 1
ORDER BY sg.[DisplayOrder] ASC, sd.[DisplayOrder] ASC
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        $items = [];
        foreach ($rows as $row) {
            $displayValue = $this->azureSpecDisplayValue($row);
            if ($displayValue === null || $displayValue === '') {
                continue;
            }
            $items[] = new ProductPublicSpecificationItem(
                code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
                name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
                groupName: AzureSqlValueMapper::nullableString($row['GroupName'] ?? null),
                displayValue: $displayValue,
                unit: AzureSqlValueMapper::nullableString($row['Unit'] ?? null),
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function azureSpecDisplayValue(array $row): ?string
    {
        $option = AzureSqlValueMapper::nullableString($row['OptionLabel'] ?? null);
        if ($option !== null && $option !== '') {
            return $option;
        }
        $text = AzureSqlValueMapper::nullableString($row['ValueText'] ?? null)
            ?? AzureSqlValueMapper::nullableString($row['ValueLongText'] ?? null);
        if ($text !== null && $text !== '') {
            return $text;
        }
        $integer = AzureSqlValueMapper::nullableString($row['ValueInteger'] ?? null);
        if ($integer !== null && $integer !== '') {
            return $integer;
        }
        $decimal = AzureSqlValueMapper::nullableString($row['ValueDecimal'] ?? null);
        if ($decimal !== null && $decimal !== '') {
            return $decimal;
        }
        if (array_key_exists('ValueBoolean', $row) && $row['ValueBoolean'] !== null) {
            return AzureSqlValueMapper::boolValue($row['ValueBoolean']) ? 'Yes' : 'No';
        }
        $min = AzureSqlValueMapper::nullableString($row['ValueMinDecimal'] ?? null);
        $max = AzureSqlValueMapper::nullableString($row['ValueMaxDecimal'] ?? null);
        if ($min !== null || $max !== null) {
            return trim(($min ?? '') . ' – ' . ($max ?? ''), ' –');
        }

        return null;
    }

    /**
     * @return list<ProductVariantSummary>
     */
    private function listPublicVariants(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [PublicId],
    v.[VariantName],
    v.[Sku],
    v.[VariantCode],
    ps.[StatusCode],
    v.[DisplayOrder],
    v.[IsActive]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = v.[ProductStatusId]
WHERE p.[PublicId] = :productPublicId
  AND v.[DeletedAtUtc] IS NULL
  AND v.[IsActive] = 1
  AND ps.[StatusCode] = N'published'
ORDER BY v.[DisplayOrder] ASC, v.[ProductVariantId] ASC
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        return array_map(static fn (array $row): ProductVariantSummary => new ProductVariantSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            variantName: AzureSqlValueMapper::stringValue($row['VariantName'] ?? ''),
            sku: AzureSqlValueMapper::nullableString($row['Sku'] ?? null),
            variantCode: AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
        ), $rows);
    }

    /**
     * @return list<ProductMediaSummary>
     */
    private function listPublicMedia(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), img.[PublicId]) AS [PublicId],
    img.[ImageRoleCode] AS [MediaCategoryCode],
    mt.[MediaTypeCode],
    img.[Title],
    img.[PublicUrl],
    img.[AltText],
    img.[DisplayOrder],
    img.[IsPrimary],
    img.[StorageContainer],
    img.[StoragePath]
FROM [product].[ProductImages] img
INNER JOIN [product].[Products] p ON p.[ProductId] = img.[ProductId]
INNER JOIN [product].[MediaTypes] mt ON mt.[MediaTypeId] = img.[MediaTypeId]
WHERE p.[PublicId] = :productPublicId
  AND img.[DeletedAtUtc] IS NULL
  AND img.[IsActive] = 1
  AND (img.[UploadStatusCode] IS NULL OR img.[UploadStatusCode] = N'active')
  AND (
        img.[ScanStatusCode] IS NULL
        OR img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
  )
ORDER BY img.[IsPrimary] DESC, img.[DisplayOrder] ASC, img.[ProductImagesId] ASC
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        $rolesByMedia = $this->listPresentationRolesByMedia($productPublicId);

        return array_map(static function (array $row) use ($rolesByMedia): ProductMediaSummary {
            $summary = new ProductMediaSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            mediaCategoryCode: AzureSqlValueMapper::stringValue($row['MediaCategoryCode'] ?? ''),
            mediaTypeCode: AzureSqlValueMapper::stringValue($row['MediaTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            altText: AzureSqlValueMapper::nullableString($row['AltText'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isPrimary: AzureSqlValueMapper::boolValue($row['IsPrimary'] ?? false),
            storagePath: AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
            );
            $roles = $rolesByMedia[$summary->publicId] ?? [];

            return $roles === [] ? $summary : $summary->withPresentationRoles($roles);
        }, $rows);
    }

    /**
     * @return array<string, list<string>>
     */
    private function listPresentationRolesByMedia(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), i.[PublicId]) AS [MediaPublicId],
    a.[RoleCode]
FROM [product].[ProductImageRoleAssignments] a
INNER JOIN [product].[Products] p ON p.[ProductId] = a.[ProductId]
INNER JOIN [product].[ProductImages] i ON i.[ProductImagesId] = a.[ProductImagesId]
WHERE p.[PublicId] = :productPublicId
  AND i.[DeletedAtUtc] IS NULL
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );
        $map = [];
        foreach ($rows as $row) {
            $mediaId = AzureSqlValueMapper::stringValue($row['MediaPublicId'] ?? '');
            $role = strtolower(AzureSqlValueMapper::stringValue($row['RoleCode'] ?? ''));
            if ($mediaId === '' || $role === '') {
                continue;
            }
            $map[$mediaId] ??= [];
            if (!in_array($role, $map[$mediaId], true)) {
                $map[$mediaId][] = $role;
            }
        }

        return $map;
    }

    /**
     * @return list<ProductDocumentSummary>
     */
    private function listPublicDocuments(ProductPublicId $productPublicId, ?string $regionCode): array
    {
        $params = [':productPublicId' => $productPublicId->toString()];
        $regionFilter = '';
        if ($regionCode !== null && trim($regionCode) !== '') {
            $regionFilter = <<<'SQL'

  AND (
        doc.[RegionId] IS NULL
        OR EXISTS (
            SELECT 1 FROM [product].[Regions] r
            WHERE r.[RegionId] = doc.[RegionId] AND r.[RegionCode] = :regionCode
        )
  )
SQL;
            $params[':regionCode'] = trim($regionCode);
        }

        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), doc.[PublicId]) AS [PublicId],
    dt.[DocumentTypeCode],
    doc.[Title],
    doc.[VersionLabel],
    doc.[LanguageCode],
    doc.[PublicUrl],
    doc.[AccessCode],
    doc.[DisplayOrder],
    doc.[StorageContainer],
    doc.[StoragePath]
FROM [product].[ProductDocuments] doc
INNER JOIN [product].[Products] p ON p.[ProductId] = doc.[ProductId]
INNER JOIN [product].[DocumentTypes] dt ON dt.[DocumentTypeId] = doc.[DocumentTypeId]
WHERE p.[PublicId] = :productPublicId
  AND doc.[DeletedAtUtc] IS NULL
  AND doc.[IsActive] = 1
  AND (doc.[UploadStatusCode] IS NULL OR doc.[UploadStatusCode] = N'active')
  AND (
        doc.[ScanStatusCode] IS NULL
        OR doc.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
  )
  AND doc.[AccessCode] = N'public'
  AND (doc.[EffectiveFromUtc] IS NULL OR doc.[EffectiveFromUtc] <= SYSUTCDATETIME())
  AND (doc.[EffectiveToUtc] IS NULL OR doc.[EffectiveToUtc] > SYSUTCDATETIME())
{$regionFilter}
ORDER BY doc.[DisplayOrder] ASC, doc.[ProductDocumentId] ASC
SQL,
            $params,
        );

        return array_map(static fn (array $row): ProductDocumentSummary => new ProductDocumentSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            documentTypeCode: AzureSqlValueMapper::stringValue($row['DocumentTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            versionLabel: AzureSqlValueMapper::nullableString($row['VersionLabel'] ?? null),
            languageCode: AzureSqlValueMapper::nullableString($row['LanguageCode'] ?? null),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            accessCode: AzureSqlValueMapper::stringValue($row['AccessCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            storagePath: AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
        ), $rows);
    }

    /**
     * @return list<string>
     */
    private function listNames(ProductPublicId $productPublicId, string $kind): array
    {
        $sql = $kind === 'industries'
            ? <<<'SQL'
SELECT i.[IndustryName] AS [Name]
FROM [product].[ProductIndustries] pi
INNER JOIN [product].[Industries] i ON i.[IndustryId] = pi.[IndustryId]
INNER JOIN [product].[Products] p ON p.[ProductId] = pi.[ProductId]
WHERE p.[PublicId] = :productPublicId AND i.[DeletedAtUtc] IS NULL AND i.[IsActive] = 1
ORDER BY pi.[DisplayOrder]
SQL
            : <<<'SQL'
SELECT a.[ApplicationName] AS [Name]
FROM [product].[ProductApplications] pa
INNER JOIN [product].[Applications] a ON a.[ApplicationId] = pa.[ApplicationId]
INNER JOIN [product].[Products] p ON p.[ProductId] = pa.[ProductId]
WHERE p.[PublicId] = :productPublicId AND a.[DeletedAtUtc] IS NULL AND a.[IsActive] = 1
ORDER BY pa.[DisplayOrder]
SQL;

        $rows = $this->connection->fetchAll($sql, [':productPublicId' => $productPublicId->toString()]);

        return array_values(array_filter(array_map(
            static fn (array $row): string => AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            $rows,
        )));
    }
}
