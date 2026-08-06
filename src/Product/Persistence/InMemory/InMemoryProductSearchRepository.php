<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchListResult;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
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
            if (!$this->matchesSearch($product, $query, $criteria->regionCode, $criteria->publishedOnly)) {
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

    private function matchesSearch(
        Product $product,
        string $query,
        ?string $regionCode,
        bool $publishedOnly,
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

        if ($regionCode !== null && trim($regionCode) !== '') {
            $found = false;
            foreach ($this->store->productRegions($product->publicId()->toString()) as $regionPublicId) {
                $code = $this->store->regionCodeForPublicId($regionPublicId);
                if ($code !== null && strcasecmp($code, trim($regionCode)) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        $needle = strtolower($query);
        if ($product->internalCode()?->toString() === $query) {
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

        return false;
    }

    private function skuMatches(Product $product, string $query): bool
    {
        $productId = $product->id();
        if ($productId === null) {
            return false;
        }

        foreach ($this->store->variantRecords() as $record) {
            $variant = $record['variant'];
            if (!$variant->productId()->equals($productId) || $variant->isSoftDeleted()) {
                continue;
            }
            if ($variant->sku()?->toString() === $query) {
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
                && ($row['codeValue'] ?? '') === $query
                && !($row['deleted'] ?? false)
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
        );
    }

    private function compareSearchResults(ProductSearchResult $a, ProductSearchResult $b, string $query): int
    {
        $rank = fn (ProductSearchResult $item): int => match (true) {
            $item->productCode === $query => 0,
            $this->variantSkuMatchesPublicId($item->publicId, $query) => 1,
            strcasecmp($item->productName, $query) === 0 => 2,
            default => 3,
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
            if ($variant->sku()?->toString() === $query) {
                return true;
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
}
