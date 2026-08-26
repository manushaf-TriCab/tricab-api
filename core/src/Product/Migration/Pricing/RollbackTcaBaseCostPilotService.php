<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

final class RollbackTcaBaseCostPilotService
{
    public const SUCCESS = 'PILOT_ROLLBACK_SUCCESS';
    public const FAILED = 'PILOT_ROLLBACK_FAILED';
    public const PROTECTED = 'ROLLBACK_PROTECTED';

    public function __construct(
        private readonly InMemoryTcaPilotPricingStore $store,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $batchId): array
    {
        $owned = $this->store->listByBatch($batchId);
        $deleted = 0;
        $protected = 0;
        $details = [];

        try {
            foreach ($owned as $row) {
                if (($row['manuallyModified'] ?? false) === true
                    || !str_starts_with((string) ($row['erpSourceReference'] ?? ''), Phase134MigrationOwnership::SOURCE_PREFIX)
                ) {
                    ++$protected;
                    $details[] = [
                        'publicId' => $row['publicId'],
                        'status' => self::PROTECTED,
                    ];
                    continue;
                }
                if (($row['deleted'] ?? false) === true) {
                    continue;
                }
                $this->store->softDelete((string) $row['publicId']);
                ++$deleted;
                $details[] = [
                    'publicId' => $row['publicId'],
                    'status' => 'SOFT_DELETED',
                ];
            }

            $remainingActiveOwned = array_filter(
                $this->store->listByBatch($batchId),
                static fn (array $r): bool => ($r['deleted'] ?? false) !== true && ($r['isActive'] ?? false) === true
                    && !(($r['manuallyModified'] ?? false) === true),
            );

            $pass = count($remainingActiveOwned) === 0;

            return [
                'status' => $pass ? self::SUCCESS : self::FAILED,
                'batchId' => $batchId,
                'deleted' => $deleted,
                'protected' => $protected,
                'details' => $details,
                'reconciliation' => [
                    'activeBatchOwnedRemaining' => count($remainingActiveOwned),
                    'PASS' => $pass,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::FAILED,
                'batchId' => $batchId,
                'error' => $e->getMessage(),
            ];
        }
    }
}
