<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\Service\LegacyPricingMaskGenerator;
use TriCab\Core\Product\Migration\Pricing\DbcoPriceListMappingContract;
use TriCab\Core\Product\Migration\Pricing\LegacyPriceIdentityPrecedence;
use TriCab\Core\Product\Migration\Pricing\LegacyPriceImportPolicy;
use TriCab\Core\Product\Migration\Pricing\LegacyPriceRecord;
use TriCab\Core\Product\Migration\Pricing\PriceImportResolution;

final class Phase132PricingMappingContractTest extends TestCase
{
    private LegacyPriceIdentityPrecedence $identity;
    private DbcoPriceListMappingContract $dbco;
    private LegacyPriceImportPolicy $policy;

    protected function setUp(): void
    {
        $this->identity = new LegacyPriceIdentityPrecedence(new LegacyPricingMaskGenerator());
        $this->dbco = new DbcoPriceListMappingContract();
        $this->policy = new LegacyPriceImportPolicy();
    }

    public function test_exact_sku_and_variant_code_resolve(): void
    {
        $index = [
            'BVA-PAXD/1C4BK' => [['productId' => 'p1', 'productVariantId' => 'v1']],
        ];

        $r = $this->identity->resolveLegacyCode('bva-paxd/1c4bk', $index);
        self::assertSame(PriceImportResolution::IDENTITY_EXACT, $r['identityType']);
        self::assertSame('v1', $r['productVariantId']);
    }

    public function test_unknown_exact_is_unresolved(): void
    {
        $r = $this->identity->resolveLegacyCode('NO-SUCH-CODE/1C1', []);
        self::assertSame(PriceImportResolution::IDENTITY_UNRESOLVED, $r['identityType']);
    }

    public function test_multi_hit_exact_is_ambiguous(): void
    {
        $index = [
            'DUP-CODE' => [
                ['productId' => 'p1', 'productVariantId' => 'v1'],
                ['productId' => 'p2', 'productVariantId' => 'v2'],
            ],
        ];
        $r = $this->identity->resolveLegacyCode('DUP-CODE', $index);
        self::assertSame(PriceImportResolution::IDENTITY_AMBIGUOUS, $r['identityType']);
        self::assertCount(2, $r['productVariantIds']);
    }

    public function test_mask_maps_family_and_exact_overrides_in_candidate_order(): void
    {
        $variants = [
            'RRA-CAXD/10A16BK' => [[
                'productId' => 'p1',
                'productVariantId' => 'v1',
                'variantCode' => 'RRA-CAXD/10A16BK',
            ]],
            'RRA-CBXD/10A16BK' => [[
                'productId' => 'p1',
                'productVariantId' => 'v2',
                'variantCode' => 'RRA-CBXD/10A16BK',
            ]],
        ];

        $mask = $this->identity->resolveLegacyCode('RRA-XXXD/10A16BK', [], [], $variants);
        self::assertSame(PriceImportResolution::IDENTITY_MASK, $mask['identityType']);
        self::assertCount(2, $mask['productVariantIds']);

        $candidates = $this->identity->candidateCodesForVariant('RRA-CAXD/10A16BK');
        self::assertSame(['RRA-CAXD/10A16BK', 'RRA-XXXD/10A16BK'], $candidates);
    }

    public function test_mask_not_applied_outside_family(): void
    {
        $variants = [
            'BVA-PAXD/1C4BK' => [[
                'productId' => 'p1',
                'productVariantId' => 'v1',
                'variantCode' => 'BVA-PAXD/1C4BK',
            ]],
        ];
        $r = $this->identity->resolveLegacyCode('RRA-XXXD/10A16BK', [], [], $variants);
        self::assertSame(PriceImportResolution::IDENTITY_UNRESOLVED, $r['identityType']);
    }

    public function test_invalid_mask_grammar_unresolved(): void
    {
        $r = $this->identity->resolveLegacyCode('NOTAMASK', [], [], []);
        self::assertSame(PriceImportResolution::IDENTITY_UNRESOLVED, $r['identityType']);
    }

    public function test_explicit_mapping_resolves_when_no_exact(): void
    {
        $r = $this->identity->resolveLegacyCode(
            'LEGACY-SPECIAL',
            [],
            ['v9' => 'LEGACY-SPECIAL'],
        );
        self::assertSame(PriceImportResolution::IDENTITY_EXPLICIT, $r['identityType']);
        self::assertSame('v9', $r['productVariantId']);
    }

    public function test_dbco_mappings(): void
    {
        self::assertSame('TCA-BASE', $this->dbco->forDbco(2507)['priceListCode']);
        self::assertSame('AUD', $this->dbco->forDbco(2507)['currencyCode']);
        self::assertSame(DbcoPriceListMappingContract::DECISION_APPROVED, $this->dbco->forDbco(2507)['decision']);

        self::assertSame('TCUS-BASE', $this->dbco->forDbco(2517)['priceListCode']);
        self::assertSame(DbcoPriceListMappingContract::DECISION_APPROVED, $this->dbco->forDbco(2517)['decision']);

        self::assertSame('TCASIA-BASE', $this->dbco->forDbco(2519)['priceListCode']);
        self::assertSame(DbcoPriceListMappingContract::DECISION_EXCLUDED, $this->dbco->forDbco(2519)['decision']);

        self::assertNull($this->dbco->forDbco(9999));
        self::assertTrue($this->dbco->isApprovedForDryRun(2507));
        self::assertTrue($this->dbco->isApprovedForDryRun(2517));
        self::assertFalse($this->dbco->isApprovedForDryRun(2519));
    }

    public function test_currency_from_pricelist_no_silent_aud_fallback(): void
    {
        $record = LegacyPriceRecord::fromLegacyRow([
            'my_row_id' => 1,
            'code' => 'BVA-PAXD/1C4BK',
            'dbco' => 9999,
            'cost' => '1.50',
            'dc' => '2026-07-28',
        ]);
        $identity = [
            'identityType' => PriceImportResolution::IDENTITY_EXACT,
            'productVariantIds' => ['v1'],
            'productId' => 'p1',
            'productVariantId' => 'v1',
            'reasons' => [],
        ];
        $resolution = $this->policy->evaluate($record, $this->dbco, $identity);
        self::assertSame(PriceImportResolution::BLOCKED, $resolution->readiness);
        self::assertNull($resolution->currencyCode);
        self::assertTrue(in_array('Unknown dbco — no PriceList mapping.', $resolution->reasons, true));
    }

    public function test_history_utc_normalization_and_policy(): void
    {
        self::assertSame(LegacyPriceImportPolicy::HISTORY_LATEST_ONLY, LegacyPriceImportPolicy::APPROVED_HISTORY_POLICY);
        self::assertSame('2026-07-28T00:00:00Z', $this->policy->utcFromLegacyDate('2026-07-28'));
        self::assertNull($this->policy->utcFromLegacyDate(null));
    }

    public function test_same_dc_identical_cost_is_safe_duplicate(): void
    {
        $decision = $this->policy->classifyDuplicateGroup([
            ['cost' => '13.846', 'br' => '10'],
            ['cost' => '13.846', 'br' => '20'],
        ]);
        self::assertSame(LegacyPriceImportPolicy::DUP_SAFE_EQUIVALENT, $decision);
    }

    public function test_same_dc_different_cost_is_ambiguous(): void
    {
        $decision = $this->policy->classifyDuplicateGroup([
            ['cost' => '13.846', 'br' => '10'],
            ['cost' => '14.000', 'br' => '20'],
        ]);
        self::assertSame(LegacyPriceImportPolicy::DUP_AMBIGUOUS, $decision);
    }

    public function test_zero_cost_is_review_negative_blocked(): void
    {
        self::assertSame(LegacyPriceImportPolicy::ZERO_REVIEW, $this->policy->zeroCostDecision('0'));
        self::assertSame(LegacyPriceImportPolicy::ZERO_DATA_ERROR, $this->policy->zeroCostDecision('-1'));

        $record = LegacyPriceRecord::fromLegacyRow([
            'my_row_id' => 2,
            'code' => 'BVA-PAXD/1C4BK',
            'dbco' => 2507,
            'cost' => '0',
            'dc' => '2026-07-28',
        ]);
        $identity = [
            'identityType' => PriceImportResolution::IDENTITY_EXACT,
            'productVariantIds' => ['v1'],
            'productId' => 'p1',
            'productVariantId' => 'v1',
            'reasons' => [],
        ];
        $resolution = $this->policy->evaluate($record, $this->dbco, $identity);
        self::assertSame(PriceImportResolution::REVIEW, $resolution->readiness);
    }

    public function test_approved_dbco_positive_cost_exact_is_ready(): void
    {
        $record = LegacyPriceRecord::fromLegacyRow([
            'my_row_id' => 3,
            'code' => 'BVA-PAXD/1C4BK',
            'dbco' => 2507,
            'cost' => '1.958',
            'dc' => '2026-07-28',
        ]);
        $identity = [
            'identityType' => PriceImportResolution::IDENTITY_EXACT,
            'productVariantIds' => ['v1'],
            'productId' => 'p1',
            'productVariantId' => 'v1',
            'reasons' => ['Exact VariantCode/Sku match.'],
        ];
        $resolution = $this->policy->evaluate($record, $this->dbco, $identity);
        self::assertSame(PriceImportResolution::READY, $resolution->readiness);
        self::assertSame('AUD', $resolution->currencyCode);
        self::assertSame('TCA-BASE', $resolution->priceListCode);
        self::assertSame('2026-07-28T00:00:00Z', $resolution->effectiveFrom);
    }

    public function test_excluded_2519_blocks_even_with_exact_identity(): void
    {
        $record = LegacyPriceRecord::fromLegacyRow([
            'my_row_id' => 4,
            'code' => 'BVA-PAXD/1C4BK',
            'dbco' => 2519,
            'cost' => '1.958',
            'dc' => '2026-07-28',
        ]);
        $identity = [
            'identityType' => PriceImportResolution::IDENTITY_EXACT,
            'productVariantIds' => ['v1'],
            'productId' => 'p1',
            'productVariantId' => 'v1',
            'reasons' => [],
        ];
        $resolution = $this->policy->evaluate($record, $this->dbco, $identity);
        self::assertSame(PriceImportResolution::BLOCKED, $resolution->readiness);
        self::assertSame('USD', $resolution->currencyCode);
    }

    public function test_safety_no_migration_write_service_invoked(): void
    {
        // Contract evaluation is pure; this test documents the Phase 13.2 hard rule.
        self::assertFalse(class_exists(\TriCab\Core\Product\Migration\Pricing\ApplyLegacyBaseCostMigrationService::class, false));
        $record = LegacyPriceRecord::fromLegacyRow([
            'my_row_id' => 5,
            'code' => 'X',
            'dbco' => 2507,
            'cost' => '1',
            'dc' => '2026-07-28',
        ]);
        $this->policy->evaluate($record, $this->dbco, [
            'identityType' => PriceImportResolution::IDENTITY_UNRESOLVED,
            'productVariantIds' => [],
            'productId' => null,
            'productVariantId' => null,
            'reasons' => [],
        ]);
        self::assertTrue(true);
    }
}
