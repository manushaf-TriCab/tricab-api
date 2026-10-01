<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Service;

use Throwable;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportStoredFile;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileFailure;
use TriCab\Core\CustomerContactReport\Application\Exception\CustomerContactReportFileStoreFailure;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore;

final class CustomerContactReportBlobWrite
{
    public function __construct(
        private readonly CustomerContactReportFileStore $files,
    ) {
    }

    public function put(string $blobPath, string $contentType, string $bytes): CustomerContactReportStoredFile
    {
        try {
            return $this->files->put($blobPath, $contentType, $bytes);
        } catch (CustomerContactReportFileStoreFailure) {
            throw CustomerContactReportFileFailure::uploadFailed();
        }
    }

    public function rollback(CustomerContactReportStoredFile $stored): void
    {
        try {
            $this->files->delete($stored->blobPath);
        } catch (Throwable) {
            error_log((json_encode([
                'event' => 'customer_contact_blob_orphan',
                'blobPath' => $stored->blobPath,
            ], JSON_UNESCAPED_SLASHES)) ?: '{"event":"customer_contact_blob_orphan"}');
        }
    }
}
