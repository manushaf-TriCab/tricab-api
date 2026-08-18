<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductBlobObjectInfo;
use TriCab\Core\Product\Application\DTO\ProductBlobWriteTicket;

/**
 * Blob storage port for product files. Implementations live in tricab-api
 * (Azure / Azurite / InMemory). Core must not depend on Azure SDKs.
 */
interface ProductBlobStore
{
    public function issueWriteUpload(
        string $container,
        string $blobPath,
        string $contentType,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket;

    /**
     * Short-lived read download ticket (reuses write-ticket shape; httpMethod GET).
     */
    public function issueReadDownload(
        string $container,
        string $blobPath,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket;

    public function headObject(string $container, string $blobPath): ?ProductBlobObjectInfo;

    public function deleteObject(string $container, string $blobPath): void;

    public function copyObject(
        string $sourceContainer,
        string $sourcePath,
        string $destContainer,
        string $destPath,
    ): void;

    /**
     * Test/dev helper: put bytes directly.
     * InMemory / Azurite adapters may implement; Azure production adapter may throw LogicException.
     */
    public function putObject(
        string $container,
        string $blobPath,
        string $contentType,
        string $binary,
    ): ProductBlobObjectInfo;

    public function buildPublicUrl(string $container, string $blobPath): ?string;

    /**
     * Read blob bytes when the adapter supports server-side download.
     * Returns null when the object is missing or the adapter cannot read.
     */
    public function getObjectBinary(string $container, string $blobPath): ?string;
}
