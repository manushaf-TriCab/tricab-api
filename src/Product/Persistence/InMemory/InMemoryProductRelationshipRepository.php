<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductRelationshipRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;

final class InMemoryProductRelationshipRepository implements ProductRelationshipRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listByProduct(
        ProductPublicId $productPublicId,
        ProductRelationshipType $type,
    ): array {
        return array_map(
            static fn (array $row): array => [
                'relatedProductPublicId' => AzureSqlValueMapper::stringValue($row['relatedProductPublicId'] ?? ''),
                'relatedProductName' => AzureSqlValueMapper::stringValue($row['relatedProductName'] ?? ''),
                'displayOrder' => AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
                'accessoryRequirementCode' => AzureSqlValueMapper::nullableString($row['accessoryRequirementCode'] ?? null),
                'notes' => AzureSqlValueMapper::nullableString($row['notes'] ?? null),
            ],
            $this->store->relationships($productPublicId->toString(), $type->toString()),
        );
    }

    public function replace(
        ProductPublicId $productPublicId,
        ProductRelationshipType $type,
        array $items,
        UserId $actorUserId,
    ): void {
        foreach ($items as $item) {
            if ($item['relatedProductPublicId'] === $productPublicId->toString()) {
                throw new \RuntimeException('A product cannot be related to itself.');
            }
        }

        $this->store->setRelationships($productPublicId->toString(), $type->toString(), $items);
    }
}
