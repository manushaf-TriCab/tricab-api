<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Storage;

use LogicException;
use TriCab\Api\Config\Settings;
use TriCab\Core\Product\Application\DTO\ProductBlobObjectInfo;
use TriCab\Core\Product\Application\DTO\ProductBlobWriteTicket;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Azure Blob / Azurite adapter using account-key SAS (blob-scoped write upload + read download).
 * Production Managed Identity / user-delegation SAS is documented for later;
 * connection-string mode is the approved non-production path.
 */
final class AzureProductBlobStore implements ProductBlobStore
{
    private readonly AzureBlobAccountSasIssuer $sas;
    private readonly AzureBlobRestClient $rest;

    public function __construct(
        private readonly Settings $settings,
    ) {
        $this->sas = new AzureBlobAccountSasIssuer(
            $settings->blobAccountName,
            $settings->blobAccountKey,
            $settings->blobEndpoint,
        );
        $this->rest = new AzureBlobRestClient(
            $settings->blobAccountName,
            $settings->blobAccountKey,
            $settings->blobEndpoint,
        );
    }

    public function issueWriteUpload(
        string $container,
        string $blobPath,
        string $contentType,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        $issued = $this->sas->issueBlobWriteSas($container, $blobPath, $contentType, $expiresInSeconds);

        return new ProductBlobWriteTicket(
            uploadUrl: $issued['url'],
            httpMethod: 'PUT',
            requiredHeaders: $issued['headers'],
            expiresAtUtc: $issued['expiresAtUtc'],
            container: $container,
            blobPath: $blobPath,
        );
    }

    public function issueReadDownload(
        string $container,
        string $blobPath,
        int $expiresInSeconds,
    ): ProductBlobWriteTicket {
        $issued = $this->sas->issueBlobReadSas($container, $blobPath, $expiresInSeconds);

        return new ProductBlobWriteTicket(
            uploadUrl: $issued['url'],
            httpMethod: 'GET',
            requiredHeaders: $issued['headers'],
            expiresAtUtc: $issued['expiresAtUtc'],
            container: $container,
            blobPath: $blobPath,
        );
    }

    public function headObject(string $container, string $blobPath): ?ProductBlobObjectInfo
    {
        $head = $this->rest->head($container, $blobPath);
        if (!$head['exists']) {
            return null;
        }

        return new ProductBlobObjectInfo(
            exists: true,
            contentType: $head['contentType'],
            contentLength: $head['contentLength'],
            etag: $head['etag'],
            contentMd5: null,
        );
    }

    public function deleteObject(string $container, string $blobPath): void
    {
        $this->rest->delete($container, $blobPath);
    }

    public function copyObject(
        string $sourceContainer,
        string $sourcePath,
        string $destContainer,
        string $destPath,
    ): void {
        $this->rest->copy($sourceContainer, $sourcePath, $destContainer, $destPath);
    }

    public function putObject(
        string $container,
        string $blobPath,
        string $contentType,
        string $binary,
    ): ProductBlobObjectInfo {
        // Allowed for Azurite / disposable non-prod only via connection-string mode.
        $this->rest->put($container, $blobPath, $contentType, $binary);
        $head = $this->headObject($container, $blobPath);
        if ($head === null) {
            throw new LogicException('Blob PUT succeeded but HEAD returned missing object.');
        }

        return $head;
    }

    public function buildPublicUrl(string $container, string $blobPath): ?string
    {
        if ($this->settings->blobCdnBaseUrl !== '') {
            return $this->settings->blobCdnBaseUrl . '/' . trim($container, '/') . '/' . ltrim($blobPath, '/');
        }

        // Fallback: direct blob endpoint (not preferred for public delivery).
        return $this->rest->objectUrl($container, $blobPath);
    }
}
