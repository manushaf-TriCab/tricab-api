<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/** The in-memory file store whose removals fail: a blob store that is down after the database commit. */
final class FailingDeleteAttachmentStore implements FinanceAttachmentStore
{
    public function __construct(public readonly InMemoryFinanceAttachmentStore $inner = new InMemoryFinanceAttachmentStore())
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

    public function countsByRecordType(string $application, string $recordType): array
    {
        return $this->inner->countsByRecordType($application, $recordType);
    }

    public function deleteForRecord(string $application, string $recordType, string $recordId): int
    {
        throw new RuntimeException('The blob store is down');
    }

    public function delete(string $application, string $id): bool
    {
        throw new RuntimeException('The blob store is down');
    }

    public function deleteUnattachedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        return $this->inner->deleteUnattachedBefore($cutoff, $limit);
    }
}
