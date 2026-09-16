<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Document;

use Throwable;
use TriCab\Api\Config\Settings;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Resolves short-lived client-accessible READ URLs for Technical library documents.
 *
 * Uses the shared ProductBlobStore SAS issuer (same mechanism as product private media).
 * Never persists SAS tokens. Never falls back to an unsigned private blob URL.
 * Never logs SAS tokens, account keys, or connection strings.
 */
final class TechnicalDocumentClientUrlResolver
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ProductBlobStore $blobStore,
    ) {
    }

    public function resolve(TechnicalDocumentRecord $record): ?string
    {
        if ($this->settings->blobAuthMode !== Settings::BLOB_AUTH_MODE_CONNECTION_STRING) {
            return null;
        }

        $container = $record->storageContainer !== null && trim($record->storageContainer) !== ''
            ? trim($record->storageContainer)
            : trim($this->settings->blobContainerProducts);
        $path = $record->storagePath !== null ? ltrim(trim($record->storagePath), '/') : '';

        if ($container === '' || $path === '') {
            return null;
        }

        try {
            $ticket = $this->blobStore->issueReadDownload(
                $container,
                $path,
                $this->settings->blobDownloadSasTtlSeconds,
            );
            $url = trim($ticket->uploadUrl);
            if ($url === '' || !str_contains($url, '?')) {
                return null;
            }

            return $url;
        } catch (Throwable) {
            // Safe diagnostic only — never include SAS / keys.
            error_log(sprintf(
                'technical_document_client_url_sign_failed publicId=%s container=%s path=%s',
                $record->publicId,
                $container,
                $path,
            ));

            return null;
        }
    }
}
