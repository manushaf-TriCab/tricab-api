<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\InitiateProduct3DModelUploadCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Domain\Service\Product3DGenerationMetadataValidator;
use TriCab\Core\Product\Domain\Service\Product3DViewerConfigurationValidator;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\Product3DModelStatus;
use TriCab\Core\Product\Domain\ValueObject\Product3DModelType;
use TriCab\Core\Product\Domain\ValueObject\Product3DSourceType;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class InitiateProduct3DModelUploadService
{
    public function __construct(
        private readonly Product3DModelRepository $models,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFilePathBuilder $pathBuilder,
        private readonly ProductFileValidationPolicy $validationPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, InitiateProduct3DModelUploadCommand $command): ProductUploadSessionResult
    {
        return $this->transaction->run(function () use ($actor, $command): ProductUploadSessionResult {
            $productPublicId = ProductPublicId::fromString($command->productPublicId);
            $sourceType = Product3DSourceType::fromString($command->sourceTypeCode);
            $validated = $this->validationPolicy->validateModel3d(
                $command->originalFileName,
                $command->contentType,
                $command->byteSize,
            );
            $modelPublicId = self::newPublicId();
            $storagePath = $this->pathBuilder->model3dPath(
                $productPublicId->toString(),
                $modelPublicId,
                $validated['safeFileName'],
            );
            $viewer = (new Product3DViewerConfigurationValidator())->defaultConfiguration();
            $generation = (new Product3DGenerationMetadataValidator())->validate($command->generationMetadata);

            $record = $this->models->create(
                $productPublicId,
                [
                    'publicId' => $modelPublicId,
                    'productVariantPublicId' => $command->productVariantPublicId,
                    'name' => trim($command->name) !== '' ? trim($command->name) : pathinfo($validated['safeFileName'], PATHINFO_FILENAME),
                    'modelTypeCode' => Product3DModelType::fromFileExtension($validated['extension'])->toString(),
                    'sourceTypeCode' => $sourceType->toString(),
                    'statusCode' => Product3DModelStatus::DRAFT,
                    'uploadStatusCode' => UploadStatusCode::PENDING_UPLOAD,
                    'scanStatusCode' => ScanStatusCode::PENDING_UPLOAD,
                    'storagePath' => $storagePath,
                    'storageContainer' => $command->storageContainer,
                    'mimeType' => $validated['contentType'],
                    'fileExtension' => $validated['extension'],
                    'fileSizeBytes' => $validated['byteSize'],
                    'originalFileName' => $validated['safeFileName'],
                    'fileName' => $validated['safeFileName'],
                    'viewerConfigurationJson' => json_encode($viewer, JSON_THROW_ON_ERROR),
                    'generationMetadataJson' => $generation['json'],
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

            $actionCode = $sourceType->toString() === Product3DSourceType::SPECIFICATION
                ? 'procedural-3d-upload-session-created'
                : 'upload-session-created';
            $auditMeta = [
                'modelPublicId' => $record->publicId,
                'fileKind' => ProductFileKind::MODEL_3D,
                'sourceTypeCode' => $sourceType->toString(),
            ];
            if (is_array($generation['data'])) {
                foreach (['style', 'detailLevel', 'sourceHash'] as $key) {
                    if (isset($generation['data'][$key]) && is_scalar($generation['data'][$key])) {
                        $auditMeta[$key] = $generation['data'][$key];
                    }
                }
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: $productPublicId,
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: $actionCode,
                newValues: $auditMeta,
            );

            return new ProductUploadSessionResult(
                entityPublicId: $record->publicId,
                uploadUrl: $ticket->uploadUrl,
                httpMethod: $ticket->httpMethod,
                requiredHeaders: $ticket->requiredHeaders,
                expiresAtUtc: $ticket->expiresAtUtc,
                storagePath: $storagePath,
                storageContainer: $command->storageContainer,
                fileKind: ProductFileKind::MODEL_3D,
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
