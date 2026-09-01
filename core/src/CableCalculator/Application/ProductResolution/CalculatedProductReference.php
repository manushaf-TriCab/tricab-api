<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\ProductResolution;

/**
 * Post-calculation product catalogue resolution — never feeds sizing.
 */
final readonly class CalculatedProductReference
{
    public function __construct(
        public string $productCode,
        public string $role,
        public string $temperatureBand,
        public ?float $sizeMm2,
        public string $resolutionStatus,
        public ?string $resolvedProductPublicId = null,
        public ?string $resolvedVariantPublicId = null,
        public ?string $slug = null,
        public ?string $displayName = null,
        public ?string $imageUrl = null,
        public ?string $customerUrl = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'productCode' => $this->productCode,
            'role' => $this->role,
            'temperatureBand' => $this->temperatureBand,
            'sizeMm2' => $this->sizeMm2,
            'resolutionStatus' => $this->resolutionStatus,
            'resolvedProductPublicId' => $this->resolvedProductPublicId,
            'resolvedVariantPublicId' => $this->resolvedVariantPublicId,
            'slug' => $this->slug,
            'displayName' => $this->displayName,
            'imageUrl' => $this->imageUrl,
            'customerUrl' => $this->customerUrl,
        ];
    }
}
