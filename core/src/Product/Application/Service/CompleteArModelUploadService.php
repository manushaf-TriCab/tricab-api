<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompleteArModelUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductArModelSummary;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\ProductArModelRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFileScanPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class CompleteArModelUploadService
{
    public function __construct(
        private readonly ProductArModelRepository $arModels,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFileScanPolicy $scanPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, CompleteArModelUploadCommand $command): ProductArModelSummary
    {
        return $this->transaction->run(function () use ($actor, $command): ProductArModelSummary {
            $record = $this->arModels->findByPublicId($command->arPublicId);
            if ($record === null) {
                throw ProductFileUploadException::invalidState(sprintf('AR model "%s" not found.', $command->arPublicId));
            }

            if ($record->storagePath === null || $record->storageContainer === null) {
                throw ProductFileUploadException::invalidState('AR model is missing storage location.');
            }

            $head = $this->blobStore->headObject($record->storageContainer, $record->storagePath);
            if ($head === null || !$head->exists) {
                throw ProductFileUploadException::blobMissing($record->storageContainer, $record->storagePath);
            }

            if ($record->byteSize !== null && $head->contentLength !== null && $head->contentLength !== $record->byteSize) {
                throw ProductFileUploadException::blobMismatch(sprintf(
                    'expected %d bytes, got %d',
                    $record->byteSize,
                    $head->contentLength,
                ));
            }

            $scanStatus = $this->scanPolicy->resolveAfterUpload();
            $publicUrl = $this->blobStore->buildPublicUrl($record->storageContainer, $record->storagePath);

            $completed = $this->arModels->markUploadCompleted(
                $record->publicId,
                [
                    'uploadStatusCode' => UploadStatusCode::ACTIVE,
                    'scanStatusCode' => $scanStatus->toString(),
                    'publicUrl' => $publicUrl,
                    'contentType' => $head->contentType ?? $record->contentType,
                    'byteSize' => $head->contentLength ?? $record->byteSize,
                    'etag' => $head->etag,
                ],
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($completed->productPublicId),
                entityTypeCode: 'product-ar-model',
                entityId: null,
                actionCode: 'upload-completed',
                newValues: [
                    'arPublicId' => $completed->publicId,
                    'uploadStatusCode' => $completed->uploadStatusCode,
                ],
            );

            return $completed;
        });
    }
}
