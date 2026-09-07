<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewDetail;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewMatrixColumn;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewMatrixRow;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewVariantMatrix;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\DTO\ProductPublic3DPayload;
use TriCab\Core\Product\Application\DTO\ProductPublicRelationshipItem;
use TriCab\Core\Product\Application\DTO\ProductPublicSpecificationItem;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Application\DTO\SpecificationValidationRules;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Contract\ProductCustomerViewQueryRepository;
use TriCab\Core\Product\Application\Service\ProductThumbnailImageResolver;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

/**
 * Admin customer-view aggregate. Loads non-deleted products regardless of
 * publish status so drafts can be previewed. Still excludes pricing, ERP,
 * audit, RowVersion, internal IDs, and restricted documents.
 */
final class AzureSqlProductCustomerViewRepository implements ProductCustomerViewQueryRepository
{
    use LoadsProductApprovalCertificates;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly Product3DModelRepository $threeDModels,
        private readonly bool $product3dEnabled = true,
    ) {
    }

    public function findByPublicId(ProductPublicId $publicId, ?string $regionCode = null): ?ProductCustomerViewDetail
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
/*q:cv.product*/
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName],
    p.[InternalCode],
    p.[ShortDescription],
    p.[FullDescription],
    f.[FamilyName],
    c.[CategoryName],
    s.[SeriesName],
    ps.[StatusCode],
    pls.[LifecycleStatusCode],
    CASE WHEN ps.[StatusCode] = N'archived' THEN 1 ELSE 0 END AS [IsArchived],
    p.[ThumbnailBackgroundColor],
    (
        SELECT TOP 1 slug.[Slug]
        FROM [product].[ProductSlugs] slug
        WHERE slug.[ProductId] = p.[ProductId]
          AND slug.[DeletedAtUtc] IS NULL
          AND slug.[IsActive] = 1
          AND slug.[IsPrimary] = 1
        ORDER BY slug.[ProductSlugId]
    ) AS [Slug]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
INNER JOIN [product].[ProductLifecycleStatuses] pls ON pls.[ProductLifecycleStatusId] = p.[ProductLifecycleStatusId]
LEFT JOIN [product].[ProductFamilies] f ON f.[ProductFamilyId] = p.[ProductFamilyId]
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = p.[ProductCategoryId]
LEFT JOIN [product].[ProductSeries] s ON s.[ProductSeriesId] = p.[ProductSeriesId]
WHERE p.[PublicId] = :publicId
  AND p.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return $this->hydrateDetail($rows[0], $regionCode);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDetail(array $row, ?string $regionCode): ProductCustomerViewDetail
    {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        $pid = ProductPublicId::fromString($publicId);
        $media = $this->listPublicMedia($pid);
        $thumbnailUrl = ProductThumbnailImageResolver::fromMediaSummaries($media);
        $slug = AzureSqlValueMapper::stringValue($row['Slug'] ?? '');
        $statusCode = AzureSqlValueMapper::stringValue($row['StatusCode'] ?? '');
        $primarySlug = $slug !== '' ? $slug : null;
        $variants = $this->listPreviewVariants($pid);
        $documents = $this->listPublicDocuments($pid, $regionCode);
        $applicationNames = $this->listNames($pid, 'applications');
        $industryNames = $this->listNames($pid, 'industries');
        $relationships = $this->listPublicRelationships($pid);
        $specifications = $this->listCustomerSpecifications($pid);
        $variantMatrix = $this->buildVariantMatrix($pid, $variants);
        $styleAssignment = $this->loadStyleAssignment($pid);

        $internalCode = AzureSqlValueMapper::nullableString($row['InternalCode'] ?? null);

        return new ProductCustomerViewDetail(
            publicId: $publicId,
            productName: AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            productCode: $internalCode ?? $this->primaryProductCode($pid),
            shortDescription: AzureSqlValueMapper::nullableString($row['ShortDescription'] ?? null),
            fullDescription: AzureSqlValueMapper::nullableString($row['FullDescription'] ?? null),
            familyName: AzureSqlValueMapper::nullableString($row['FamilyName'] ?? null),
            categoryName: AzureSqlValueMapper::nullableString($row['CategoryName'] ?? null),
            seriesName: AzureSqlValueMapper::nullableString($row['SeriesName'] ?? null),
            slug: $slug,
            thumbnailUrl: $thumbnailUrl,
            variants: $variants,
            media: $media,
            documents: $documents,
            applicationNames: $applicationNames,
            industryNames: $industryNames,
            relationships: $relationships,
            specifications: $specifications,
            variantMatrix: $variantMatrix,
            statusCode: $statusCode,
            lifecycleStatusCode: AzureSqlValueMapper::stringValue($row['LifecycleStatusCode'] ?? ''),
            isPublished: strcasecmp($statusCode, 'published') === 0,
            isArchived: AzureSqlValueMapper::boolValue($row['IsArchived'] ?? false),
            primarySlug: $primarySlug,
            threeD: $this->loadPublicThreeD($pid),
            styleCode: $styleAssignment['styleCode'],
            parentStyleCode: $styleAssignment['parentStyleCode'],
            approvalCertificates: $this->listApprovalCertificates($pid),
            thumbnailBackgroundColor: ThumbnailBackgroundColor::tryFromString(
                AzureSqlValueMapper::nullableString($row['ThumbnailBackgroundColor'] ?? null),
            )?->toString(),
        );
    }

    /**
     * @return array{styleCode: ?string, parentStyleCode: ?string}
     */
    private function loadStyleAssignment(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    child.[StyleCode] AS [StyleCode],
    parent.[StyleCode] AS [ParentStyleCode]
FROM [product].[ProductStyleAssignments] psa
INNER JOIN [product].[Products] p ON p.[ProductId] = psa.[ProductId]
INNER JOIN [product].[ProductStyles] child ON child.[ProductStyleId] = psa.[ProductStyleId]
LEFT JOIN [product].[ProductStyles] parent ON parent.[ProductStyleId] = child.[ParentStyleId]
    AND parent.[DeletedAtUtc] IS NULL
WHERE p.[PublicId] = :publicId
  AND p.[DeletedAtUtc] IS NULL
  AND child.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $productPublicId->toString()],
        );
        if ($rows === []) {
            return ['styleCode' => null, 'parentStyleCode' => null];
        }

        return [
            'styleCode' => AzureSqlValueMapper::nullableString($rows[0]['StyleCode'] ?? null),
            'parentStyleCode' => AzureSqlValueMapper::nullableString($rows[0]['ParentStyleCode'] ?? null),
        ];
    }

    private function loadPublicThreeD(ProductPublicId $pid): ProductPublic3DPayload
    {
        if (!$this->product3dEnabled) {
            return ProductPublic3DPayload::empty();
        }

        try {
            return $this->threeDModels->listPublicByProduct($pid);
        } catch (\Throwable) {
            // Phase 2D schema may be pending migration — never break Customer View.
            return ProductPublic3DPayload::empty();
        }
    }

    private function primaryProductCode(ProductPublicId $productPublicId): ?string
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
/*q:cv.product_code*/
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
/*q:cv.relationships*/
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
    private function listCustomerSpecifications(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
/*q:cv.product_specs*/
SELECT
    sd.[DefinitionCode] AS [Code],
    sd.[DefinitionName] AS [Name],
    sg.[GroupName],
    COALESCE(psv.[UnitOverride], sd.[Unit]) AS [Unit],
    psv.[ValueText],
    psv.[ValueLongText],
    CONVERT(NVARCHAR(50), psv.[ValueInteger]) AS [ValueInteger],
    CONVERT(NVARCHAR(50), psv.[ValueDecimal]) AS [ValueDecimal],
    psv.[ValueBoolean],
    CONVERT(NVARCHAR(50), psv.[ValueMinDecimal]) AS [ValueMinDecimal],
    CONVERT(NVARCHAR(50), psv.[ValueMaxDecimal]) AS [ValueMaxDecimal],
    so.[OptionLabel],
    (
        SELECT STRING_AGG(opt.[OptionLabel], N' | ') WITHIN GROUP (ORDER BY opt.[DisplayOrder], opt.[OptionLabel])
        FROM [product].[ProductSpecificationOptionValues] pov
        INNER JOIN [product].[SpecificationOptions] opt
            ON opt.[SpecificationOptionId] = pov.[SpecificationOptionId]
        WHERE pov.[ProductSpecificationValueId] = psv.[ProductSpecificationValueId]
    ) AS [MultiOptionLabels],
    sd.[ValidationRulesJson],
    sd.[ScopeCode]
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
            $rules = SpecificationValidationRules::fromJson(
                AzureSqlValueMapper::nullableString($row['ValidationRulesJson'] ?? null),
            );
            if ($rules->isAdminOnly || !$rules->isPublicVisible) {
                continue;
            }

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
     * Catalogue Technical Dimensions column codes (Customer View order).
     *
     * @var list<string>
     */
    private const TECHNICAL_DIMENSION_CODES = [
        'SIZE',
        'MIN-BEND-RADIUS',
        'NOM-COND-OD',
        'NOM-INS-OD',
        'NOM-CABLE-OD',
        'CURRENT',
        'APPROX-WEIGHT',
    ];

    /**
     * @param list<ProductVariantSummary> $variants
     */
    private function buildVariantMatrix(ProductPublicId $productPublicId, array $variants): ProductCustomerViewVariantMatrix
    {
        $placeholders = [];
        $params = [];
        foreach (self::TECHNICAL_DIMENSION_CODES as $index => $code) {
            $key = ':code' . $index;
            $placeholders[] = $key;
            $params[$key] = $code;
        }
        $inList = implode(', ', $placeholders);

        $columnRows = $this->connection->fetchAll(
            <<<SQL
/*q:cv.matrix_columns*/
SELECT
    CONVERT(NVARCHAR(36), sd.[PublicId]) AS [DefinitionPublicId],
    sd.[DefinitionCode] AS [Code],
    sd.[DefinitionName] AS [Name],
    sg.[GroupName],
    sd.[Unit],
    sd.[DataTypeCode],
    sd.[DisplayOrder],
    sd.[ValidationRulesJson],
    sg.[DisplayOrder] AS [GroupDisplayOrder]
FROM [product].[SpecificationDefinitions] sd
LEFT JOIN [product].[SpecificationGroups] sg ON sg.[SpecificationGroupId] = sd.[SpecificationGroupId]
WHERE sd.[DeletedAtUtc] IS NULL
  AND sd.[IsActive] = 1
  AND sd.[ScopeCode] IN (N'variant', N'both')
  AND sd.[DefinitionCode] IN ({$inList})
SQL,
            $params,
        );

        $columnsByCode = [];
        foreach ($columnRows as $row) {
            $rules = SpecificationValidationRules::fromJson(
                AzureSqlValueMapper::nullableString($row['ValidationRulesJson'] ?? null),
            );
            if ($rules->isAdminOnly || !$rules->isPublicVisible) {
                continue;
            }
            $code = AzureSqlValueMapper::stringValue($row['Code'] ?? '');
            $columnsByCode[$code] = $row;
        }

        $columns = [];
        $columnIds = [];
        foreach (self::TECHNICAL_DIMENSION_CODES as $index => $code) {
            $row = $columnsByCode[$code] ?? null;
            if ($row === null) {
                continue;
            }
            $definitionPublicId = AzureSqlValueMapper::stringValue($row['DefinitionPublicId'] ?? '');
            $columns[] = new ProductCustomerViewMatrixColumn(
                definitionPublicId: $definitionPublicId,
                code: $code,
                name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
                groupName: AzureSqlValueMapper::nullableString($row['GroupName'] ?? null),
                unit: AzureSqlValueMapper::nullableString($row['Unit'] ?? null),
                dataTypeCode: AzureSqlValueMapper::stringValue($row['DataTypeCode'] ?? ''),
                displayOrder: $index + 1,
            );
            $columnIds[] = $definitionPublicId;
        }

        $valueRows = [];
        if ($columnIds !== []) {
            $valueParams = [':productPublicId' => $productPublicId->toString()] + $params;
            $valueRows = $this->connection->fetchAll(
                <<<SQL
/*q:cv.matrix_values*/
SELECT
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    CONVERT(NVARCHAR(36), sd.[PublicId]) AS [DefinitionPublicId],
    psv.[ValueText],
    psv.[ValueLongText],
    CONVERT(NVARCHAR(50), psv.[ValueInteger]) AS [ValueInteger],
    CONVERT(NVARCHAR(50), psv.[ValueDecimal]) AS [ValueDecimal],
    psv.[ValueBoolean],
    CONVERT(NVARCHAR(50), psv.[ValueMinDecimal]) AS [ValueMinDecimal],
    CONVERT(NVARCHAR(50), psv.[ValueMaxDecimal]) AS [ValueMaxDecimal],
    so.[OptionLabel]
FROM [product].[SpecificationDefinitions] sd
INNER JOIN [product].[ProductVariantSpecificationValues] psv
    ON psv.[SpecificationDefinitionId] = sd.[SpecificationDefinitionId]
   AND psv.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ProductVariants] v
    ON v.[ProductVariantId] = psv.[ProductVariantId]
   AND v.[DeletedAtUtc] IS NULL
   AND v.[IsActive] = 1
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
LEFT JOIN [product].[SpecificationOptions] so ON so.[SpecificationOptionId] = psv.[SpecificationOptionId]
WHERE p.[PublicId] = :productPublicId
  AND sd.[DeletedAtUtc] IS NULL
  AND sd.[DefinitionCode] IN ({$inList})
SQL,
                $valueParams,
            );
        }

        /** @var array<string, array<string, string|null>> $cellsByVariant */
        $cellsByVariant = [];
        foreach ($variants as $variant) {
            $cellsByVariant[$variant->publicId] = array_fill_keys($columnIds, null);
        }

        foreach ($valueRows as $row) {
            $variantPublicId = AzureSqlValueMapper::stringValue($row['VariantPublicId'] ?? '');
            $definitionPublicId = AzureSqlValueMapper::nullableString($row['DefinitionPublicId'] ?? null);
            if ($variantPublicId === '' || $definitionPublicId === null || $definitionPublicId === '') {
                continue;
            }
            if (!isset($cellsByVariant[$variantPublicId]) || !array_key_exists($definitionPublicId, $cellsByVariant[$variantPublicId])) {
                continue;
            }
            $displayValue = $this->azureSpecDisplayValue($row);
            $cellsByVariant[$variantPublicId][$definitionPublicId] = ($displayValue !== null && $displayValue !== '')
                ? $displayValue
                : null;
        }

        $matrixRows = [];
        foreach ($variants as $variant) {
            $matrixRows[] = new ProductCustomerViewMatrixRow(
                publicId: $variant->publicId,
                variantName: $variant->variantName,
                sku: $variant->sku,
                variantCode: $variant->variantCode,
                statusCode: $variant->statusCode,
                displayOrder: $variant->displayOrder,
                isActive: $variant->isActive,
                cells: $cellsByVariant[$variant->publicId] ?? array_fill_keys($columnIds, null),
            );
        }

        return new ProductCustomerViewVariantMatrix($columns, $matrixRows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function azureSpecDisplayValue(array $row): ?string
    {
        $multiOptions = AzureSqlValueMapper::nullableString($row['MultiOptionLabels'] ?? null);
        if ($multiOptions !== null && $multiOptions !== '') {
            return $multiOptions;
        }
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
            return $this->formatNumericDisplay($decimal);
        }
        if (array_key_exists('ValueBoolean', $row) && $row['ValueBoolean'] !== null) {
            return AzureSqlValueMapper::boolValue($row['ValueBoolean']) ? 'Yes' : 'No';
        }
        $min = $this->formatNumericDisplay(AzureSqlValueMapper::nullableString($row['ValueMinDecimal'] ?? null));
        $max = $this->formatNumericDisplay(AzureSqlValueMapper::nullableString($row['ValueMaxDecimal'] ?? null));
        if ($min !== null || $max !== null) {
            $unit = AzureSqlValueMapper::nullableString($row['Unit'] ?? null) ?? '';
            $name = strtolower(AzureSqlValueMapper::stringValue($row['Name'] ?? ''));
            $isTemp = str_contains($name, 'temp') || str_contains($unit, '°') || strcasecmp($unit, 'C') === 0;
            if ($isTemp) {
                $minLabel = $min !== null ? $this->formatSignedTemp($min) : '';
                $maxLabel = $max !== null ? $this->formatSignedTemp($max) : '';
                $joined = trim($minLabel . ' to ' . $maxLabel);

                return $joined !== '' ? $joined : null;
            }

            return trim(($min ?? '') . ' – ' . ($max ?? ''), ' –');
        }

        return null;
    }

    private function formatNumericDisplay(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return trim($value);
        }
        $number = (float) $value;
        if (floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
    }

    private function formatSignedTemp(string $value): string
    {
        if (!is_numeric($value)) {
            return $value;
        }
        $number = (float) $value;
        $formatted = $this->formatNumericDisplay($value) ?? $value;
        if ($number > 0) {
            return '+' . $formatted;
        }

        return $formatted;
    }

    /**
     * Active non-deleted variants of any status (draft included for admin preview).
     *
     * @return list<ProductVariantSummary>
     */
    private function listPreviewVariants(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
/*q:cv.variants*/
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
/*q:cv.media*/
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
  AND (img.[UploadStatusCode] IS NULL OR img.[UploadStatusCode] IN (N'active', N'uploaded'))
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
/*q:cv.documents*/
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
/*q:cv.industries*/
SELECT i.[IndustryName] AS [Name]
FROM [product].[ProductIndustries] pi
INNER JOIN [product].[Industries] i ON i.[IndustryId] = pi.[IndustryId]
INNER JOIN [product].[Products] p ON p.[ProductId] = pi.[ProductId]
WHERE p.[PublicId] = :productPublicId AND i.[DeletedAtUtc] IS NULL AND i.[IsActive] = 1
ORDER BY pi.[DisplayOrder]
SQL
            : <<<'SQL'
/*q:cv.applications*/
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
