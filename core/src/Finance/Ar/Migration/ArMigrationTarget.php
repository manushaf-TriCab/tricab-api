<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Domain\ArRecord;

/**
 * Where the migrated rows go: the Tri-AR tables in `finance` (T5.15). Storage only; what to write, in what
 * order and what to skip is {@see ArMigrator}'s, so the in-memory twin and the SQL adapter behave alike.
 * The files are not here: they go through the finance file store.
 */
interface ArMigrationTarget
{
    /** @return array<string, int> rows in each Tri-AR table, by table name */
    public function counts(): array;

    public function hasRecord(string $id): bool;

    /** The id of the record holding this message id (compared without regard to case, as the unique index does), or null. */
    public function recordHoldingMessage(string $messageId): ?string;

    /** @return list<string> the ids of the record's `ar_email_attachments` rows */
    public function attachmentIdsOf(string $recordId): array;

    /** @return list<ArOption> */
    public function options(string $listName): array;

    public function hasScanRun(string $id): bool;

    public function hasTombstone(string $messageId): bool;

    /** @return array<string, DateTimeImmutable> mailbox => the watermark of its latest completed run */
    public function latestWatermarks(): array;

    /**
     * Runs `$work` as one unit: commits when it returns, rolls back when it throws, and rethrows.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;

    public function insertRecord(ArRecord $record): void;

    public function insertAttachment(ArMigratedAttachment $attachment): void;

    public function insertLine(ArMigratedLine $line): void;

    public function insertScanRun(ArMigratedScanRun $run): void;

    public function insertOption(ArOption $option): void;

    public function insertTombstone(string $messageId, DateTimeImmutable $at): void;
}
