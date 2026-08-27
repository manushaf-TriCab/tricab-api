<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * In-memory pilot pricing store for Phase 13.4 unit tests (and dry-run simulation).
 */
final class InMemoryTcaPilotPricingStore
{
    /** @var array<string, array<string, mixed>> keyed by publicId */
    private array $rows = [];

    /** @var list<string> */
    private array $writeLog = [];

    private bool $failAfterNCreates = false;
    private int $failAfter = 0;
    private int $createCount = 0;

    public function failAfterCreates(int $n): void
    {
        $this->failAfterNCreates = true;
        $this->failAfter = $n;
        $this->createCount = 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByVariantAndPriceList(string $variantId, string $priceListCode): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['deleted'] ?? false) === true) {
                continue;
            }
            if (($row['isActive'] ?? false) !== true) {
                continue;
            }
            if ($row['productVariantId'] === $variantId && $row['priceListCode'] === $priceListCode) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function create(array $row): array
    {
        if ($this->failAfterNCreates) {
            ++$this->createCount;
            if ($this->createCount > $this->failAfter) {
                throw new \RuntimeException('SIMULATED_WRITE_FAILURE');
            }
        }
        $publicId = (string) ($row['publicId'] ?? ('pvp-' . count($this->rows) + 1));
        $stored = array_merge($row, [
            'publicId' => $publicId,
            'deleted' => false,
            'isActive' => true,
            'createdAtUtc' => $row['createdAtUtc'] ?? gmdate('c'),
            'updatedAtUtc' => $row['updatedAtUtc'] ?? gmdate('c'),
            'manuallyModified' => false,
        ]);
        $this->rows[$publicId] = $stored;
        $this->writeLog[] = 'CREATE:' . $publicId;

        return $stored;
    }

    public function softDelete(string $publicId): void
    {
        if (!isset($this->rows[$publicId])) {
            return;
        }
        $this->rows[$publicId]['deleted'] = true;
        $this->rows[$publicId]['isActive'] = false;
        $this->writeLog[] = 'SOFT_DELETE:' . $publicId;
    }

    public function markManualEdit(string $publicId, string $newCost): void
    {
        if (!isset($this->rows[$publicId])) {
            return;
        }
        $this->rows[$publicId]['baseCost'] = $newCost;
        $this->rows[$publicId]['manuallyModified'] = true;
        // Keep migration ownership marker so rollback can detect and protect the row.
        $this->rows[$publicId]['updatedAtUtc'] = gmdate('c');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByBatch(string $batchId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if (Phase134MigrationOwnership::isBatchOwned($row['erpSourceReference'] ?? null, $batchId)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allActive(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $r): bool => ($r['deleted'] ?? false) !== true && ($r['isActive'] ?? false) === true,
        ));
    }

    /** @return list<string> */
    public function writeLog(): array
    {
        return $this->writeLog;
    }

    public function clearWriteLog(): void
    {
        $this->writeLog = [];
    }
}
