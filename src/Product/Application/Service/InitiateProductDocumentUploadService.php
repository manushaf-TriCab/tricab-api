<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\InitiateProductDocumentUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\DocumentAccessCode;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class InitiateProductDocumentUploadService
{
    public function __construct(
        private readonly ProductDocumentRepository $documents,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFilePathBuilder $pathBuilder,
        private readonly ProductFileValidationPolicy $validationPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, InitiateProductDocumentUploadCommand $command): ProductUploadSessionResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductUploadSessionResult {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $accessCode = DocumentAccessCode::fromString($command->accessCode);
            $validated = $command->isCad
                ? $this->validationPolicy->validateCad($command->originalFileName, $command->contentType, $command->byteSize)
                : $this->validationPolicy->validateDocument($command->originalFileName, $command->contentType, $command->byteSize);
            $fileKind = $command->isCad ? ProductFileKind::CAD : ProductFileKind::DOCUMENT;
            $documentPublicId = self::newPublicId();
            $storagePath = $this->pathBuilder->documentPath(
                $productPublicId->toString(),
                $documentPublicId,
                $validated['safeFileName'],
            );
            $actorUserId = $this->audit->actorUserId($actor);

            $record = $this->documents->createUploadSession(
                $productPublicId,
                [
                    'publicId' => $documentPublicId,
                    'documentTypeCode' => strtolower(trim($command->documentTypeCode)),
                    'title' => $command->title,
                    'accessCode' => $accessCode->toString(),
                    'originalFileName' => $validated['safeFileName'],
                    'contentType' => $validated['contentType'],
                    'byteSize' => $validated['byteSize'],
                    'fileExtension' => $validated['extension'],
                    'storagePath' => $storagePath,
                    'storageContainer' => $command->storageContainer,
                    'uploadStatusCode' => UploadStatusCode::PENDING_UPLOAD,
                    'scanStatusCode' => ScanStatusCode::PENDING_UPLOAD,
                    'versionLabel' => $command->versionLabel,
                    'languageCode' => $command->languageCode,
                    'displayOrder' => $command->displayOrder,
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
                entityTypeCode: 'product-document',
                entityId: null,
                actionCode: 'upload-session-created',
                newValues: [
                    'documentPublicId' => $record->publicId,
                    'fileKind' => $fileKind,
                    'contentType' => $validated['contentType'],
                    'byteSize' => $validated['byteSize'],
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
                fileKind: $fileKind,
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
