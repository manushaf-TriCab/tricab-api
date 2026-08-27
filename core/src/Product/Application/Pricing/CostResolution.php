<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Server-side Cost API (or fake) resolution. Never serialize cost fields to customer DTOs.
 */
final readonly class CostResolution
{
    public function __construct(
        public string $productCode,
        public string $dbco,
        public string $status,
        public ?string $defaultCost,
        public ?string $defaultLength,
        public ?string $requestedLength,
        public ?string $calculatedCostPerMeter,
        public ?string $legacyUnitCode,
        public bool $success,
        public ?string $failureReason,
    ) {
    }

    public static function failure(
        string $productCode,
        string $dbco,
        string $failureReason,
        string $status = CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
        ?string $requestedLength = null,
    ): self {
        return new self(
            productCode: $productCode,
            dbco: $dbco,
            status: $status,
            defaultCost: null,
            defaultLength: null,
            requestedLength: $requestedLength,
            calculatedCostPerMeter: null,
            legacyUnitCode: null,
            success: false,
            failureReason: $failureReason,
        );
    }

    public function isMake(): bool
    {
        return strtoupper($this->status) === CustomerSellPriceStatus::AVAILABILITY_MAKE;
    }

    public function isStocked(): bool
    {
        return strtoupper($this->status) === CustomerSellPriceStatus::AVAILABILITY_STOCKED;
    }

    /**
     * MAKE → calculatedCostPerMeter; STOCKED → prefer calculatedCostPerMeter else defaultCost.
     * Must be numeric and &gt; 0.
     */
    public function effectiveCostPerUnit(): ?string
    {
        if (!$this->success) {
            return null;
        }

        $candidate = null;
        if ($this->isMake()) {
            $candidate = $this->calculatedCostPerMeter;
        } elseif ($this->isStocked()) {
            $candidate = $this->calculatedCostPerMeter;
            if ($candidate === null || $candidate === '' || !is_numeric($candidate) || bccomp((string) $candidate, '0', 6) <= 0) {
                $candidate = $this->defaultCost;
            }
        } else {
            return null;
        }

        if ($candidate === null || $candidate === '' || !is_numeric($candidate)) {
            return null;
        }
        $normalized = (string) $candidate;
        if (bccomp($normalized, '0', 6) <= 0) {
            return null;
        }

        return $normalized;
    }
}
