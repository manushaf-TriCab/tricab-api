<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use DateTimeImmutable;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Application\DTO\ProductPublicRelationshipItem;
use TriCab\Core\Product\Application\DTO\ProductPublicSpecificationItem;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Contract\ProductPublicQueryRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;

final class InMemoryProductPublicQueryRepository implements ProductPublicQueryRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findBySlug(ProductSlug $slug, ?string $regionCode = null): ?ProductPublicDetail
    {
        foreach ($this->store->slugs() as $row) {
            if (($row['slug'] ?? '') !== $slug->toString() || ($row['deleted'] ?? false) === true) {
                continue;
            }
            if (($row['isActive'] ?? true) === false) {
                continue;
            }

            $productPublicId = $row['productPublicId'] ?? null;
            if (!is_string($productPublicId)) {
                continue;
            }

            $record = $this->store->productRecordByPublicId(
                ProductPublicId::fromString($productPublicId),
            );
            if ($record === null || !$this->isPubliclyVisible($record['product'], $regionCode)) {
                continue;
            }

            if ($regionCode !== null && trim($regionCode) !== '') {
                $regionPublicId = $row['regionPublicId'] ?? null;
                if (is_string($regionPublicId)) {
                    $slugRegionCode = $this->store->regionCodeForPublicId($regionPublicId);
                    if ($slugRegionCode !== null && strcasecmp($slugRegionCode, trim($regionCode)) !== 0) {
                        continue;
                    }
                }
            }

            $slugValue = $row['slug'] ?? null;
            if (!is_string($slugValue)) {
                continue;
            }

            return $this->buildDetail($record['product'], $slugValue, $regionCode);
        }

        return null;
    }

    public function findByPublicId(ProductPublicId $publicId, ?string $regionCode = null): ?ProductPublicDetail
    {
        $record = $this->store->productRecordByPublicId($publicId);
        if ($record === null || !$this->isPubliclyVisible($record['product'], $regionCode)) {
            return null;
        }

        $slug = $this->primarySlug($publicId->toString());
        if ($slug === null) {
            return null;
        }

        return $this->buildDetail($record['product'], $slug, $regionCode);
    }

    private function isPubliclyVisible(Product $product, ?string $regionCode): bool
    {
        if ($product->isSoftDeleted()) {
            return false;
        }

        if (!$product->isActive()) {
            return false;
        }

        if (!$product->status()->isPublished()) {
            return false;
        }

        $now = $this->store->clock()->now();
        if ($product->publishAtUtc() !== null && $product->publishAtUtc() > $now) {
            return false;
        }
        if ($product->unpublishAtUtc() !== null && $product->unpublishAtUtc() <= $now) {
            return false;
        }

        if ($regionCode === null || trim($regionCode) === '') {
            return true;
        }

        $normalizedRegion = trim($regionCode);
        foreach ($this->store->productRegions($product->publicId()->toString()) as $regionPublicId) {
            $code = $this->store->regionCodeForPublicId($regionPublicId);
            if ($code !== null && strcasecmp($code, $normalizedRegion) === 0) {
                return true;
            }
        }

        return false;
    }

    private function buildDetail(Product $product, string $slug, ?string $regionCode): ProductPublicDetail
    {
        $publicId = $product->publicId()->toString();
        $media = $this->listPublicMedia($publicId);
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
            productName: $product->productName(),
            productCode: $this->primaryProductCode($publicId) ?? $product->internalCode()?->toString(),
            shortDescription: $product->shortDescription(),
            fullDescription: $product->fullDescription(),
            familyName: $this->store->familyNameForId($product->familyId()),
            categoryName: $this->store->categoryNameForId($product->categoryId()),
            seriesName: $this->store->seriesNameForId($product->seriesId()),
            slug: $slug,
            thumbnailUrl: $thumbnailUrl,
            variants: $this->listPublicVariants($product),
            media: $media,
            documents: $this->listPublicDocuments($publicId, $regionCode),
            applicationNames: $this->listNames($publicId, 'applications'),
            industryNames: $this->listNames($publicId, 'industries'),
            relationships: $this->listPublicRelationships($publicId),
            specifications: $this->listPublicSpecifications($publicId),
        );
    }

    private function primaryProductCode(string $productPublicId): ?string
    {
        foreach ($this->store->productCodes() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId
                && ($row['isPrimary'] ?? false) === true
                && ($row['isActive'] ?? true) === true
            ) {
                $code = $row['codeValue'] ?? $row['code'] ?? $row['productCode'] ?? null;

                return is_string($code) ? $code : null;
            }
        }

        return null;
    }

    /**
     * @return list<ProductPublicRelationshipItem>
     */
    private function listPublicRelationships(string $productPublicId): array
    {
        $types = [
            ProductRelationshipType::ACCESSORY,
            ProductRelationshipType::RELATED,
            ProductRelationshipType::REPLACEMENT,
            ProductRelationshipType::CROSS_SELL,
            ProductRelationshipType::UP_SELL,
        ];
        $items = [];
        foreach ($types as $type) {
            foreach ($this->store->relationships($productPublicId, $type) as $row) {
                $relatedPublicId = $row['relatedProductPublicId'] ?? null;
                if (!is_string($relatedPublicId) || $relatedPublicId === '') {
                    continue;
                }
                $related = $this->store->productRecordByPublicId(ProductPublicId::fromString($relatedPublicId));
                if ($related === null || !$this->isPubliclyVisible($related['product'], null)) {
                    continue;
                }
                $items[] = new ProductPublicRelationshipItem(
                    relatedProductPublicId: $relatedPublicId,
                    relatedProductName: $related['product']->productName(),
                    relationshipTypeCode: $type,
                    relatedSlug: $this->primarySlug($relatedPublicId),
                    displayOrder: is_int($row['displayOrder'] ?? null) ? $row['displayOrder'] : 0,
                );
            }
        }

        usort(
            $items,
            static fn (ProductPublicRelationshipItem $a, ProductPublicRelationshipItem $b): int =>
                strcmp($a->relationshipTypeCode, $b->relationshipTypeCode)
                ?: ($a->displayOrder <=> $b->displayOrder)
                ?: strcmp($a->relatedProductPublicId, $b->relatedProductPublicId),
        );

        return $items;
    }

    /**
     * @return list<ProductPublicSpecificationItem>
     */
    private function listPublicSpecifications(string $productPublicId): array
    {
        $definitionsById = [];
        foreach ($this->store->specDefinitions() as $definition) {
            $publicId = $definition['publicId'] ?? null;
            if (is_string($publicId)) {
                $definitionsById[$publicId] = $definition;
            }
        }

        $items = [];
        foreach ($this->store->productSpecValues() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId) {
                continue;
            }
            $definitionPublicId = $row['definitionPublicId'] ?? null;
            if (!is_string($definitionPublicId)) {
                continue;
            }
            $definition = $definitionsById[$definitionPublicId] ?? null;
            if ($definition === null || ($definition['isActive'] ?? true) === false) {
                continue;
            }

            $displayValue = $this->specDisplayValue($row);
            if ($displayValue === null || $displayValue === '') {
                continue;
            }

            $items[] = new ProductPublicSpecificationItem(
                code: is_string($definition['code'] ?? null) ? $definition['code'] : $definitionPublicId,
                name: is_string($definition['name'] ?? null) ? $definition['name'] : 'Specification',
                groupName: is_string($definition['groupName'] ?? null) ? $definition['groupName'] : null,
                displayValue: $displayValue,
                unit: is_string($definition['unit'] ?? null) ? $definition['unit'] : null,
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function specDisplayValue(array $row): ?string
    {
        if (isset($row['value']) && (is_string($row['value']) || is_numeric($row['value']))) {
            return (string) $row['value'];
        }
        if (isset($row['textValue']) && is_string($row['textValue'])) {
            return $row['textValue'];
        }
        if (isset($row['booleanValue']) && is_bool($row['booleanValue'])) {
            return $row['booleanValue'] ? 'Yes' : 'No';
        }
        if (isset($row['minimum']) || isset($row['maximum'])) {
            $min = isset($row['minimum']) && (is_string($row['minimum']) || is_numeric($row['minimum']))
                ? (string) $row['minimum']
                : '';
            $max = isset($row['maximum']) && (is_string($row['maximum']) || is_numeric($row['maximum']))
                ? (string) $row['maximum']
                : '';

            return trim($min . ' – ' . $max, ' –');
        }

        return null;
    }

    /**
     * @return list<ProductVariantSummary>
     */
    private function listPublicVariants(Product $product): array
    {
        $productId = $product->id();
        if ($productId === null) {
            return [];
        }

        $items = [];
        foreach ($this->store->variantRecords() as $record) {
            $variant = $record['variant'];
            if (!$variant->productId()->equals($productId)) {
                continue;
            }
            if ($variant->isSoftDeleted() || !$variant->isActive() || !$variant->status()->isPublished()) {
                continue;
            }
            $items[] = new ProductVariantSummary(
                publicId: $variant->publicId()->toString(),
                variantName: $variant->variantName(),
                sku: $variant->sku()?->toString(),
                variantCode: $variant->variantCode()?->toString(),
                statusCode: $variant->status()->toString(),
                displayOrder: $variant->displayOrder()->toInt(),
                isActive: $variant->isActive(),
            );
        }

        usort(
            $items,
            static fn (ProductVariantSummary $a, ProductVariantSummary $b): int => $a->displayOrder <=> $b->displayOrder
                ?: strcmp($a->publicId, $b->publicId),
        );

        return $items;
    }

    /**
     * @return list<ProductMediaSummary>
     */

    /**
     * @param array<string, mixed> $row
     */
    private function isPubliclyVisibleFile(array $row): bool
    {
        if (($row['isActive'] ?? true) === false) {
            return false;
        }

        $upload = strtolower((string) ($row['uploadStatusCode'] ?? 'active'));
        if ($upload !== '' && $upload !== 'active') {
            return false;
        }

        $scan = strtolower((string) ($row['scanStatusCode'] ?? 'not_required'));
        if ($scan !== '' && !in_array($scan, ['clean', 'not_required', 'skipped'], true)) {
            return false;
        }

        return true;
    }

    private function listPublicMedia(string $productPublicId): array
    {
        $items = [];
        foreach ($this->store->media() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId || ($row['archived'] ?? false)) {
                continue;
            }
            if (($row['isActive'] ?? true) === false) {
                continue;
            }
            if (!$this->isPubliclyVisibleFile($row)) {
                continue;
            }
            $items[] = new ProductMediaSummary(
                publicId: $this->stringValue($row, 'publicId'),
                mediaCategoryCode: $this->stringValue($row, 'mediaCategoryCode'),
                mediaTypeCode: $this->stringValue($row, 'mediaTypeCode'),
                title: $this->stringValue($row, 'title'),
                publicUrl: $this->nullableStringValue($row, 'publicUrl'),
                altText: $this->nullableStringValue($row, 'altText'),
                displayOrder: $this->intValue($row, 'displayOrder'),
                isPrimary: (bool) ($row['isPrimary'] ?? false),
            );
        }

        usort(
            $items,
            static fn (ProductMediaSummary $a, ProductMediaSummary $b): int => ($b->isPrimary <=> $a->isPrimary)
                ?: ($a->displayOrder <=> $b->displayOrder)
                ?: strcmp($a->publicId, $b->publicId),
        );

        return $items;
    }

    /**
     * @return list<ProductDocumentSummary>
     */
    private function listPublicDocuments(string $productPublicId, ?string $regionCode): array
    {
        $now = $this->store->clock()->now();
        $items = [];
        foreach ($this->store->documents() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId || ($row['archived'] ?? false)) {
                continue;
            }
            if (($row['isActive'] ?? true) === false) {
                continue;
            }
            if (!$this->isPubliclyVisibleFile($row)) {
                continue;
            }
            if (($row['accessCode'] ?? '') !== 'public') {
                continue;
            }
            if (!$this->isEffective($row, $now)) {
                continue;
            }
            if ($regionCode !== null && trim($regionCode) !== '') {
                $regionPublicId = $row['regionPublicId'] ?? null;
                if (is_string($regionPublicId)) {
                    $docRegionCode = $this->store->regionCodeForPublicId($regionPublicId);
                    if ($docRegionCode !== null && strcasecmp($docRegionCode, trim($regionCode)) !== 0) {
                        continue;
                    }
                }
            }

            $items[] = new ProductDocumentSummary(
                publicId: $this->stringValue($row, 'publicId'),
                documentTypeCode: $this->stringValue($row, 'documentTypeCode'),
                title: $this->stringValue($row, 'title'),
                versionLabel: $this->nullableStringValue($row, 'versionLabel'),
                languageCode: $this->nullableStringValue($row, 'languageCode'),
                publicUrl: $this->nullableStringValue($row, 'publicUrl'),
                accessCode: $this->stringValue($row, 'accessCode'),
                displayOrder: $this->intValue($row, 'displayOrder'),
            );
        }

        usort(
            $items,
            static fn (ProductDocumentSummary $a, ProductDocumentSummary $b): int => $a->displayOrder <=> $b->displayOrder
                ?: strcmp($a->publicId, $b->publicId),
        );

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isEffective(array $row, DateTimeImmutable $now): bool
    {
        $effectiveFrom = $row['effectiveFromUtc'] ?? null;
        if (is_string($effectiveFrom) && $effectiveFrom !== '') {
            $from = new DateTimeImmutable($effectiveFrom);
            if ($from > $now) {
                return false;
            }
        }

        $effectiveTo = $row['effectiveToUtc'] ?? null;
        if (is_string($effectiveTo) && $effectiveTo !== '') {
            $to = new DateTimeImmutable($effectiveTo);
            if ($to <= $now) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function listNames(string $productPublicId, string $kind): array
    {
        $publicIds = $kind === 'industries'
            ? $this->store->productIndustries($productPublicId)
            : $this->store->productApplications($productPublicId);

        $names = [];
        foreach ($publicIds as $publicId) {
            $name = $kind === 'industries'
                ? $this->store->industryNameForPublicId($publicId)
                : $this->store->applicationNameForPublicId($publicId);
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function primarySlug(string $productPublicId): ?string
    {
        foreach ($this->store->slugs() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId
                && ($row['isPrimary'] ?? false)
                && ($row['isActive'] ?? true)
                && !($row['deleted'] ?? false)
            ) {
                $slug = $row['slug'] ?? null;

                return is_string($slug) ? $slug : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
