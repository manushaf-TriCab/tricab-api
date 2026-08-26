<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Customer-safe sell-price result. Must never contain baseCost / margin / internal ids.
 */
final readonly class CustomerVariantSellPriceResult
{
    public function __construct(
        public string $variantPublicId,
        public string $status,
        public string $availability,
        public ?string $unitSellPrice,
        public ?string $currencyCode,
        public ?string $uomCode,
        public ?string $uomSymbol,
        public ?string $variantCode,
        public bool $quantityEnabled,
        public bool $lengthEnabled,
        public ?string $displayMessage,
        public bool $canAddToRfq,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toCustomerArray(): array
    {
        return [
            'variantId' => $this->variantPublicId,
            'status' => $this->status,
            'availability' => $this->availability,
            'unitPrice' => $this->unitSellPrice,
            'currency' => $this->currencyCode,
            'uom' => $this->uomSymbol ?? $this->uomCode,
            'uomCode' => $this->uomCode,
            'variantCode' => $this->variantCode,
            'quantity' => ['enabled' => $this->quantityEnabled],
            'length' => ['enabled' => $this->lengthEnabled, 'unit' => $this->uomSymbol ?? 'm'],
            'displayMessage' => $this->displayMessage,
            'canAddToRfq' => $this->canAddToRfq,
        ];
    }
}
