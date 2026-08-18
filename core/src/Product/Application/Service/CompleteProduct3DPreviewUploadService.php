<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompleteProduct3DPreviewUploadCommand;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;
use TriCab\Core\Product\Application\Exception\Product3DModelNotFound;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Verifies a preview blob exists after SAS PUT and persists PreviewImage* columns.
 */
final class CompleteProduct3DPreviewUploadService
{
    public function __construct(
        private readonly Product3DModelRepository $models,
        private readonly ProductBlobStore $blobStore,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, CompleteProduct3DPreviewUploadCommand $command): Product3DModelSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product3DModelSummary {
            $model = $this->models->findByPublicId($command->modelPublicId);
            if ($model === null || $model->productPublicId !== $command->productPublicId) {
                throw Product3DModelNotFound::forPublicId($command->modelPublicId);
            }

            $head = $this->blobStore->headObject($command->storageContainer, $command->storagePath);
            if ($head === null || !$head->exists) {
                throw ProductFileUploadException::blobMissing($command->storageContainer, $command->storagePath);
            }
            if ($head->contentLength !== null && $head->contentLength !== $command->byteSize) {
                throw ProductFileUploadException::blobMismatch(sprintf(
                    'expected %d bytes, got %d',
                    $command->byteSize,
                    $head->contentLength,
                ));
            }

            $publicUrl = $this->blobStore->buildPublicUrl($command->storageContainer, $command->storagePath);
            if ($publicUrl === null || $publicUrl === '') {
                $publicUrl = '/api/v1/dev/product-files/' . rawurlencode($model->publicId);
            }

            $updated = $this->models->update(
                $model->publicId,
                [
                    'previewImagePublicUrl' => $publicUrl,
                    'previewImageStoragePath' => $command->storagePath,
                ],
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($updated->productPublicId),
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: 'generated-3d-preview-image',
                newValues: [
                    'modelPublicId' => $updated->publicId,
                    'previewImageStoragePath' => $command->storagePath,
                    'contentType' => $command->contentType,
                    'byteSize' => $command->byteSize,
                ],
            );

            return $updated;
        });
    }
}
