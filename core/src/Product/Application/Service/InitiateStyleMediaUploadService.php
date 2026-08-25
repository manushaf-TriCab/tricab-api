<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\InitiateStyleMediaUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class InitiateStyleMediaUploadService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFilePathBuilder $pathBuilder,
        private readonly ProductFileValidationPolicy $validationPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, InitiateStyleMediaUploadCommand $command): ProductUploadSessionResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductUploadSessionResult {
            $style = $this->styles->findByPublicId($command->stylePublicId, false);
            if ($style === null) {
                throw ProductStyleNotFound::forPublicId($command->stylePublicId);
            }

            $type = StyleResourceType::fromString($command->resourceType);
            if ($type->isSpecifications()) {
                throw InvalidProductValue::for('StyleResourceType', $command->resourceType);
            }

            $validated = $this->validate($type, $command);
            $kind = $this->storageKind($type);
            $assetPublicId = self::newPublicId();
            $storagePath = $this->pathBuilder->styleAssetPath(
                $style->publicId,
                $kind,
                $assetPublicId,
                $validated['safeFileName'],
            );
            $roleCode = $command->roleCode !== null && trim($command->roleCode) !== ''
                ? strtolower(trim($command->roleCode))
                : null;
            $isPrimary = $command->isPrimary
                || $roleCode === 'hero'
                || $roleCode === 'primary';

            $asset = $this->styles->createStyleAssetUploadSession(
                $style->publicId,
                [
                    'publicId' => $assetPublicId,
                    'resourceType' => $type->toString(),
                    'title' => $command->title !== '' ? $command->title : $validated['safeFileName'],
                    'roleCode' => $roleCode,
                    'altText' => $command->altText,
                    'storageProviderCode' => 'azure-blob',
                    'storageContainer' => $command->storageContainer,
                    'storagePath' => $storagePath,
                    'publicUrl' => null,
                    'contentType' => $validated['contentType'],
                    'checksumSha256' => null,
                    'displayOrder' => $command->displayOrder,
                    'isPrimary' => $isPrimary,
                    'uploadStatusCode' => UploadStatusCode::PENDING_UPLOAD,
                    'byteSize' => $validated['byteSize'],
                    'originalFileName' => $validated['safeFileName'],
                    'metadata' => $command->metadata,
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
                productPublicId: null,
                entityTypeCode: 'product-style-media',
                entityId: null,
                actionCode: 'upload-session-created',
                newValues: [
                    'stylePublicId' => $style->publicId,
                    'assetPublicId' => $asset['publicId'] ?? $assetPublicId,
                    'resourceType' => $type->toString(),
                    'contentType' => $validated['contentType'],
                    'byteSize' => $validated['byteSize'],
                    'storagePath' => $storagePath,
                    'storageContainer' => $command->storageContainer,
                ],
            );

            return new ProductUploadSessionResult(
                entityPublicId: (string) ($asset['publicId'] ?? $assetPublicId),
                uploadUrl: $ticket->uploadUrl,
                httpMethod: $ticket->httpMethod,
                requiredHeaders: $ticket->requiredHeaders,
                expiresAtUtc: $ticket->expiresAtUtc,
                storagePath: $storagePath,
                storageContainer: $command->storageContainer,
                fileKind: $validated['fileKind'],
            );
        });
    }

    /**
     * @return array{safeFileName: string, extension: string, contentType: string, byteSize: int, fileKind: string}
     */
    private function validate(StyleResourceType $type, InitiateStyleMediaUploadCommand $command): array
    {
        return match ($type->toString()) {
            StyleResourceType::IMAGES => $this->validationPolicy->validateImage(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            ),
            StyleResourceType::DOCUMENTS => $this->validationPolicy->validateDocument(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            ),
            StyleResourceType::SPIN_360 => $this->validationPolicy->validate360Frame(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            ),
            StyleResourceType::MODELS_3D => $this->validationPolicy->validateModel3d(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            ),
            StyleResourceType::AR_ASSETS => $this->validationPolicy->validateAr(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            ),
            default => throw InvalidProductValue::for('StyleResourceType', $type->toString()),
        };
    }

    private function storageKind(StyleResourceType $type): string
    {
        return match ($type->toString()) {
            StyleResourceType::IMAGES => 'images',
            StyleResourceType::DOCUMENTS => 'documents',
            StyleResourceType::SPIN_360 => '360',
            StyleResourceType::MODELS_3D => '3d',
            StyleResourceType::AR_ASSETS => 'ar',
            default => 'images',
        };
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
