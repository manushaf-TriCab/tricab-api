<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpdateProductPageMediaLayoutCommand;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductPageMediaLayoutRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class UpdateProductPageMediaLayoutService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductPageMediaLayoutRepository $layouts,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, UpdateProductPageMediaLayoutCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $existing = $this->products->findByPublicId($productPublicId);
            if ($existing === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }

            $existing->assertEditable();
            $actorUserId = $this->audit->actorUserId($actor);
            $this->layouts->save($productPublicId, $command->presentation, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-page-media-layout',
                entityId: $existing->id()?->toInt(),
                actionCode: 'updated',
                newValues: [
                    'descriptionImageWidth' => $command->presentation->width(),
                    'descriptionImageAlignment' => $command->presentation->alignment(),
                ],
            );
        });
    }
}
