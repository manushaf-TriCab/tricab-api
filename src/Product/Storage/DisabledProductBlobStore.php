<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Storage;

use LogicException;
use TriCab\Core\Product\Application\DTO\ProductBlobObjectInfo;
use TriCab\Core\Product\Application\DTO\ProductBlobWriteTicket;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Production guard when BLOB_AUTH_MODE=disabled.
 */
final class DisabledProductBlobStore implements ProductBlobStore
{
    public function issueWriteUpload(
        string $container,
        string $blobPath,
        string $contentType,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        throw ProductFileUploadException::invalidState('Product blob storage is disabled.');
    }

    public function issueReadDownload(
        string $container,
        string $blobPath,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        throw ProductFileUploadException::invalidState('Product blob storage is disabled.');
    }

    public function headObject(string $container, string $blobPath): ?ProductBlobObjectInfo
    {
        throw ProductFileUploadException::invalidState('Product blob storage is disabled.');
    }

    public function deleteObject(string $container, string $blobPath): void
    {
        throw ProductFileUploadException::invalidState('Product blob storage is disabled.');
    }

    public function copyObject(
        string $sourceContainer,
        string $sourcePath,
        string $destContainer,
        string $destPath,
    ): void {
        throw ProductFileUploadException::invalidState('Product blob storage is disabled.');
    }

    public function putObject(
        string $container,
        string $blobPath,
        string $contentType,
        string $binary,
    ): ProductBlobObjectInfo {
        throw new LogicException('Product blob storage is disabled.');
    }

    public function buildPublicUrl(string $container, string $blobPath): ?string
    {
        return null;
    }

    public function getObjectBinary(string $container, string $blobPath): ?string
    {
        unset($container, $blobPath);

        return null;
    }
}
