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
}