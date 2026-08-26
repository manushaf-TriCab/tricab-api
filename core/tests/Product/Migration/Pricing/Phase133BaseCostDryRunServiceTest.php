<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Pricing\BuildLegacyBaseCostDryRunService;
use TriCab\Core\Product\Migration\Pricing\LegacyPriceImportPolicy;
use TriCab\Core\Product\Migration\Pricing\Phase133BaseCostDryRunCliGuard;
use TriCab\Core\Product\Migration\Pricing\Phase133BaseCostDryRunResolution;
use TriCab\Core\Product\Migration\Pricing\Phase133DryRunCatalogueSnapshot;
use TriCab\Core\Product\Migration\Pricing\PriceImportResolution;

final class Phase133BaseCostDryRunServiceTest extends TestCase
{
    private BuildLegacyBaseCostDryRunService $service;

    protected function setUp(): void
    {
        $this->service = new BuildLegacyBaseCostDryRunService();
    }

    public function test_scope_includes_2507_2517_excludes_2519(): void
    {
        $snapshot = new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [
                $this->row('1', 'AAA-PAXD/1C1BK', '1.00', '2026-07-28', 2507),
                $this->row('2', 'AAA-PAXD/1C1BK', '2.00', '2026-07-28', 2517),
                $this->row('3', 'AAA-PAXD/1C1BK', '3.00', '2026-07-28', 2519),
            ],
            variants: [
                ['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'AAA-PAXD/1C1BK', 'sku' => ''],
            ],
        );
        $result = $this->service->execute($snapshot);
        self::assertSame(1, $result['summary']['source']['sourceRowsDbco2507']);
        self::assertSame(1, $result['summary']['source']['sourceRowsDbco2517']);
        self::assertSame(1, $result['summary']['source']['sourceRowsExcluded2519']);
        $dbcos = array_unique(array_map(static fn ($r) => $r->dbco, $result['resolutions']));
        self::assertNotContains(2519, $dbcos);
    }

    public function test_latest_only_and_safe_duplicate_collapse(): void
    {
        $snapshot = new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [
                $this->row('1', 'BBB-PAXD/1C1BK', '1.00', '2026-07-01', 2507),
                $this->row('2', 'BBB-PAXD/1C1BK', '5.00', '2026-07-28', 2507, '10'),
                $this->row('3', 'BBB-PAXD/1C1BK', '5.00', '2026-07-28', 2507, '20'),
            ],
            variants: [
                ['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'BBB-PAXD/1C1BK', 'sku' => ''],
            ],
        );
        $result = $this->service->execute($snapshot);
        $ready = array_values(array_filter(
            $result['resolutions'],
            static fn ($r) => $r->readiness === Phase133BaseCostDryRunResolution::READY,
        ));
        self::assertCount(1, $ready);
        self::assertSame('5.00', $ready[0]->baseCost);
        self::assertSame(LegacyPriceImportPolicy::DUP_SAFE_EQUIVALENT, $ready[0]->duplicateDecision);
        self::assertGreaterThanOrEqual(1, $result['summary']['source']['collapsedSourceRows']);
    }

    public function test_differing_same_date_cost_blocks(): void
    {
        $snapshot = new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [
                $this->row('1', 'CCC-PAXD/1C1BK', '1.00', '2026-07-28', 2507),
                $this->row('2', 'CCC-PAXD/1C1BK', '9.00', '2026-07-28', 2507),
            ],
            variants: [
                ['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'CCC-PAXD/1C1BK', 'sku' => ''],
            ],
        );
        $result = $this->service->execute($snapshot);
        $blocked = array_values(array_filter(
            $result['resolutions'],
            static fn ($r) => in_array('DIFFERING_SAME_DATE_COST', $r->reasons, true),
        ));
        self::assertNotEmpty($blocked);
        self::assertSame(Phase133BaseCostDryRunResolution::BLOCKED, $blocked[0]->readiness);
    }

    public function test_exact_sku_and_multi_hit(): void
    {
        $ok = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'SKU-1', '2.5', '2026-07-28', 2507)],
            variants: [
                ['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'OTHER', 'sku' => 'SKU-1'],
            ],
        ));
        self::assertSame(PriceImportResolution::IDENTITY_EXACT, $ok['resolutions'][0]->identityType);

        $amb = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'DUP', '2.5', '2026-07-28', 2507)],
            variants: [
                ['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'DUP', 'sku' => ''],
                ['productId' => 'p2', 'productVariantId' => 'v2', 'variantCode' => 'DUP', 'sku' => ''],
            ],
        ));
        self::assertSame(PriceImportResolution::IDENTITY_AMBIGUOUS, $amb['resolutions'][0]->identityType);
        self::assertSame(Phase133BaseCostDryRunResolution::BLOCKED, $amb['resolutions'][0]->readiness);
    }

    public function test_explicit_and_mask_and_exact_shadows_mask(): void
    {
        $explicit = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'LEGACY-X', '3.00', '2026-07-28', 2507)],
            variants: [
                ['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'NOMATCH', 'sku' => ''],
            ],
            explicitLegacyCodeByVariantId: ['v1' => 'LEGACY-X'],
        ));
        self::assertSame(PriceImportResolution::IDENTITY_EXPLICIT, $explicit['resolutions'][0]->identityType);

        $mask = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'RRA-XXXD/10A16BK', '4.00', '2026-07-28', 2507)],
            variants: [
                ['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'RRA-CAXD/10A16BK', 'sku' => ''],
                ['productId' => 'p1', 'productVariantId' => 'v2', 'variantCode' => 'RRA-CBXD/10A16BK', 'sku' => ''],
            ],
        ));
        $maskReady = array_filter($mask['resolutions'], static fn ($r) => $r->identityType === PriceImportResolution::IDENTITY_MASK && $r->readiness === Phase133BaseCostDryRunResolution::READY);
        self::assertCount(2, $maskReady);

        $shadow = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [
                $this->row('1', 'RRA-CAXD/10A16BK', '4.00', '2026-07-28', 2507),
                $this->row('2', 'RRA-XXXD/10A16BK', '9.00', '2026-07-28', 2507),
            ],
            variants: [
                ['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'RRA-CAXD/10A16BK', 'sku' => ''],
            ],
        ));
        $shadowed = array_values(array_filter(
            $shadow['resolutions'],
            static fn ($r) => $r->precedenceDecision === 'MASK_SHADOWED_BY_EXACT',
        ));
        self::assertNotEmpty($shadowed);
        self::assertSame(Phase133BaseCostDryRunResolution::SKIPPED, $shadowed[0]->readiness);
        $exactReady = array_values(array_filter(
            $shadow['resolutions'],
            static fn ($r) => $r->identityType === PriceImportResolution::IDENTITY_EXACT && $r->readiness === Phase133BaseCostDryRunResolution::READY,
        ));
        self::assertCount(1, $exactReady);
        self::assertSame('4.00', $exactReady[0]->baseCost);
    }

    public function test_cost_and_pricelist_rules(): void
    {
        $zero = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'Z-PAXD/1C1BK', '0', '2026-07-28', 2507)],
            variants: [['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'Z-PAXD/1C1BK', 'sku' => '']],
        ));
        self::assertSame(Phase133BaseCostDryRunResolution::REVIEW, $zero['resolutions'][0]->readiness);

        $neg = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'N-PAXD/1C1BK', '-1', '2026-07-28', 2507)],
            variants: [['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'N-PAXD/1C1BK', 'sku' => '']],
        ));
        self::assertSame(Phase133BaseCostDryRunResolution::BLOCKED, $neg['resolutions'][0]->readiness);

        $ok = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [
                $this->row('1', 'A-PAXD/1C1BK', '1.5', '2026-07-28', 2507),
                $this->row('2', 'A-PAXD/1C1BK', '1.5', '2026-07-28', 2517),
            ],
            variants: [['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'A-PAXD/1C1BK', 'sku' => '']],
        ));
        $byList = [];
        foreach ($ok['resolutions'] as $r) {
            if ($r->readiness === Phase133BaseCostDryRunResolution::READY) {
                $byList[$r->priceListCode] = $r->currencyCode;
            }
        }
        self::assertSame('AUD', $byList['TCA-BASE']);
        self::assertSame('USD', $byList['TCUS-BASE']);
    }

    public function test_existing_target_comparison(): void
    {
        $create = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'E-PAXD/1C1BK', '1.5', '2026-07-28', 2507)],
            variants: [['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'E-PAXD/1C1BK', 'sku' => '']],
        ));
        self::assertSame(Phase133BaseCostDryRunResolution::EXISTING_CREATE, $create['resolutions'][0]->existingTargetDecision);

        $same = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'E-PAXD/1C1BK', '1.5', '2026-07-28', 2507)],
            variants: [['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'E-PAXD/1C1BK', 'sku' => '']],
            existingPrices: [
                'v1|TCA-BASE' => [
                    'priceListCode' => 'TCA-BASE',
                    'currencyCode' => 'AUD',
                    'baseCost' => '1.5',
                    'effectiveFrom' => '2026-07-28T00:00:00Z',
                    'source' => 'legacy-tc',
                ],
            ],
        ));
        self::assertSame(Phase133BaseCostDryRunResolution::EXISTING_NO_CHANGE, $same['resolutions'][0]->existingTargetDecision);

        $manual = $this->service->execute(new Phase133DryRunCatalogueSnapshot(
            legacyPriceRows: [$this->row('1', 'E-PAXD/1C1BK', '2.0', '2026-07-28', 2507)],
            variants: [['productId' => 'p1', 'productVariantId' => 'v1', 'variantCode' => 'E-PAXD/1C1BK', 'sku' => '']],
            existingPrices: [
                'v1|TCA-BASE' => [
                    'priceListCode' => 'TCA-BASE',
                    'currencyCode' => 'AUD',
                    'baseCost' => '9.0',
                    'effectiveFrom' => null,
                    'source' => 'manual',
                ],
            ],
        ));
        self::assertSame(Phase133BaseCostDryRunResolution::EXISTING_MANUAL, $manual['resolutions'][0]->existingTargetDecision);
        self::assertSame(Phase133BaseCostDryRunResolution::REVIEW, $manual['resolutions'][0]->readiness);
    }

    public function test_cli_guard_refuses_apply_flags(): void
    {
        self::assertNotNull(Phase133BaseCostDryRunCliGuard::refuseApply(['--apply']));
        self::assertNotNull(Phase133BaseCostDryRunCliGuard::refuseApply(['--write']));
        self::assertNull(Phase133BaseCostDryRunCliGuard::refuseApply(['--confirm-non-production']));
    }

    public function test_no_write_service_class_exists(): void
    {
        self::assertFalse(class_exists(\TriCab\Core\Product\Migration\Pricing\ApplyLegacyBaseCostMigrationService::class, false));
    }

    /**
     * @return array{sourceRowId: string, code: string, cost: string|null, dc: string|null, dbco: int, br?: string|null}
     */
    private function row(string $id, string $code, ?string $cost, ?string $dc, int $dbco, ?string $br = null): array
    {
        return [
            'sourceRowId' => $id,
            'code' => $code,
            'cost' => $cost,
            'dc' => $dc,
            'dbco' => $dbco,
            'br' => $br,
        ];
    }
}
