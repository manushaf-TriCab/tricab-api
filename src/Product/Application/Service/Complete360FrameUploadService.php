<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\Complete360FrameUploadCommand;
use TriCab\Core\Product\Application\DTO\Product360FrameSummary;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFileScanPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class Complete360FrameUploadService
{
    public function __construct(
        private readonly Product360Repository $sets,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFileScanPolicy $scanPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, Complete360FrameUploadCommand $command): Product360FrameSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product360FrameSummary {
            $frame = $this->sets->findFrameByPublicId($command->framePublicId);
            if ($frame === null) {
                throw ProductFileUploadException::invalidState(sprintf('360 frame "%s" not found.', $command->framePublicId));
            }

            $set = $this->sets->findSetByPublicId($frame->setPublicId);
            if ($set === null) {
                throw ProductFileUploadException::invalidState(sprintf('360 set "%s" not found.', $frame->setPublicId));
            }

            if ($frame->storagePath === null || $frame->storageContainer === null) {
                throw ProductFileUploadException::invalidState('360 frame is missing storage location.');
            }

            $head = $this->blobStore->headObject($frame->storageContainer, $frame->storagePath);
            if ($head === null || !$head->exists) {
                throw ProductFileUploadException::blobMissing($frame->storageContainer, $frame->storagePath);
            }

            if ($frame->byteSize !== null && $head->contentLength !== null && $head->contentLength !== $frame->byteSize) {
                throw ProductFileUploadException::blobMismatch(sprintf(
                    'expected %d bytes, got %d',
                    $frame->byteSize,
                    $head->contentLength,
                ));
            }

            $scanStatus = $this->scanPolicy->resolveAfterUpload();
            $publicUrl = $this->blobStore->buildPublicUrl($frame->storageContainer, $frame->storagePath);

            $completed = $this->sets->markFrameUploadCompleted(
                $frame->publicId,
                [
                    'uploadStatusCode' => UploadStatusCode::ACTIVE,
                    'scanStatusCode' => $scanStatus->toString(),
                    'publicUrl' => $publicUrl,
                    'contentType' => $head->contentType ?? $frame->contentType,
                    'byteSize' => $head->contentLength ?? $frame->byteSize,
                    'etag' => $head->etag,
                ],
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($set->productPublicId),
                entityTypeCode: 'product-360-frame',
                entityId: null,
                actionCode: 'upload-completed',
                newValues: [
                    'framePublicId' => $completed->publicId,
                    'setPublicId' => $completed->setPublicId,
                    'frameNumber' => $completed->frameNumber,
                ],
            );

            return $completed;
        });
    }
}
