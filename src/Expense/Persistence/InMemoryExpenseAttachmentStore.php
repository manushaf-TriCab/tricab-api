<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseAttachmentStore;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseAttachmentRef;
use TriCab\Core\Expense\Module;

/**
 * Process-local attachment adapter for domain tests. HTTP uses AzureBlobExpenseAttachmentStore.
 */
final class InMemoryExpenseAttachmentStore implements ExpenseAttachmentStore
{
    /** @var array<string, true> */
    private array $objects = [];

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
        unset($contentType);
        $path = ltrim($blobPath, '/');

        return [
            'uploadUrl' => 'memory://' . Module::BLOB_CONTAINER . '/' . $path . '?w=1',
            'httpMethod' => 'PUT',
            'requiredHeaders' => ['Content-Type' => 'application/octet-stream', 'x-ms-blob-type' => 'BlockBlob'],
            'expiresAtUtc' => gmdate('Y-m-d\TH:i:s\Z', time() + max(60, $expiresInSeconds)),
            'container' => Module::BLOB_CONTAINER,
            'blobPath' => $path,
        ];
    }

    public function issueAuthorizedRead(ExpenseAttachmentRef $ref, int $expiresInSeconds): ?array
    {
        if (!$ref->isAzureBlobPath()) {
            return null;
        }
        $path = ltrim($ref->reference(), '/');

        return [
            'url' => 'memory://' . Module::BLOB_CONTAINER . '/' . $path . '?r=1',
            'httpMethod' => 'GET',
            'requiredHeaders' => [],
            'expiresAtUtc' => gmdate('Y-m-d\TH:i:s\Z', time() + max(60, $expiresInSeconds)),
        ];
    }

    public function objectExists(string $blobPath): bool
    {
        return isset($this->objects[ltrim($blobPath, '/')]);
    }

    public function deleteIfManaged(ExpenseAttachmentRef $ref): void
    {
        if ($ref->isAzureBlobPath()) {
            unset($this->objects[ltrim($ref->reference(), '/')]);
        }
    }

    public function put(string $blobPath): void
    {
        $this->objects[ltrim($blobPath, '/')] = true;
    }
}
