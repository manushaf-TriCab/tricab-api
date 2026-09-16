<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Document;

/**
 * Blob paths for Technical library documents.
 *
 * Shares the Product Module blob container/port (ProductBlobStore) without
 * nesting under a Product public id. Paths stay under products/technical/…
 * so existing SAS/read infrastructure remains reusable.
 */
final class TechnicalDocumentPathBuilder
{
    public function documentPath(string $documentPublicId, string $safeFileName): string
    {
        return sprintf(
            'products/technical/documents/%s/%s',
            $documentPublicId,
            $safeFileName,
        );
    }
}
