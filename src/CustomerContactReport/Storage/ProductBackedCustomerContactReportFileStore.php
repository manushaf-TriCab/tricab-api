<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Storage;

use Throwable;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredFile;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileStoreFailure;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Customer Contact files in the shared blob account, under a cc/ prefix.
 * The container is the existing Tri Expense container. No second client.
 */
final class ProductBackedCustomerContactReportFileStore implements CustomerContactReportFileStore
{
    public function __construct(
        private readonly ProductBlobStore $blobs,
        private readonly string $container,
    ) {
    }

    public function put(string $blobPath, string $contentType, string $bytes): CustomerContactReportStoredFile
    {
        try {
            $this->blobs->putObject($this->container, $blobPath, $contentType, $bytes);
            $url = $this->blobs->buildPublicUrl($this->container, $blobPath);
        } catch (Throwable) {
            throw new CustomerContactReportFileStoreFailure('put failed');
        }
        if ($url === null || $url === '') {
            throw new CustomerContactReportFileStoreFailure('put failed');
        }

        return new CustomerContactReportStoredFile($blobPath, $url);
    }

    public function delete(string $blobPath): void
    {
        try {
            $this->blobs->deleteObject($this->container, $blobPath);
        } catch (Throwable) {
            throw new CustomerContactReportFileStoreFailure('delete failed');
        }
    }
}
