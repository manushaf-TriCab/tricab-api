<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Entity\ProductRelationship;
use TriCab\Core\Product\Domain\Exception\InvalidProductRelationshipException;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;

final class ProductRelationshipPolicy
{
    /**
     * @param list<ProductRelationship> $existing
     */
    public function assertCanAdd(
        ProductId $productId,
        ProductId $relatedProductId,
        ProductRelationshipType $type,
        array $existing,
    ): void {
        if ($productId->equals($relatedProductId)) {
            throw InvalidProductRelationshipException::selfReference($type->toString());
        }

        foreach ($existing as $relationship) {
            if (
                $relationship->type()->equals($type)
                && $relationship->productId()->equals($productId)
                && $relationship->relatedProductId()->equals($relatedProductId)
            ) {
                throw InvalidProductRelationshipException::duplicate($type->toString());
            }
        }
    }
}
