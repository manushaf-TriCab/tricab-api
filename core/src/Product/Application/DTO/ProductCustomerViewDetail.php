<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Admin preview of the customer datasheet for a product.
 * Works for draft/unpublished products. Excludes pricing, ERP, audit,
 * RowVersion, internal integer IDs, and restricted documents.
 */
final readonly class ProductCustomerViewDetail
{
    /**
     * @param list<ProductVariantSummary> $variants
     * @param list<ProductMediaSummary> $media
     * @param list<ProductDocumentSummary> $documents
     * @param list<string> $applicationNames
     * @param list<string> $industryNames
     * @param list<ProductPublicRelationshipItem> $relationships
     * @param list<ProductPublicSpecificationItem> $specifications
     * @param list<ProductApprovalCertificateItem> $approvalCertificates
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
        public array $relationships,
        public array $specifications,
        public ProductCustomerViewVariantMatrix $variantMatrix,
        public string $statusCode,
        public string $lifecycleStatusCode,
        public bool $isPublished,
        public bool $isArchived,
        public ?string $primarySlug,
        public ProductPublic3DPayload $threeD = new ProductPublic3DPayload(false, null, []),
        public ?string $styleCode = null,
        public ?string $parentStyleCode = null,
        /** @var list<ProductApprovalCertificateItem> */
        public array $approvalCertificates = [],
    ) {
    }
}
