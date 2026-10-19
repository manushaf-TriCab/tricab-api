<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Storage;

use Throwable;
use TriCab\Core\Npr\Contract\NprAttachmentStore;
use TriCab\Core\Npr\Domain\Exception\NprAttachmentStorageUnavailable;
use TriCab\Core\Product\Contract\ProductBlobStore;

/**
 * Attachments in their own private container (`NPR_ATTACHMENTS_CONTAINER`)
 * through the platform's blob store: in memory locally (`BLOB_AUTH_MODE=memory`),
 * Azure otherwise. The container must already exist and must never be public;
 * like onboarding's, this does not create it. Bytes are only ever read back by
 * the authenticated, request-scoped download.
 */
final class BlobNprAttachmentStore implements NprAttachmentStore
{
    public function __construct(
        private readonly ProductBlobStore $blobs,
        private readonly string $container,
    ) {
    }

    public function put(string $blobKey, string $contentType, string $bytes): void
    {
        try {
            $this->blobs->putObject($this->container, $blobKey, $contentType, $bytes);
        } catch (Throwable) {
            throw new NprAttachmentStorageUnavailable('Attachment storage is not available.');
        }
    }

    public function get(string $blobKey): ?string
    {
        try {
            return $this->blobs->getObjectBinary($this->container, $blobKey);
        } catch (Throwable) {
            throw new NprAttachmentStorageUnavailable('Attachment storage is not available.');
        }
    }

    public function discard(string $blobKey): void
    {
        try {
            $this->blobs->deleteObject($this->container, $blobKey);
        } catch (Throwable) {
            // Best effort: an orphaned blob is unreachable (no row names it).
        }
    }
}
