<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;

/**
 * Future Azure SQL: ProductAccessories / RelatedProducts / ReplacementProducts /
 * ProductCrossSells / ProductUpSells
 *
 * @phpstan-type RelationshipItem array{
 *   relatedProductPublicId: string,
 *   relatedProductName: string,
 *   displayOrder: int,
 *   accessoryRequirementCode?: ?string,
 *   notes?: ?string
 * }
 */
interface ProductRelationshipRepository
{
    /**
     * @return list<RelationshipItem>
     */
    public function listByProduct(
        ProductPublicId $productPublicId,
        ProductRelationshipType $type,
    ): array;

    /**
     * @param list<RelationshipItem> $items
     */
    public function replace(
        ProductPublicId $productPublicId,
        ProductRelationshipType $type,
        array $items,
        UserId $actorUserId,
    ): void;
}
