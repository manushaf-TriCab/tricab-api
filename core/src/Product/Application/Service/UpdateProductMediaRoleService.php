<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpdateProductMediaRoleCommand;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductFileException;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;
use TriCab\Core\Product\Domain\ValueObject\PresentationMediaRole;
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
            $actorUserId = $this->audit->actorUserId($actor);
            $roleCode = strtolower(trim($command->mediaCategoryCode));

            if (PresentationMediaRole::isPresentationRole($roleCode)) {
                $role = PresentationMediaRole::fromString($roleCode);
                $this->assertPublicEligibleForPresentationRole(
                    $productPublicId,
                    $command->mediaPublicId,
                );
                $this->media->assignPresentationRole(
                    $productPublicId,
                    $command->mediaPublicId,
                    $role->toString(),
                    $actorUserId,
                );

                // Keep IsPrimary synchronized with canonical primary assignment only.
                if ($role->toString() === PresentationMediaRole::PRIMARY) {
                    $this->media->setPrimary($productPublicId, $command->mediaPublicId, $actorUserId);
                }

                $this->audit->record(
                    actor: $actor,
                    productPublicId: $productPublicId,
                    entityTypeCode: 'product-media',
                    entityId: null,
                    actionCode: 'presentation-role-assigned',
                    previousValues: null,
                    newValues: [
                        'mediaPublicId' => $command->mediaPublicId,
                        'mediaCategoryCode' => $role->toString(),
                    ],
                );

                return;
            }

            $role = ImageRoleCode::fromString($roleCode);
            $this->media->updateImageRole(
                $productPublicId,
                $command->mediaPublicId,
                $role->toString(),
                $actorUserId,
            );

            if ($role->toString() === ImageRoleCode::PRIMARY) {
                $this->assertPublicEligibleForPresentationRole(
                    $productPublicId,
                    $command->mediaPublicId,
                );
                $this->media->setPrimary($productPublicId, $command->mediaPublicId, $actorUserId);
                $this->media->assignPresentationRole(
                    $productPublicId,
                    $command->mediaPublicId,
                    PresentationMediaRole::PRIMARY,
                    $actorUserId,
                );
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

    private function assertPublicEligibleForPresentationRole(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
    ): void {
        foreach ($this->media->listByProduct($productPublicId) as $item) {
            if ($item->publicId !== $mediaPublicId) {
                continue;
            }
            if (PublicEligibleProductMedia::isEligible($item)) {
                return;
            }

            throw InvalidProductFileException::for(
                'Presentation roles can only be assigned to active, publicly displayable product images.',
            );
        }

        throw InvalidProductFileException::for(
            'Presentation roles can only be assigned to active, publicly displayable product images.',
        );
    }
}
