<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\InitiateArModelUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Contract\ProductArModelRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class InitiateArModelUploadService
{
    public function __construct(
        private readonly ProductArModelRepository $arModels,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFilePathBuilder $pathBuilder,
        private readonly ProductFileValidationPolicy $validationPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, InitiateArModelUploadCommand $command): ProductUploadSessionResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductUploadSessionResult {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $validated = $this->validationPolicy->validateAr(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            );
            $arPublicId = self::newPublicId();
            $storagePath = $this->pathBuilder->arPath(
                $productPublicId->toString(),
                $arPublicId,
                $validated['safeFileName'],
            );

            $record = $this->arModels->createUploadSession(
                $productPublicId,
                [
                    'publicId' => $arPublicId,
                    'title' => $command->title,
                    'originalFileName' => $validated['safeFileName'],
                    'contentType' => $validated['contentType'],
                    'byteSize' => $validated['byteSize'],
                    'fileExtension' => $validated['extension'],
                    'storagePath' => $storagePath,
                    'storageContainer' => $command->storageContainer,
                    'uploadStatusCode' => UploadStatusCode::PENDING_UPLOAD,
                    'scanStatusCode' => ScanStatusCode::PENDING_UPLOAD,
                    'isPrimary' => $command->isPrimary,
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
                productPublicId: $productPublicId,
                entityTypeCode: 'product-ar-model',
                entityId: null,
                actionCode: 'upload-session-created',
                newValues: [
                    'arPublicId' => $record->publicId,
                    'fileKind' => ProductFileKind::AR,
                    'storagePath' => $storagePath,
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
                fileKind: ProductFileKind::AR,
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
