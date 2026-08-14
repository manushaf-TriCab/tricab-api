<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class GroundedProduct
{
    /**
     * @param list<string> $applications
     * @param list<array{name: string, value: string}> $specifications
     */
    public function __construct(
        public string $publicId,
        public string $productName,
        public ?string $productCode,
        public ?string $slug,
        public ?string $seriesName,
        public ?string $categoryName,
        public ?string $summary,
        public array $applications,
        public array $specifications,
        public ?string $thumbnailUrl,
        public string $statusCode = 'published',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        $isPublished = strcasecmp($this->statusCode, 'published') === 0;
        $routeKey = $this->seriesName ?? $this->slug;
        $href = $isPublished
            ? ($routeKey !== null ? '/products/cable/' . rawurlencode($routeKey) : null)
            : '/admin/products/' . rawurlencode($this->publicId) . '/customer-view';

        return [
            'type' => 'product',
            'publicId' => $this->publicId,
            'title' => $this->productName,
            'eyebrow' => $this->productCode ?? $this->seriesName,
            'description' => $this->summary,
            'href' => $href,
            'imageUrl' => $this->thumbnailUrl,
            'statusCode' => $this->statusCode,
            'isPublished' => $isPublished,
            'applications' => array_slice($this->applications, 0, 4),
            'specifications' => array_slice($this->specifications, 0, 6),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toGroundingArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'productName' => $this->productName,
            'productCode' => $this->productCode,
            'slug' => $this->slug,
            'seriesName' => $this->seriesName,
            'categoryName' => $this->categoryName,
            'summary' => $this->summary,
            'statusCode' => $this->statusCode,
            'isPublished' => strcasecmp($this->statusCode, 'published') === 0,
            'applications' => $this->applications,
            'specifications' => $this->specifications,
        ];
    }
}
