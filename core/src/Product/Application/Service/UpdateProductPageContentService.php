<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpdateProductPageContentCommand;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductPageContentRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class UpdateProductPageContentService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductPageContentRepository $content,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, UpdateProductPageContentCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $existing = $this->products->findByPublicId($productPublicId);
            if ($existing === null) {
                throw ProductNotFound::forPublicId($command->productPublicId);
            }

            $existing->assertEditable();
            $actorUserId = $this->audit->actorUserId($actor);
            $previous = $this->content->find($productPublicId);
            $this->content->save($productPublicId, $command->content, $actorUserId, $command->expectedVersion);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-page-content',
                entityId: $existing->id()?->toInt(),
                actionCode: 'updated',
                previousValues: [
                    'showProductMarking' => $previous->showProductMarking(),
                    'coreColoursNote' => $previous->coreColoursNote(),
                    'showCoreColoursNote' => $previous->showCoreColoursNote(),
                ],
                newValues: [
                    'showProductMarking' => $command->content->showProductMarking(),
                    'coreColoursNote' => $command->content->coreColoursNote(),
                    'showCoreColoursNote' => $command->content->showCoreColoursNote(),
                ],
            );
        });
    }
}
