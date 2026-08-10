<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpdateProductMediaRoleCommand;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class UpdateProductMediaRoleService
{
    public function __construct(
        private readonly ProductMediaRepository $media,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, UpdateProductMediaRoleCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $role = ImageRoleCode::fromString($command->mediaCategoryCode);
            $actorUserId = $this->audit->actorUserId($actor);

            $this->media->updateImageRole(
                $productPublicId,
                $command->mediaPublicId,
                $role->toString(),
                $actorUserId,
            );

            if ($role->toString() === ImageRoleCode::PRIMARY) {
                $this->media->setPrimary($productPublicId, $command->mediaPublicId, $actorUserId);
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-media',
                entityId: null,
                actionCode: 'role-updated',
                newValues: [
                    'mediaPublicId' => $command->mediaPublicId,
                    'mediaCategoryCode' => $role->toString(),
                ],
            );
        });
    }
}
