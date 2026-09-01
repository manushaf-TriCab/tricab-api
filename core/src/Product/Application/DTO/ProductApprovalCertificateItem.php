<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Reusable approval certificate assigned to a product.
 * Master data lives on SpecificationOptions for the APPROVALS definition.
 */
final readonly class ProductApprovalCertificateItem
{
    public function __construct(
        public string $publicId,
        public string $code,
        public string $name,
        public ?string $issuer,
        public ?string $imageUrl,
        public string $statusCode,
        public int $displayOrder,
    ) {
    }
}
