<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\SoftDeleteProductMediaCommand;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SoftDeleteProductMediaService
{
    public function __construct(
        private readonly ProductMediaRepository $media,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, SoftDeleteProductMediaCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $actorUserId = $this->audit->actorUserId($actor);

            $this->media->archive($productPublicId, $command->mediaPublicId, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-media',
                entityId: null,
                actionCode: 'soft-deleted',
                newValues: ['mediaPublicId' => $command->mediaPublicId],
            );
        });
    }
}
