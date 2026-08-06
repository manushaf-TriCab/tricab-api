<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ReplaceProductMediaUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Application\Exception\ProductMediaNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class ReplaceProductMediaUploadService
{
    public function __construct(
        private readonly ProductMediaRepository $media,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFilePathBuilder $pathBuilder,
        private readonly ProductFileValidationPolicy $validationPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, ReplaceProductMediaUploadCommand $command): ProductUploadSessionResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductUploadSessionResult {
            $existing = $this->media->findByPublicId($command->mediaPublicId);
            if ($existing === null) {
                throw ProductMediaNotFound::forPublicId($command->mediaPublicId);
            }

            $validated = $this->validationPolicy->validateImage(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            );
            $container = $command->storageContainer ?? $existing->storageContainer ?? 'product-media';
            $storagePath = $this->pathBuilder->imagePath(
                $existing->productPublicId,
                $existing->publicId,
                $validated['safeFileName'],
            );
            // Distinct replace path to avoid clobbering active blob until complete.
            $pendingPath = preg_replace('/(\.[^.]+)$/', '.replace-$1', $storagePath) ?? ($storagePath . '.replace');

            $actorUserId = $this->audit->actorUserId($actor);
            $record = $this->media->beginReplaceUpload(
                $existing->publicId,
                [
                    'originalFileName' => $validated['safeFileName'],
                    'contentType' => $validated['contentType'],
                    'byteSize' => $validated['byteSize'],
                    'fileExtension' => $validated['extension'],
                    'storagePath' => $pendingPath,
                    'storageContainer' => $container,
                    'uploadStatusCode' => UploadStatusCode::PENDING_UPLOAD,
                    'scanStatusCode' => ScanStatusCode::PENDING_UPLOAD,
                ],
                $actorUserId,
            );

            $writePath = $record->pendingStoragePath ?? $pendingPath;
            $writeContainer = $record->pendingStorageContainer ?? $container;

            $ticket = $this->blobStore->issueWriteUpload(
                $writeContainer,
                $writePath,
                $validated['contentType'],
                $command->uploadExpiresInSeconds,
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($existing->productPublicId),
                entityTypeCode: 'product-media',
                entityId: null,
                actionCode: 'upload-session-created',
                newValues: [
                    'mediaPublicId' => $record->publicId,
                    'replace' => true,
                    'fileKind' => ProductFileKind::IMAGE,
                    'storagePath' => $writePath,
                ],
            );

            return new ProductUploadSessionResult(
                entityPublicId: $record->publicId,
                uploadUrl: $ticket->uploadUrl,
                httpMethod: $ticket->httpMethod,
                requiredHeaders: $ticket->requiredHeaders,
                expiresAtUtc: $ticket->expiresAtUtc,
                storagePath: $writePath,
                storageContainer: $writeContainer,
                fileKind: ProductFileKind::IMAGE,
            );
        });
    }
}
