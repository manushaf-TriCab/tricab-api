<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;

/**
 * Server-side customer sell-price resolver.
 * Uses Azure base cost + optional legacy Cost API effective cost + margin/FX + proven catalogue formula.
 */
final class ResolveCustomerSellPriceService
{
    public const PROBE_LENGTH = '1';

    public function __construct(
        private readonly VariantCommercialCatalogRepository $catalog,
        private readonly CustomerPricingMarginRepository $margins,
        private readonly CurrencyFxRateRepository $fx,
        private readonly LegacyCustomerSellFormulaContract $formula = new LegacyCustomerSellFormulaContract(),
        private readonly ?LegacyCostCalculationGateway $legacyCost = null,
        private readonly bool $legacyCostApiEnabled = false,
        private readonly ResolvePricingDbcoService $dbcoResolver = new ResolvePricingDbcoService(),
        private readonly string $pricingCostSource = 'legacy-fallback',
        private readonly string $defaultTmmktmMargin = '0',
    ) {
    }

    public function resolve(
        string $variantPublicId,
        CustomerPricingContext $context,
        bool $includeDiagnostic = false,
        ?string $asOfDateUtc = null,
        bool $applyTasRule = false,
        ?string $requestedLength = null,
    ): CustomerVariantSellPriceResult|CustomerVariantSellPriceDiagnostic {
        $asOf = $asOfDateUtc ?? gmdate('Y-m-d');
        $trace = [];

        if ($context->authState === CustomerSellPriceStatus::LOGIN_REQUIRED) {
            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::LOGIN_REQUIRED, 'Sign in to view pricing'), $includeDiagnostic, $trace);
        }
        if ($context->authState === CustomerSellPriceStatus::CUSTOMER_REQUIRED || $context->customerPublicId === null) {
            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::CUSTOMER_REQUIRED, 'Select or link a customer account to view pricing'), $includeDiagnostic, $trace);
        }

        if ($requestedLength !== null && $requestedLength !== '' && !$this->isValidPositiveLength($requestedLength)) {
            return $this->wrap(
                $this->statusOnly($variantPublicId, CustomerSellPriceStatus::REVIEW, 'Invalid length'),
                $includeDiagnostic,
                array_merge($trace, ['INVALID_LENGTH']),
            );
        }

        $catalogEntry = $this->catalog->findCommercialCatalog($variantPublicId, $asOf);
        if ($catalogEntry === null) {
            $trace[] = 'VARIANT_NOT_IN_CATALOG';

            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, 'Contact us for pricing'), $includeDiagnostic, $trace);
        }

        $base = $this->buildCommercialBase($catalogEntry, $context);
        $hasAzureBase = $catalogEntry['azureBase'] !== null;
        $attemptLegacyCostApi = $this->shouldAttemptLegacyCostApi();

        $trace[] = 'PRICING_COST_SOURCE_MODE:' . $this->pricingCostSource;

        if (!$attemptLegacyCostApi && !$hasAzureBase) {
            $trace[] = 'NO_AZURE_BASE_COST';

            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, 'Contact us for pricing', $base), $includeDiagnostic, $trace, $base);
        }

        if ($this->isLegacyCostApiPrimaryMode()) {
            $trace[] = 'BASE_META_FROM_CATALOG:' . $base['priceListCode'];
        } else {
            $trace[] = $hasAzureBase
                ? 'BASE_META_FROM_AZURE:' . $base['priceListCode']
                : 'BASE_META_FROM_CATALOG:' . $base['priceListCode'];
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

        $costForFormula = null;
        $availability = $base['availability'] ?? CustomerSellPriceStatus::AVAILABILITY_UNKNOWN;
        $costSource = 'AZURE_BASE';
        $effectiveCostForDiag = isset($base['baseCost']) ? (string) $base['baseCost'] : null;

        if ($attemptLegacyCostApi) {
            $productCode = trim((string) ($base['variantCode'] ?? ''));
            if ($productCode === '') {
                $trace[] = 'NO_PRODUCT_CODE';

                return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, 'Contact us for pricing', $base), $includeDiagnostic, $trace, $base);
            }

            $dbco = $this->dbcoResolver->resolve($context, (string) $base['priceListCode']);
            if ($dbco === null) {
                $trace[] = 'DBCO_UNRESOLVED';

                return $this->wrap(
                    $this->commercialContextIncomplete($variantPublicId, $context, $base),
                    $includeDiagnostic,
                    $trace,
                    $base,
                );
            }

            $lengthForCall = ($requestedLength !== null && $requestedLength !== '')
                ? trim($requestedLength)
                : null;
            $isProbe = $lengthForCall === null;
            $callLength = $isProbe ? self::PROBE_LENGTH : $lengthForCall;

            $cost = $this->legacyCost->resolveCost($productCode, $dbco, $callLength);
            $trace[] = 'COST_API_CALL length=' . $callLength . ($isProbe ? ' (probe)' : '');

            if ($isProbe) {
                if ($cost->success && $cost->isMake()) {
                    $trace[] = 'COST_SOURCE:LEGACY_COST_API';
                    $trace[] = 'LENGTH_REQUIRED status=MAKE';

                    return $this->wrap(
                        $this->lengthRequiredResult($variantPublicId, $base),
                        $includeDiagnostic,
                        $trace,
                        array_merge($base, ['availability' => CustomerSellPriceStatus::AVAILABILITY_MAKE]),
                        null,
                        null,
                        false,
                        $requestedLength,
                        'LEGACY_COST_API',
                    );
                }

                if ($cost->success && $cost->isStocked()) {
                    $effective = $cost->effectiveCostPerUnit();
                    if ($effective === null) {
                        $trace[] = 'COST_API_STOCKED_NO_EFFECTIVE';
                        // fall through to Azure stocked handling below via failure path
                        $cost = CostResolution::failure($productCode, $dbco, 'MISSING_DEFAULT_COST', CustomerSellPriceStatus::AVAILABILITY_STOCKED, $callLength);
                    } else {
                        $costForFormula = $effective;
                        $availability = CustomerSellPriceStatus::AVAILABILITY_STOCKED;
                        $costSource = 'LEGACY_COST_API';
                        $effectiveCostForDiag = $effective;
                        $trace[] = 'COST_SOURCE:LEGACY_COST_API';
                        $trace[] = 'AVAILABILITY:STOCKED';
                    }
                }

                if ($costForFormula === null) {
                    $fallback = $this->handleCostApiFailure(
                        $variantPublicId,
                        $context,
                        $base,
                        $cost,
                        $trace,
                        $includeDiagnostic,
                        $requestedLength,
                    );
                    if ($fallback instanceof CustomerVariantSellPriceResult || $fallback instanceof CustomerVariantSellPriceDiagnostic) {
                        return $fallback;
                    }
                    // Azure STOCKED fallback assigned cost
                    $costForFormula = $fallback['cost'];
                    $availability = $fallback['availability'];
                    $costSource = $fallback['costSource'];
                    $effectiveCostForDiag = $costForFormula;
                    $trace = $fallback['trace'];
                }
            } else {
                // Explicit length provided
                if ($cost->success) {
                    $effective = $cost->effectiveCostPerUnit();
                    if ($effective === null) {
                        $cost = CostResolution::failure(
                            $productCode,
                            $dbco,
                            'MISSING_EFFECTIVE_COST',
                            $cost->status,
                            $callLength,
                        );
                    } else {
                        $costForFormula = $effective;
                        $availability = $cost->isMake()
                            ? CustomerSellPriceStatus::AVAILABILITY_MAKE
                            : ($cost->isStocked()
                                ? CustomerSellPriceStatus::AVAILABILITY_STOCKED
                                : $availability);
                        $costSource = 'LEGACY_COST_API';
                        $effectiveCostForDiag = $effective;
                        $trace[] = 'COST_SOURCE:LEGACY_COST_API';
                        $trace[] = 'AVAILABILITY:' . $availability;
                        if ($requestedLength !== null) {
                            $trace[] = 'REQUESTED_LENGTH:' . $requestedLength;
                        }
                    }
                }

                if ($costForFormula === null) {
                    $fallback = $this->handleCostApiFailure(
                        $variantPublicId,
                        $context,
                        $base,
                        $cost,
                        $trace,
                        $includeDiagnostic,
                        $requestedLength,
                    );
                    if ($fallback instanceof CustomerVariantSellPriceResult || $fallback instanceof CustomerVariantSellPriceDiagnostic) {
                        return $fallback;
                    }
                    $costForFormula = $fallback['cost'];
                    $availability = $fallback['availability'];
                    $costSource = $fallback['costSource'];
                    $effectiveCostForDiag = $costForFormula;
                    $trace = $fallback['trace'];
                }
            }
        } else {
            // Legacy Cost API disabled — Azure-only (unchanged).
            if (bccomp($base['baseCost'], '0', 6) <= 0) {
                return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::PRICE_NOT_AVAILABLE, 'Contact us for pricing', $base), $includeDiagnostic, $trace, $base);
            }
            $costForFormula = $base['baseCost'];
            $trace[] = 'BASE_COST_FROM_AZURE:' . $base['priceListCode'];
        }

        if ($costForFormula === null || bccomp($costForFormula, '0', 6) <= 0) {
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
        if ($margin === null && ($context->overrideMarginPercent === null || $context->overrideMarginPercent === '')) {
            $trace[] = 'NO_MARGIN_RULE';
            $defaultMargin = $this->normalizedDefaultTmmktmMargin();
            if ($defaultMargin === null) {
                return $this->wrap(
                    $this->commercialContextIncomplete(
                        $variantPublicId,
                        $context,
                        array_merge($base, ['availability' => $availability]),
                    ),
                    $includeDiagnostic,
                    $trace,
                    array_merge($base, ['availability' => $availability]),
                    $fx,
                );
            }

            $margin = [
                'marginPercent' => $defaultMargin,
                'ruleLevel' => 'default',
                'coreClass' => 'p',
            ];
            $trace[] = 'MARGIN_DEFAULT:' . $defaultMargin;
        }

        $resolvedMarginPercent = $margin['marginPercent'] ?? null;
        $overrideMarginPercent = $context->overrideMarginPercent;
        $effectiveMarginPercent = $overrideMarginPercent ?? $resolvedMarginPercent;
        if ($effectiveMarginPercent === null || $effectiveMarginPercent === '') {
            $trace[] = 'NO_EFFECTIVE_MARGIN';

            return $this->wrap($this->statusOnly($variantPublicId, CustomerSellPriceStatus::TBC, 'Price TBC', $base), $includeDiagnostic, $trace, $base, $fx);
        }

        $marginSource = $overrideMarginPercent !== null && $overrideMarginPercent !== ''
            ? 'STAFF_OVERRIDE'
            : 'TMMKTM';
        if ($resolvedMarginPercent !== null) {
            $trace[] = 'MARGIN_RESOLVED:' . ($margin['ruleLevel'] ?? 'unknown') . '=' . $resolvedMarginPercent;
        }
        $trace[] = 'MARGIN_EFFECTIVE:' . $marginSource . '=' . $effectiveMarginPercent;

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
            $rawSell = $this->formula->resolveSellPrice($costForFormula, $fx['rate'], $effectiveMarginPercent);
        } catch (\InvalidArgumentException) {
            return $this->wrap(
                $this->statusOnly($variantPublicId, CustomerSellPriceStatus::REVIEW, 'Contact us for pricing', $base),
                $includeDiagnostic,
                $trace,
                array_merge($base, ['baseCost' => $effectiveCostForDiag, 'availability' => $availability]),
                $fx,
                [
                    'marginPercent' => $effectiveMarginPercent,
                    'resolvedMarginPercent' => $resolvedMarginPercent,
                    'overrideMarginPercent' => $overrideMarginPercent,
                    'marginSource' => $marginSource,
                    'ruleLevel' => $margin['ruleLevel'] ?? null,
                ],
                false,
                $requestedLength,
                $costSource,
            );
        }

        $display = $this->roundDisplay($rawSell);
        $customer = new CustomerVariantSellPriceResult(
            variantPublicId: $variantPublicId,
            status: CustomerSellPriceStatus::RESOLVED,
            availability: $availability,
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
            baseCost: $effectiveCostForDiag,
            baseCurrency: $baseCurrency,
            priceListCode: $base['priceListCode'],
            marginPercent: $effectiveMarginPercent,
            fxRate: $fx['rate'],
            fxSource: $fx['source'],
            formulaPath: 'base/fx * 100/(100-margin)',
            trace: $trace,
            tasRuleApplied: $tasApplied,
            resolvedMarginPercent: $resolvedMarginPercent,
            overrideMarginPercent: $overrideMarginPercent,
            marginSource: $marginSource,
        );
    }

    /**
     * @param list<string> $variantPublicIds
     * @return list<CustomerVariantSellPriceResult>
     */
    public function resolveBatch(
        array $variantPublicIds,
        CustomerPricingContext $context,
        ?string $asOfDateUtc = null,
        ?string $requestedLength = null,
    ): array {
        $out = [];
        foreach (array_values(array_unique($variantPublicIds)) as $id) {
            $result = $this->resolve($id, $context, false, $asOfDateUtc, false, $requestedLength);
            $out[] = $result instanceof CustomerVariantSellPriceDiagnostic ? $result->customer : $result;
        }

        return $out;
    }

    private function shouldAttemptLegacyCostApi(): bool
    {
        return $this->legacyCostApiEnabled && $this->legacyCost !== null;
    }

    private function isLegacyCostApiPrimaryMode(): bool
    {
        return $this->shouldAttemptLegacyCostApi() && $this->pricingCostSource === 'legacy-cost-api';
    }

    /** @return numeric-string|null */
    private function normalizedDefaultTmmktmMargin(): ?string
    {
        $raw = trim($this->defaultTmmktmMargin);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        if (bccomp(bcsub('100', $raw, 12), '0', 12) <= 0) {
            return null;
        }
        if (bccomp($raw, '0', 12) === 0) {
            return '0';
        }

        return $raw;
    }

    /**
     * @param array{
     *   variantPublicId: string,
     *   variantCode: string,
     *   sku: ?string,
     *   styleCode: ?string,
     *   uomCode: string,
     *   uomSymbol: string,
     *   legacyProductCode: string,
     *   azureBase: ?array{
     *     baseCost: string,
     *     currencyCode: string,
     *     priceListCode: string,
     *     availability: string
     *   }
     * } $catalog
     *
     * @return array<string, mixed>
     */
    private function buildCommercialBase(array $catalog, CustomerPricingContext $context): array
    {
        $sellCurrency = strtoupper($context->sellCurrency);
        $inferred = match ($sellCurrency) {
            'USD' => ['priceListCode' => 'TCUS-BASE', 'currencyCode' => 'USD'],
            default => ['priceListCode' => 'TCA-BASE', 'currencyCode' => 'AUD'],
        };

        $variantCode = trim($catalog['legacyProductCode']);
        $azure = $catalog['azureBase'];
        if ($azure !== null && !$this->isLegacyCostApiPrimaryMode()) {
            return [
                'baseCost' => $azure['baseCost'],
                'currencyCode' => $azure['currencyCode'],
                'priceListCode' => $azure['priceListCode'],
                'uomCode' => $catalog['uomCode'],
                'uomSymbol' => $catalog['uomSymbol'],
                'variantCode' => $variantCode,
                'styleCode' => $catalog['styleCode'],
                'availability' => $azure['availability'],
            ];
        }

        if ($azure !== null) {
            return [
                'baseCost' => '0',
                'currencyCode' => $azure['currencyCode'],
                'priceListCode' => $azure['priceListCode'],
                'uomCode' => $catalog['uomCode'],
                'uomSymbol' => $catalog['uomSymbol'],
                'variantCode' => $variantCode,
                'styleCode' => $catalog['styleCode'],
                'availability' => $azure['availability'],
            ];
        }

        return [
            'baseCost' => '0',
            'currencyCode' => $inferred['currencyCode'],
            'priceListCode' => $inferred['priceListCode'],
            'uomCode' => $catalog['uomCode'],
            'uomSymbol' => $catalog['uomSymbol'],
            'variantCode' => $variantCode,
            'styleCode' => $catalog['styleCode'],
            'availability' => CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
        ];
    }

    /**
     * Unresolved commercial margin / DBCO — business TBC, not infrastructure failure.
     *
     * @param array<string, mixed>|null $base
     */
    private function commercialContextIncomplete(
        string $variantPublicId,
        CustomerPricingContext $context,
        ?array $base = null,
    ): CustomerVariantSellPriceResult {
        $message = $context->pricingMode === CustomerPricingContext::MODE_STAFF_SELECTED
            ? 'Customer pricing setup incomplete'
            : 'Price TBC';

        return $this->statusOnly($variantPublicId, CustomerSellPriceStatus::TBC, $message, $base);
    }

    public function isValidPositiveLength(string $length): bool
    {
        $trimmed = trim($length);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return false;
        }

        return bccomp($trimmed, '0', 6) > 0;
    }

    /**
     * @param array<string, mixed> $base
     * @param list<string> $trace
     * @return CustomerVariantSellPriceResult|CustomerVariantSellPriceDiagnostic|array{cost: string, availability: string, costSource: string, trace: list<string>}
     */
    private function handleCostApiFailure(
        string $variantPublicId,
        CustomerPricingContext $context,
        array $base,
        CostResolution $cost,
        array $trace,
        bool $includeDiagnostic,
        ?string $requestedLength,
    ): CustomerVariantSellPriceResult|CustomerVariantSellPriceDiagnostic|array {
        unset($context);
        $azureAvailability = strtoupper((string) ($base['availability'] ?? CustomerSellPriceStatus::AVAILABILITY_UNKNOWN));
        $knownMake = $cost->isMake() || $azureAvailability === CustomerSellPriceStatus::AVAILABILITY_MAKE;
        $knownStocked = $cost->isStocked() || $azureAvailability === CustomerSellPriceStatus::AVAILABILITY_STOCKED;

        $trace[] = 'COST_API_FAILED:' . ($cost->failureReason ?? 'UNKNOWN');

        if ($knownMake && !$knownStocked) {
            $trace[] = 'NO_AZURE_FALLBACK_FOR_MAKE';

            return $this->wrap(
                $this->statusOnly(
                    $variantPublicId,
                    CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE,
                    'Pricing temporarily unavailable',
                    array_merge($base, ['availability' => CustomerSellPriceStatus::AVAILABILITY_MAKE]),
                ),
                $includeDiagnostic,
                $trace,
                array_merge($base, ['availability' => CustomerSellPriceStatus::AVAILABILITY_MAKE]),
                null,
                null,
                false,
                $requestedLength,
                'COST_SOURCE_UNAVAILABLE',
            );
        }

        if ($knownStocked || (!$knownMake && $azureAvailability === CustomerSellPriceStatus::AVAILABILITY_STOCKED)) {
            if (
                !$this->isLegacyCostApiPrimaryMode()
                && isset($base['baseCost']) && is_numeric($base['baseCost']) && bccomp((string) $base['baseCost'], '0', 6) > 0
            ) {
                $trace[] = 'COST_API_FAILED_AZURE_STOCKED_FALLBACK';

                return [
                    'cost' => (string) $base['baseCost'],
                    'availability' => CustomerSellPriceStatus::AVAILABILITY_STOCKED,
                    'costSource' => 'AZURE_STOCKED_FALLBACK',
                    'trace' => $trace,
                ];
            }
        }

        // Complete failure with unknown/MAKE-leaning status: never silent Azure for MAKE.
        if ($azureAvailability === CustomerSellPriceStatus::AVAILABILITY_MAKE || $cost->isMake()) {
            $trace[] = 'NO_AZURE_FALLBACK_FOR_MAKE';

            return $this->wrap(
                $this->statusOnly(
                    $variantPublicId,
                    CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE,
                    'Pricing temporarily unavailable',
                    array_merge($base, ['availability' => CustomerSellPriceStatus::AVAILABILITY_MAKE]),
                ),
                $includeDiagnostic,
                $trace,
                array_merge($base, ['availability' => CustomerSellPriceStatus::AVAILABILITY_MAKE]),
                null,
                null,
                false,
                $requestedLength,
                'COST_SOURCE_UNAVAILABLE',
            );
        }

        $trace[] = 'COST_SOURCE_UNAVAILABLE';

        return $this->wrap(
            $this->statusOnly($variantPublicId, CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE, 'Pricing temporarily unavailable', $base),
            $includeDiagnostic,
            $trace,
            $base,
            null,
            null,
            false,
            $requestedLength,
            'COST_SOURCE_UNAVAILABLE',
        );
    }

    /**
     * @param array<string, mixed> $base
     */
    private function lengthRequiredResult(string $variantPublicId, array $base): CustomerVariantSellPriceResult
    {
        return new CustomerVariantSellPriceResult(
            variantPublicId: $variantPublicId,
            status: CustomerSellPriceStatus::LENGTH_REQUIRED,
            availability: CustomerSellPriceStatus::AVAILABILITY_MAKE,
            unitSellPrice: null,
            currencyCode: null,
            uomCode: $base['uomCode'] ?? 'M',
            uomSymbol: $base['uomSymbol'] ?? 'm',
            variantCode: $base['variantCode'] ?? null,
            quantityEnabled: true,
            lengthEnabled: true,
            displayMessage: 'Enter length to calculate price',
            canAddToRfq: false,
        );
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
     * @param array{
     *   marginPercent?: string|null,
     *   resolvedMarginPercent?: string|null,
     *   overrideMarginPercent?: string|null,
     *   marginSource?: string|null,
     *   ruleLevel?: string|null
     * }|null $margin
     */
    private function wrap(
        CustomerVariantSellPriceResult $customer,
        bool $includeDiagnostic,
        array $trace,
        ?array $base = null,
        ?array $fx = null,
        ?array $margin = null,
        bool $tasApplied = false,
        ?string $requestedLength = null,
        ?string $costSource = null,
    ): CustomerVariantSellPriceResult|CustomerVariantSellPriceDiagnostic {
        if ($requestedLength !== null && $requestedLength !== '') {
            $trace[] = 'REQUESTED_LENGTH:' . $requestedLength;
        }
        if ($costSource !== null && $costSource !== '') {
            $trace[] = 'costSource=' . $costSource;
        }

        if (!$includeDiagnostic) {
            return $customer;
        }

        return new CustomerVariantSellPriceDiagnostic(
            customer: $customer,
            baseCost: isset($base['baseCost']) ? (string) $base['baseCost'] : null,
            baseCurrency: isset($base['currencyCode']) ? (string) $base['currencyCode'] : null,
            priceListCode: isset($base['priceListCode']) ? (string) $base['priceListCode'] : null,
            marginPercent: isset($margin['marginPercent']) ? (string) $margin['marginPercent'] : null,
            fxRate: $fx['rate'] ?? null,
            fxSource: $fx['source'] ?? null,
            formulaPath: 'base/fx * 100/(100-margin)',
            trace: $trace,
            tasRuleApplied: $tasApplied,
            resolvedMarginPercent: isset($margin['resolvedMarginPercent']) ? (string) $margin['resolvedMarginPercent'] : null,
            overrideMarginPercent: isset($margin['overrideMarginPercent']) ? (string) $margin['overrideMarginPercent'] : null,
            marginSource: isset($margin['marginSource']) ? (string) $margin['marginSource'] : null,
        );
    }
}
