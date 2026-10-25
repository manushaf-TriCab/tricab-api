<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Attachments;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/** Rows and bytes held in memory, with the same rules as the SQL and blob store: the tests', and a database-less run's. */
final class InMemoryFinanceAttachmentStore implements FinanceAttachmentStore
{
    /** @var array<string, FinanceAttachment> keyed by id, in insertion order */
    private array $rows = [];

    /** @var array<string, string> bytes keyed by blob path */
    private array $blobs = [];

    public function put(FinanceAttachment $attachment, string $bytes): void
    {
        $this->blobs[$attachment->blobPath] = $bytes;
        $this->rows[$attachment->id] = $attachment;
    }

    public function find(string $application, string $id): ?FinanceAttachment
    {
        $row = $this->rows[$id] ?? null;

        return $row !== null && $row->application === $application ? $row : null;
    }

    public function bytes(FinanceAttachment $attachment): ?string
    {
        return $this->blobs[$attachment->blobPath] ?? null;
    }

    public function attach(string $application, string $id, string $recordType, string $recordId, DateTimeImmutable $at): bool
    {
        $row = $this->find($application, $id);
        if ($row === null || $row->isAttached()) {
            return false;
        }
        $this->rows[$id] = $row->withRecord($recordType, $recordId, $at);

        return true;
    }

    public function forRecord(string $application, string $recordType, string $recordId): array
    {
        $rows = array_values(array_filter(
            $this->rows,
            static fn (FinanceAttachment $row): bool => $row->application === $application
                && $row->recordType === $recordType
                && $row->recordId !== null && strcasecmp($row->recordId, $recordId) === 0,
        ));
        usort($rows, static fn (FinanceAttachment $a, FinanceAttachment $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        return $rows;
    }

    public function countsByRecordType(string $application, string $recordType): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            if ($row->application === $application && $row->recordType === $recordType && $row->recordId !== null) {
                $counts[$row->recordId] = ($counts[$row->recordId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function deleteForRecord(string $application, string $recordType, string $recordId): int
    {
        $rows = $this->forRecord($application, $recordType, $recordId);
        foreach ($rows as $row) {
            $this->remove($row);
        }

        return count($rows);
    }

    public function delete(string $application, string $id): bool
    {
        $row = $this->find($application, $id);
        if ($row === null) {
            return false;
        }
        $this->remove($row);

        return true;
    }

    public function deleteUnattachedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        $deleted = 0;
        foreach ($this->rows as $row) {
            if ($deleted >= $limit) {
                break;
            }
            if (!$row->isAttached() && $row->createdAt < $cutoff) {
                $this->remove($row);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /** @return list<string> every stored blob path: what a test checks to see no bytes were left behind */
    public function blobPaths(): array
    {
        return array_keys($this->blobs);
    }

    private function remove(FinanceAttachment $row): void
    {
        unset($this->rows[$row->id], $this->blobs[$row->blobPath]);
    }
}
