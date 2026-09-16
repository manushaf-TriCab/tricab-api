<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;

/**
 * Dual-read ready attachment port. Stage 2 does not write Blob or download legacy files.
 */
interface ExpenseAttachmentStore
{
    public function describe(ExpenseAttachmentRef $ref): array;

    /**
     * @return array{uploadUrl:string,httpMethod:string,requiredHeaders:array<string,string>,expiresAtUtc:string,container:string,blobPath:string}
     */
    public function initiateWrite(string $blobPath, string $contentType, int $expiresInSeconds): array;

    /**
     * @return array{url:string,httpMethod:string,requiredHeaders:array<string,string>,expiresAtUtc:string}|null
     */
    public function issueAuthorizedRead(ExpenseAttachmentRef $ref, int $expiresInSeconds): ?array;

    public function objectExists(string $blobPath): bool;

    public function deleteIfManaged(ExpenseAttachmentRef $ref): void;

    /**
     * Server-side read of an attachment this store owns. Returns null for
     * legacy WordPress references and for any adapter that cannot read bytes.
     *
     * This is the only way OCR is allowed to obtain a document: callers pass an
     * attachment reference taken off a receipt they have been authorized for,
     * never a URL, so an arbitrary address can never be fetched on our behalf.
     */
    public function readManagedBytes(ExpenseAttachmentRef $ref): ?string;
}