<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Public/customer-safe product detail. Excludes internal IDs, audit, pricing,
 * ERP, migration, RowVersion, and restricted documents.
 */
final readonly class ProductPublicDetail
{
    /**
     * @param list<ProductVariantSummary> $variants
     * @param list<ProductMediaSummary> $media
     * @param list<ProductDocumentSummary> $documents
     * @param list<string> $applicationNames
     * @param list<string> $industryNames
     * @param list<ProductPublicRelationshipItem> $relationships
     * @param list<ProductPublicSpecificationItem> $specifications
     */
    public function __construct(
        public string $publicId,
        public string $productName,
        public ?string $productCode,
        public ?string $shortDescription,
        public ?string $fullDescription,
        public ?string $familyName,
        public ?string $categoryName,
        public ?string $seriesName,
        public string $slug,
        public ?string $thumbnailUrl,
        public array $variants,
        public array $media,
        public array $documents,
        public array $applicationNames,
        public array $industryNames,
        public array $relationships = [],
        public array $specifications = [],
    ) {
    }
}
