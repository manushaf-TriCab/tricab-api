<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

use TriCab\Core\Npr\Domain\NewNprAttachment;
use TriCab\Core\Npr\Domain\NprAttachment;
use TriCab\Core\Npr\Domain\NprPerson;

/** Attachment metadata (`[npr].[Attachments]`). Callers decide who may see or change a file. */
interface NprAttachmentRepository
{
    /**
     * Records the file and one Activity row (`attachment_added`) in one transaction.
     * Null when the request does not exist.
     */
    public function add(string $requestPublicId, NewNprAttachment $new, string $actorRole): ?NprAttachment;

    /** @return list<NprAttachment> not deleted, oldest first */
    public function list(string $requestPublicId): array;

    /** A file of that request that is not deleted, or null. */
    public function find(string $requestPublicId, string $attachmentPublicId): ?NprAttachment;

    /**
     * Soft delete (the row and the blob are kept) with one Activity row (`attachment_removed`).
     * Null when there is no such live file on the request.
     */
    public function softDelete(string $requestPublicId, string $attachmentPublicId, NprPerson $by, string $actorRole): ?NprAttachment;

    /**
     * Every blob key the request ever stored, soft-deleted files included
     * (their blobs are kept), for removal when the request itself is deleted.
     *
     * @return list<string>
     */
    public function allBlobKeys(string $requestPublicId): array;
}
