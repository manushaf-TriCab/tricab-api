<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Published member of a product's style family: every product assigned to the product's parent style or a
 * style beneath it in the persisted ProductStyles hierarchy (never inferred from product codes or names).
 */
final readonly class ProductCustomerViewStyleFamilyProduct
{
    /**
     * @param list<string> $jacketOptionCodes JACKET-OPTION-* specification codes carried by this member
     */
    public function __construct(
        public string $publicId,
        public string $productCode,
        public string $styleCode,
        public ?string $slug,
        public array $jacketOptionCodes = [],
    ) {
    }
}
