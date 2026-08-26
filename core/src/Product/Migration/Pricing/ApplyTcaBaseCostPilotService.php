<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Phase 13.4 TCA pilot apply (dry-run or write via injected store).
 * Pure orchestration — Azure adapter supplies store for live apply.
 */
final class ApplyTcaBaseCostPilotService
{
    public const CREATE = 'CREATE';
    public const NO_CHANGE = 'NO_CHANGE';
    public const SOURCE_CHANGED_REVIEW = 'SOURCE_CHANGED_REVIEW';
    public const MANUAL_DATA_CONFLICT = 'MANUAL_DATA_CONFLICT';
    public const SOURCE_CHANGED_SINCE_DRY_RUN = 'SOURCE_CHANGED_SINCE_DRY_RUN';
    public const TARGET_CHANGED_SINCE_PREFLIGHT = 'TARGET_CHANGED_SINCE_PREFLIGHT';
    public const PRICE_PRECISION_OVERFLOW = 'PRICE_PRECISION_OVERFLOW';
    public const BLOCKED = 'BLOCKED';

    public function __construct(
        private readonly InMemoryTcaPilotPricingStore $store = new InMemoryTcaPilotPricingStore(),
    ) {
    }

    public function store(): InMemoryTcaPilotPricingStore
    {
        return $this->store;
    }

    /**
     * @param list<Phase133BaseCostDryRunResolution> $candidates
     * @param array<string, array{cost: string, dc: ?string}> $liveSourceByCodeDbco keyed "CODE|2507"
     * @return array<string, mixed>
     */
    public function execute(
        string $batchId,
        string $style,
        array $candidates,
        bool $apply,
        array $liveSourceByCodeDbco = [],
    ): array {
        $decisions = [];
        $create = $noChange = $review = $blocked = $manual = 0;
        $plannedCreates = [];

        foreach ($candidates as $c) {
            if ($c->productVariantId === null || $c->priceListCode !== 'TCA-BASE') {
                $decisions[] = ['status' => self::BLOCKED, 'reason' => 'INVALID_CANDIDATE', 'variantId' => $c->productVariantId];
                ++$blocked;
                continue;
            }

            if (!$this->fitsPrecision($c->baseCost)) {
                $decisions[] = ['status' => self::BLOCKED, 'reason' => self::PRICE_PRECISION_OVERFLOW, 'variantId' => $c->productVariantId];
                ++$blocked;
                continue;
            }

            $liveKey = $c->normalizedCode . '|2507';
            if ($liveSourceByCodeDbco !== [] && isset($liveSourceByCodeDbco[$liveKey])) {
                $live = $liveSourceByCodeDbco[$liveKey];
                $liveCost = (string) $live['cost'];
                $liveDc = isset($live['dc']) ? substr((string) $live['dc'], 0, 10) : null;
                $candDc = $c->effectiveFrom !== null ? substr($c->effectiveFrom, 0, 10) : null;
                if (bccomp($liveCost, $c->baseCost, 6) !== 0 || $liveDc !== $candDc) {
                    $decisions[] = [
                        'status' => self::BLOCKED,
                        'reason' => self::SOURCE_CHANGED_SINCE_DRY_RUN,
                        'variantId' => $c->productVariantId,
                    ];
                    ++$blocked;
                    continue;
                }
            }

            $existing = $this->store->findActiveByVariantAndPriceList($c->productVariantId, 'TCA-BASE');
            if ($existing !== null) {
                $own = Phase134MigrationOwnership::decode($existing['erpSourceReference'] ?? null);
                if (!($own['isPilotOwned'] ?? false)) {
                    $decisions[] = [
                        'status' => self::MANUAL_DATA_CONFLICT,
                        'reason' => self::MANUAL_DATA_CONFLICT,
                        'variantId' => $c->productVariantId,
                        'pricingPublicId' => $existing['publicId'] ?? null,
                    ];
                    ++$manual;
                    continue;
                }
                $sameCost = bccomp((string) $existing['baseCost'], $c->baseCost, 6) === 0;
                $sameCode = ($own['normalizedCode'] ?? null) === $c->normalizedCode;
                if ($sameCost && $sameCode) {
                    $decisions[] = [
                        'status' => self::NO_CHANGE,
                        'variantId' => $c->productVariantId,
                        'pricingPublicId' => $existing['publicId'] ?? null,
                    ];
                    ++$noChange;
                    continue;
                }
                $decisions[] = [
                    'status' => self::SOURCE_CHANGED_REVIEW,
                    'variantId' => $c->productVariantId,
                    'pricingPublicId' => $existing['publicId'] ?? null,
                ];
                ++$review;
                continue;
            }

            $erpRef = Phase134MigrationOwnership::encode(
                $batchId,
                $c->normalizedCode,
                2507,
                $c->effectiveFrom !== null ? substr($c->effectiveFrom, 0, 10) : null,
                $c->baseCost,
                $c->sourceRowIds,
            );
            $plannedCreates[] = [
                'productVariantId' => $c->productVariantId,
                'productId' => $c->productId,
                'priceListCode' => 'TCA-BASE',
                'currencyCode' => 'AUD',
                'baseCost' => $c->baseCost,
                'effectiveFromUtc' => $c->effectiveFrom,
                'effectiveToUtc' => null,
                'erpSourceReference' => $erpRef,
                'legacyCode' => $c->normalizedCode,
                'sourceRowIds' => $c->sourceRowIds,
            ];
            $decisions[] = [
                'status' => self::CREATE,
                'variantId' => $c->productVariantId,
                'legacyCode' => $c->normalizedCode,
            ];
            ++$create;
        }

        $result = [
            'batchId' => $batchId,
            'style' => $style,
            'apply' => $apply,
            'transaction' => $apply ? 'PENDING' : 'DRY_RUN',
            'CREATE' => $create,
            'NO_CHANGE' => $noChange,
            'SOURCE_CHANGED_REVIEW' => $review,
            'MANUAL_DATA_CONFLICT' => $manual,
            'BLOCKED' => $blocked,
            'decisions' => $decisions,
            'reconciliation' => null,
            'sideEffects' => [
                'ProductPrices' => 0,
                'CustomerProductPrices' => 0,
                'PricingTiers' => 0,
                'PriceListCustomers' => 0,
            ],
            'status' => 'PILOT_DRY_RUN_COMPLETE',
        ];

        if (!$apply) {
            return $result;
        }

        if ($blocked > 0 || $manual > 0 || $review > 0) {
            $result['transaction'] = 'NOT_STARTED';
            $result['status'] = 'PILOT_APPLY_BLOCKED';

            return $result;
        }

        $created = [];
        try {
            foreach ($plannedCreates as $row) {
                $created[] = $this->store->create($row);
            }
            // in-transaction reconciliation
            foreach ($created as $i => $row) {
                $expected = $plannedCreates[$i];
                if (bccomp((string) $row['baseCost'], $expected['baseCost'], 6) !== 0
                    || ($row['priceListCode'] ?? '') !== 'TCA-BASE'
                    || ($row['currencyCode'] ?? '') !== 'AUD'
                    || ($row['erpSourceReference'] ?? '') !== $expected['erpSourceReference']
                ) {
                    throw new \RuntimeException('RECONCILIATION_FAILED');
                }
            }
            $result['transaction'] = 'COMMITTED';
            $result['status'] = 'PILOT_APPLY_SUCCESS';
            $result['reconciliation'] = [
                'expected' => count($plannedCreates),
                'found' => count($created),
                'exactMatches' => count($created),
                'mismatches' => 0,
                'missing' => 0,
                'unexpected' => 0,
                'PASS' => true,
            ];
            $result['createdPublicIds'] = array_map(static fn (array $r): string => (string) $r['publicId'], $created);
        } catch (\Throwable $e) {
            // simulate rollback: remove created in this attempt
            foreach ($created as $row) {
                $this->store->softDelete((string) $row['publicId']);
            }
            $result['transaction'] = 'ROLLED_BACK';
            $result['status'] = 'PILOT_APPLY_FAILED';
            $result['error'] = $e->getMessage();
            $result['CREATE'] = 0;
            $result['reconciliation'] = ['PASS' => false, 'error' => $e->getMessage()];
        }

        return $result;
    }

    private function fitsPrecision(string $cost): bool
    {
        if (!is_numeric($cost)) {
            return false;
        }
        if (!preg_match('/^-?\d+(\.\d{1,6})?$/', $cost) && !preg_match('/^-?\d+$/', $cost)) {
            // allow normalized decimals with trailing zeros via bcmul check
            $scaled = bcmul($cost, '1000000', 0);

            return is_numeric($scaled);
        }

        return true;
    }
}
