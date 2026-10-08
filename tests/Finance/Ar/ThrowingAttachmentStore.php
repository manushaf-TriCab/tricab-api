<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/** A file store that works until told to fail a record-wide delete (the blob service being down). */
final class ThrowingAttachmentStore implements FinanceAttachmentStore
{
    public bool $failDeletes = true;

    public function __construct(private readonly FinanceAttachmentStore $inner)
    {
    }

    public function put(FinanceAttachment $attachment, string $bytes): void
    {
        $this->inner->put($attachment, $bytes);
    }

    public function find(string $application, string $id): ?FinanceAttachment
    {
        return $this->inner->find($application, $id);
    }

    public function bytes(FinanceAttachment $attachment): ?string
    {
        return $this->inner->bytes($attachment);
    }

    public function attach(string $application, string $id, string $recordType, string $recordId, DateTimeImmutable $at): bool
    {
        return $this->inner->attach($application, $id, $recordType, $recordId, $at);
    }

    public function forRecord(string $application, string $recordType, string $recordId): array
    {
        return $this->inner->forRecord($application, $recordType, $recordId);
    }

    public function deleteForRecord(string $application, string $recordType, string $recordId): int
    {
        if ($this->failDeletes) {
            throw new RuntimeException('blob service unavailable');
        }

        return $this->inner->deleteForRecord($application, $recordType, $recordId);
    }

    public function delete(string $application, string $id): bool
    {
        return $this->inner->delete($application, $id);
    }

    public function deleteUnattachedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        return $this->inner->deleteUnattachedBefore($cutoff, $limit);
    }
}
