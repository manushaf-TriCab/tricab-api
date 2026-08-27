<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Pricing;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Pricing\ApplyTcaBaseCostPilotService;
use TriCab\Core\Product\Migration\Pricing\EnsureTcaPricingReferenceDataService;
use TriCab\Core\Product\Migration\Pricing\InMemoryTcaPilotPricingStore;
use TriCab\Core\Product\Migration\Pricing\Phase133BaseCostDryRunResolution;
use TriCab\Core\Product\Migration\Pricing\Phase134MigrationOwnership;
use TriCab\Core\Product\Migration\Pricing\Phase134PilotCliGuard;
use TriCab\Core\Product\Migration\Pricing\PriceImportResolution;
use TriCab\Core\Product\Migration\Pricing\RollbackTcaBaseCostPilotService;
use TriCab\Core\Product\Migration\Pricing\SelectTcaPilotCandidatesService;

final class Phase134TcaPilotApplyTest extends TestCase
{
    public function test_reference_setup_create_no_change_and_conflict(): void
    {
        $svc = new EnsureTcaPricingReferenceDataService();
        $missing = $svc->plan([
            'externalSystemPresent' => false,
            'audCurrencyPresent' => true,
            'audCurrencyActive' => true,
            'tcaBase' => null,
        ]);
        self::assertSame(EnsureTcaPricingReferenceDataService::CREATED, $missing['status']);
        self::assertContains('CREATE_PRICELIST_TCA-BASE', $missing['actions']);

        $ok = $svc->plan([
            'externalSystemPresent' => true,
            'audCurrencyPresent' => true,
            'audCurrencyActive' => true,
            'tcaBase' => [
                'currencyCode' => 'AUD',
                'isActive' => true,
                'deleted' => false,
                'dbcoMapped' => '2507',
            ],
        ]);
        self::assertSame(EnsureTcaPricingReferenceDataService::NO_CHANGE, $ok['status']);

        $conflict = $svc->plan([
            'externalSystemPresent' => true,
            'audCurrencyPresent' => true,
            'audCurrencyActive' => true,
            'tcaBase' => [
                'currencyCode' => 'USD',
                'isActive' => true,
                'deleted' => false,
                'dbcoMapped' => '2507',
            ],
        ]);
        self::assertSame(EnsureTcaPricingReferenceDataService::CONFLICT, $conflict['status']);

        $audMissing = $svc->plan([
            'externalSystemPresent' => true,
            'audCurrencyPresent' => false,
            'audCurrencyActive' => false,
            'tcaBase' => null,
        ]);
        self::assertSame(EnsureTcaPricingReferenceDataService::BLOCKED, $audMissing['status']);
    }

    public function test_candidate_selection_tca_exact_style_only(): void
    {
        $selector = new SelectTcaPilotCandidatesService();
        $resolutions = [
            $this->resolution('BVA-PAXD/1C4BK', 'v1', 'READY', 'EXACT', '1.5', 2507, 'TCA-BASE'),
            $this->resolution('BVA-PAXD/1C6BK', 'v2', 'READY', 'EXACT', '2.5', 2507, 'TCA-BASE'),
            $this->resolution('BVA-PAXD/1C10BK', 'v3', 'READY', 'MASK', '3.5', 2507, 'TCA-BASE'),
            $this->resolution('RRA-CAXD/1C4BK', 'v4', 'READY', 'EXACT', '4.5', 2507, 'TCA-BASE'),
            $this->resolution('BVA-PAXD/1C4BK', 'v5', 'READY', 'EXACT', '5.5', 2517, 'TCUS-BASE'),
            $this->resolution('BVA-XXXD/1C4BK', 'v6', 'READY', 'EXACT', '0', 2507, 'TCA-BASE'),
        ];
        $meta = [
            'v1' => ['variantCode' => 'BVA-PAXD/1C4BK'],
            'v2' => ['variantCode' => 'BVA-PAXD/1C6BK'],
            'v3' => ['variantCode' => 'BVA-PAXD/1C10BK'],
            'v4' => ['variantCode' => 'RRA-CAXD/1C4BK'],
            'v5' => ['variantCode' => 'BVA-PAXD/1C4BK'],
            'v6' => ['variantCode' => 'BVA-XXXD/1C4BK'],
        ];
        $selected = $selector->select($resolutions, $meta, 'BVA', 20);
        self::assertSame('PILOT_CANDIDATES_READY', $selected['status']);
        self::assertCount(2, $selected['candidates']);
        self::assertNull(Phase134PilotCliGuard::parseStyle([]));
        self::assertSame('BVA', Phase134PilotCliGuard::parseStyle(['--style=BVA']));
    }

    public function test_style_required_and_hard_max(): void
    {
        $selector = new SelectTcaPilotCandidatesService();
        self::assertSame(Phase134PilotCliGuard::PILOT_STYLE_REQUIRED, $selector->select([], [], '', 20)['status']);
        self::assertSame(Phase134PilotCliGuard::MAX_VARIANTS_EXCEEDED, $selector->select([], [], 'BVA', 30)['status']);
    }

    public function test_apply_create_no_change_manual_conflict_idempotent(): void
    {
        $store = new InMemoryTcaPilotPricingStore();
        $apply = new ApplyTcaBaseCostPilotService($store);
        $cands = [
            $this->resolution('BVA-PAXD/1C4BK', 'v1', 'READY', 'EXACT', '1.500000', 2507, 'TCA-BASE'),
            $this->resolution('BVA-PAXD/1C6BK', 'v2', 'READY', 'EXACT', '2.500000', 2507, 'TCA-BASE'),
        ];
        $batch = 'pricing-p13.4-tca-BVA-test1';
        $first = $apply->execute($batch, 'BVA', $cands, true);
        self::assertSame('PILOT_APPLY_SUCCESS', $first['status']);
        self::assertSame(2, $first['CREATE']);
        self::assertSame('COMMITTED', $first['transaction']);

        $second = $apply->execute($batch, 'BVA', $cands, true);
        self::assertSame('PILOT_APPLY_SUCCESS', $second['status']);
        self::assertSame(0, $second['CREATE']);
        self::assertSame(2, $second['NO_CHANGE']);

        $store->create([
            'productVariantId' => 'v3',
            'priceListCode' => 'TCA-BASE',
            'currencyCode' => 'AUD',
            'baseCost' => '9',
            'erpSourceReference' => 'source:manual',
        ]);
        $withManual = $apply->execute($batch, 'BVA', array_merge($cands, [
            $this->resolution('BVA-PAXD/1C10BK', 'v3', 'READY', 'EXACT', '3.0', 2507, 'TCA-BASE'),
        ]), true);
        self::assertSame('PILOT_APPLY_BLOCKED', $withManual['status']);
        self::assertSame(1, $withManual['MANUAL_DATA_CONFLICT']);
    }

    public function test_transaction_failure_midway_rolls_back(): void
    {
        $store = new InMemoryTcaPilotPricingStore();
        $store->failAfterCreates(1);
        $apply = new ApplyTcaBaseCostPilotService($store);
        $cands = [
            $this->resolution('BVA-A', 'v1', 'READY', 'EXACT', '1.0', 2507, 'TCA-BASE'),
            $this->resolution('BVA-B', 'v2', 'READY', 'EXACT', '2.0', 2507, 'TCA-BASE'),
        ];
        $result = $apply->execute('batch-fail', 'BVA', $cands, true);
        self::assertSame('PILOT_APPLY_FAILED', $result['status']);
        self::assertSame('ROLLED_BACK', $result['transaction']);
        self::assertCount(0, $store->allActive());
    }

    public function test_source_drift_blocks(): void
    {
        $apply = new ApplyTcaBaseCostPilotService();
        $cands = [$this->resolution('BVA-A', 'v1', 'READY', 'EXACT', '1.0', 2507, 'TCA-BASE', '2026-07-28T00:00:00Z')];
        $result = $apply->execute('b', 'BVA', $cands, true, [
            'BVA-A|2507' => ['cost' => '9.0', 'dc' => '2026-07-28'],
        ]);
        self::assertSame('PILOT_APPLY_BLOCKED', $result['status']);
        self::assertSame(1, $result['BLOCKED']);
    }

    public function test_rollback_protects_manual_and_supports_reapply(): void
    {
        $store = new InMemoryTcaPilotPricingStore();
        $apply = new ApplyTcaBaseCostPilotService($store);
        $rollback = new RollbackTcaBaseCostPilotService($store);
        $cands = [
            $this->resolution('BVA-A', 'v1', 'READY', 'EXACT', '1.0', 2507, 'TCA-BASE'),
            $this->resolution('BVA-B', 'v2', 'READY', 'EXACT', '2.0', 2507, 'TCA-BASE'),
        ];
        $batch = 'batch-rb';
        $apply->execute($batch, 'BVA', $cands, true);
        $ids = array_map(static fn ($r) => $r['publicId'], $store->allActive());
        self::assertCount(2, $ids);

        $store->markManualEdit($ids[0], '99.0');
        $rb = $rollback->execute($batch);
        self::assertSame(RollbackTcaBaseCostPilotService::SUCCESS, $rb['status']);
        self::assertSame(1, $rb['protected']);
        self::assertSame(1, $rb['deleted']);
        self::assertCount(1, $store->allActive());

        $otherBatch = $store->create([
            'productVariantId' => 'vx',
            'priceListCode' => 'TCA-BASE',
            'currencyCode' => 'AUD',
            'baseCost' => '1',
            'erpSourceReference' => Phase134MigrationOwnership::encode('other-batch', 'X', 2507, '2026-07-28', '1', ['1']),
        ]);
        $rb2 = $rollback->execute($batch);
        self::assertSame(RollbackTcaBaseCostPilotService::SUCCESS, $rb2['status']);
        self::assertNotNull($store->findActiveByVariantAndPriceList('vx', 'TCA-BASE'));

        // reapply remaining eligible
        $again = $apply->execute('batch-rb-2', 'BVA', [$cands[1]], true);
        self::assertSame('PILOT_APPLY_SUCCESS', $again['status']);
        self::assertSame(1, $again['CREATE']);
        unset($otherBatch);
    }

    public function test_cli_guard_confirmations(): void
    {
        self::assertFalse(Phase134PilotCliGuard::applyConfirmationsOk(['--apply']));
        self::assertTrue(Phase134PilotCliGuard::applyConfirmationsOk([
            '--apply', '--confirm-non-production', '--confirm-pilot',
        ]));
        self::assertTrue(Phase134PilotCliGuard::rollbackConfirmationsOk([
            '--batch=x', '--confirm-non-production', '--confirm-rollback',
        ]));
    }

    public function test_side_effect_counters_remain_zero(): void
    {
        $apply = new ApplyTcaBaseCostPilotService();
        $result = $apply->execute('b', 'BVA', [
            $this->resolution('BVA-A', 'v1', 'READY', 'EXACT', '1.0', 2507, 'TCA-BASE'),
        ], true);
        self::assertSame(0, $result['sideEffects']['ProductPrices']);
        self::assertSame(0, $result['sideEffects']['CustomerProductPrices']);
        self::assertSame(0, $result['sideEffects']['PricingTiers']);
        self::assertSame(0, $result['sideEffects']['PriceListCustomers']);
    }

    private function resolution(
        string $code,
        string $variantId,
        string $readiness,
        string $identity,
        string $cost,
        int $dbco,
        string $priceList,
        ?string $effectiveFrom = '2026-07-28T00:00:00Z',
    ): Phase133BaseCostDryRunResolution {
        return new Phase133BaseCostDryRunResolution(
            sourceRowIds: ['1'],
            legacyCode: $code,
            normalizedCode: $code,
            dbco: $dbco,
            identityType: $identity,
            productId: 'p1',
            productVariantId: $variantId,
            productVariantIds: [$variantId],
            priceListCode: $priceList,
            priceListId: null,
            referenceResolution: Phase133BaseCostDryRunResolution::REF_CONTRACT_ONLY,
            currencyCode: $dbco === 2507 ? 'AUD' : 'USD',
            currencyId: null,
            baseCost: $cost,
            effectiveFrom: $effectiveFrom,
            effectiveTo: null,
            duplicateDecision: 'SAFE_EQUIVALENT_DUPLICATE',
            precedenceDecision: 'NONE',
            collisionDecision: 'NONE',
            existingTargetDecision: Phase133BaseCostDryRunResolution::EXISTING_CREATE,
            zeroCostDecision: 'VALID_POSITIVE_COST',
            historyDecision: 'LATEST_ONLY',
            readiness: $readiness,
            reasons: [],
            trace: [],
        );
    }
}
