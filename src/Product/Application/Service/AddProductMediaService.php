<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AddProductMediaCommand;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;
use TriCab\Core\Product\Domain\ValueObject\MediaStoragePath;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class AddProductMediaService
{
    public function __construct(
        private readonly ProductMediaRepository $media,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, AddProductMediaCommand $command): ProductMediaSummary
    {
        return $this->transaction->run(function () use ($actor, $command): ProductMediaSummary {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $imageRole = ImageRoleCode::fromString($command->mediaCategoryCode);
            $storagePath = MediaStoragePath::fromString($command->storagePath);
            $actorUserId = $this->audit->actorUserId($actor);

            $created = $this->media->create(
                $productPublicId,
                [
                    'mediaCategoryCode' => $imageRole->toString(),
                    'mediaTypeCode' => $command->mediaTypeCode,
                    'title' => $command->title,
                    'storagePath' => $storagePath->toString(),
                    'storageContainer' => $command->storageContainer,
                    'contentType' => $command->contentType,
                    'fileExtension' => $command->fileExtension,
                    'byteSize' => $command->byteSize,
                    'altText' => $command->altText,
                    'caption' => $command->caption,
                    'displayOrder' => $command->displayOrder,
                    'isPrimary' => $command->isPrimary,
                    'publicUrl' => $command->publicUrl,
                ],
                $actorUserId,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-media',
                entityId: null,
                actionCode: 'created',
                newValues: ['title' => $created->title, 'mediaTypeCode' => $created->mediaTypeCode],
            );

            return $created;
        });
    }
}
