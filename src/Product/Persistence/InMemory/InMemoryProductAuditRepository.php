<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductAuditEntry;
use TriCab\Core\Product\Contract\ProductAuditRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProductAuditRepository implements ProductAuditRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId, int $limit = 50): array
    {
        $items = [];
        foreach (array_reverse($this->store->auditEvents()) as $row) {
            $rowProductPublicId = AzureSqlValueMapper::nullableString($row['productPublicId'] ?? null);
            if ($rowProductPublicId !== $productPublicId->toString()) {
                continue;
            }
            $items[] = new ProductAuditEntry(
                publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
                productPublicId: $rowProductPublicId,
                entityTypeCode: AzureSqlValueMapper::stringValue($row['entityTypeCode'] ?? ''),
                entityId: AzureSqlValueMapper::nullableInt($row['entityId'] ?? null),
                actionCode: AzureSqlValueMapper::stringValue($row['actionCode'] ?? ''),
                actorDisplayName: AzureSqlValueMapper::nullableString($row['actorDisplayName'] ?? null),
                correlationId: AzureSqlValueMapper::nullableString($row['correlationId'] ?? null),
                sourceCode: AzureSqlValueMapper::nullableString($row['sourceCode'] ?? null),
                previousValuesJson: AzureSqlValueMapper::nullableString($row['previousValuesJson'] ?? null),
                newValuesJson: AzureSqlValueMapper::nullableString($row['newValuesJson'] ?? null),
                createdAtUtc: AzureSqlValueMapper::stringValue($row['createdAtUtc'] ?? ''),
            );
            if (count($items) >= max(1, min($limit, 200))) {
                break;
            }
        }

        return $items;
    }

    public function record(
        ?ProductPublicId $productPublicId,
        string $entityTypeCode,
        ?int $entityId,
        string $actionCode,
        ?UserId $actorUserId,
        ?string $correlationId,
        ?string $sourceCode,
        ?array $previousValues,
        ?array $newValues,
    ): void {
        $this->store->addAuditEvent([
            'publicId' => $this->store->newPublicId(),
            'productPublicId' => $productPublicId?->toString(),
            'entityTypeCode' => $entityTypeCode,
            'entityId' => $entityId,
            'actionCode' => $actionCode,
            'actorDisplayName' => $actorUserId !== null ? (string) $actorUserId->toInt() : null,
            'correlationId' => $correlationId,
            'sourceCode' => $sourceCode,
            'previousValuesJson' => $previousValues !== null ? json_encode($previousValues) : null,
            'newValuesJson' => $newValues !== null ? json_encode($newValues) : null,
            'createdAtUtc' => $this->store->nowUtc(),
        ]);
    }
}
