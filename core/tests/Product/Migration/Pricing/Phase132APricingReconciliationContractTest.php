<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Service\LegacyPricingMaskGenerator;
use TriCab\Core\Product\Migration\Pricing\DbcoPriceListMappingContract;
use TriCab\Core\Product\Migration\Pricing\FuturePricingArchitectureContract;
use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;
use TriCab\Core\Product\Migration\Pricing\LegacyCyrteMappingContract;
use TriCab\Core\Product\Migration\Pricing\LegacyPriceIdentityPrecedence;
use TriCab\Core\Product\Migration\Pricing\LegacyPriceImportPolicy;
use TriCab\Core\Product\Migration\Pricing\LegacyPriceRecord;
use TriCab\Core\Product\Migration\Pricing\PriceImportResolution;

final class Phase132APricingReconciliationContractTest extends TestCase
{
    private DbcoPriceListMappingContract $dbco;
    private LegacyPriceImportPolicy $policy;
    private LegacyCustomerSellFormulaContract $sell;
    private LegacyCyrteMappingContract $cyrte;
    private LegacyPriceIdentityPrecedence $identity;

    protected function setUp(): void
    {
        $this->dbco = new DbcoPriceListMappingContract();
        $this->policy = new LegacyPriceImportPolicy();
        $this->sell = new LegacyCustomerSellFormulaContract();
        $this->cyrte = new LegacyCyrteMappingContract();
        $this->identity = new LegacyPriceIdentityPrecedence(new LegacyPricingMaskGenerator());
    }

    public function test_dbco_2507_and_2517_approved_high_for_phase_133(): void
    {
        self::assertSame(DbcoPriceListMappingContract::DECISION_APPROVED, $this->dbco->forDbco(2507)['decision']);
        self::assertSame('HIGH', $this->dbco->forDbco(2507)['confidence']);
        self::assertSame('AUD', $this->dbco->forDbco(2507)['currencyCode']);
        self::assertTrue($this->dbco->isApprovedForDryRun(2507));

        self::assertSame(DbcoPriceListMappingContract::DECISION_APPROVED, $this->dbco->forDbco(2517)['decision']);
        self::assertSame('HIGH', $this->dbco->forDbco(2517)['confidence']);
        self::assertSame('USD', $this->dbco->forDbco(2517)['currencyCode']);
        self::assertTrue($this->dbco->isApprovedForDryRun(2517));

        self::assertSame([2507, 2517], $this->dbco->phase133ScopedDbcos());
    }

    public function test_dbco_2519_excluded_from_phase_133(): void
    {
        $row = $this->dbco->forDbco(2519);
        self::assertSame(DbcoPriceListMappingContract::DECISION_EXCLUDED, $row['decision']);
        self::assertFalse($row['phase133InScope']);
        self::assertFalse($this->dbco->isApprovedForDryRun(2519));
        self::assertSame(
            DbcoPriceListMappingContract::CLASSIFICATION_2519,
            '2519_NOT_REQUIRED_FOR_PHASE_13_3',
        );

        $resolution = $this->policy->evaluate(
            LegacyPriceRecord::fromLegacyRow([
                'my_row_id' => 1,
                'code' => 'BVA-PAXD/1C4BK',
                'dbco' => 2519,
                'cost' => '1.50',
                'dc' => '2026-07-28',
            ]),
            $this->dbco,
            [
                'identityType' => PriceImportResolution::IDENTITY_EXACT,
                'productVariantIds' => ['v1'],
                'productId' => 'p1',
                'productVariantId' => 'v1',
                'reasons' => [],
            ],
        );
        self::assertSame(PriceImportResolution::BLOCKED, $resolution->readiness);
    }

    public function test_unknown_dbco_blocked(): void
    {
        self::assertNull($this->dbco->forDbco(9999));
        self::assertFalse($this->dbco->isApprovedForDryRun(9999));
    }

    public function test_nz_uses_2507_aud_base_not_separate_pricelist(): void
    {
        // Architectural assertion: NZ sell currency may be NZD while base cost remains TCA-BASE/AUD.
        $base = $this->dbco->forDbco(2507);
        self::assertSame('AUD', $base['currencyCode']);
        self::assertSame('TCA-BASE', $base['priceListCode']);
        self::assertSame('NZD', $this->cyrte->currencyForId(LegacyCyrteMappingContract::NZD));
        self::assertFalse($this->cyrte->fxRequiredForBaseCostMigration());
    }

    public function test_sell_formula_margin_on_sell_not_markup(): void
    {
        $legacy = $this->sell->resolveSellPrice('100', '1', '20');
        $wrong = $this->sell->incorrectMarkupShortcut('100', '20');

        self::assertSame(0, bccomp($legacy, '125', 6));
        self::assertSame(0, bccomp($wrong, '120', 6));
        self::assertNotSame(0, bccomp($legacy, $wrong, 6));
    }

    public function test_tas_adjustment_is_review_only(): void
    {
        $adjusted = $this->sell->applyTasAdjustment('99');
        self::assertSame(0, bccomp($adjusted, '100', 6));
        self::assertSame(
            FuturePricingArchitectureContract::TAS_RULE,
            'LEGACY_SPECIAL_RULE_REVIEW',
        );
    }

    public function test_cyrte_proven_ids(): void
    {
        self::assertSame('AUD', $this->cyrte->currencyForId(3200));
        self::assertSame('USD', $this->cyrte->currencyForId(3201));
        self::assertSame('NZD', $this->cyrte->currencyForId(1006807));
        self::assertNull($this->cyrte->currencyForId(999999));
    }

    public function test_history_latest_only_high(): void
    {
        self::assertSame(LegacyPriceImportPolicy::HISTORY_LATEST_ONLY, LegacyPriceImportPolicy::APPROVED_HISTORY_POLICY);
    }

    public function test_identity_exact_then_explicit_then_mask(): void
    {
        $exact = $this->identity->resolveLegacyCode(
            'CODE-EXACT',
            ['CODE-EXACT' => [['productId' => 'p1', 'productVariantId' => 'v1']]],
            ['v9' => 'CODE-EXACT'],
        );
        self::assertSame(PriceImportResolution::IDENTITY_EXACT, $exact['identityType']);

        $explicit = $this->identity->resolveLegacyCode(
            'LEGACY-SPECIAL',
            [],
            ['v9' => 'LEGACY-SPECIAL'],
        );
        self::assertSame(PriceImportResolution::IDENTITY_EXPLICIT, $explicit['identityType']);

        $mask = $this->identity->resolveLegacyCode(
            'RRA-XXXD/10A16BK',
            [],
            [],
            [
                'RRA-CAXD/10A16BK' => [[
                    'productId' => 'p1',
                    'productVariantId' => 'v1',
                    'variantCode' => 'RRA-CAXD/10A16BK',
                ]],
            ],
        );
        self::assertSame(PriceImportResolution::IDENTITY_MASK, $mask['identityType']);
    }

    public function test_zero_cost_review_not_free(): void
    {
        self::assertSame(LegacyPriceImportPolicy::ZERO_REVIEW, $this->policy->zeroCostDecision('0'));
    }

    public function test_approved_2507_and_2517_ready(): void
    {
        foreach ([2507 => 'AUD', 2517 => 'USD'] as $dbco => $currency) {
            $resolution = $this->policy->evaluate(
                LegacyPriceRecord::fromLegacyRow([
                    'my_row_id' => (string) $dbco,
                    'code' => 'BVA-PAXD/1C4BK',
                    'dbco' => $dbco,
                    'cost' => '1.958',
                    'dc' => '2026-07-28',
                ]),
                $this->dbco,
                [
                    'identityType' => PriceImportResolution::IDENTITY_EXACT,
                    'productVariantIds' => ['v1'],
                    'productId' => 'p1',
                    'productVariantId' => 'v1',
                    'reasons' => [],
                ],
            );
            self::assertSame(PriceImportResolution::READY, $resolution->readiness);
            self::assertSame($currency, $resolution->currencyCode);
        }
    }

    public function test_dormant_features_and_security_contract(): void
    {
        $arch = new FuturePricingArchitectureContract();
        self::assertSame('DORMANT', FuturePricingArchitectureContract::CUSTOMER_PRODUCT_PRICES);
        self::assertSame('DORMANT', FuturePricingArchitectureContract::PRICING_TIERS);
        self::assertSame('TAX_EXCLUSIVE', FuturePricingArchitectureContract::TAX_POLICY);
        self::assertContains('baseCost', $arch->publicApiMustNeverExpose());
        self::assertContains('RESOLVED', $arch->priceDisplayStates());
        self::assertSame('NOT_AVAILABLE', $arch->customerSellPriceResolutionShape()['status']);
    }
}
