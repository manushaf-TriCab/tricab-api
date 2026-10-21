<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompleteProduct3DModelUploadCommand;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFileScanPolicy;
use TriCab\Core\Product\Domain\Exception\Product3DGlbInvalid;
use TriCab\Core\Product\Domain\Service\Product3DGlbInspector;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\Product3DModelStatus;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class CompleteProduct3DModelUploadService
{
    public function __construct(
        private readonly Product3DModelRepository $models,
        private readonly ProductBlobStore $blobStore,
        private readonly ProductFileScanPolicy $scanPolicy,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly ProductAdminQueryRepository $products,
        private readonly Product3DGlbInspector $inspector = new Product3DGlbInspector(),
        private readonly ProductFileValidationPolicy $validationPolicy = new ProductFileValidationPolicy(),
    ) {
    }

    public function execute(ActorContext $actor, CompleteProduct3DModelUploadCommand $command): Product3DModelSummary
    {
        // Rejections are returned (not thrown) from the transaction so the failed status commits.
        $outcome = $this->transaction->run(function () use ($actor, $command): Product3DModelSummary|ProductFileUploadException {
            $record = $this->models->findByPublicId($command->modelPublicId);
            if ($record === null) {
                throw ProductFileUploadException::invalidState(sprintf('3D model "%s" not found.', $command->modelPublicId));
            }
            if ($record->uploadStatusCode !== UploadStatusCode::PENDING_UPLOAD) {
                throw ProductFileUploadException::invalidState(sprintf(
                    '3D model "%s" is not awaiting upload (upload status "%s").',
                    $record->publicId,
                    $record->uploadStatusCode,
                ));
            }
            // No product-scoped permission exists beyond PRODUCT.MEDIA.MANAGE: require a live owning product.
            if ($this->products->findDetailByPublicId(ProductPublicId::fromString($record->productPublicId)) === null) {
                throw ProductNotFound::forPublicId($record->productPublicId);
            }
            if ($record->storagePath === null || $record->storageContainer === null) {
                throw ProductFileUploadException::invalidState('3D model is missing storage location.');
            }

            if ($command->binaryBase64 !== null && $command->binaryBase64 !== '') {
                $bytes = base64_decode($command->binaryBase64, true);
                if ($bytes === false) {
                    throw ProductFileUploadException::invalidState('binaryBase64 is invalid.');
                }
                $this->blobStore->putObject(
                    $record->storageContainer,
                    $record->storagePath,
                    $record->mimeType ?? 'application/octet-stream',
                    $bytes,
                );
            }

            $head = $this->blobStore->headObject($record->storageContainer, $record->storagePath);
            if ($head === null || !$head->exists) {
                throw ProductFileUploadException::blobMissing($record->storageContainer, $record->storagePath);
            }
            if ($record->fileSizeBytes !== null && $head->contentLength !== null && $head->contentLength !== $record->fileSizeBytes) {
                throw ProductFileUploadException::blobMismatch(sprintf(
                    'expected %d bytes, got %d',
                    $record->fileSizeBytes,
                    $head->contentLength,
                ));
            }

            try {
                $this->inspectGlb($record->storageContainer, $record->storagePath, $head->contentLength);
            } catch (Product3DGlbInvalid $invalid) {
                $this->models->markUploadFailed($record->publicId, $this->audit->actorUserId($actor));
                $this->audit->record(
                    actor: $actor,
                    productPublicId: ProductPublicId::fromString($record->productPublicId),
                    entityTypeCode: 'product-3d-model',
                    entityId: null,
                    actionCode: 'upload-rejected',
                    newValues: ['modelPublicId' => $record->publicId, 'errorCode' => $invalid->errorCode],
                );

                return ProductFileUploadException::invalidState(sprintf(
                    '3D model failed GLB inspection (%s): %s',
                    $invalid->errorCode,
                    $invalid->getMessage(),
                ));
            }

            $scanStatus = $this->scanPolicy->resolveAfterUpload();
            $publicUrl = $this->blobStore->buildPublicUrl($record->storageContainer, $record->storagePath);
            // Azure / CDN adapters return absolute HTTPS URLs. Memory mode may return null —
            // only then use the gated relative development delivery route.
            if ($publicUrl === null || $publicUrl === '') {
                $publicUrl = '/api/v1/dev/product-files/' . rawurlencode($record->publicId);
            }
            $completed = $this->models->markUploadCompleted(
                $record->publicId,
                [
                    'uploadStatusCode' => UploadStatusCode::ACTIVE,
                    'scanStatusCode' => $scanStatus->toString(),
                    'statusCode' => Product3DModelStatus::READY,
                    'publicUrl' => $publicUrl,
                    'mimeType' => $head->contentType ?? $record->mimeType,
                    'fileSizeBytes' => $head->contentLength ?? $record->fileSizeBytes,
                ],
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($completed->productPublicId),
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: 'upload-completed',
                newValues: ['modelPublicId' => $completed->publicId, 'statusCode' => $completed->statusCode],
            );

            return $completed;
        });

        if ($outcome instanceof ProductFileUploadException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * @throws Product3DGlbInvalid
     */
    private function inspectGlb(string $container, string $path, ?int $contentLength): void
    {
        if ($contentLength !== null && $contentLength > $this->validationPolicy->model3dMaxBytes()) {
            throw Product3DGlbInvalid::because(Product3DGlbInvalid::TOO_LARGE, 'GLB exceeds the maximum allowed size.');
        }
        if ($contentLength !== null && $contentLength > Product3DGlbInspector::MAX_INSPECT_BYTES) {
            // Too large for deep inspection: header-only check via a ranged read.
            $header = $this->blobStore->getObjectRange($container, $path, 0, Product3DGlbInspector::HEADER_BYTES);
            $this->inspector->inspectHeader($header ?? '', $contentLength);

            return;
        }
        $binary = $this->blobStore->getObjectBinary($container, $path);
        if ($binary === null) {
            throw ProductFileUploadException::blobMissing($container, $path);
        }
        $this->inspector->inspect($binary);
    }
}
