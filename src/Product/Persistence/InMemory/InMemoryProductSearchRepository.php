<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchListResult;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\Service\ProductThumbnailImageResolver;
use TriCab\Core\Product\Contract\ProductSearchRepository;
use TriCab\Core\Product\Domain\Entity\Product;

final class InMemoryProductSearchRepository implements ProductSearchRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function search(ProductSearchCriteria $criteria): ProductSearchListResult
    {
        $query = $criteria->normalizedQuery();
        $matches = [];

        foreach ($this->store->productRecords() as $record) {
            $product = $record['product'];
            if (!$this->matchesSearch(
                $product,
                $query,
                $criteria->regionCode,
                $criteria->publishedOnly,
                $criteria->normalizedStatusCodes(),
            )) {
                continue;
            }
            $matches[] = $this->toSearchResult($product, $query);
        }

        usort(
            $matches,
            fn (ProductSearchResult $a, ProductSearchResult $b): int => $this->compareSearchResults($a, $b, $query),
        );

        $total = count($matches);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $pageItems = array_slice($matches, $offset, $criteria->pageSize);

        return new ProductSearchListResult(
            items: $pageItems,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function suggest(string $query, int $limit = 10): array
    {
        $trimmed = trim($query);
        if ($trimmed === '' || $limit < 1) {
            return [];
        }

        $limit = min($limit, 25);
        $prefix = strtolower($trimmed);
        $suggestions = [];

        foreach ($this->store->productRecords() as $record) {
            $product = $record['product'];
            if ($product->isSoftDeleted() || !$product->status()->isPublished()) {
                continue;
            }

            foreach ([$product->productName(), $product->internalCode()?->toString()] as $candidate) {
                if ($candidate !== null && str_starts_with(strtolower($candidate), $prefix)) {
                    $suggestions[] = $candidate;
                }
            }
        }

        foreach ($this->store->variantRecords() as $record) {
            $sku = $record['variant']->sku()?->toString();
            if ($sku !== null && !$record['variant']->isSoftDeleted() && str_starts_with(strtolower($sku), $prefix)) {
                $suggestions[] = $sku;
            }
        }

        $unique = [];
        foreach ($suggestions as $suggestion) {
            if (!in_array($suggestion, $unique, true)) {
                $unique[] = $suggestion;
            }
            if (count($unique) >= $limit) {
                break;
            }
        }

        return $unique;
    }

    /** @param list<string> $statusCodes */
    private function matchesSearch(
        Product $product,
        string $query,
        ?string $regionCode,
        bool $publishedOnly,
        array $statusCodes,
    ): bool {
        if ($product->isSoftDeleted()) {
            return false;
        }

        if ($publishedOnly) {
            if (!$product->status()->isPublished() || !$product->isActive()) {
                return false;
            }
            $now = $this->store->clock()->now();
            if ($product->publishAtUtc() !== null && $product->publishAtUtc() > $now) {
                return false;
            }
            if ($product->unpublishAtUtc() !== null && $product->unpublishAtUtc() <= $now) {
                return false;
            }
        }

        if (
            $statusCodes !== []
            && !in_array(strtolower($product->status()->toString()), $statusCodes, true)
        ) {
            return false;
        }

        if ($regionCode !== null && trim($regionCode) !== '') {
            $found = false;
            foreach ($this->store->productRegions($product->publicId()->toString()) as $regionPublicId) {
                if ($this->store->regionPublicIdMatchesCode($regionPublicId, $regionCode)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        $needle = strtolower($query);
        $internal = $product->internalCode()?->toString();
        if ($internal !== null && (
            $internal === $query
            || str_starts_with(strtolower($internal), $needle)
        )) {
            return true;
        }
        if (str_contains(strtolower($product->productName()), $needle)) {
            return true;
        }
        if ($this->skuMatches($product, $query)) {
            return true;
        }
        if ($this->slugMatches($product, $query)) {
            return true;
        }
        if ($this->productCodeMatches($product, $query)) {
            return true;
        }
        if ($this->categoryOrSeriesMatches($product, $needle)) {
            return true;
        }
        if ($this->descriptionMatches($product, $needle)) {
            return true;
        }
        if ($this->applicationMatches($product, $needle)) {
            return true;
        }
        if ($this->specificationMatches($product, $needle)) {
            return true;
        }

        return false;
    }

    private function skuMatches(Product $product, string $query): bool
    {
        $productId = $product->id();
        if ($productId === null) {
            return false;
        }

        $needle = strtolower($query);
        foreach ($this->store->variantRecords() as $record) {
            $variant = $record['variant'];
            if (!$variant->productId()->equals($productId) || $variant->isSoftDeleted()) {
                continue;
            }
            $sku = $variant->sku()?->toString();
            if ($sku !== null && (
                $sku === $query
                || str_starts_with(strtolower($sku), $needle)
            )) {
                return true;
            }
            $variantCode = $variant->variantCode()?->toString();
            if ($variantCode !== null && (
                $variantCode === $query
                || str_starts_with(strtolower($variantCode), $needle)
            )) {
                return true;
            }
        }

        return false;
    }

    private function slugMatches(Product $product, string $query): bool
    {
        foreach ($this->store->slugs() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $product->publicId()->toString()
                && ($row['slug'] ?? '') === $query
                && !($row['deleted'] ?? false)
            ) {
                return true;
            }
        }

        return false;
    }

    private function productCodeMatches(Product $product, string $query): bool
    {
        foreach ($this->store->productCodes() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $product->publicId()->toString()
                && !($row['deleted'] ?? false)
                && (
                    ($row['codeValue'] ?? '') === $query
                    || str_starts_with(strtolower((string) ($row['codeValue'] ?? '')), strtolower($query))
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function categoryOrSeriesMatches(Product $product, string $needle): bool
    {
        $category = $this->store->categoryNameForId($product->categoryId());
        if ($category !== null && str_contains(strtolower($category), $needle)) {
            return true;
        }

        $series = $this->store->seriesNameForId($product->seriesId());

        return $series !== null && str_contains(strtolower($series), $needle);
    }

    private function descriptionMatches(Product $product, string $needle): bool
    {
        foreach ([$product->shortDescription(), $product->fullDescription()] as $description) {
            if ($description !== null && str_contains(strtolower($description), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function applicationMatches(Product $product, string $needle): bool
    {
        $publicId = $product->publicId()->toString();
        foreach ($this->store->productApplications($publicId) as $applicationPublicId) {
            $name = $this->store->applicationNameForPublicId($applicationPublicId);
            if ($name !== null && str_contains(strtolower($name), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function specificationMatches(Product $product, string $needle): bool
    {
        $definitions = [];
        foreach ($this->store->specDefinitions() as $definition) {
            $publicId = $definition['publicId'] ?? null;
            if (is_string($publicId) && ($definition['isActive'] ?? true) !== false) {
                $definitions[$publicId] = $definition;
            }
        }

        $productPublicId = $product->publicId()->toString();
        foreach ($this->store->productSpecValues() as $value) {
            if (($value['productPublicId'] ?? '') !== $productPublicId) {
                continue;
            }
            $definitionPublicId = $value['definitionPublicId'] ?? null;
            $definition = is_string($definitionPublicId) ? ($definitions[$definitionPublicId] ?? []) : [];
            foreach ([
                $definition['name'] ?? null,
                $definition['code'] ?? null,
                $definition['unit'] ?? null,
                $value['value'] ?? null,
                $value['textValue'] ?? null,
                $value['optionLabel'] ?? null,
                $value['unitOverride'] ?? null,
                $value['minimum'] ?? null,
                $value['maximum'] ?? null,
            ] as $candidate) {
                if (
                    (is_string($candidate) || is_numeric($candidate))
                    && str_contains(strtolower((string) $candidate), $needle)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function toSearchResult(Product $product, string $query): ProductSearchResult
    {
        $publicId = $product->publicId()->toString();

        return new ProductSearchResult(
            publicId: $publicId,
            productName: $product->productName(),
            productCode: $product->internalCode()?->toString(),
            slug: $this->primarySlug($publicId),
            categoryName: $this->store->categoryNameForId($product->categoryId()),
            seriesName: $this->store->seriesNameForId($product->seriesId()),
            statusCode: $product->status()->toString(),
            thumbnailUrl: $this->thumbnailUrl($publicId),
            thumbnailImageUrl: $this->thumbnailImageUrl($publicId),
            thumbnailBackgroundColor: $this->store->thumbnailBackgroundColorForProduct($product->publicId()),
        );
    }

    private function compareSearchResults(ProductSearchResult $a, ProductSearchResult $b, string $query): int
    {
        $rank = fn (ProductSearchResult $item): int => match (true) {
            $item->productCode === $query => 0,
            $this->variantSkuMatchesPublicId($item->publicId, $query) => 1,
            $item->productCode !== null
                && str_starts_with(strtolower($item->productCode), strtolower($query)) => 2,
            $this->variantCodePrefixMatchesPublicId($item->publicId, $query) => 3,
            strcasecmp($item->productName, $query) === 0 => 4,
            default => 5,
        };

        $value = $rank($a) <=> $rank($b);
        if ($value !== 0) {
            return $value;
        }

        return strcmp($a->productName, $b->productName);
    }

    private function variantSkuMatchesPublicId(string $productPublicId, string $query): bool
    {
        foreach ($this->store->variantRecords() as $record) {
            $variant = $record['variant'];
            $parent = $this->store->productRecordById($variant->productId());
            if ($parent === null || $parent['product']->publicId()->toString() !== $productPublicId) {
                continue;
            }
            if ($variant->sku()?->toString() === $query || $variant->variantCode()?->toString() === $query) {
                return true;
            }
        }

        return false;
    }

    private function variantCodePrefixMatchesPublicId(string $productPublicId, string $query): bool
    {
        $needle = strtolower($query);
        foreach ($this->store->variantRecords() as $record) {
            $variant = $record['variant'];
            if ($variant->isSoftDeleted()) {
                continue;
            }
            $parent = $this->store->productRecordById($variant->productId());
            if ($parent === null || $parent['product']->publicId()->toString() !== $productPublicId) {
                continue;
            }
            foreach ([$variant->sku()?->toString(), $variant->variantCode()?->toString()] as $code) {
                if ($code !== null && str_starts_with(strtolower($code), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function primarySlug(string $productPublicId): ?string
    {
        foreach ($this->store->slugs() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId
                && ($row['isPrimary'] ?? false)
                && !($row['deleted'] ?? false)
            ) {
                $slug = $row['slug'] ?? null;

                return is_string($slug) ? $slug : null;
            }
        }

        return null;
    }

    private function thumbnailUrl(string $productPublicId): ?string
    {
        foreach ($this->store->media() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId
                && ($row['isPrimary'] ?? false)
                && !($row['archived'] ?? false)
            ) {
                $publicUrl = $row['publicUrl'] ?? null;

                return is_string($publicUrl) ? $publicUrl : null;
            }
        }

        return null;
    }

    private function thumbnailImageUrl(string $productPublicId): ?string
    {
        $media = [];
        foreach ($this->store->media() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId || ($row['archived'] ?? false)) {
                continue;
            }
            $publicUrl = $row['publicUrl'] ?? null;
            if (!is_string($publicUrl) || $publicUrl === '') {
                continue;
            }
            $media[] = new ProductMediaSummary(
                publicId: (string) ($row['publicId'] ?? ''),
                mediaCategoryCode: (string) ($row['mediaCategoryCode'] ?? 'gallery'),
                mediaTypeCode: (string) ($row['mediaTypeCode'] ?? 'primary-image'),
                title: (string) ($row['title'] ?? 'Image'),
                publicUrl: $publicUrl,
                altText: null,
                displayOrder: (int) ($row['displayOrder'] ?? 0),
                isPrimary: (bool) ($row['isPrimary'] ?? false),
            );
        }

        return ProductThumbnailImageResolver::fromMediaSummaries($media);
    }
}
