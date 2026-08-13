<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Storage;

use TriCab\Core\Product\Application\DTO\ProductBlobObjectInfo;
use TriCab\Core\Product\Application\DTO\ProductBlobWriteTicket;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * HMAC-free recording adapter for resolver tests. Never logs secrets.
 */
final class RecordingBlobStore implements ProductBlobStore
{
    public ?int $lastTtl = null;
    public ?string $lastContainer = null;
    public ?string $lastPath = null;

    public function issueWriteUpload(
        string $container,
        string $blobPath,
        string $contentType,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        unset($contentType);

        return $this->ticket($container, $blobPath, $expiresInSeconds, 'PUT');
    }

    public function issueReadDownload(
        string $container,
        string $blobPath,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        $this->lastContainer = $container;
        $this->lastPath = $blobPath;
        $this->lastTtl = $expiresInSeconds;

        $expiry = gmdate('Y-m-d\TH:i:s\Z', time() + $expiresInSeconds);
        $url = 'https://tricabv2.blob.core.windows.net/'
            . rawurlencode($container) . '/' . ltrim($blobPath, '/')
            . '?sp=r&sr=b&spr=https&se=' . rawurlencode($expiry) . '&sig=test';

        return new ProductBlobWriteTicket(
            uploadUrl: $url,
            httpMethod: 'GET',
            requiredHeaders: [],
            expiresAtUtc: $expiry,
            container: $container,
            blobPath: $blobPath,
        );
    }

    public function headObject(string $container, string $blobPath): ?ProductBlobObjectInfo
    {
        unset($container, $blobPath);

        return null;
    }

    public function deleteObject(string $container, string $blobPath): void
    {
        unset($container, $blobPath);
    }

    public function copyObject(
        string $sourceContainer,
        string $sourcePath,
        string $destContainer,
        string $destPath,
    ): void {
        unset($sourceContainer, $sourcePath, $destContainer, $destPath);
    }

    public function putObject(
        string $container,
        string $blobPath,
        string $contentType,
        string $binary,
    ): ProductBlobObjectInfo {
        unset($container, $blobPath, $contentType, $binary);

        return new ProductBlobObjectInfo(true, 'application/octet-stream', 0, null, null);
    }

    public function buildPublicUrl(string $container, string $blobPath): ?string
    {
        return 'https://tricabv2.blob.core.windows.net/' . rawurlencode($container) . '/' . ltrim($blobPath, '/');
    }

    private function ticket(
        string $container,
        string $blobPath,
        int $expiresInSeconds,
        string $method,
    ): ProductBlobWriteTicket {
        return new ProductBlobWriteTicket(
            uploadUrl: 'https://example.test/unused',
            httpMethod: $method,
            requiredHeaders: [],
            expiresAtUtc: gmdate('Y-m-d\TH:i:s\Z', time() + $expiresInSeconds),
            container: $container,
            blobPath: $blobPath,
        );
    }
}
