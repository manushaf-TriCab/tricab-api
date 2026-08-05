<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ReplaceProductRelationshipsCommand;
use TriCab\Core\Product\Application\Exception\InvalidProductRelationshipAssignment;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductRelationshipRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\Entity\ProductRelationship;
use TriCab\Core\Product\Domain\Service\ProductRelationshipPolicy;
use TriCab\Core\Product\Domain\ValueObject\AccessoryRequirementCode;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Replaces the full set of relationships of one type for a product.
 * Uses ProductRelationshipPolicy (self-reference + duplicate detection) and
 * rejects soft-deleted related products.
 */
final class ReplaceProductRelationshipsService
{
    public function __construct(
        private readonly ProductRelationshipRepository $relationships,
        private readonly ProductRepository $products,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly ProductRelationshipPolicy $policy = new ProductRelationshipPolicy(),
    ) {
    }

    public function execute(ActorContext $actor, ReplaceProductRelationshipsCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $product = $this->products->findByPublicId($productPublicId);
            if ($product === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }
            $productId = $product->id();
            if ($productId === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }

            $type = ProductRelationshipType::fromString($command->relationshipType);
            $accumulated = [];
            $repositoryItems = [];

            foreach ($command->items as $item) {
                $relatedPublicId = ProductPublicId::fromString($item['relatedProductPublicId']);
                $relatedProduct = $this->products->findByPublicId($relatedPublicId);
                if ($relatedProduct === null) {
                    throw InvalidProductRelationshipAssignment::relatedProductNotFound($item['relatedProductPublicId']);
                }
                if ($relatedProduct->isSoftDeleted()) {
                    throw InvalidProductRelationshipAssignment::relatedProductSoftDeleted($item['relatedProductPublicId']);
                }
                $relatedId = $relatedProduct->id();
                if ($relatedId === null) {
                    throw InvalidProductRelationshipAssignment::relatedProductNotFound($item['relatedProductPublicId']);
                }

                $this->policy->assertCanAdd($productId, $relatedId, $type, $accumulated);

                $accessoryRequirementCode = $item['accessoryRequirementCode'] ?? null;

                $accumulated[] = new ProductRelationship(
                    productId: $productId,
                    relatedProductId: $relatedId,
                    type: $type,
                    displayOrder: DisplayOrder::fromInt($item['displayOrder'] ?? 0),
                    accessoryRequirement: $accessoryRequirementCode !== null
                        ? AccessoryRequirementCode::fromString($accessoryRequirementCode)
                        : null,
                    notes: $item['notes'] ?? null,
                );

                $repositoryItems[] = [
                    'relatedProductPublicId' => $item['relatedProductPublicId'],
                    'relatedProductName' => $relatedProduct->productName(),
                    'displayOrder' => $item['displayOrder'] ?? 0,
                    'accessoryRequirementCode' => $accessoryRequirementCode,
                    'notes' => $item['notes'] ?? null,
                ];
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $this->relationships->replace($productPublicId, $type, $repositoryItems, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-relationship',
                entityId: null,
                actionCode: 'replaced',
                newValues: ['relationshipType' => $type->toString(), 'count' => count($command->items)],
            );
        });
    }
}
