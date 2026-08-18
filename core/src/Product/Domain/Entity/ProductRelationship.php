<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use TriCab\Core\Product\Domain\Exception\InvalidProductRelationshipException;
use TriCab\Core\Product\Domain\ValueObject\AccessoryRequirementCode;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;

final class ProductRelationship
{
    public function __construct(
        private readonly ProductId $productId,
        private readonly ProductId $relatedProductId,
        private readonly ProductRelationshipType $type,
        private readonly DisplayOrder $displayOrder,
        private readonly ?AccessoryRequirementCode $accessoryRequirement = null,
        private readonly ?string $notes = null,
    ) {
        if ($productId->equals($relatedProductId)) {
            throw InvalidProductRelationshipException::selfReference($type->toString());
        }

        if (
            $type->toString() === ProductRelationshipType::ACCESSORY
            && $accessoryRequirement === null
        ) {
            throw InvalidProductRelationshipException::for(
                'Accessory relationships require an accessory requirement code.',
            );
        }
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function relatedProductId(): ProductId
    {
        return $this->relatedProductId;
    }

    public function type(): ProductRelationshipType
    {
        return $this->type;
    }

    public function displayOrder(): DisplayOrder
    {
        return $this->displayOrder;
    }

    public function accessoryRequirement(): ?AccessoryRequirementCode
    {
        return $this->accessoryRequirement;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }
}
