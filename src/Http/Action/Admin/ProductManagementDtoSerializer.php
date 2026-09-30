<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use TriCab\Core\Product\Application\DTO\LegacyPriceContextVerification;
use TriCab\Core\Product\Application\DTO\PriceListLookupItem;
use TriCab\Core\Product\Application\DTO\PriceListRecord;
use TriCab\Core\Product\Application\DTO\PriceListUsage;
use TriCab\Core\Product\Application\DTO\PricingUnitLookupItem;

use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\DTO\ProductAdminListItem;
use TriCab\Core\Product\Application\DTO\ProductAuditEntry;
use TriCab\Core\Product\Application\DTO\EffectiveSpecificationValue;
use TriCab\Core\Product\Application\DTO\EffectiveStyleAsset;
use TriCab\Core\Product\Application\DTO\ProductClassificationRecord;
use TriCab\Core\Product\Application\DTO\ProductClassificationUsage;
use TriCab\Core\Product\Application\DTO\ProductStyleAssignment;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Application\DTO\ProductStyleTreeNode;
use TriCab\Core\Product\Application\DTO\ProductStyleUsage;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingRow;
use TriCab\Core\Product\Application\DTO\ProductCodeStyleMappingRow;
use TriCab\Core\Product\Application\DTO\UnresolvedLegacyStyleProduct;
use TriCab\Core\Product\Application\DTO\StyleDiscoveryProposal;
use TriCab\Core\Product\Application\DTO\StyleHierarchyEvidenceRow;
use TriCab\Core\Product\Application\DTO\StyleMembershipEvidence;
use TriCab\Core\Product\Application\DTO\StyleSharedDataComparison;
use TriCab\Core\Product\Application\DTO\ProductCodePreviewResult;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Application\DTO\ProductListResult;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Application\DTO\ProductMediaFileRecord;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\Service\PresentationMediaResolver;
use TriCab\Core\Product\Application\Service\ProductThumbnailImageResolver;
use TriCab\Core\Product\Domain\ValueObject\PresentationMediaRole;
use TriCab\Core\Product\Application\DTO\ProductDocumentDownloadResult;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Application\DTO\Product360SetSummary;
use TriCab\Core\Product\Application\DTO\Product360FrameSummary;
use TriCab\Core\Product\Application\DTO\ProductArModelSummary;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;
use TriCab\Core\Product\Application\DTO\ProductPublic3DModel;
use TriCab\Core\Product\Application\DTO\ProductPublic3DPayload;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Application\DTO\ProductPricingOverview;
use TriCab\Core\Product\Application\DTO\ProductPricingMatrixRow;
use TriCab\Core\Product\Application\DTO\ProductBaseCostHistoryEntry;
use TriCab\Core\Product\Application\DTO\LegacyBaseCostComparison;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewDetail;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewMatrixColumn;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewMatrixRow;
use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Application\DTO\ProductApprovalCertificateItem;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessCheck;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessIssue;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessReport;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchListResult;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchResult;
use TriCab\Core\Product\Application\DTO\ProductSearchListResult;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\SpecificationDefinitionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationGroupRecord;
use TriCab\Core\Product\Application\DTO\SpecificationOptionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUnitRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Api\Product\Catalogue\CatalogueStaticMediaResolver;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;

/**
 * Serializes Core Product DTOs to admin/public API response arrays.
 * Never exposes numeric internal IDs — publicIds only on outward-facing payloads.
 */
final class ProductManagementDtoSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function mutationResult(ProductMutationResult $result): array
    {
        return [
            'publicId' => $result->publicId,
            'rowVersion' => $result->rowVersion,
            'statusCode' => $result->statusCode,
            'createdAtUtc' => $result->createdAtUtc,
            'updatedAtUtc' => $result->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminDetail(ProductAdminDetail $detail): array
    {
        return [
            'publicId' => $detail->publicId,
            'productName' => $detail->productName,
            'internalName' => $detail->internalName,
            'internalCode' => $detail->internalCode,
            'shortDescription' => $detail->shortDescription,
            'fullDescription' => $detail->fullDescription,
            'familyPublicId' => $detail->familyPublicId,
            'categoryPublicId' => $detail->categoryPublicId,
            'seriesPublicId' => $detail->seriesPublicId,
            'statusCode' => $detail->statusCode,
            'lifecycleStatusCode' => $detail->lifecycleStatusCode,
            'isFeatured' => $detail->isFeatured,
            'publishAtUtc' => $detail->publishAtUtc,
            'unpublishAtUtc' => $detail->unpublishAtUtc,
            'discontinueAtUtc' => $detail->discontinueAtUtc,
            'erpReference' => $detail->erpReference,
            'legacyProductId' => $detail->legacyProductId,
            'variants' => array_map(self::variantSummary(...), $detail->variants),
            'regionCodes' => $detail->regionCodes,
            'marketCodes' => $detail->marketCodes,
            'industryCodes' => $detail->industryCodes,
            'applicationCodes' => $detail->applicationCodes,
            'primarySlug' => $detail->primarySlug,
            'isActive' => $detail->isActive,
            'isArchived' => $detail->isArchived,
            'createdAtUtc' => $detail->createdAtUtc,
            'updatedAtUtc' => $detail->updatedAtUtc,
            'createdByDisplayName' => $detail->createdByDisplayName,
            'updatedByDisplayName' => $detail->updatedByDisplayName,
            'rowVersion' => $detail->rowVersion,
            'thumbnailBackgroundColor' => $detail->thumbnailBackgroundColor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminListItem(
        ProductAdminListItem $item,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $item->publicId,
            'productName' => $item->productName,
            'internalCode' => $item->internalCode,
            'primaryProductCode' => $item->primaryProductCode,
            'thumbnailUrl' => self::resolveAssetUrl($devUrls, $item->thumbnailUrl, $item->publicId),
            'familyName' => $item->familyName,
            'categoryName' => $item->categoryName,
            'seriesName' => $item->seriesName,
            'variantCount' => $item->variantCount,
            'regionCodes' => $item->regionCodes,
            'statusCode' => $item->statusCode,
            'lifecycleStatusCode' => $item->lifecycleStatusCode,
            'updatedAtUtc' => $item->updatedAtUtc,
            'updatedByDisplayName' => $item->updatedByDisplayName,
            'isArchived' => $item->isArchived,
            'rowVersion' => $item->rowVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function listResult(
        ProductListResult $result,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'items' => array_map(
                static fn (ProductAdminListItem $item): array => self::adminListItem($item, $devUrls),
                $result->items,
            ),
            'pagination' => self::pagination($result->pagination),
        ];
    }

    /**
     * Customer-safe product detail — excludes rowVersion, pricing, ERP, legacy, deletedAt, internal ids.
     *
     * @return array<string, mixed>
     */
    public static function publicDetail(
        ProductPublicDetail $detail,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $media = array_map(
            static fn (ProductMediaSummary $item): array => self::publicMediaSummary($item, $devUrls),
            $detail->media,
        );

        return [
            'publicId' => $detail->publicId,
            'productName' => $detail->productName,
            'productCode' => $detail->productCode,
            'shortDescription' => $detail->shortDescription,
            'fullDescription' => $detail->fullDescription,
            'familyName' => $detail->familyName,
            'categoryName' => $detail->categoryName,
            'seriesName' => $detail->seriesName,
            'slug' => $detail->slug,
            'thumbnailUrl' => self::resolveAssetUrl(
                $devUrls,
                ProductThumbnailImageResolver::fromMediaSummaries($detail->media),
            ),
            'thumbnailImageUrl' => self::resolveAssetUrl(
                $devUrls,
                ProductThumbnailImageResolver::fromMediaSummaries($detail->media),
            ),
            'thumbnailBackgroundColor' => $detail->thumbnailBackgroundColor ?? null,
            'variants' => array_map(self::variantSummary(...), $detail->variants),
            'media' => $media,
            'documents' => array_map(
                static fn (ProductDocumentSummary $item): array => self::publicDocumentSummary($item, $devUrls),
                $detail->documents,
            ),
            'applicationNames' => $detail->applicationNames,
            'industryNames' => $detail->industryNames,
            'relationships' => array_map(self::publicRelationship(...), $detail->relationships),
            'specifications' => array_map(self::publicSpecification(...), $detail->specifications),
            'approvalCertificates' => array_map(
                static fn (ProductApprovalCertificateItem $item): array => self::approvalCertificate($item, $devUrls),
                $detail->approvalCertificates,
            ),
            'threeD' => self::publicThreeD($detail->threeD, $devUrls),
            'spin360' => array_map(
                static fn (Product360SetSummary $set): array => self::public360SetSummary($set, $devUrls),
                $detail->spin360,
            ),
        ];
    }

    /**
     * Admin preview of the customer datasheet (works for draft/unpublished products).
     *
     * @return array<string, mixed>
     */
    public static function customerViewDetail(
        ProductCustomerViewDetail $detail,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $media = array_map(
            static fn (ProductMediaSummary $item): array => self::publicMediaSummary($item, $devUrls),
            $detail->media,
        );

        $canonicalThumbnail = self::resolveAssetUrl(
            $devUrls,
            ProductThumbnailImageResolver::fromMediaSummaries($detail->media),
        );

        $productThumbnail = PresentationMediaResolver::select(
            $detail->media,
            PresentationMediaRole::PRODUCT_THUMBNAIL,
        );
        $primaryVisual = PresentationMediaResolver::select(
            $detail->media,
            PresentationMediaRole::PRIMARY,
        );
        $crossSection = PresentationMediaResolver::select(
            $detail->media,
            PresentationMediaRole::CROSS_SECTION,
        );

        return [
            'preview' => [
                'statusCode' => $detail->statusCode,
                'lifecycleStatusCode' => $detail->lifecycleStatusCode,
                'isPublished' => $detail->isPublished,
                'isArchived' => $detail->isArchived,
                'primarySlug' => $detail->primarySlug,
            ],
            'publicId' => $detail->publicId,
            'productName' => $detail->productName,
            'productCode' => $detail->productCode,
            'shortDescription' => $detail->shortDescription,
            'fullDescription' => $detail->fullDescription,
            'familyName' => $detail->familyName,
            'categoryName' => $detail->categoryName,
            'seriesName' => $detail->seriesName,
            'slug' => $detail->slug,
            'thumbnailUrl' => $canonicalThumbnail,
            'thumbnailImageUrl' => $canonicalThumbnail,
            'presentationMedia' => [
                'productThumbnail' => $productThumbnail === null
                    ? null
                    : self::publicMediaSummary($productThumbnail, $devUrls),
                'primary' => $primaryVisual === null
                    ? null
                    : self::publicMediaSummary($primaryVisual, $devUrls),
                'crossSection' => $crossSection === null
                    ? null
                    : self::publicMediaSummary($crossSection, $devUrls),
            ],
            'variants' => array_map(self::variantSummary(...), $detail->variants),
            'media' => $media,
            'documents' => array_map(
                static fn (ProductDocumentSummary $item): array => self::publicDocumentSummary($item, $devUrls),
                $detail->documents,
            ),
            'applicationNames' => $detail->applicationNames,
            'industryNames' => $detail->industryNames,
            'relationships' => array_map(self::publicRelationship(...), $detail->relationships),
            'specifications' => array_map(self::publicSpecification(...), $detail->specifications),
            'approvalCertificates' => array_map(
                static fn (ProductApprovalCertificateItem $item): array => self::approvalCertificate($item, $devUrls),
                $detail->approvalCertificates,
            ),
            'variantMatrix' => [
                'layout' => $detail->variantMatrix->layout,
                'familyCode' => $detail->variantMatrix->familyCode,
                'columns' => array_map(self::customerViewMatrixColumn(...), $detail->variantMatrix->columns),
                'rows' => array_map(self::customerViewMatrixRow(...), $detail->variantMatrix->rows),
            ],
            'threeD' => self::publicThreeD($detail->threeD, $devUrls),
            'styleCode' => $detail->styleCode,
            'parentStyleCode' => $detail->parentStyleCode,
            'thumbnailBackgroundColor' => $detail->thumbnailBackgroundColor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerViewMatrixColumn(ProductCustomerViewMatrixColumn $column): array
    {
        return [
            'definitionPublicId' => $column->definitionPublicId,
            'code' => $column->code,
            'name' => $column->name,
            'groupName' => $column->groupName,
            'unit' => $column->unit,
            'dataTypeCode' => $column->dataTypeCode,
            'displayOrder' => $column->displayOrder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerViewMatrixRow(ProductCustomerViewMatrixRow $row): array
    {
        return [
            'publicId' => $row->publicId,
            'variantName' => $row->variantName,
            'sku' => $row->sku,
            'variantCode' => $row->variantCode,
            'statusCode' => $row->statusCode,
            'displayOrder' => $row->displayOrder,
            'isActive' => $row->isActive,
            'cells' => $row->cells === [] ? new \stdClass() : $row->cells, // always a JSON object (a product may have no matrix columns)
            'colour' => $row->colour,
            'packSize' => $row->packSize,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicRelationship(\TriCab\Core\Product\Application\DTO\ProductPublicRelationshipItem $item): array
    {
        return [
            'relatedProductPublicId' => $item->relatedProductPublicId,
            'relatedProductName' => $item->relatedProductName,
            'relationshipTypeCode' => $item->relationshipTypeCode,
            'relatedSlug' => $item->relatedSlug,
            'displayOrder' => $item->displayOrder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicSpecification(\TriCab\Core\Product\Application\DTO\ProductPublicSpecificationItem $item): array
    {
        return [
            'code' => $item->code,
            'name' => $item->name,
            'groupName' => $item->groupName,
            'displayValue' => $item->displayValue,
            'unit' => $item->unit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function approvalCertificate(
        ProductApprovalCertificateItem $item,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $imageUrl = CatalogueStaticMediaResolver::instance()->toPublicUrl($item->imageUrl);
        if ($imageUrl !== null && (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://'))) {
            $imageUrl = self::resolveAssetUrl($devUrls, $imageUrl);
        }

        return [
            'publicId' => $item->publicId,
            'code' => $item->code,
            'name' => $item->name,
            'issuer' => $item->issuer,
            'imageUrl' => $imageUrl,
            'statusCode' => $item->statusCode,
            'displayOrder' => $item->displayOrder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function searchListResult(
        ProductSearchListResult $result,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'items' => array_map(
                static fn (ProductSearchResult $item): array => self::searchResult($item, $devUrls),
                $result->items,
            ),
            'pagination' => self::pagination($result->pagination),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function codeSearchListResult(ProductCodeSearchListResult $result): array
    {
        return [
            'items' => array_map(
                static fn (ProductCodeSearchResult $item): array => self::codeSearchResult($item),
                $result->items,
            ),
            'pagination' => self::pagination($result->pagination),
        ];
    }

    /**
     * The nine segments are exposed individually as well as assembled: the UI renders
     * a segment-by-segment breakdown, and catalogue fields are null for the ~27,000
     * indexed codes with no loaded variant behind them.
     *
     * @return array<string, mixed>
     */
    public static function codeSearchResult(ProductCodeSearchResult $result): array
    {
        return [
            'codeValue' => $result->codeValue,
            'styleCode' => $result->styleCode,
            'styleName' => $result->styleName,
            'segments' => [
                'styleCode' => $result->styleCode,
                'styleSuffix' => $result->styleSuffix,
                'jacketTypeCode' => $result->jacketTypeCode,
                'coreColourConfig' => $result->coreColourConfig,
                'braidArmourScreen' => $result->braidArmourScreen,
                'conductorCode' => $result->conductorCode,
                'coreCount' => $result->coreCount,
                'coreTypeCode' => $result->coreTypeCode,
                'crossSection' => $result->crossSectionRaw,
                'jacketColour' => $result->jacketColour,
            ],
            'crossSectionUnit' => $result->crossSectionUnit,
            'crossSectionMm2' => $result->crossSectionMm2,
            'crossSectionNominalMm2' => $result->crossSectionNominalMm2,
            'setCores' => $result->setCores,
            'stockStatus' => $result->stockStatus,
            'isCurrent' => $result->isCurrent,
            'weightKgPerKm' => $result->weightKgPerKm,
            'description' => $result->description,
            'productName' => $result->productName,
            'productSlug' => $result->productSlug,
            'variantPublicId' => $result->variantPublicId,
            'variantCode' => $result->variantCode,
            'matchTier' => $result->matchTier,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function searchResult(
        ProductSearchResult $result,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $result->publicId,
            'productName' => $result->productName,
            'productCode' => $result->productCode,
            'slug' => $result->slug,
            'categoryName' => $result->categoryName,
            'seriesName' => $result->seriesName,
            'statusCode' => $result->statusCode,
            'thumbnailUrl' => self::resolvePublicMediaUrl($devUrls, $result->thumbnailUrl, $result->publicId),
            'thumbnailImageUrl' => self::resolvePublicMediaUrl(
                $devUrls,
                $result->thumbnailImageUrl ?? $result->thumbnailUrl,
                $result->publicId,
            ),
            'thumbnailBackgroundColor' => $result->thumbnailBackgroundColor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function readinessReport(ProductPublishReadinessReport $report): array
    {
        return [
            'isReady' => $report->isReady,
            'errors' => array_map(self::readinessIssue(...), $report->errors()),
            'warnings' => array_map(self::readinessIssue(...), $report->warnings()),
            'checks' => array_map(self::readinessCheck(...), $report->checks),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pricingSummary(ProductPricingSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'recordType' => $summary->recordType,
            'variantPublicId' => $summary->variantPublicId,
            'variantName' => $summary->variantName,
            'variantCode' => $summary->variantCode,
            'currencyCode' => $summary->currencyCode,
            'baseCost' => $summary->baseCost,
            'unitPrice' => $summary->baseCost,
            'minimumQuantity' => $summary->minimumQuantity,
            'priceListCode' => $summary->priceListCode,
            'priceListPublicId' => $summary->priceListPublicId,
            'regionPublicId' => $summary->regionPublicId,
            'marketPublicId' => $summary->marketPublicId,
            'unitOfMeasureCode' => $summary->unitOfMeasureCode,
            'unitOfMeasureSymbol' => $summary->unitOfMeasureSymbol,
            'unitOfMeasurePublicId' => $summary->unitOfMeasurePublicId,
            'priceSourceCode' => $summary->priceSourceCode,
            'effectiveFromUtc' => $summary->effectiveFromUtc,
            'effectiveToUtc' => $summary->effectiveToUtc,
            'status' => $summary->status,
            'isActive' => $summary->isActive,
            'rowVersion' => $summary->rowVersion,
            'legacyItemCode' => $summary->legacyItemCode,
            'erpSourceReference' => $summary->erpSourceReference,
            'lastSourceUpdatedAtUtc' => $summary->lastSourceUpdatedAtUtc,
        ];
    }

    /**
     * @param list<ProductPricingSummary> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function pricingList(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = self::pricingSummary($item);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pricingOverview(ProductPricingOverview $overview): array
    {
        return [
            'variantCount' => $overview->variantCount,
            'variantsWithPricing' => $overview->variantsWithPricing,
            'missingBaseCostCount' => $overview->missingBaseCostCount,
            'currentRecordCount' => $overview->currentRecordCount,
            'scheduledRecordCount' => $overview->scheduledRecordCount,
            'expiredRecordCount' => $overview->expiredRecordCount,
            'inactiveRecordCount' => $overview->inactiveRecordCount,
            'defaultUnitOfMeasurePublicId' => $overview->defaultUnitOfMeasurePublicId,
            'defaultUnitOfMeasureCode' => $overview->defaultUnitOfMeasureCode,
            'defaultUnitOfMeasureSymbol' => $overview->defaultUnitOfMeasureSymbol,
            'defaultUnitSource' => $overview->defaultUnitSource,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pricingUnitLookup(PricingUnitLookupItem $item): array
    {
        return [
            'publicId' => $item->publicId,
            'code' => $item->code,
            'name' => $item->name,
            'symbol' => $item->symbol,
            'displayOrder' => $item->displayOrder,
            'isActive' => $item->isActive,
        ];
    }

    /**
     * @param list<PricingUnitLookupItem> $items
     * @return list<array<string, mixed>>
     */
    public static function pricingUnitLookupList(array $items): array
    {
        return array_map(self::pricingUnitLookup(...), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pricingMatrixRow(ProductPricingMatrixRow $row): array
    {
        return [
            'variantPublicId' => $row->variantPublicId,
            'variantName' => $row->variantName,
            'variantCode' => $row->variantCode,
            'sku' => $row->sku,
            'displayOrder' => $row->displayOrder,
            'legacyItemCode' => $row->legacyItemCode,
            'currentPricingPublicId' => $row->currentPricingPublicId,
            'currencyCode' => $row->currencyCode,
            'baseCost' => $row->baseCost,
            'unitOfMeasureCode' => $row->unitOfMeasureCode,
            'unitOfMeasureSymbol' => $row->unitOfMeasureSymbol,
            'priceListCode' => $row->priceListCode,
            'priceSourceCode' => $row->priceSourceCode,
            'effectiveFromUtc' => $row->effectiveFromUtc,
            'effectiveToUtc' => $row->effectiveToUtc,
            'status' => $row->status,
            'isActive' => $row->isActive,
            'rowVersion' => $row->rowVersion,
        ];
    }

    /**
     * @param list<ProductPricingMatrixRow> $items
     * @return list<array<string, mixed>>
     */
    public static function pricingMatrixList(array $items): array
    {
        return array_map(self::pricingMatrixRow(...), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public static function baseCostHistoryEntry(ProductBaseCostHistoryEntry $entry): array
    {
        return [
            'publicId' => $entry->publicId,
            'recordType' => $entry->recordType,
            'variantPublicId' => $entry->variantPublicId,
            'variantName' => $entry->variantName,
            'currencyCode' => $entry->currencyCode,
            'baseCost' => $entry->baseCost,
            'unitOfMeasureCode' => $entry->unitOfMeasureCode,
            'priceListCode' => $entry->priceListCode,
            'priceSourceCode' => $entry->priceSourceCode,
            'effectiveFromUtc' => $entry->effectiveFromUtc,
            'effectiveToUtc' => $entry->effectiveToUtc,
            'status' => $entry->status,
            'isActive' => $entry->isActive,
            'rowVersion' => $entry->rowVersion,
        ];
    }

    /**
     * @param list<ProductBaseCostHistoryEntry> $items
     * @return list<array<string, mixed>>
     */
    public static function baseCostHistoryList(array $items): array
    {
        return array_map(self::baseCostHistoryEntry(...), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public static function legacyBaseCostComparison(LegacyBaseCostComparison $comparison): array
    {
        return [
            'variantPublicId' => $comparison->variantPublicId,
            'variantName' => $comparison->variantName,
            'legacyItemCode' => $comparison->legacyItemCode,
            'legacyPriceCode' => $comparison->legacyPriceCode,
            'newBaseCost' => $comparison->newBaseCost,
            'newCurrencyCode' => $comparison->newCurrencyCode,
            'newPriceSourceCode' => $comparison->newPriceSourceCode,
            'legacyBaseCost' => $comparison->legacyBaseCost,
            'legacyCurrencyCode' => $comparison->legacyCurrencyCode,
            'legacyDebtorCompanyCode' => $comparison->legacyDebtorCompanyCode,
            'legacyLastUpdatedUtc' => $comparison->legacyLastUpdatedUtc,
            'differenceAmount' => $comparison->differenceAmount,
            'legacyAvailable' => $comparison->legacyAvailable,
            'message' => $comparison->message,
            'resolutionStatus' => $comparison->resolutionStatus,
            'resolutionSource' => $comparison->resolutionSource,
            'priceDataStatus' => $comparison->priceDataStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function legacyPricingCodeInspection(\TriCab\Core\Product\Application\DTO\LegacyPricingCodeInspection $inspection): array
    {
        return [
            'exists' => $inspection->exists,
            'revisionCount' => $inspection->revisionCount,
            'latestDc' => $inspection->latestDc,
            'latestCost' => $inspection->latestCost,
            'zeroCost' => $inspection->zeroCost,
            'duplicateLatestDc' => $inspection->duplicateLatestDc,
            'priceDataStatus' => $inspection->priceDataStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function legacyVariantPricingIdentity(\TriCab\Core\Product\Application\DTO\LegacyVariantPricingIdentityResolution $resolution): array
    {
        return [
            'variantPublicId' => $resolution->variantPublicId,
            'variantCode' => $resolution->variantCode,
            'priceListPublicId' => $resolution->priceListPublicId,
            'legacyDbco' => $resolution->legacyDbco,
            'resolutionStatus' => $resolution->resolutionStatus,
            'legacyPriceCode' => $resolution->legacyPriceCode,
            'resolutionSource' => $resolution->resolutionSource,
            'explicitMappingCode' => $resolution->explicitMappingCode,
            'exactCandidateCode' => $resolution->exactCandidateCode,
            'maskCandidates' => $resolution->maskCandidates,
            'legacyMatch' => $resolution->legacyMatch !== null
                ? self::legacyPricingCodeInspection($resolution->legacyMatch)
                : null,
            'priceDataStatus' => $resolution->priceDataStatus,
            'warnings' => $resolution->warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function legacyVariantPricingIdentityAudit(\TriCab\Core\Product\Application\DTO\LegacyVariantPricingIdentityAuditSummary $summary): array
    {
        return [
            'variantsScanned' => $summary->variantsScanned,
            'explicitCount' => $summary->explicitCount,
            'exactCount' => $summary->exactCount,
            'maskCount' => $summary->maskCount,
            'unresolvedCount' => $summary->unresolvedCount,
            'ambiguousCount' => $summary->ambiguousCount,
            'invalidExplicitCount' => $summary->invalidExplicitCount,
            'zeroCostCount' => $summary->zeroCostCount,
            'priceRevisionAmbiguousCount' => $summary->priceRevisionAmbiguousCount,
            'systemDefaultUomCount' => $summary->systemDefaultUomCount,
            'noPriceListContextCount' => $summary->noPriceListContextCount,
            'byDbco' => $summary->byDbco,
            'legacyQueryCount' => $summary->legacyQueryCount,
            'executionSeconds' => $summary->executionSeconds,
            'warnings' => $summary->warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function variantLegacyPricingIdentityMapping(\TriCab\Core\Product\Application\DTO\VariantLegacyPricingIdentityMapping $mapping): array
    {
        return [
            'publicId' => $mapping->publicId,
            'variantPublicId' => $mapping->variantPublicId,
            'legacyPriceCode' => $mapping->legacyPriceCode,
            'isActive' => $mapping->isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mediaSummary(
        ProductMediaSummary $summary,
        bool $includeAdminFields = true,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $publicUrl = self::resolveAssetUrl(
            $devUrls,
            $summary->publicUrl,
            $summary->publicId,
            $summary->storageContainer,
            $summary->storagePath,
        );
        $storedThumb = $summary->thumbnailUrl;
        if ($storedThumb !== null && $storedThumb !== '' && $storedThumb !== $summary->publicUrl) {
            $thumbnailUrl = self::resolveAssetUrl(
                $devUrls,
                $storedThumb,
                $summary->publicId,
                $summary->storageContainer,
                $summary->storagePath,
            );
        } else {
            $thumbnailUrl = $publicUrl;
        }
        if (($thumbnailUrl === null || $thumbnailUrl === '') && $publicUrl !== null && $publicUrl !== '') {
            $thumbnailUrl = $publicUrl;
        }

        $payload = [
            'publicId' => $summary->publicId,
            'mediaCategoryCode' => $summary->mediaCategoryCode,
            'mediaTypeCode' => $summary->mediaTypeCode,
            'title' => $summary->title,
            'publicUrl' => $publicUrl,
            'thumbnailUrl' => $thumbnailUrl,
            'altText' => $summary->altText,
            'displayOrder' => $summary->displayOrder,
            'isPrimary' => $summary->isPrimary,
            'presentationRoles' => $summary->presentationRoles,
        ];

        if ($includeAdminFields) {
            $payload['uploadStatus'] = $summary->uploadStatusCode;
            $payload['scanStatus'] = $summary->scanStatusCode;
            $payload['uploadStatusCode'] = $summary->uploadStatusCode;
            $payload['scanStatusCode'] = $summary->scanStatusCode;
            $payload['byteSize'] = $summary->byteSize;
            $payload['contentType'] = $summary->contentType;
            $payload['originalFileName'] = $summary->originalFileName;
            $payload['widthPx'] = $summary->widthPx;
            $payload['heightPx'] = $summary->heightPx;
            $payload['storagePath'] = $summary->storagePath;
            $payload['storageContainer'] = $summary->storageContainer;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicMediaSummary(
        ProductMediaSummary $summary,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return self::mediaSummary($summary, false, $devUrls);
    }

    /**
     * @return array<string, mixed>
     */
    public static function mediaFileRecord(
        ProductMediaFileRecord $record,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return self::mediaSummary($record->toSummary(true), true, $devUrls);
    }

    /**
     * @param list<ProductMediaSummary> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function mediaList(
        array $items,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = self::mediaSummary($item, true, $devUrls);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function documentSummary(
        ProductDocumentSummary $summary,
        bool $includeAdminFields = true,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $payload = [
            'publicId' => $summary->publicId,
            'documentTypeCode' => $summary->documentTypeCode,
            'title' => $summary->title,
            'versionLabel' => $summary->versionLabel,
            'languageCode' => $summary->languageCode,
            'publicUrl' => self::resolveAssetUrl(
                $devUrls,
                $summary->publicUrl,
                $summary->publicId,
                $summary->storageContainer,
                $summary->storagePath,
            ),
            'accessCode' => $summary->accessCode,
            'displayOrder' => $summary->displayOrder,
        ];

        if ($includeAdminFields) {
            $payload['uploadStatus'] = $summary->uploadStatusCode;
            $payload['scanStatus'] = $summary->scanStatusCode;
            $payload['uploadStatusCode'] = $summary->uploadStatusCode;
            $payload['scanStatusCode'] = $summary->scanStatusCode;
            $payload['byteSize'] = $summary->byteSize;
            $payload['contentType'] = $summary->contentType;
            $payload['originalFileName'] = $summary->originalFileName;
            $payload['fileExtension'] = $summary->fileExtension;
            $payload['storagePath'] = $summary->storagePath;
            $payload['storageContainer'] = $summary->storageContainer;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicDocumentSummary(
        ProductDocumentSummary $summary,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return self::documentSummary($summary, false, $devUrls);
    }

    /**
     * @param list<ProductDocumentSummary> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function documentList(
        array $items,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = self::documentSummary($item, true, $devUrls);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function variantSummary(ProductVariantSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'variantName' => $summary->variantName,
            'sku' => $summary->sku,
            'variantCode' => $summary->variantCode,
            'statusCode' => $summary->statusCode,
            'displayOrder' => $summary->displayOrder,
            'isActive' => $summary->isActive,
        ];
    }

    /**
     * @param list<ProductVariantSummary> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function variantList(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = self::variantSummary($item);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function codePreview(ProductCodePreviewResult $result): array
    {
        return [
            'generatedCode' => $result->generatedCode,
            'explanation' => $result->explanation,
            'validationErrors' => $result->validationErrors,
            'warnings' => $result->warnings,
            'isUnique' => $result->isUnique,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function auditEntry(ProductAuditEntry $entry): array
    {
        return [
            'publicId' => $entry->publicId,
            'productPublicId' => $entry->productPublicId,
            'entityTypeCode' => $entry->entityTypeCode,
            'actionCode' => $entry->actionCode,
            'actorDisplayName' => $entry->actorDisplayName,
            'correlationId' => $entry->correlationId,
            'sourceCode' => $entry->sourceCode,
            'previousValuesJson' => $entry->previousValuesJson,
            'newValuesJson' => $entry->newValuesJson,
            'createdAtUtc' => $entry->createdAtUtc,
        ];
    }

    /**
     * @param list<ProductAuditEntry> $entries
     *
     * @return list<array<string, mixed>>
     */
    public static function auditList(array $entries): array
    {
        $mapped = [];
        foreach ($entries as $entry) {
            $mapped[] = self::auditEntry($entry);
        }

        return $mapped;
    }

    /**
     * @param array{
     *   statuses: list<ProductLookupItem>,
     *   lifecycleStatuses: list<ProductLookupItem>,
     *   families: list<ProductLookupItem>,
     *   categories: list<ProductLookupItem>,
     *   series: list<ProductLookupItem>,
     *   regions: list<ProductLookupItem>,
     *   markets: list<ProductLookupItem>,
     *   industries: list<ProductLookupItem>,
     *   applications: list<ProductLookupItem>,
     *   mediaTypes: list<ProductLookupItem>,
     *   documentTypes: list<ProductLookupItem>,
     *   currencies: list<ProductLookupItem>,
     *   specificationDefinitions: list<ProductLookupItem>,
     *   codeRuleSets: list<ProductLookupItem>
     * } $bundle
     *
     * @return array<string, mixed>
     */
    public static function lookupsBundle(array $bundle): array
    {
        return [
            'statuses' => self::lookupItems($bundle['statuses']),
            'lifecycleStatuses' => self::lookupItems($bundle['lifecycleStatuses']),
            'families' => self::lookupItems($bundle['families']),
            'categories' => self::lookupItems($bundle['categories']),
            'series' => self::lookupItems($bundle['series']),
            'regions' => self::lookupItems($bundle['regions']),
            'markets' => self::lookupItems($bundle['markets']),
            'industries' => self::lookupItems($bundle['industries']),
            'applications' => self::lookupItems($bundle['applications']),
            'mediaTypes' => self::lookupItems($bundle['mediaTypes']),
            'documentTypes' => self::lookupItems($bundle['documentTypes']),
            'currencies' => self::lookupItems($bundle['currencies']),
            'specificationDefinitions' => self::lookupItems($bundle['specificationDefinitions']),
            'codeRuleSets' => self::lookupItems($bundle['codeRuleSets']),
            'priceLists' => self::priceListLookupItems($bundle['priceLists'] ?? []),
            'pricingUnits' => self::pricingUnitLookupList($bundle['pricingUnits'] ?? []),
        ];
    }

    /**
     * @param list<PriceListLookupItem> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function priceListLookupItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = [
                'publicId' => $item->publicId,
                'code' => $item->code,
                'name' => $item->name,
                'currencyCode' => $item->currencyCode,
                'regionName' => $item->regionName,
                'legacySourceCode' => $item->legacySourceCode,
                'legacyContextCode' => $item->legacyContextCode,
                'isActive' => $item->isActive,
            ];
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function priceListRecord(PriceListRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'code' => $record->code,
            'name' => $record->name,
            'currencyCode' => $record->currencyCode,
            'currencyPublicId' => $record->currencyPublicId,
            'regionPublicId' => $record->regionPublicId,
            'regionCode' => $record->regionCode,
            'regionName' => $record->regionName,
            'marketPublicId' => $record->marketPublicId,
            'marketCode' => $record->marketCode,
            'marketName' => $record->marketName,
            'legacySourceCode' => $record->legacySourceCode,
            'legacyContextType' => $record->legacyContextType,
            'legacyContextCode' => $record->legacyContextCode,
            'effectiveFromUtc' => $record->effectiveFromUtc,
            'effectiveToUtc' => $record->effectiveToUtc,
            'isActive' => $record->isActive,
            'isDeleted' => $record->isDeleted,
            'rowVersion' => $record->rowVersion,
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function priceListUsage(PriceListUsage $usage): array
    {
        return [
            'productPriceCount' => $usage->productPriceCount,
            'variantPriceCount' => $usage->variantPriceCount,
            'customerPriceCount' => $usage->customerPriceCount,
            'priceListCustomerCount' => $usage->priceListCustomerCount,
            'summary' => $usage->summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function legacyPriceContextVerification(LegacyPriceContextVerification $result): array
    {
        return [
            'connectionOk' => $result->connectionOk,
            'legacySourceCode' => $result->legacySourceCode,
            'legacyContextType' => $result->legacyContextType,
            'legacyContextCode' => $result->legacyContextCode,
            'priceRowCount' => $result->priceRowCount,
            'distinctCodeCount' => $result->distinctCodeCount,
            'valid' => $result->valid,
            'message' => $result->message,
        ];
    }

    /**
     * @param list<ProductLookupItem> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function lookupItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = self::lookupItem($item);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function lookupItem(ProductLookupItem $item): array
    {
        return [
            'publicId' => $item->publicId,
            'code' => $item->code,
            'name' => $item->name,
            'parentPublicId' => $item->parentPublicId,
            'displayOrder' => $item->displayOrder,
            'isActive' => $item->isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pagination(Pagination $pagination): array
    {
        return [
            'page' => $pagination->page,
            'pageSize' => $pagination->pageSize,
            'totalItems' => $pagination->totalItems,
            'totalPages' => $pagination->totalPages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function acknowledged(): array
    {
        return ['acknowledged' => true];
    }

    /**
     * Editable product/variant specification value (admin GET/PUT shape).
     *
     * @param array{
     *   definitionPublicId: string,
     *   valueText: ?string,
     *   valueLongText: ?string,
     *   valueInteger: ?int,
     *   valueDecimal: ?string,
     *   valueBoolean: ?bool,
     *   valueDate: ?string,
     *   valueUrl: ?string,
     *   valueMinDecimal: ?string,
     *   valueMaxDecimal: ?string,
     *   optionPublicId: ?string,
     *   optionPublicIds: list<string>,
     *   optionStatuses?: array<string, string>
     * } $value
     *
     * @return array<string, mixed>
     */
    public static function specificationValue(array $value): array
    {
        return [
            'definitionPublicId' => $value['definitionPublicId'],
            'valueText' => $value['valueText'],
            'valueLongText' => $value['valueLongText'],
            'valueInteger' => $value['valueInteger'],
            'valueDecimal' => $value['valueDecimal'],
            'valueBoolean' => $value['valueBoolean'],
            'valueDate' => $value['valueDate'],
            'valueUrl' => $value['valueUrl'],
            'valueMinDecimal' => $value['valueMinDecimal'],
            'valueMaxDecimal' => $value['valueMaxDecimal'],
            'optionPublicId' => $value['optionPublicId'],
            'optionPublicIds' => array_values($value['optionPublicIds']),
            'optionStatuses' => $value['optionStatuses'] ?? [],
        ];
    }

    /**
     * @param array{
     *   pageTitle?: ?string,
     *   metaDescription?: ?string,
     *   canonicalUrl?: ?string,
     *   openGraphTitle?: ?string,
     *   openGraphDescription?: ?string,
     *   openGraphImageUrl?: ?string,
     *   searchKeywords?: ?string,
     *   regionPublicId?: ?string
     * }|null $seo
     *
     * @return array<string, mixed>|null
     */
    public static function seoPayload(?array $seo): ?array
    {
        if ($seo === null) {
            return null;
        }

        return [
            'regionPublicId' => $seo['regionPublicId'] ?? null,
            'pageTitle' => $seo['pageTitle'] ?? null,
            'metaDescription' => $seo['metaDescription'] ?? null,
            'canonicalUrl' => $seo['canonicalUrl'] ?? null,
            'openGraphTitle' => $seo['openGraphTitle'] ?? null,
            'openGraphDescription' => $seo['openGraphDescription'] ?? null,
            'openGraphImageUrl' => $seo['openGraphImageUrl'] ?? null,
            'searchKeywords' => $seo['searchKeywords'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readinessIssue(ProductPublishReadinessIssue $issue): array
    {
        return [
            'code' => $issue->code,
            'area' => $issue->area,
            'message' => $issue->message,
            'severity' => $issue->severity,
            'entityPublicId' => $issue->entityPublicId,
            'field' => $issue->field,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readinessCheck(ProductPublishReadinessCheck $check): array
    {
        return [
            'code' => $check->code,
            'passed' => $check->passed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function uploadSessionResult(ProductUploadSessionResult $result): array
    {
        return [
            'entityPublicId' => $result->entityPublicId,
            'uploadUrl' => $result->uploadUrl,
            'httpMethod' => $result->httpMethod,
            'requiredHeaders' => $result->requiredHeaders,
            'expiresAtUtc' => $result->expiresAtUtc,
            'storagePath' => $result->storagePath,
            'storageContainer' => $result->storageContainer,
            'fileKind' => $result->fileKind,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function documentDownloadResult(ProductDocumentDownloadResult $result): array
    {
        return [
            'documentPublicId' => $result->documentPublicId,
            'downloadUrl' => $result->downloadUrl,
            'httpMethod' => $result->httpMethod,
            'requiredHeaders' => $result->requiredHeaders,
            'expiresAtUtc' => $result->expiresAtUtc,
            'accessMode' => $result->accessMode,
            'contentType' => $result->contentType,
            'originalFileName' => $result->originalFileName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function public360SetSummary(
        Product360SetSummary $summary,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $summary->publicId,
            'title' => $summary->title,
            'expectedFrameCount' => $summary->expectedFrameCount,
            'uploadedFrameCount' => $summary->uploadedFrameCount,
            'isActive' => $summary->isActive,
            'frames' => array_map(
                static fn (Product360FrameSummary $frame): array => [
                    'publicId' => $frame->publicId,
                    'frameNumber' => $frame->frameNumber,
                    'publicUrl' => self::resolveAssetUrl(
                        $devUrls,
                        $frame->publicUrl,
                        $frame->publicId,
                        $frame->storageContainer,
                        $frame->storagePath,
                    ),
                ],
                $summary->frames,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function product360SetSummary(
        Product360SetSummary $summary,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $summary->publicId,
            'productPublicId' => $summary->productPublicId,
            'title' => $summary->title,
            'expectedFrameCount' => $summary->expectedFrameCount,
            'uploadedFrameCount' => $summary->uploadedFrameCount,
            'statusCode' => $summary->statusCode,
            'isActive' => $summary->isActive,
            'frames' => array_map(
                static fn (Product360FrameSummary $frame): array => self::product360FrameSummary($frame, $devUrls),
                $summary->frames,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function product360FrameSummary(
        Product360FrameSummary $summary,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $summary->publicId,
            'setPublicId' => $summary->setPublicId,
            'frameNumber' => $summary->frameNumber,
            'uploadStatus' => $summary->uploadStatusCode,
            'scanStatus' => $summary->scanStatusCode,
            'publicUrl' => self::resolveAssetUrl(
                $devUrls,
                $summary->publicUrl,
                $summary->publicId,
                $summary->storageContainer,
                $summary->storagePath,
            ),
            'contentType' => $summary->contentType,
            'byteSize' => $summary->byteSize,
            'originalFileName' => $summary->originalFileName,
            'storagePath' => $summary->storagePath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function productArModelSummary(
        ProductArModelSummary $summary,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $summary->publicId,
            'productPublicId' => $summary->productPublicId,
            'title' => $summary->title,
            'uploadStatus' => $summary->uploadStatusCode,
            'scanStatus' => $summary->scanStatusCode,
            'publicUrl' => self::resolveAssetUrl(
                $devUrls,
                $summary->publicUrl,
                $summary->publicId,
                $summary->storageContainer,
                $summary->storagePath,
            ),
            'contentType' => $summary->contentType,
            'fileExtension' => $summary->fileExtension,
            'byteSize' => $summary->byteSize,
            'originalFileName' => $summary->originalFileName,
            'isPrimary' => $summary->isPrimary,
            'storagePath' => $summary->storagePath,
        ];
    }

    /**
     * Admin 3D Studio model summary. May include storage paths for upload completion.
     *
     * @return array<string, mixed>
     */
    public static function product3DModelSummary(
        Product3DModelSummary $summary,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $summary->publicId,
            'productPublicId' => $summary->productPublicId,
            'productVariantPublicId' => $summary->productVariantPublicId,
            'name' => $summary->name,
            'modelTypeCode' => $summary->modelTypeCode,
            'sourceTypeCode' => $summary->sourceTypeCode,
            'statusCode' => $summary->statusCode,
            'uploadStatusCode' => $summary->uploadStatusCode,
            'scanStatusCode' => $summary->scanStatusCode,
            'publicUrl' => self::resolveAssetUrl(
                $devUrls,
                $summary->publicUrl,
                $summary->publicId,
                $summary->storageContainer,
                $summary->storagePath,
            ),
            'previewImagePublicUrl' => self::resolveAssetUrl(
                $devUrls,
                $summary->previewImagePublicUrl,
                $summary->publicId,
                $summary->storageContainer,
                $summary->previewImageStoragePath,
            ),
            'storagePath' => $summary->storagePath,
            'storageContainer' => $summary->storageContainer,
            'mimeType' => $summary->mimeType,
            'fileExtension' => $summary->fileExtension,
            'fileSizeBytes' => $summary->fileSizeBytes,
            'originalFileName' => $summary->originalFileName,
            'viewerConfiguration' => $summary->viewerConfiguration,
            'generationMetadata' => $summary->generationMetadata,
            'materialOverrides' => $summary->materialOverrides,
            'analysis' => $summary->analysis,
            'recommendation' => $summary->recommendation(),
            'optimisation' => $summary->optimisation,
            'activeAsset' => $summary->activeAsset === null
                ? null
                : self::product3DAssetSummary($summary->activeAsset, $devUrls),
            'assets' => $summary->assets === null
                ? null
                : array_map(
                    static fn ($asset) => self::product3DAssetSummary($asset, $devUrls),
                    $summary->assets,
                ),
            'isPrimary' => $summary->isPrimary,
            'isActive' => $summary->isActive,
            'displayOrder' => $summary->displayOrder,
            'publishedAtUtc' => $summary->publishedAtUtc,
            'rowVersion' => $summary->rowVersion,
            'createdAtUtc' => $summary->createdAtUtc,
            'updatedAtUtc' => $summary->updatedAtUtc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function product3DAssetSummary(
        \TriCab\Core\Product\Application\DTO\Product3DAssetSummary $asset,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $asset->publicId,
            'modelPublicId' => $asset->modelPublicId,
            'assetTypeCode' => $asset->assetTypeCode,
            'statusCode' => $asset->statusCode,
            'scanStatusCode' => $asset->scanStatusCode,
            'publicUrl' => self::resolveAssetUrl(
                $devUrls,
                $asset->publicUrl,
                $asset->publicId,
                $asset->storageContainer,
                $asset->storagePath,
            ),
            'storagePath' => $asset->storagePath,
            'storageContainer' => $asset->storageContainer,
            'mimeType' => $asset->mimeType,
            'fileExtension' => $asset->fileExtension,
            'fileSizeBytes' => $asset->fileSizeBytes,
            'originalFileName' => $asset->originalFileName,
            'sha256' => $asset->sha256,
            'optimisationProfile' => $asset->optimisationProfile,
            'analysis' => $asset->analysis,
            'optimisationMetadata' => $asset->optimisationMetadata,
            'rowVersion' => $asset->rowVersion,
            'createdAtUtc' => $asset->createdAtUtc,
            'updatedAtUtc' => $asset->updatedAtUtc,
        ];
    }

    /**
     * Customer-safe 3D payload — no storage paths, scan status, or generation secrets.
     *
     * @return array<string, mixed>
     */
    public static function publicThreeD(
        ProductPublic3DPayload $payload,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'available' => $payload->available,
            'primary' => $payload->primary === null ? null : self::publicThreeDModel($payload->primary, $devUrls),
            'models' => array_map(
                static fn (ProductPublic3DModel $model): array => self::publicThreeDModel($model, $devUrls),
                $payload->models,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicThreeDModel(
        ProductPublic3DModel $model,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        return [
            'publicId' => $model->publicId,
            'name' => $model->name,
            'modelType' => $model->modelType,
            'url' => self::resolveAssetUrl(
                $devUrls,
                $model->url,
                $model->publicId,
                $model->storageContainer,
                $model->storagePath,
            ) ?? $model->url,
            'previewImageUrl' => $model->previewImageUrl === null || $model->previewImageUrl === ''
                ? null
                : self::resolveAssetUrl(
                    $devUrls,
                    $model->previewImageUrl,
                    $model->publicId,
                    $model->storageContainer,
                    $model->previewImageStoragePath,
                ),
            'viewerConfiguration' => $model->viewerConfiguration,
            'isPrimary' => $model->isPrimary,
            'productVariantPublicId' => $model->productVariantPublicId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function classificationRecord(ProductClassificationRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'code' => $record->code,
            'name' => $record->name,
            'description' => $record->description,
            'parentPublicId' => $record->parentPublicId,
            'parentName' => $record->parentName,
            'displayOrder' => $record->displayOrder,
            'isActive' => $record->isActive,
            'isDeleted' => $record->isDeleted,
            'rowVersion' => $record->rowVersion,
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
            'productCount' => $record->productCount,
            'activeChildCount' => $record->activeChildCount,
            'inactiveChildCount' => $record->inactiveChildCount,
        ];
    }

    /**
     * @param list<ProductClassificationRecord> $items
     * @return list<array<string, mixed>>
     */
    public static function classificationList(array $items): array
    {
        return array_map(self::classificationRecord(...), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public static function classificationUsage(ProductClassificationUsage $usage): array
    {
        return [
            'publicId' => $usage->publicId,
            'level' => $usage->level,
            'productCount' => $usage->productCount,
            'activeCategoryCount' => $usage->activeCategoryCount,
            'inactiveCategoryCount' => $usage->inactiveCategoryCount,
            'activeSeriesCount' => $usage->activeSeriesCount,
            'inactiveSeriesCount' => $usage->inactiveSeriesCount,
            'deletedChildCount' => $usage->deletedChildCount,
            'summary' => $usage->summaryMessage(),
        ];
    }


    public static function specificationGroup(SpecificationGroupRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'code' => $record->code,
            'name' => $record->name,
            'description' => $record->description,
            'displayOrder' => $record->displayOrder,
            'isActive' => $record->isActive,
            'isDeleted' => $record->isDeleted,
            'rowVersion' => $record->rowVersion,
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
            'definitionCount' => $record->definitionCount,
            'activeDefinitionCount' => $record->activeDefinitionCount,
        ];
    }

    /** @param list<SpecificationGroupRecord> $items @return list<array<string, mixed>> */
    public static function specificationGroupList(array $items): array
    {
        return array_map(self::specificationGroup(...), $items);
    }

    public static function specificationUnit(SpecificationUnitRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'code' => $record->code,
            'name' => $record->name,
            'symbol' => $record->symbol,
            'description' => $record->description,
            'displayOrder' => $record->displayOrder,
            'isActive' => $record->isActive,
            'isDeleted' => $record->isDeleted,
            'rowVersion' => $record->rowVersion,
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
            'definitionCount' => $record->definitionCount,
        ];
    }

    /** @param list<SpecificationUnitRecord> $items @return list<array<string, mixed>> */
    public static function specificationUnitList(array $items): array
    {
        return array_map(self::specificationUnit(...), $items);
    }

    public static function specificationOption(SpecificationOptionRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'definitionPublicId' => $record->definitionPublicId,
            'code' => $record->code,
            'name' => $record->name,
            'value' => $record->value,
            'displayOrder' => $record->displayOrder,
            'isActive' => $record->isActive,
            'isDeleted' => $record->isDeleted,
            'rowVersion' => $record->rowVersion,
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
            'valueUsageCount' => $record->valueUsageCount,
            'imageUrl' => $record->imageUrl,
        ];
    }

    /** @param list<SpecificationOptionRecord> $items @return list<array<string, mixed>> */
    public static function specificationOptionList(array $items): array
    {
        return array_map(self::specificationOption(...), $items);
    }

    public static function specificationDefinition(SpecificationDefinitionRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'code' => $record->code,
            'name' => $record->name,
            'description' => $record->description,
            'groupPublicId' => $record->groupPublicId,
            'groupCode' => $record->groupCode,
            'groupName' => $record->groupName,
            'dataTypeCode' => $record->dataTypeCode,
            'scopeCode' => $record->scopeCode,
            'unit' => $record->unit,
            'unitPublicId' => $record->unitPublicId,
            'displayOrder' => $record->displayOrder,
            'isRequired' => $record->isRequired,
            'isFilterable' => $record->isFilterable,
            'isSearchable' => $record->isSearchable,
            'isComparable' => $record->isComparable,
            'minimumValue' => $record->minimumValue,
            'maximumValue' => $record->maximumValue,
            'decimalPrecision' => $record->decimalPrecision,
            'validationRules' => $record->validationRules->toArray(),
            'isActive' => $record->isActive,
            'isDeleted' => $record->isDeleted,
            'rowVersion' => $record->rowVersion,
            'options' => self::specificationOptionList($record->options),
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
            'productValueCount' => $record->productValueCount,
            'variantValueCount' => $record->variantValueCount,
        ];
    }

    /** @param list<SpecificationDefinitionRecord> $items @return list<array<string, mixed>> */
    public static function specificationDefinitionList(array $items): array
    {
        return array_map(self::specificationDefinition(...), $items);
    }

    public static function specificationUsage(SpecificationUsage $usage): array
    {
        return [
            'publicId' => $usage->publicId,
            'level' => $usage->level,
            'productValueCount' => $usage->productValueCount,
            'variantValueCount' => $usage->variantValueCount,
            'definitionCount' => $usage->definitionCount,
            'activeDefinitionCount' => $usage->activeDefinitionCount,
            'optionCount' => $usage->optionCount,
            'templateCount' => $usage->templateCount,
            'summary' => $usage->summaryMessage(),
        ];
    }

    /**
     * Response-time browser URL. SAS is never persisted.
     * Website /assets and /certificates paths rewrite to /media when a copy exists.
     */
    private static function resolvePublicMediaUrl(
        ?ProductDevMediaUrlResolver $devUrls,
        ?string $storedUrl,
        string $filePublicId = '',
        ?string $storageContainer = null,
        ?string $storagePath = null,
    ): ?string {
        return self::resolveAssetUrl(
            $devUrls,
            CatalogueStaticMediaResolver::instance()->toPublicUrl($storedUrl),
            $filePublicId,
            $storageContainer,
            $storagePath,
        );
    }

    /**
     * Response-time browser URL. SAS is never persisted.
     */
    private static function resolveAssetUrl(
        ?ProductDevMediaUrlResolver $devUrls,
        ?string $storedUrl,
        string $filePublicId = '',
        ?string $storageContainer = null,
        ?string $storagePath = null,
    ): ?string {
        if ($devUrls === null) {
            return $storedUrl;
        }

        return $devUrls->resolvePublicUrl($storedUrl, $filePublicId, $storageContainer, $storagePath);
    }

    /**
     * @param list<ProductMediaSummary> $media
     * @param list<array<string, mixed>> $serializedMedia
     */
    private static function resolveDetailThumbnail(
        ?string $storedThumbnailUrl,
        array $media,
        array $serializedMedia,
        ?ProductDevMediaUrlResolver $devUrls,
    ): ?string {
        if (($storedThumbnailUrl === null || $storedThumbnailUrl === '') && $serializedMedia !== []) {
            foreach ($serializedMedia as $item) {
                $candidate = $item['publicUrl'] ?? $item['thumbnailUrl'] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }

            return $storedThumbnailUrl;
        }

        if ($storedThumbnailUrl !== null && $storedThumbnailUrl !== '' && $devUrls !== null && $media !== []) {
            foreach ($media as $item) {
                if ($item->isPrimary || ($item->publicUrl !== null && $item->publicUrl !== '')) {
                    $resolved = $devUrls->resolvePublicUrl(
                        $storedThumbnailUrl,
                        $item->publicId,
                        $item->storageContainer,
                        $item->storagePath,
                    );
                    if ($resolved !== null && $resolved !== '') {
                        return $resolved;
                    }
                }
            }
        }

        return self::resolveAssetUrl($devUrls, $storedThumbnailUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public static function styleRecord(ProductStyleRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'styleCode' => $record->styleCode,
            'displayName' => $record->displayName,
            'parentPublicId' => $record->parentPublicId,
            'parentStyleCode' => $record->parentStyleCode,
            'parentDisplayName' => $record->parentDisplayName,
            'sortOrder' => $record->sortOrder,
            'isActive' => $record->isActive,
            'isDeleted' => $record->isDeleted,
            'rowVersion' => $record->rowVersion,
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
            'productCount' => $record->productCount,
            'childCount' => $record->childCount,
            'activeChildCount' => $record->activeChildCount,
            'inheritance' => $record->inheritance->toMap(),
            'ancestors' => $record->ancestors,
            'children' => $record->children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function styleTreeNode(ProductStyleTreeNode $node): array
    {
        return [
            'publicId' => $node->publicId,
            'styleCode' => $node->styleCode,
            'displayName' => $node->displayName,
            'parentPublicId' => $node->parentPublicId,
            'sortOrder' => $node->sortOrder,
            'isActive' => $node->isActive,
            'childCount' => $node->childCount,
            'productCount' => $node->productCount,
            'children' => array_map(self::styleTreeNode(...), $node->children),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function styleUsage(ProductStyleUsage $usage): array
    {
        return [
            'publicId' => $usage->publicId,
            'productCount' => $usage->productCount,
            'childCount' => $usage->childCount,
            'activeChildCount' => $usage->activeChildCount,
            'deletedChildCount' => $usage->deletedChildCount,
            'summary' => $usage->summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function styleAssignment(ProductStyleAssignment $assignment): array
    {
        return [
            'productPublicId' => $assignment->productPublicId,
            'stylePublicId' => $assignment->stylePublicId,
            'styleCode' => $assignment->styleCode,
            'displayName' => $assignment->displayName,
            'parentPublicId' => $assignment->parentPublicId,
            'parentStyleCode' => $assignment->parentStyleCode,
            'ancestors' => $assignment->ancestors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function effectiveSpecification(EffectiveSpecificationValue $item): array
    {
        return [
            'definitionPublicId' => $item->definitionPublicId,
            'definitionCode' => $item->definitionCode,
            'definitionName' => $item->definitionName,
            'displayValue' => $item->displayValue,
            'value' => $item->value,
            'inherited' => $item->provenance->inherited,
            'sourceType' => $item->provenance->sourceType,
            'sourceStylePublicId' => $item->provenance->sourceStylePublicId,
            'sourceStyleCode' => $item->provenance->sourceStyleCode,
            'sourceLevel' => $item->provenance->sourceLevel,
            'label' => $item->provenance->label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function effectiveAsset(
        EffectiveStyleAsset $item,
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $filePublicId = is_string($item->asset['publicId'] ?? null) ? $item->asset['publicId'] : '';

        return [
            'publicId' => $filePublicId !== '' ? $filePublicId : null,
            'resourceType' => $item->resourceType,
            'title' => $item->title,
            'roleCode' => $item->roleCode,
            'storagePath' => $item->storagePath,
            'storageContainer' => $item->storageContainer,
            'publicUrl' => self::resolveAssetUrl(
                $devUrls,
                $item->publicUrl,
                $filePublicId,
                $item->storageContainer,
                $item->storagePath,
            ),
            'displayOrder' => $item->displayOrder,
            'isPrimary' => $item->isPrimary,
            'asset' => $item->asset,
            'inherited' => $item->provenance->inherited,
            'sourceType' => $item->provenance->sourceType,
            'sourceStylePublicId' => $item->provenance->sourceStylePublicId,
            'sourceStyleCode' => $item->provenance->sourceStyleCode,
            'sourceLevel' => $item->provenance->sourceLevel,
            'label' => $item->provenance->label,
        ];
    }

    /**
     * @param list<EffectiveSpecificationValue> $specifications
     * @param list<EffectiveStyleAsset> $images
     * @param list<EffectiveStyleAsset> $documents
     * @param list<EffectiveStyleAsset> $spin360
     * @param list<EffectiveStyleAsset> $models3d
     * @param list<EffectiveStyleAsset> $arAssets
     * @param array<string, int> $specSummary
     * @return array<string, mixed>
     */
    public static function productStyleInheritance(
        ?ProductStyleAssignment $assignment,
        array $specifications,
        array $images,
        array $specSummary,
        array $documents = [],
        array $spin360 = [],
        array $models3d = [],
        array $arAssets = [],
        ?ProductDevMediaUrlResolver $devUrls = null,
    ): array {
        $map = static fn (EffectiveStyleAsset $item): array => self::effectiveAsset($item, $devUrls);

        return [
            'assignment' => $assignment === null ? null : self::styleAssignment($assignment),
            'specifications' => array_map(self::effectiveSpecification(...), $specifications),
            'specificationSummary' => $specSummary,
            'images' => array_map($map, $images),
            'documents' => array_map($map, $documents),
            'spin360' => array_map($map, $spin360),
            'models3d' => array_map($map, $models3d),
            'arAssets' => array_map($map, $arAssets),
        ];
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    public static function styleDiscoveryPreview(array $preview): array
    {
        return [
            'writeGuard' => $preview['writeGuard'],
            'styleCodes' => $preview['styleCodes'] ?? [],
            'proposals' => array_map(static fn (StyleDiscoveryProposal $p): array => [
                'parentStyleCode' => $p->parentStyleCode,
                'childStyleCode' => $p->childStyleCode,
                'reason' => $p->reason,
                'confidence' => $p->confidence,
                'productCount' => $p->productCount,
                'sharedSpecificationEvidence' => $p->sharedSpecificationEvidence,
                'sharedMediaEvidence' => $p->sharedMediaEvidence,
                'candidateSource' => $p->candidateSource,
                'warnings' => $p->warnings,
                'familyEvidence' => $p->familyEvidence,
                'sourceEvidence' => $p->sourceEvidence,
                'applicationState' => $p->applicationState,
                'ambiguousParent' => $p->ambiguousParent,
                'cycleConflict' => $p->cycleConflict,
                'persistedParentStyleCode' => $p->persistedParentStyleCode,
                'prefixCandidates' => $p->prefixCandidates,
            ], $preview['proposals'] ?? []),
            'comparisons' => array_map(static fn (StyleSharedDataComparison $c): array => [
                'proposedParentStyleCode' => $c->proposedParentStyleCode,
                'childStyleCodes' => $c->childStyleCodes,
                'identicalSpecificationCount' => $c->identicalSpecificationCount,
                'differingSpecificationCount' => $c->differingSpecificationCount,
                'missingSpecificationCount' => $c->missingSpecificationCount,
                'identicalSpecificationCodes' => $c->identicalSpecificationCodes,
                'differingSpecificationCodes' => $c->differingSpecificationCodes,
                'missingSpecificationCodes' => $c->missingSpecificationCodes,
                'commonMediaCount' => $c->commonMediaCount,
                'commonMediaKeys' => $c->commonMediaKeys,
                'childSpecificMediaKeys' => $c->childSpecificMediaKeys,
            ], $preview['comparisons'] ?? []),
            'proposedTrees' => $preview['proposedTrees'] ?? [],
            'standaloneStyleCodes' => $preview['standaloneStyleCodes'] ?? [],
            'targetOnlyStyleCodes' => $preview['targetOnlyStyleCodes'] ?? [],
            'inventory' => $preview['inventory'] ?? null,
            'counters' => $preview['counters'] ?? [],
            'notes' => $preview['notes'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    public static function styleHierarchyEvidenceReview(array $review): array
    {
        $rows = [];
        foreach ($review['rows'] ?? [] as $row) {
            $rows[] = $row instanceof StyleHierarchyEvidenceRow ? $row->toArray() : $row;
        }
        $selected = [];
        foreach ($review['selected'] ?? [] as $row) {
            $selected[] = $row instanceof StyleHierarchyEvidenceRow ? $row->toArray() : $row;
        }
        $membership = [];
        foreach ($review['membership'] ?? [] as $code => $item) {
            $membership[$code] = $item instanceof StyleMembershipEvidence ? $item->toArray() : $item;
        }

        return [
            'writeGuard' => $review['writeGuard'] ?? [],
            'sourceReads' => $review['sourceReads'] ?? [],
            'originalCounters' => $review['originalCounters'] ?? [],
            'counters' => $review['counters'] ?? [],
            'rows' => $rows,
            'selected' => $selected,
            'groups' => $review['groups'] ?? [],
            'membership' => $membership,
            'preview' => isset($review['preview']) && is_array($review['preview'])
                ? self::styleDiscoveryPreview($review['preview'])
                : null,
            'relationshipsApplied' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    public static function productStyleMappingDryRun(array $mapping): array
    {
        $rows = [];
        foreach ($mapping['rows'] ?? [] as $row) {
            $rows[] = $row instanceof ProductStyleMappingRow ? $row->toArray() : $row;
        }
        $safe = [];
        foreach ($mapping['safeAssignmentCandidates'] ?? [] as $row) {
            $safe[] = $row instanceof ProductStyleMappingRow ? $row->toArray() : $row;
        }
        $unresolved = [];
        foreach ($mapping['unresolvedLegacyProducts'] ?? [] as $row) {
            $unresolved[] = $row instanceof UnresolvedLegacyStyleProduct ? $row->toArray() : $row;
        }
        $cable = [];
        foreach ($mapping['cableStyleCases'] ?? [] as $row) {
            $cable[] = $row instanceof ProductStyleMappingRow ? $row->toArray() : $row;
        }
        $existing = [];
        foreach ($mapping['existingAssignments'] ?? [] as $row) {
            $existing[] = $row instanceof ProductStyleMappingRow ? $row->toArray() : $row;
        }

        return [
            'writeGuard' => $mapping['writeGuard'] ?? [],
            'sourceReads' => $mapping['sourceReads'] ?? [],
            'hierarchy' => $mapping['hierarchy'] ?? ['selectedRelationships' => [], 'newParentRelationshipsApplied' => 0],
            'identity' => $mapping['identity'] ?? [],
            'counters' => $mapping['counters'] ?? [],
            'notes' => $mapping['notes'] ?? [],
            'styleCoverage' => $mapping['styleCoverage'] ?? [],
            'productsPerStyle' => $mapping['productsPerStyle'] ?? [],
            'rows' => $rows,
            'safeAssignmentCandidates' => $safe,
            'unresolvedLegacyProducts' => $unresolved,
            'cableStyleCases' => $cable,
            'existingAssignments' => $existing,
            'assignmentsApplied' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    public static function productCodeStyleMappingDryRun(array $mapping): array
    {
        $toRows = static function (array $items): array {
            $out = [];
            foreach ($items as $row) {
                $out[] = $row instanceof ProductCodeStyleMappingRow ? $row->toArray() : $row;
            }

            return $out;
        };

        return [
            'writeGuard' => $mapping['writeGuard'] ?? [],
            'sourceReads' => $mapping['sourceReads'] ?? [],
            'businessRule' => $mapping['businessRule'] ?? [],
            'hierarchy' => $mapping['hierarchy'] ?? ['selectedRelationships' => [], 'newParentRelationshipsApplied' => 0],
            'notes' => $mapping['notes'] ?? [],
            'counters' => $mapping['counters'] ?? [],
            'styleCoverage' => $mapping['styleCoverage'] ?? [],
            'duplicateProductCodes' => $mapping['duplicateProductCodes'] ?? [],
            'rows' => $toRows($mapping['rows'] ?? []),
            'existingStyleAssignmentCandidates' => $toRows($mapping['existingStyleAssignmentCandidates'] ?? []),
            'missingStyleCreationCandidates' => $toRows($mapping['missingStyleCreationCandidates'] ?? []),
            'assignmentAfterCreateCandidates' => $toRows($mapping['assignmentAfterCreateCandidates'] ?? []),
            'assignmentConflicts' => $toRows($mapping['assignmentConflicts'] ?? []),
            'existingAssignments' => $toRows($mapping['existingAssignments'] ?? []),
            'stylesCreated' => 0,
            'assignmentsApplied' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public static function styleFamilyTreeDryRun(array $tree): array
    {
        return [
            'writeGuard' => $tree['writeGuard'] ?? [],
            'sourceReads' => $tree['sourceReads'] ?? [],
            'businessRule' => $tree['businessRule'] ?? [],
            'notes' => $tree['notes'] ?? [],
            'cycleValidation' => $tree['cycleValidation'] ?? [],
            'counters' => $tree['counters'] ?? [],
            'families' => $tree['families'] ?? [],
            'proposedTree' => $tree['proposedTree'] ?? [],
            'operatorConfirmedRelationships' => $tree['operatorConfirmedRelationships'] ?? [],
            'proposedAdditionalRelationships' => $tree['proposedAdditionalRelationships'] ?? [],
            'reviewRelationships' => $tree['reviewRelationships'] ?? [],
            'rejectedRelationships' => $tree['rejectedRelationships'] ?? [],
            'standaloneStyles' => $tree['standaloneStyles'] ?? [],
            'missingParentStyleCandidates' => $tree['missingParentStyleCandidates'] ?? [],
            'missingChildStyleCandidates' => $tree['missingChildStyleCandidates'] ?? [],
            'productMappings' => $tree['productMappings'] ?? [],
            'styleRoles' => $tree['styleRoles'] ?? [],
            'smokeRelationships' => $tree['smokeRelationships'] ?? [],
            'inheritanceCandidates' => $tree['inheritanceCandidates'] ?? [],
            'relationshipsApplied' => 0,
        ];
    }

}
