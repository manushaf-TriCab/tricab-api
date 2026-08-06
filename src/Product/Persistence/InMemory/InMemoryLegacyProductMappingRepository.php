<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Product\Application\DTO\LegacyProductMappingDetails;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;

final class InMemoryLegacyProductMappingRepository implements LegacyProductMappingRepository
{
    private int $nextLegacyMappingId = 1;

    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findBySource(string $sourceSystemCode, string $sourceProductKey): ?LegacyProductMappingDetails
    {
        $key = $this->store->legacyMappingKey($sourceSystemCode, $sourceProductKey);
        $row = $this->store->legacyMappings()[$key] ?? null;

        return $row === null ? null : $this->toDetails($row);
    }

    public function upsert(array $data): LegacyProductMappingDetails
    {
        $key = $this->store->legacyMappingKey($data['sourceSystemCode'], $data['sourceProductKey']);
        $existing = $this->store->legacyMappings()[$key] ?? null;
        $now = $this->store->nowUtc();

        $row = [
            'legacyProductMappingId' => $existing['legacyProductMappingId'] ?? $this->nextLegacyMappingId++,
            'sourceSystemCode' => $data['sourceSystemCode'],
            'sourceProductKey' => $data['sourceProductKey'],
            'productPublicId' => $data['productPublicId'] ?? null,
            'productVariantPublicId' => $data['productVariantPublicId'] ?? null,
            'migrationStatusCode' => $data['migrationStatusCode'],
            'migrationNotes' => $data['migrationNotes'] ?? null,
            'createdAtUtc' => $existing['createdAtUtc'] ?? $now,
            'updatedAtUtc' => $now,
            'migratedAtUtc' => in_array($data['migrationStatusCode'], ['imported', 'updated'], true) ? $now : ($existing['migratedAtUtc'] ?? null),
        ];
        $this->store->setLegacyMapping($key, $row);

        return $this->toDetails($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDetails(array $row): LegacyProductMappingDetails
    {
        return new LegacyProductMappingDetails(
            legacyProductMappingId: AzureSqlValueMapper::intValue($row['legacyProductMappingId'] ?? 0),
            sourceSystemCode: AzureSqlValueMapper::stringValue($row['sourceSystemCode'] ?? ''),
            sourceProductKey: AzureSqlValueMapper::stringValue($row['sourceProductKey'] ?? ''),
            productPublicId: AzureSqlValueMapper::nullableString($row['productPublicId'] ?? null),
            productVariantPublicId: AzureSqlValueMapper::nullableString($row['productVariantPublicId'] ?? null),
            migrationStatusCode: AzureSqlValueMapper::stringValue($row['migrationStatusCode'] ?? ''),
            migrationNotes: AzureSqlValueMapper::nullableString($row['migrationNotes'] ?? null),
            createdAtUtc: AzureSqlValueMapper::stringValue($row['createdAtUtc'] ?? ''),
            updatedAtUtc: AzureSqlValueMapper::stringValue($row['updatedAtUtc'] ?? ''),
            migratedAtUtc: AzureSqlValueMapper::nullableString($row['migratedAtUtc'] ?? null),
        );
    }
}
