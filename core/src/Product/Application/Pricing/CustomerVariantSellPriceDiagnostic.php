<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Staff-only diagnostic expansion. Not returned on ordinary customer endpoints.
 *
 * @phpstan-type Trace list<string>
 */
final readonly class CustomerVariantSellPriceDiagnostic
{
    /**
     * @param Trace $trace
     */
    public function __construct(
        public CustomerVariantSellPriceResult $customer,
        public ?string $baseCost,
        public ?string $baseCurrency,
        public ?string $priceListCode,
        public ?string $marginPercent,
        public ?string $fxRate,
        public ?string $fxSource,
        public string $formulaPath,
        public array $trace,
        public bool $tasRuleApplied,
        public ?string $resolvedMarginPercent = null,
        public ?string $overrideMarginPercent = null,
        public ?string $marginSource = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toStaffArray(): array
    {
        return [
            'customer' => $this->customer->toCustomerArray(),
            'diagnostic' => [
                'baseCost' => $this->baseCost,
                'baseCurrency' => $this->baseCurrency,
                'priceListCode' => $this->priceListCode,
                'marginPercent' => $this->marginPercent,
                'resolvedMarginPercent' => $this->resolvedMarginPercent,
                'overrideMarginPercent' => $this->overrideMarginPercent,
                'marginSource' => $this->marginSource,
                'fxRate' => $this->fxRate,
                'fxSource' => $this->fxSource,
                'formulaPath' => $this->formulaPath,
                'tasRule' => $this->tasRuleApplied ? 'APPLIED' : 'LEGACY_SPECIAL_RULE_REVIEW_NOT_APPLIED',
                'trace' => $this->trace,
            ],
        ];
    }
}
