<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\DeleteProductMediaFileCommand;
use TriCab\Core\Product\Application\Exception\ProductMediaNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class DeleteProductMediaFileService
{
    public function __construct(
        private readonly ProductMediaRepository $media,
        private readonly ProductBlobStore $blobStore,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, DeleteProductMediaFileCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $record = $this->media->findByPublicId($command->mediaPublicId);
            if ($record === null) {
                throw ProductMediaNotFound::forPublicId($command->mediaPublicId);
            }

            if ($record->storageContainer !== null && $record->storagePath !== null) {
                try {
                    $this->blobStore->deleteObject($record->storageContainer, $record->storagePath);
                } catch (\Throwable) {
                    // best effort
                }
            }

            if ($record->pendingStorageContainer !== null && $record->pendingStoragePath !== null) {
                try {
                    $this->blobStore->deleteObject($record->pendingStorageContainer, $record->pendingStoragePath);
                } catch (\Throwable) {
                    // best effort
                }
            }

            $actorUserId = $this->audit->actorUserId($actor);
            $this->media->clearFile($record->publicId, $actorUserId);

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($record->productPublicId),
                entityTypeCode: 'product-media',
                entityId: null,
                actionCode: 'file-deleted',
                previousValues: [
                    'mediaPublicId' => $record->publicId,
                    'storagePath' => $record->storagePath,
                    'publicUrl' => $record->publicUrl,
                ],
            );
        });
    }
}
