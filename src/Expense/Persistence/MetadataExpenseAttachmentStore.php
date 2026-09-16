<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use RuntimeException;
use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;

final class MetadataExpenseAttachmentStore implements ExpenseAttachmentStore
{
    public function describe(ExpenseAttachmentRef $ref): array
    {
        return [
            'storageSource' => $ref->storageSource(),
            'fileName' => $ref->fileName(),
            'hasReference' => $ref->reference() !== '',
            'dualReadClass' => $ref->dualReadClass(),
        ];
    }

    public function initiateWrite(string $blobPath, string $contentType, int $expiresInSeconds): array
    {
        unset($blobPath, $contentType, $expiresInSeconds);
        throw new RuntimeException('Expense attachment storage is not configured.');
    }

    public function issueAuthorizedRead(ExpenseAttachmentRef $ref, int $expiresInSeconds): ?array
    {
        unset($ref, $expiresInSeconds);

        return null;
    }

    public function objectExists(string $blobPath): bool
    {
        unset($blobPath);

        return false;
    }

    public function deleteIfManaged(ExpenseAttachmentRef $ref): void
    {
        unset($ref);
    }

    public function readManagedBytes(ExpenseAttachmentRef $ref): ?string
    {
        unset($ref);

        return null;
    }
}
