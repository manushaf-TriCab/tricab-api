<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpdateProductThumbnailPresentationCommand;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Contract\ProductThumbnailPresentationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class UpdateProductThumbnailPresentationService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductThumbnailPresentationRepository $presentation,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, UpdateProductThumbnailPresentationCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $existing = $this->products->findByPublicId($productPublicId);
            if ($existing === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }

            $existing->assertEditable();
            $actorUserId = $this->audit->actorUserId($actor);

            $this->presentation->updateThumbnailBackgroundColor(
                $productPublicId,
                $command->thumbnailBackgroundColor,
                $actorUserId,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-thumbnail-presentation',
                entityId: $existing->id()?->toInt(),
                actionCode: 'updated',
                newValues: [
                    'thumbnailBackgroundColor' => $command->thumbnailBackgroundColor?->toString(),
                ],
            );
        });
    }
}
