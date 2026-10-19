<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

/** Where attachment bytes live: a private Blob container, never Azure SQL. Names are generated, never the client's. */
interface NprAttachmentStore
{
    /** @throws \TriCab\Core\Npr\Domain\Exception\NprAttachmentStorageUnavailable */
    public function put(string $blobKey, string $contentType, string $bytes): void;

    /**
     * @return string|null null when the blob is missing
     * @throws \TriCab\Core\Npr\Domain\Exception\NprAttachmentStorageUnavailable
     */
    public function get(string $blobKey): ?string;

    /** Best effort removal of an orphan (an upload whose row could not be written). */
    public function discard(string $blobKey): void;
}
