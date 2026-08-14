<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\InitiateProduct3DPreviewUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Application\Exception\Product3DModelNotFound;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Issues a short-lived WRITE SAS for a Product3DModel preview image (WebP/JPEG/PNG).
 * Does not mutate preview columns until CompleteProduct3DPreviewUploadService succeeds.
 */
final class InitiateProduct3DPreviewUploadService
{
    public function __construct(
        private readonly Product3DModelRepository $models,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFilePathBuilder $pathBuilder,
        private readonly ProductFileValidationPolicy $validationPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, InitiateProduct3DPreviewUploadCommand $command): ProductUploadSessionResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductUploadSessionResult {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $model = $this->models->findByPublicId($command->modelPublicId);
            if ($model === null || $model->productPublicId !== $command->productPublicId) {
                throw Product3DModelNotFound::forPublicId($command->modelPublicId);
            }
            if ($model->rowVersion !== $command->expectedRowVersion) {
                throw ProductConcurrencyConflict::forPublicId($command->modelPublicId);
            }

            $validated = $this->validationPolicy->validateModel3dPreview(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            );

            // Versioned filename avoids CDN/browser cache stale previews.
            $ext = $validated['extension'];
            $versionedName = sprintf('preview-%s.%s', gmdate('YmdHis'), $ext);
            $storagePath = $this->pathBuilder->model3dPreviewPath(
                $productPublicId->toString(),
                $model->publicId,
                $versionedName,
            );

            $ticket = $this->blobStore->issueWriteUpload(
                $command->storageContainer,
                $storagePath,
                $validated['contentType'],
                $command->uploadExpiresInSeconds,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: 'preview-upload-session-created',
                newValues: [
                    'modelPublicId' => $model->publicId,
                    'fileKind' => ProductFileKind::MODEL_3D_PREVIEW,
                    'storagePath' => $storagePath,
                ],
            );

            return new ProductUploadSessionResult(
                entityPublicId: $model->publicId,
                uploadUrl: $ticket->uploadUrl,
                httpMethod: $ticket->httpMethod,
                requiredHeaders: $ticket->requiredHeaders,
                expiresAtUtc: $ticket->expiresAtUtc,
                storagePath: $storagePath,
                storageContainer: $command->storageContainer,
                fileKind: ProductFileKind::MODEL_3D_PREVIEW,
            );
        });
    }
}
