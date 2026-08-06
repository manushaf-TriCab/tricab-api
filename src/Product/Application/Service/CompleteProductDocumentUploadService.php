<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompleteProductDocumentUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductDocumentFileRecord;
use TriCab\Core\Product\Application\Exception\ProductDocumentNotFound;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Contract\ProductFileScanPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class CompleteProductDocumentUploadService
{
    public function __construct(
        private readonly ProductDocumentRepository $documents,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFileScanPolicy $scanPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, CompleteProductDocumentUploadCommand $command): ProductDocumentFileRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductDocumentFileRecord {
            $record = $this->documents->findByPublicId($command->documentPublicId);
            if ($record === null) {
                throw ProductDocumentNotFound::forPublicId($command->documentPublicId);
            }

            $container = $record->pendingStorageContainer ?? $record->storageContainer;
            $path = $record->pendingStoragePath ?? $record->storagePath;
            if ($container === null || $path === null) {
                throw ProductFileUploadException::invalidState('Document upload session is missing storage location.');
            }

            $head = $this->blobStore->headObject($container, $path);
            if (($head === null || !$head->exists) && $command->binaryContent !== null && $command->binaryContent !== '') {
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
            $publicUrl = $this->blobStore->buildPublicUrl($container, $path);
            $actorUserId = $this->audit->actorUserId($actor);
            $previousPath = $record->previousStoragePath;
            $previousContainer = $record->storageContainer;

            $completed = $this->documents->markUploadCompleted(
                $record->publicId,
                [
                    'uploadStatusCode' => UploadStatusCode::ACTIVE,
                    'scanStatusCode' => $scanStatus->toString(),
                    'publicUrl' => $publicUrl,
                    'contentType' => $head->contentType ?? $record->contentType,
                    'byteSize' => $head->contentLength ?? $record->byteSize,
                    'etag' => $head->etag,
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
                entityTypeCode: 'product-document',
                entityId: null,
                actionCode: 'upload-completed',
                newValues: [
                    'documentPublicId' => $completed->publicId,
                    'uploadStatusCode' => $completed->uploadStatusCode,
                    'scanStatusCode' => $completed->scanStatusCode,
                ],
            );

            return $completed;
        });
    }
}
