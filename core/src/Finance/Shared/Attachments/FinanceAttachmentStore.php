<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

use DateTimeImmutable;

/**
 * Where finance files and their rows are kept. Storage only: the rules (sizes,
 * names, ids, paths, who may read) are {@see FinanceAttachmentService}'s, so every
 * adapter behaves the same. Every lookup names the app, so one app cannot reach
 * another's file by id.
 */
interface FinanceAttachmentStore
{
    /** Writes the bytes, then the row, so a row always names bytes that exist. */
    public function put(FinanceAttachment $attachment, string $bytes): void;

    public function find(string $application, string $id): ?FinanceAttachment;

    /** Null when the row's bytes are gone. */
    public function bytes(FinanceAttachment $attachment): ?string;

    /** True when the file was unattached and is now on the record; false, nothing changed, otherwise. */
    public function attach(string $application, string $id, string $recordType, string $recordId, DateTimeImmutable $at): bool;

    /** @return list<FinanceAttachment> oldest first */
    public function forRecord(string $application, string $recordType, string $recordId): array;

    /**
     * How many files each record of one type has: one grouped query for a whole list, so a page of records
     * costs one read however many rows it shows. Records with no file are absent.
     *
     * @return array<string, int> record id to count
     */
    public function countsByRecordType(string $application, string $recordType): array;

    /** Removes the bytes and the row of every file on the record. Returns how many. */
    public function deleteForRecord(string $application, string $recordType, string $recordId): int;

    /** Removes one file's bytes and row. False when there is no such file. */
    public function delete(string $application, string $id): bool;

    /**
     * Removes up to `$limit` files never attached to a record and uploaded before
     * `$cutoff`. A file attached while this runs is left alone. Returns how many.
     */
    public function deleteUnattachedBefore(DateTimeImmutable $cutoff, int $limit): int;
}
