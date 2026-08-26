<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;

/**
 * Server-side customer sell-price resolver.
 * Uses Azure base cost + legacy margin/FX read models + proven catalogue formula.
 */
final class ResolveCustomerSellPriceService
{
    public function __construct(
        private readonly VariantBaseCostRepository $baseCosts,
        private readonly CustomerPricingMarginRepository $margins,
        private readonly CurrencyFxRateRepository $fx,
        private readonly LegacyCustomerSellFormulaContract $formula = new LegacyCustomerSellFormulaContract(),
    ) {
    }

    public function resolve(
        string $variantPublicId,
        CustomerPricingContext $context,
        bool $includeDiagnostic = false,
        ?string $asOfDateUtc = null,
        bool $applyTasRule = false,
    ): CustomerVariantSellPriceResult|CustomerVariantSellPriceDiagnostic {
        $asOf = $asOfDateUtc ?? gmdate('Y-m-d');
        $trace = [];

        if ($context->authState === CustomerSellPriceStatus::LOGIN_REQUIRED) {
            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::LOGIN_REQUIRED, 'Sign in to view pricing'), $includeDiagnostic, $trace);
        }
        if ($context->authState === CustomerSellPriceStatus::CUSTOMER_REQUIRED || $context->customerPublicId === null) {
            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::CUSTOMER_REQUIRED, 'Select or link a customer account to view pricing'), $includeDiagnostic, $trace);
        }

        $base = $this->baseCosts->findActiveBaseCost($variantPublicId, $asOf);
        if ($base === null) {
            $trace[] = 'NO_AZURE_BASE_COST';

            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, 'Contact us for pricing'), $includeDiagnostic, $trace);
        }

        $trace[] = 'BASE_COST_FROM_AZURE:' . $base['priceListCode'];
        if (bccomp($base['baseCost'], '0', 6) <= 0) {
            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, 'Contact us for pricing', $base), $includeDiagnostic, $trace, $base);
        }

        $sellCurrency = strtoupper($context->sellCurrency);
        $baseCurrency = strtoupper($base['currencyCode']);
        if ($base['priceListCode'] === 'TCA-BASE' && $baseCurrency === 'AUD') {
            if (!in_array($sellCurrency, ['AUD', 'NZD'], true)) {
                $trace[] = 'UNSUPPORTED_SELL_CURRENCY:' . $sellCurrency;

                return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::FX_NOT_AVAILABLE, 'Pricing unavailable in this currency', $base), $includeDiagnostic, $trace, $base);
            }
        } elseif ($base['priceListCode'] === 'TCUS-BASE' && $baseCurrency === 'USD') {
            if ($sellCurrency !== 'USD') {
                $trace[] = 'UNSUPPORTED_SELL_CURRENCY:' . $sellCurrency;

                return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::FX_NOT_AVAILABLE, 'Pricing unavailable in this currency', $base), $includeDiagnostic, $trace, $base);
            }
        } else {
            $trace[] = 'PRICE_LIST_CURRENCY_MISMATCH';

            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, 'Contact us for pricing', $base), $includeDiagnostic, $trace, $base);
        }

        $fx = $this->resolveFx($baseCurrency, $sellCurrency);
        if ($fx === null) {
            $trace[] = 'FX_NOT_AVAILABLE:' . $sellCurrency;

            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::FX_NOT_AVAILABLE, 'Pricing unavailable in this currency', $base), $includeDiagnostic, $trace, $base);
        }
        $trace[] = 'FX:' . $fx['source'] . '=' . $fx['rate'];

        $country = strtoupper((string) ($context->countryCode ?? ''));
        if ($country === '') {
            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::TBC, 'Price TBC', $base), $includeDiagnostic, $trace, $base, $fx);
        }

        $styleCode = (string) ($base['styleCode'] ?? '');
        $coreCount = $this->inferCoreCount((string) ($base['variantCode'] ?? ''));
        $margin = $this->margins->resolveCableMargin(
            $styleCode,
            $country,
            $context->territoryCode,
            $context->marketCode,
            $coreCount,
            'p',
        );
        if ($margin === null) {
            $trace[] = 'NO_MARGIN_RULE';

            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::TBC, 'Price TBC', $base), $includeDiagnostic, $trace, $base, $fx);
        }
        $trace[] = 'MARGIN:' . $margin['ruleLevel'] . '=' . $margin['marginPercent'];

        $costForFormula = $base['baseCost'];
        $tasApplied = false;
        if ($applyTasRule && strtoupper((string) $context->territoryCode) === 'TAS') {
            // Explicit opt-in only — default is LEGACY_SPECIAL_RULE_REVIEW (not applied).
            $costForFormula = $this->formula->applyTasAdjustment($costForFormula);
            $tasApplied = true;
            $trace[] = 'TAS_100_99_APPLIED';
        } else {
            $trace[] = 'TAS_RULE_NOT_APPLIED';
        }

        try {
            $rawSell = $this->formula->resolveSellPrice($costForFormula, $fx['rate'], $margin['marginPercent']);
        } catch (\InvalidArgumentException) {
            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::REVIEW, 'Contact us for pricing', $base), $includeDiagnostic, $trace, $base, $fx, $margin);
        }

        $display = $this->roundDisplay($rawSell);
        $customer = new CustomerVariantSellPriceResult(
            variantPublicId: $variantPublicId,
            status: CustomerSellPriceStatus::RESOLVED,
            availability: $base['availability'] ?? CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
            unitSellPrice: $display,
            currencyCode: $sellCurrency,
            uomCode: $base['uomCode'] ?? 'M',
            uomSymbol: $base['uomSymbol'] ?? 'm',
            variantCode: $base['variantCode'] ?? null,
            quantityEnabled: true,
            lengthEnabled: true,
            displayMessage: null,
            canAddToRfq: true,
        );

        if (!$includeDiagnostic) {
            return $customer;
        }

        return new CustomerVariantSellPriceDiagnostic(
            customer: $customer,
            baseCost: $base['baseCost'],
            baseCurrency: $baseCurrency,
            priceListCode: $base['priceListCode'],
            marginPercent: $margin['marginPercent'],
            fxRate: $fx['rate'],
            fxSource: $fx['source'],
            formulaPath: 'base/fx * 100/(100-margin)',
            trace: $trace,
            tasRuleApplied: $tasApplied,
        );
    }

    /**
     * @param list<string> $variantPublicIds
     * @return list<CustomerVariantSellPriceResult>
     */
    public function resolveBatch(array $variantPublicIds, CustomerPricingContext $context, ?string $asOfDateUtc = null): array
    {
        $out = [];
        foreach (array_values(array_unique($variantPublicIds)) as $id) {
            $result = $this->resolve($id, $context, false, $asOfDateUtc);
            $out[] = $result instanceof CustomerVariantSellPriceDiagnostic ? $result->customer : $result;
        }

        return $out;
    }

    /**
     * @return array{rate: string, source: string}|null
     */
    private function resolveFx(string $baseCurrency, string $sellCurrency): ?array
    {
        if ($baseCurrency === $sellCurrency) {
            return ['rate' => '1', 'source' => 'IDENTITY'];
        }

        // NZ: TCA AUD base → NZD sell via cyrte
        if ($baseCurrency === 'AUD' && $sellCurrency === 'NZD') {
            return $this->fx->findLatestRate('NZD');
        }

        // Unsupported pairs (incl. guessing TCASIA)
        return null;
    }

    private function inferCoreCount(string $variantCode): int
    {
        if (preg_match('/\/(\d+)C/i', $variantCode, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    private function roundDisplay(string $raw): string
    {
        return bcadd($raw, '0', CustomerSellPriceStatus::DISPLAY_SCALE);
    }

    /**
     * @param array<string, mixed>|null $base
     */
    private function statusOnly(
        string $variantPublicId,
        string $status,
        string $message,
        ?array $base = null,
    ): CustomerVariantSellPriceResult {
        return new CustomerVariantSellPriceResult(
            variantPublicId: $variantPublicId,
            status: $status,
            availability: $base['availability'] ?? CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
            unitSellPrice: null,
            currencyCode: null,
            uomCode: $base['uomCode'] ?? 'M',
            uomSymbol: $base['uomSymbol'] ?? 'm',
            variantCode: $base['variantCode'] ?? null,
            quantityEnabled: false,
            lengthEnabled: false,
            displayMessage: $message,
            canAddToRfq: false,
        );
    }

    /**
     * @param list<string> $trace
     * @param array<string, mixed>|null $base
     * @param array{rate: string, source: string}|null $fx
     * @param array{marginPercent: string, ruleLevel?: string, coreClass?: string}|null $margin
     */
    private function wrap(
        CustomerVariantSellPriceResult $customer,
        bool $includeDiagnostic,
        array $trace,
        ?array $base = null,
        ?array $fx = null,
        ?array $margin = null,
        bool $tasApplied = false,
    ): CustomerVariantSellPriceResult|CustomerVariantSellPriceDiagnostic {
        if (!$includeDiagnostic) {
            return $customer;
        }

        return new CustomerVariantSellPriceDiagnostic(
            customer: $customer,
            baseCost: isset($base['baseCost']) ? (string) $base['baseCost'] : null,
            baseCurrency: isset($base['currencyCode']) ? (string) $base['currencyCode'] : null,
            priceListCode: isset($base['priceListCode']) ? (string) $base['priceListCode'] : null,
            marginPercent: $margin['marginPercent'] ?? null,
            fxRate: $fx['rate'] ?? null,
            fxSource: $fx['source'] ?? null,
            formulaPath: 'base/fx * 100/(100-margin)',
            trace: $trace,
            tasRuleApplied: $tasApplied,
        );
    }
}
