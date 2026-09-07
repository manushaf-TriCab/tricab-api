<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\SetPrimaryProductImageCommand;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductFileException;
use TriCab\Core\Product\Domain\ValueObject\PresentationMediaRole;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SetPrimaryProductImageService
{
    public function __construct(
        private readonly ProductMediaRepository $media,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, SetPrimaryProductImageCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $actorUserId = $this->audit->actorUserId($actor);
            $this->assertPublicEligible($productPublicId, $command->mediaPublicId);
            $this->media->setPrimary($productPublicId, $command->mediaPublicId, $actorUserId);
            $this->media->assignPresentationRole(
                $productPublicId,
                $command->mediaPublicId,
                PresentationMediaRole::PRIMARY,
                $actorUserId,
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-media',
                entityId: null,
                actionCode: 'primary-set',
                newValues: ['mediaPublicId' => $command->mediaPublicId],
            );
        });
    }

    private function assertPublicEligible(ProductPublicId $productPublicId, string $mediaPublicId): void
    {
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
