<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\DTO\ProductAdminListItem;
use TriCab\Core\Product\Application\DTO\ProductAuditEntry;
use TriCab\Core\Product\Application\DTO\ProductCodePreviewResult;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Application\DTO\ProductListResult;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\DTO\ProductMutationResult;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessCheck;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessIssue;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessReport;
use TriCab\Core\Product\Application\DTO\ProductSearchListResult;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Application\DTO\Pagination;

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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminListItem(ProductAdminListItem $item): array
    {
        return [
            'publicId' => $item->publicId,
            'productName' => $item->productName,
            'internalCode' => $item->internalCode,
            'primaryProductCode' => $item->primaryProductCode,
            'thumbnailUrl' => $item->thumbnailUrl,
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
    public static function listResult(ProductListResult $result): array
    {
        return [
            'items' => array_map(self::adminListItem(...), $result->items),
            'pagination' => self::pagination($result->pagination),
        ];
    }

    /**
     * Customer-safe product detail — excludes rowVersion, pricing, ERP, legacy, deletedAt, internal ids.
     *
     * @return array<string, mixed>
     */
    public static function publicDetail(ProductPublicDetail $detail): array
    {
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
            'thumbnailUrl' => $detail->thumbnailUrl,
            'variants' => array_map(self::variantSummary(...), $detail->variants),
            'media' => array_map(self::mediaSummary(...), $detail->media),
            'documents' => array_map(self::documentSummary(...), $detail->documents),
            'applicationNames' => $detail->applicationNames,
            'industryNames' => $detail->industryNames,
            'relationships' => array_map(self::publicRelationship(...), $detail->relationships),
            'specifications' => array_map(self::publicSpecification(...), $detail->specifications),
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
    public static function searchListResult(ProductSearchListResult $result): array
    {
        return [
            'items' => array_map(self::searchResult(...), $result->items),
            'pagination' => self::pagination($result->pagination),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function searchResult(ProductSearchResult $result): array
    {
        return [
            'publicId' => $result->publicId,
            'productName' => $result->productName,
            'productCode' => $result->productCode,
            'slug' => $result->slug,
            'categoryName' => $result->categoryName,
            'seriesName' => $result->seriesName,
            'statusCode' => $result->statusCode,
            'thumbnailUrl' => $result->thumbnailUrl,
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
            'currencyCode' => $summary->currencyCode,
            'unitPrice' => $summary->unitPrice,
            'minimumQuantity' => $summary->minimumQuantity,
            'priceListCode' => $summary->priceListCode,
            'effectiveFromUtc' => $summary->effectiveFromUtc,
            'effectiveToUtc' => $summary->effectiveToUtc,
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
    public static function mediaSummary(ProductMediaSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'mediaCategoryCode' => $summary->mediaCategoryCode,
            'mediaTypeCode' => $summary->mediaTypeCode,
            'title' => $summary->title,
            'publicUrl' => $summary->publicUrl,
            'altText' => $summary->altText,
            'displayOrder' => $summary->displayOrder,
            'isPrimary' => $summary->isPrimary,
        ];
    }

    /**
     * @param list<ProductMediaSummary> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function mediaList(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = self::mediaSummary($item);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function documentSummary(ProductDocumentSummary $summary): array
    {
        return [
            'publicId' => $summary->publicId,
            'documentTypeCode' => $summary->documentTypeCode,
            'title' => $summary->title,
            'versionLabel' => $summary->versionLabel,
            'languageCode' => $summary->languageCode,
            'publicUrl' => $summary->publicUrl,
            'accessCode' => $summary->accessCode,
            'displayOrder' => $summary->displayOrder,
        ];
    }

    /**
     * @param list<ProductDocumentSummary> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function documentList(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = self::documentSummary($item);
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
}
