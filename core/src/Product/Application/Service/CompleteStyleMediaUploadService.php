<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompleteStyleMediaUploadCommand;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * @phpstan-import-type StyleAsset from ProductStyleRepository
 */
final class CompleteStyleMediaUploadService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ProductBlobStore $blobStore,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /**
     * @return StyleAsset
     */
    public function execute(ActorContext $actor, CompleteStyleMediaUploadCommand $command): array
    {
        return $this->transaction->run(function () use ($actor, $command): array {
            $style = $this->styles->findByPublicId($command->stylePublicId, false);
            if ($style === null) {
                throw ProductStyleNotFound::forPublicId($command->stylePublicId);
            }

            $record = $this->styles->findStyleAssetByPublicId($command->stylePublicId, $command->assetPublicId);
            if ($record === null) {
                throw ProductStyleNotFound::forPublicId($command->assetPublicId);
            }

            $container = is_string($record['storageContainer'] ?? null) ? $record['storageContainer'] : null;
            $path = is_string($record['storagePath'] ?? null) ? $record['storagePath'] : null;
            if ($container === null || $container === '' || $path === null || $path === '') {
                throw ProductFileUploadException::invalidState('Style media upload session is missing storage location.');
            }

            $head = $this->blobStore->headObject($container, $path);
            if (($head === null || !$head->exists) && $command->binaryContent !== null && $command->binaryContent !== '') {
                $this->blobStore->putObject(
                    $container,
                    $path,
                    is_string($record['contentType'] ?? null) ? $record['contentType'] : 'application/octet-stream',
                    $command->binaryContent,
                );
                $head = $this->blobStore->headObject($container, $path);
            }
            if ($head === null || !$head->exists) {
                throw ProductFileUploadException::blobMissing($container, $path);
            }

            $expectedSize = isset($record['byteSize']) && is_int($record['byteSize']) ? $record['byteSize'] : null;
            if ($expectedSize !== null && $head->contentLength !== null && $head->contentLength !== $expectedSize) {
                throw ProductFileUploadException::blobMismatch(sprintf(
                    'expected %d bytes, got %d',
                    $expectedSize,
                    $head->contentLength,
                ));
            }

            $expectedType = is_string($record['contentType'] ?? null) ? $record['contentType'] : null;
            if (
                $expectedType !== null
                && $head->contentType !== null
                && strtolower($head->contentType) !== strtolower($expectedType)
            ) {
                throw ProductFileUploadException::blobMismatch(sprintf(
                    'expected content-type %s, got %s',
                    $expectedType,
                    $head->contentType,
                ));
            }

            $publicUrl = $this->blobStore->buildPublicUrl($container, $path);
            $completed = $this->styles->markStyleAssetUploadCompleted(
                $command->stylePublicId,
                $command->assetPublicId,
                [
                    'uploadStatusCode' => UploadStatusCode::ACTIVE,
                    'publicUrl' => $publicUrl,
                    'contentType' => $head->contentType ?? $expectedType,
                    'byteSize' => $head->contentLength ?? $expectedSize,
                    'storagePath' => $path,
                    'storageContainer' => $container,
                ],
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style-media',
                entityId: null,
                actionCode: 'upload-completed',
                newValues: [
                    'stylePublicId' => $command->stylePublicId,
                    'assetPublicId' => $command->assetPublicId,
                    'storagePath' => $path,
                    'byteSize' => $completed['byteSize'] ?? null,
                    'contentType' => $completed['contentType'] ?? null,
                ],
            );

            return $completed;
        });
    }
}
