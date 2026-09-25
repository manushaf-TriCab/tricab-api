<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Document;

use TriCab\Core\Onboarding\Application\DTO\Document\RenderedDocument;
use TriCab\Core\Onboarding\Contract\OnboardingDocumentStore;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Generated documents in their own private container, through the platform's
 * blob store — in memory locally (`BLOB_AUTH_MODE=memory`), Azure otherwise.
 * The container is `BLOB_CONTAINER_ONBOARDING`; it must never be public.
 */
final class BlobOnboardingDocumentStore implements OnboardingDocumentStore
{
    public function __construct(
        private readonly ProductBlobStore $blobs,
        private readonly string $container,
    ) {
    }

    public function put(string $blobPath, RenderedDocument $document): array
    {
        $this->blobs->putObject($this->container, $blobPath, $document->contentType, $document->bytes);

        return ['container' => $this->container, 'path' => $blobPath];
    }
}
