<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\InitiateProductMediaUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\ImageRoleCode;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class InitiateProductMediaUploadService
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

    public function execute(ActorContext $actor, InitiateProductMediaUploadCommand $command): ProductUploadSessionResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductUploadSessionResult {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $imageRole = ImageRoleCode::fromString($command->mediaCategoryCode);
            $validated = $this->validationPolicy->validateImage(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            );
            $actorUserId = $this->audit->actorUserId($actor);
            $mediaPublicId = self::newPublicId();
            $storagePath = $this->pathBuilder->imagePath(
                $productPublicId->toString(),
                $mediaPublicId,
                $validated['safeFileName'],
            );

            $record = $this->media->createUploadSession(
                $productPublicId,
                [
                    'publicId' => $mediaPublicId,
                    'mediaCategoryCode' => $imageRole->toString(),
                    'mediaTypeCode' => $command->mediaTypeCode,
                    'title' => $command->title,
                    'originalFileName' => $validated['safeFileName'],
                    'contentType' => $validated['contentType'],
                    'byteSize' => $validated['byteSize'],
                    'fileExtension' => $validated['extension'],
                    'storagePath' => $storagePath,
                    'storageContainer' => $command->storageContainer,
                    'uploadStatusCode' => UploadStatusCode::PENDING_UPLOAD,
                    'scanStatusCode' => ScanStatusCode::PENDING_UPLOAD,
                    'altText' => $command->altText,
                    'caption' => $command->caption,
                    'displayOrder' => $command->displayOrder,
                    'isPrimary' => $command->isPrimary,
                ],
                $actorUserId,
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
                entityTypeCode: 'product-media',
                entityId: null,
                actionCode: 'upload-session-created',
                newValues: [
                    'mediaPublicId' => $record->publicId,
                    'fileKind' => ProductFileKind::IMAGE,
                    'contentType' => $validated['contentType'],
                    'byteSize' => $validated['byteSize'],
                    'storagePath' => $storagePath,
                    'storageContainer' => $command->storageContainer,
                ],
            );

            return new ProductUploadSessionResult(
                entityPublicId: $record->publicId,
                uploadUrl: $ticket->uploadUrl,
                httpMethod: $ticket->httpMethod,
                requiredHeaders: $ticket->requiredHeaders,
                expiresAtUtc: $ticket->expiresAtUtc,
                storagePath: $storagePath,
                storageContainer: $command->storageContainer,
                fileKind: ProductFileKind::IMAGE,
            );
        });
    }

    private static function newPublicId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
