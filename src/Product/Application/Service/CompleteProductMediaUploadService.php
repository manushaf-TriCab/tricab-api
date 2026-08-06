<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompleteProductMediaUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductMediaFileRecord;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Application\Exception\ProductMediaNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFileScanPolicy;
use TriCab\Core\Product\Contract\ProductImageMetadataExtractor;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class CompleteProductMediaUploadService
{
    public function __construct(
        private readonly ProductMediaRepository $media,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFileScanPolicy $scanPolicy,
        private readonly ProductImageMetadataExtractor $imageMetadata,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, CompleteProductMediaUploadCommand $command): ProductMediaFileRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductMediaFileRecord {
            $record = $this->media->findByPublicId($command->mediaPublicId);
            if ($record === null) {
                throw ProductMediaNotFound::forPublicId($command->mediaPublicId);
            }

            $container = $record->pendingStorageContainer ?? $record->storageContainer;
            $path = $record->pendingStoragePath ?? $record->storagePath;
            if ($container === null || $path === null) {
                throw ProductFileUploadException::invalidState('Media upload session is missing storage location.');
            }

            $head = $this->blobStore->headObject($container, $path);
            if (($head === null || !$head->exists) && $command->binaryContent !== null && $command->binaryContent !== '') {
                // Dev/InMemory path: browser cannot PUT to memory:// URLs; accept verified bytes once.
                $this->blobStore->putObject(
                    $container,
                    $path,
                    $record->contentType ?? 'application/octet-stream',
                    $command->binaryContent,
                );
                $head = $this->blobStore->headObject($container, $path);
            }
            if ($head === null || !$head->exists) {
                throw ProductFileUploadException::blobMissing($container, $path);
            }

            if ($record->byteSize !== null && $head->contentLength !== null && $head->contentLength !== $record->byteSize) {
                throw ProductFileUploadException::blobMismatch(sprintf(
                    'expected %d bytes, got %d',
                    $record->byteSize,
                    $head->contentLength,
                ));
            }

            if (
                $record->contentType !== null
                && $head->contentType !== null
                && strtolower($head->contentType) !== strtolower($record->contentType)
            ) {
                throw ProductFileUploadException::blobMismatch(sprintf(
                    'expected content-type %s, got %s',
                    $record->contentType,
                    $head->contentType,
                ));
            }

            $scanStatus = $this->scanPolicy->resolveAfterUpload();
            $dims = ['widthPx' => null, 'heightPx' => null];
            if ($command->binaryContent !== null && $command->binaryContent !== '') {
                $dims = $this->imageMetadata->extractFromBinary($command->binaryContent);
            }

            $publicUrl = $this->blobStore->buildPublicUrl($container, $path);
            $actorUserId = $this->audit->actorUserId($actor);

            $previousPath = $record->previousStoragePath;
            $previousContainer = $record->storageContainer;

            $completed = $this->media->markUploadCompleted(
                $record->publicId,
                [
                    'uploadStatusCode' => UploadStatusCode::ACTIVE,
                    'scanStatusCode' => $scanStatus->toString(),
                    'publicUrl' => $publicUrl,
                    'contentType' => $head->contentType ?? $record->contentType,
                    'byteSize' => $head->contentLength ?? $record->byteSize,
                    'etag' => $head->etag,
                    'widthPx' => $dims['widthPx'],
                    'heightPx' => $dims['heightPx'],
                    'storagePath' => $path,
                    'storageContainer' => $container,
                ],
                $actorUserId,
            );

            if ($previousPath !== null && $previousContainer !== null && $previousPath !== $path) {
                try {
                    $this->blobStore->deleteObject($previousContainer, $previousPath);
                } catch (\Throwable) {
                    // best effort
                }
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($completed->productPublicId),
                entityTypeCode: 'product-media',
                entityId: null,
                actionCode: 'upload-completed',
                newValues: [
                    'mediaPublicId' => $completed->publicId,
                    'uploadStatusCode' => $completed->uploadStatusCode,
                    'scanStatusCode' => $completed->scanStatusCode,
                    'byteSize' => $completed->byteSize,
                    'contentType' => $completed->contentType,
                ],
            );

            return $completed;
        });
    }
}
