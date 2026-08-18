<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\Initiate360FrameUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class Initiate360FrameUploadService
{
    public function __construct(
        private readonly Product360Repository $sets,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFilePathBuilder $pathBuilder,
        private readonly ProductFileValidationPolicy $validationPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, Initiate360FrameUploadCommand $command): ProductUploadSessionResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductUploadSessionResult {
            $set = $this->sets->findSetByPublicId($command->setPublicId);
            if ($set === null) {
                throw ProductFileUploadException::invalidState(sprintf('360 set "%s" not found.', $command->setPublicId));
            }

            if ($command->frameNumber < 1 || $command->frameNumber > $set->expectedFrameCount) {
                throw ProductFileUploadException::invalidState(sprintf(
                    'frameNumber %d is out of range for set expecting %d frames.',
                    $command->frameNumber,
                    $set->expectedFrameCount,
                ));
            }

            $validated = $this->validationPolicy->validate360Frame(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            );
            $storagePath = $this->pathBuilder->frame360Path(
                $set->productPublicId,
                $set->publicId,
                $command->frameNumber,
                $validated['safeFileName'],
            );

            $frame = $this->sets->createFrameUploadSession(
                $set->publicId,
                [
                    'frameNumber' => $command->frameNumber,
                    'originalFileName' => $validated['safeFileName'],
                    'contentType' => $validated['contentType'],
                    'byteSize' => $validated['byteSize'],
                    'fileExtension' => $validated['extension'],
                    'storagePath' => $storagePath,
                    'storageContainer' => $command->storageContainer,
                    'uploadStatusCode' => UploadStatusCode::PENDING_UPLOAD,
                    'scanStatusCode' => ScanStatusCode::PENDING_UPLOAD,
                ],
                $this->audit->actorUserId($actor),
            );

            $ticket = $this->blobStore->issueWriteUpload(
                $command->storageContainer,
                $storagePath,
                $validated['contentType'],
                $command->uploadExpiresInSeconds,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($set->productPublicId),
                entityTypeCode: 'product-360-frame',
                entityId: null,
                actionCode: 'upload-session-created',
                newValues: [
                    'framePublicId' => $frame->publicId,
                    'setPublicId' => $set->publicId,
                    'frameNumber' => $command->frameNumber,
                    'storagePath' => $storagePath,
                ],
            );

            return new ProductUploadSessionResult(
                entityPublicId: $frame->publicId,
                uploadUrl: $ticket->uploadUrl,
                httpMethod: $ticket->httpMethod,
                requiredHeaders: $ticket->requiredHeaders,
                expiresAtUtc: $ticket->expiresAtUtc,
                storagePath: $storagePath,
                storageContainer: $command->storageContainer,
                fileKind: ProductFileKind::FRAME_360,
            );
        });
    }
}
