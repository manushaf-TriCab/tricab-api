<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar\Migration;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Migration\ArMigratedAttachment;
use TriCab\Core\Finance\Ar\Migration\ArMigratedLine;
use TriCab\Core\Finance\Ar\Migration\ArMigratedScanRun;
use TriCab\Core\Finance\Ar\Migration\ArMigrationTarget;

/**
 * The Tri-AR tables held in memory, with the SQL's rules that matter to the migration: a message id is unique
 * without regard to case where present, an option is keyed by list and value, a transaction restores a snapshot on
 * failure. Tests may set `$failOnLine` to a line id to make that write throw.
 */
final class InMemoryArMigrationTarget implements ArMigrationTarget
{
    /** @var array<string, ArRecord> */
    public array $records = [];

    /** @var array<string, ArMigratedAttachment> */
    public array $attachments = [];

    /** @var array<string, ArMigratedLine> */
    public array $lines = [];

    /** @var array<string, ArMigratedScanRun> */
    public array $scanRuns = [];

    /** @var array<string, ArOption> keyed by `list/value` */
    public array $options = [];

    /** @var array<string, DateTimeImmutable> */
    public array $tombstones = [];

    public ?string $failOnLine = null;

    private int $depth = 0;

    public function counts(): array
    {
        return [
            'ar_email_records' => count($this->records),
            'ar_email_attachments' => count($this->attachments),
            'ar_remittance_lines' => count($this->lines),
            'ar_scan_runs' => count($this->scanRuns),
            'ar_option_lists' => count($this->options),
            'ar_deleted_messages' => count($this->tombstones),
        ];
    }

    public function hasRecord(string $id): bool
    {
        return isset($this->records[$id]);
    }

    public function recordHoldingMessage(string $messageId): ?string
    {
        foreach ($this->records as $record) {
            if ($record->sourceMessageId !== null && strcasecmp($record->sourceMessageId, $messageId) === 0) {
                return $record->id;
            }
        }

        return null;
    }

    public function attachmentIdsOf(string $recordId): array
    {
        $ids = [];
        foreach ($this->attachments as $attachment) {
            if ($attachment->recordId === $recordId) {
                $ids[] = $attachment->id;
            }
        }

        return $ids;
    }

    public function options(string $listName): array
    {
        return array_values(array_filter($this->options, static fn (ArOption $o): bool => $o->listName === $listName));
    }

    public function hasScanRun(string $id): bool
    {
        return isset($this->scanRuns[$id]);
    }

    public function hasTombstone(string $messageId): bool
    {
        foreach (array_keys($this->tombstones) as $held) {
            if (strcasecmp((string) $held, $messageId) === 0) {
                return true;
            }
        }

        return false;
    }

    public function latestWatermarks(): array
    {
        $out = [];
        foreach ($this->scanRuns as $run) {
            if ($run->status === 'completed' && $run->watermark !== null && (!isset($out[$run->mailbox]) || $out[$run->mailbox] < $run->watermark)) {
                $out[$run->mailbox] = $run->watermark;
            }
        }

        return $out;
    }

    public function transactional(callable $work): mixed
    {
        if ($this->depth > 0) {
            return $work();
        }
        $snapshot = [$this->records, $this->attachments, $this->lines, $this->scanRuns, $this->options, $this->tombstones];
        ++$this->depth;
        try {
            return $work();
        } catch (Throwable $failure) {
            [$this->records, $this->attachments, $this->lines, $this->scanRuns, $this->options, $this->tombstones] = $snapshot;
            throw $failure;
        } finally {
            --$this->depth;
        }
    }

    public function insertRecord(ArRecord $record): void
    {
        if (isset($this->records[$record->id]) || ($record->sourceMessageId !== null && $this->recordHoldingMessage($record->sourceMessageId) !== null)) {
            throw new \RuntimeException('Duplicate record.');
        }
        $this->records[$record->id] = $record;
    }

    public function insertAttachment(ArMigratedAttachment $attachment): void
    {
        if (!isset($this->records[$attachment->recordId])) {
            throw new \RuntimeException('No such record.');
        }
        $this->attachments[$attachment->id] = $attachment;
    }

    public function insertLine(ArMigratedLine $line): void
    {
        if ($this->failOnLine === $line->id) {
            throw new \RuntimeException('Injected failure.');
        }
        $this->lines[$line->id] = $line;
    }

    public function insertScanRun(ArMigratedScanRun $run): void
    {
        $this->scanRuns[$run->id] = $run;
    }

    public function insertOption(ArOption $option): void
    {
        $this->options[$option->listName . '/' . $option->value] = $option;
    }

    public function insertTombstone(string $messageId, DateTimeImmutable $at): void
    {
        $this->tombstones[$messageId] = $at;
    }
}
