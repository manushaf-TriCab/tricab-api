<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use DateTimeImmutable;
use ReflectionClass;
use Throwable;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Service\ArRecordService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/**
 * The Tri-AR data migration (T5.15): reads the old app's data, works out what each target row would be, and
 * (only when asked to) writes it. {@see prepare()} writes nothing anywhere and is the whole of a plan run;
 * {@see apply()} writes what a plan holds, or refuses while anything stands in the way.
 *
 * Safe to repeat: every row has an id that is the same on every run (the source's own when it fits, else derived
 * from it), and a row the target already has under that id is counted and left alone. The rows for one record
 * (the record, its attachment rows, their lines) go in as one transaction; its files go to the file store first
 * and are taken out again if the transaction fails. A file that cannot be found, read or stored, or a value the
 * target refuses, costs that row only (and the lines under an attachment), and is reported by source row id.
 */
final class ArMigrator
{
    private const NOT_MIGRATED = ['outbound_messages', 'credit_note_requests'];

    public function __construct(
        private readonly ArMigrationSource $source,
        private readonly ArMigrationTarget $target,
        private readonly ArAttachmentFolder $folder,
        private readonly FinanceAttachmentStore $files,
        private readonly ArMigrationUsers $users,
        private readonly ?ArMessageIdTranslator $translator = null,
    ) {
    }

    /**
     * Reads the source and the target and writes nothing.
     *
     * @throws ArMigrationRefused when the options contradict one another or ask for what is not there
     * @throws ArMessageIdTranslationFailed when the translation service cannot be used
     */
    public function prepare(ArMigrationOptions $options): ArMigrationPlan
    {
        if ($options->translateIds && $options->withoutMessageIds) {
            throw new ArMigrationRefused(['--translate-ids and --without-message-ids contradict one another.']);
        }
        if ($options->translateIds && $this->translator === null) {
            throw new ArMigrationRefused(['No message id translator is available, so --translate-ids cannot be used.']);
        }

        $report = new ArMigrationReport();
        $mapper = new ArRowMapper($report, $this->users, $this->folder);

        $counts = $this->source->counts();
        foreach ($counts as $table => $rows) {
            $report->add('source_rows', $table, $rows);
        }
        foreach (self::NOT_MIGRATED as $table) {
            if (($counts[$table] ?? 0) > 0) {
                $report->blocker(sprintf('%s has %d row(s) and this tool does not migrate that table.', $table, $counts[$table]));
            }
        }
        if (!$options->translateIds && !$options->withoutMessageIds) {
            $report->blocker('Neither --translate-ids nor --without-message-ids was given, so the records\' message ids are undecided.');
            $report->set('message_ids', 'undecided');
        } else {
            $report->set('message_ids', $options->translateIds ? 'translated' : 'blanked');
        }

        $messages = $this->messageIds($options, $report);
        $units = $this->units($mapper, $report, $messages, $options->translateIds);
        [$scanRuns, $watermarks] = $this->scanRuns($mapper, $report);
        $optionRows = $this->optionRows($mapper, $report);
        $tombstones = $this->tombstones($mapper, $report, $options);

        foreach ($this->source->orphanAttachmentIds() as $id) {
            $report->problem('email_attachments', $id, 'orphan_no_record');
        }
        foreach ($this->source->orphanLineIds() as $id) {
            $report->problem('remittance_lines', $id, 'orphan_no_attachment');
        }
        $report->set('watermarks', $this->watermarks($watermarks));
        if ($report->conflictCount() > 0) {
            $report->blocker(sprintf('%d row(s) conflict with what the target already holds; this tool does not resolve them.', $report->conflictCount()));
        }

        return new ArMigrationPlan($report, $units, $scanRuns, $optionRows, $tombstones, $watermarks);
    }

    /**
     * Writes what the plan holds. Nothing is written if the plan is blocked.
     *
     * @throws ArMigrationRefused
     */
    public function apply(ArMigrationPlan $plan): ArMigrationReport
    {
        if ($plan->blocked()) {
            throw new ArMigrationRefused($plan->report->blockers());
        }
        $report = $plan->report;

        foreach ([ArOption::LIST_CATEGORY, ArOption::LIST_METHOD] as $list) {
            $rows = array_values(array_filter($plan->options, static fn (ArOption $o): bool => $o->listName === $list));
            if ($rows === []) {
                continue;
            }
            $this->write($report, 'option_lists', $list, count($rows), function () use ($rows): void {
                $this->target->transactional(function () use ($rows): void {
                    foreach ($rows as $row) {
                        $this->target->insertOption($row);
                    }
                });
            });
        }
        foreach ($plan->scanRuns as $run) {
            $this->write($report, 'scan_runs', $run->id, 1, fn () => $this->target->insertScanRun($run));
        }
        foreach ($plan->tombstones as $tombstone) {
            $this->write($report, 'deleted_messages', $tombstone['id'], 1, fn () => $this->target->insertTombstone($tombstone['id'], $tombstone['at']));
        }
        foreach ($plan->units as $unit) {
            $this->applyUnit($unit, $report);
        }

        $report->set('source_vs_target', $this->sourceVsTarget($report));
        $report->set('watermarks', $this->watermarks($plan->sourceWatermarks));

        return $report;
    }

    // ---- reading ------------------------------------------------------------------------

    /**
     * The immutable id (or none) each record is to carry, by source record id.
     *
     * @return array<string, array{id: string|null, unreachable: bool}>
     */
    private function messageIds(ArMigrationOptions $options, ArMigrationReport $report): array
    {
        $refs = $this->source->messageRefs();
        /** @var array<string, array<string, string|null>> $translated mailbox => raw id => immutable id */
        $translated = [];
        if ($options->translateIds && $this->translator !== null) {
            /** @var array<string, array<string, true>> $wanted */
            $wanted = [];
            foreach ($refs as $ref) {
                $raw = self::text($ref['source_message_id']);
                if ($raw !== '') {
                    $wanted[self::mailbox($ref['mailbox'])][$raw] = true;
                }
            }
            foreach ($wanted as $mailbox => $ids) {
                $translated[$mailbox] = $this->translator->translate($mailbox, array_map('strval', array_keys($ids)));
            }
        }

        $out = [];
        foreach ($refs as $ref) {
            $id = self::text($ref['id']);
            $raw = self::text($ref['source_message_id']);
            if ($raw === '') {
                $report->add('findings', 'no_message_id_in_source');
                $out[$id] = ['id' => null, 'unreachable' => true];
                continue;
            }
            $immutable = $options->translateIds ? ($translated[self::mailbox($ref['mailbox'])][$raw] ?? null) : null;
            $immutable = $immutable === null ? null : trim($immutable);
            if ($immutable === '') {
                $immutable = null;
            }
            if ($immutable !== null && mb_strlen($immutable) > 200) {
                // An id cannot be cut to fit; it is treated as one that could not be translated.
                $report->add('findings', 'message_id_too_long');
                $immutable = null;
            }
            if ($immutable === null && $options->translateIds) {
                $report->add('findings', 'message_unreachable');
            }
            $out[$id] = ['id' => $immutable, 'unreachable' => $immutable === null];
        }

        return $out;
    }

    /**
     * @param array<string, array{id: string|null, unreachable: bool}> $messages
     * @return list<ArMigrationUnit>
     */
    private function units(ArRowMapper $mapper, ArMigrationReport $report, array $messages, bool $translated): array
    {
        $units = [];
        /** @var array<string, true> $taken lower-cased immutable ids already given to a record */
        $taken = [];
        /** @var array<string, int> $storedNames how many attachment rows name each stored file */
        $storedNames = [];

        foreach ($this->source->records() as $row) {
            $sourceId = self::text($row['id'] ?? null);
            try {
                $id = $mapper->id('email_records', $row['id'] ?? null);
                $message = $messages[$sourceId] ?? ['id' => null, 'unreachable' => true];
                $record = $mapper->record($row, $id, $message['id'], $message['unreachable']);
            } catch (ArMigrationBadValue $bad) {
                $report->problem('email_records', $sourceId, $bad->kind);
                $this->skipChildren($report, $sourceId, 'record_not_migrated');
                continue;
            }

            if ($message['id'] !== null) {
                $key = strtolower($message['id']);
                if (isset($taken[$key])) {
                    $report->problem('email_records', $sourceId, 'duplicate_message_id');
                    $this->skipChildren($report, $sourceId, 'record_not_migrated');
                    continue;
                }
                $taken[$key] = true;
            }

            $present = $this->target->hasRecord($id);
            if (!$present && $translated && $message['id'] !== null) {
                $holder = $this->target->recordHoldingMessage($message['id']);
                if ($holder !== null && $holder !== $id) {
                    $report->conflict('email_records', $sourceId);
                    $this->skipChildren($report, $sourceId, 'conflict');
                    continue;
                }
            }
            $report->add($present ? 'already_present' : 'to_insert', 'email_records');

            $have = $present ? array_flip($this->target->attachmentIdsOf($id)) : [];
            $add = [];
            foreach ($this->source->attachmentsOf($sourceId) as $attachmentRow) {
                $stored = self::text($attachmentRow['stored_name'] ?? null);
                $storedNames[$stored] = ($storedNames[$stored] ?? 0) + 1;
                $attachmentSource = self::text($attachmentRow['id'] ?? null);
                try {
                    $attachmentId = $mapper->id('email_attachments', $attachmentRow['id'] ?? null);
                } catch (ArMigrationBadValue $bad) {
                    $report->problem('email_attachments', $attachmentSource, $bad->kind);
                    continue;
                }
                $lineRows = $this->source->linesOf($attachmentSource);
                if (isset($have[$attachmentId])) {
                    $report->add('already_present', 'email_attachments');
                    $report->add('already_present', 'remittance_lines', count($lineRows));
                    continue;
                }
                try {
                    $attachment = $mapper->attachment($attachmentRow, $attachmentId, $id);
                } catch (ArMigrationBadValue $bad) {
                    $report->problem('email_attachments', $attachmentSource, $bad->kind);
                    $report->add('skipped', 'remittance_lines.attachment_not_migrated', count($lineRows));
                    continue;
                }
                $lines = [];
                foreach ($lineRows as $position => $lineRow) {
                    try {
                        $lineId = $mapper->id('remittance_lines', $lineRow['id'] ?? null);
                        $lines[] = $mapper->line($lineRow, $lineId, $attachmentId, $id, $position + 1, $attachment->createdAt);
                    } catch (ArMigrationBadValue $bad) {
                        $report->problem('remittance_lines', self::text($lineRow['id'] ?? null), $bad->kind);
                    }
                }
                $report->add('to_insert', 'email_attachments');
                $report->add('to_insert', 'remittance_lines', count($lines));
                $add[] = ['attachment' => $attachment, 'lines' => $lines];
            }

            if (!$present || $add !== []) {
                $units[] = new ArMigrationUnit($sourceId, $record, $present, $add);
            }
        }

        $report->add('findings', 'attachment_rows_sharing_a_stored_file', array_sum(array_filter($storedNames, static fn (int $n): bool => $n > 1)));

        return $units;
    }

    /** Counts a record's attachment rows and lines as left out, for a reason. */
    private function skipChildren(ArMigrationReport $report, string $recordSourceId, string $reason): void
    {
        foreach ($this->source->attachmentsOf($recordSourceId) as $attachmentRow) {
            $report->add('skipped', 'email_attachments.' . $reason);
            $report->add('skipped', 'remittance_lines.' . $reason, count($this->source->linesOf(self::text($attachmentRow['id'] ?? null))));
        }
    }

    /**
     * @return array{0: list<ArMigratedScanRun>, 1: array<string, DateTimeImmutable>} the runs to write, and the highest completed watermark per mailbox in the source
     */
    private function scanRuns(ArRowMapper $mapper, ArMigrationReport $report): array
    {
        $watermarks = [];
        $runs = [];
        foreach ($this->source->scanRuns() as $row) {
            $sourceId = self::text($row['id'] ?? null);
            try {
                $id = $mapper->id('scan_runs', $row['id'] ?? null);
                $run = $mapper->scanRun($row, $id);
            } catch (ArMigrationBadValue $bad) {
                $report->problem('scan_runs', $sourceId, $bad->kind);
                continue;
            }
            if ($run->status === 'completed' && $run->watermark !== null) {
                $best = $watermarks[$run->mailbox] ?? null;
                if ($best === null || $best < $run->watermark) {
                    $watermarks[$run->mailbox] = $run->watermark;
                }
            }
            if ($this->target->hasScanRun($id)) {
                $report->add('already_present', 'scan_runs');
                continue;
            }
            $report->add('to_insert', 'scan_runs');
            $runs[] = $run;
        }

        return [$runs, $watermarks];
    }

    /**
     * Each list is written whole, or not at all: a list the target already holds that differs is a conflict.
     *
     * @return list<ArOption>
     */
    private function optionRows(ArRowMapper $mapper, ArMigrationReport $report): array
    {
        /** @var array<string, list<ArOption>> $byList */
        $byList = [];
        $ordinal = 0;
        foreach ($this->source->optionRows() as $row) {
            ++$ordinal;
            try {
                $option = $mapper->option($row);
            } catch (ArMigrationBadValue $bad) {
                $report->problem('option_lists', 'row-' . $ordinal, $bad->kind);
                continue;
            }
            $byList[$option->listName][] = $option;
        }

        $out = [];
        foreach ($byList as $list => $options) {
            $held = $this->target->options($list);
            if ($held === []) {
                $report->add('to_insert', 'option_lists', count($options));
                array_push($out, ...$options);
            } elseif (self::sameOptions($held, $options)) {
                $report->add('already_present', 'option_lists', count($options));
            } else {
                $report->conflict('option_lists', $list);
            }
        }

        return $out;
    }

    /**
     * @return list<array{id: string, at: DateTimeImmutable}>
     */
    private function tombstones(ArRowMapper $mapper, ArMigrationReport $report, ArMigrationOptions $options): array
    {
        $rows = [];
        foreach ($this->source->deletedMessages() as $row) {
            $rows[] = $row;
        }
        if ($rows === []) {
            return [];
        }
        if (!$options->translateIds || $this->translator === null) {
            // A tombstone is keyed by the message id, and an old per-folder id would never match a scan's.
            $report->add('skipped', 'deleted_messages.message_ids_not_translated', count($rows));

            return [];
        }
        $raw = array_values(array_unique(array_filter(array_map(static fn (array $r): string => self::text($r['source_message_id'] ?? null), $rows), static fn (string $id): bool => $id !== '')));
        $translated = $raw === [] ? [] : $this->translator->translate($options->mailbox, $raw);

        $out = [];
        /** @var array<string, true> $taken */
        $taken = [];
        foreach ($rows as $position => $row) {
            $immutable = trim($translated[self::text($row['source_message_id'] ?? null)] ?? '');
            if ($immutable === '' || mb_strlen($immutable) > 200) {
                $report->add('skipped', 'deleted_messages.message_unreachable');
                continue;
            }
            if (isset($taken[strtolower($immutable)])) {
                $report->add('skipped', 'deleted_messages.duplicate_message_id');
                continue;
            }
            $taken[strtolower($immutable)] = true;
            try {
                $at = $mapper->tombstoneDate($row);
            } catch (ArMigrationBadValue $bad) {
                $report->problem('deleted_messages', 'row-' . ($position + 1), $bad->kind);
                continue;
            }
            if ($this->target->hasTombstone($immutable)) {
                $report->add('already_present', 'deleted_messages');
                continue;
            }
            $report->add('to_insert', 'deleted_messages');
            $out[] = ['id' => $immutable, 'at' => $at];
        }

        return $out;
    }

    /**
     * @param list<ArOption> $held
     * @param list<ArOption> $wanted
     */
    private static function sameOptions(array $held, array $wanted): bool
    {
        if (count($held) !== count($wanted)) {
            return false;
        }
        $key = static fn (ArOption $o): string => json_encode([$o->value, $o->label, $o->description, $o->color, $o->sortOrder, $o->active, $o->isSystem], JSON_THROW_ON_ERROR);
        $a = array_map($key, $held);
        $b = array_map($key, $wanted);
        sort($a);
        sort($b);

        return $a === $b;
    }

    // ---- writing ------------------------------------------------------------------------

    /** One write that is reported by table and source id if it fails; a failure does not stop the run. */
    private function write(ArMigrationReport $report, string $table, string $id, int $rows, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $failure) {
            $report->problem($table, $id, 'write_failed.' . (new ReflectionClass($failure))->getShortName());
            $report->add('failed', $table, $rows);

            return;
        }
        $report->add('inserted', $table, $rows);
    }

    private function applyUnit(ArMigrationUnit $unit, ArMigrationReport $report): void
    {
        $lines = 0;
        foreach ($unit->attachments as $pair) {
            $lines += count($pair['lines']);
        }
        $created = [];
        try {
            foreach ($unit->attachments as $pair) {
                $this->storeFile($pair['attachment'], $created);
            }
            $this->target->transactional(function () use ($unit): void {
                if (!$unit->recordPresent) {
                    $this->target->insertRecord($unit->record);
                }
                foreach ($unit->attachments as $pair) {
                    $this->target->insertAttachment($pair['attachment']);
                    foreach ($pair['lines'] as $line) {
                        $this->target->insertLine($line);
                    }
                }
            });
        } catch (Throwable $failure) {
            foreach ($created as $fileId) {
                try {
                    $this->files->delete(Module::APP_AR, $fileId);
                } catch (Throwable) {
                    // The unit has failed already; the sweep removes a file that never reached a record.
                }
            }
            $report->problem('email_records', $unit->sourceId, 'write_failed.' . (new ReflectionClass($failure))->getShortName());
            $report->add('failed', 'email_records', $unit->recordPresent ? 0 : 1);
            $report->add('failed', 'email_attachments', count($unit->attachments));
            $report->add('failed', 'remittance_lines', $lines);

            return;
        }
        $report->add('inserted', 'email_records', $unit->recordPresent ? 0 : 1);
        $report->add('inserted', 'email_attachments', count($unit->attachments));
        $report->add('inserted', 'remittance_lines', $lines);
        $report->add('inserted', 'files', count($created));
    }

    /**
     * Puts the attachment's bytes in the file store under its derived id and on its record. A file already there
     * from an earlier run that stopped half way is reused; `$created` lists only what this call wrote.
     *
     * @param list<string> $created
     */
    private function storeFile(ArMigratedAttachment $attachment, array &$created): void
    {
        $found = $this->files->find(Module::APP_AR, $attachment->fileId);
        if ($found !== null) {
            if ($found->recordId === null) {
                $this->files->attach(Module::APP_AR, $attachment->fileId, ArRecordService::ATTACHMENT_RECORD_TYPE, $attachment->recordId, $attachment->createdAt);
            } elseif ($found->recordId !== $attachment->recordId || $found->recordType !== ArRecordService::ATTACHMENT_RECORD_TYPE) {
                throw new \RuntimeException('A file with this id already belongs to another record.');
            }

            return;
        }
        $service = new FinanceAttachmentService($this->files, static fn (): DateTimeImmutable => $attachment->createdAt, static fn (): string => $attachment->fileId);
        $created[] = $attachment->fileId;
        $service->upload(Module::APP_AR, $attachment->fileName, $attachment->contentType, $this->folder->read($attachment->filePath), null);
        $service->attach(Module::APP_AR, $attachment->fileId, ArRecordService::ATTACHMENT_RECORD_TYPE, $attachment->recordId, null);
    }

    /**
     * Rows per table: in the source, in the target now (all of it, platform-scanned rows included), and how many of
     * the source's are in the target (already there or written by this run).
     *
     * @return array<string, array{source: int, target: int, from_source: int, all_there: bool}>
     */
    private function sourceVsTarget(ArMigrationReport $report): array
    {
        $target = $this->target->counts();
        $pairs = [
            'email_records' => 'ar_email_records', 'email_attachments' => 'ar_email_attachments', 'remittance_lines' => 'ar_remittance_lines',
            'scan_runs' => 'ar_scan_runs', 'option_lists' => 'ar_option_lists', 'deleted_messages' => 'ar_deleted_messages',
        ];
        $out = [];
        foreach ($pairs as $from => $to) {
            $source = $report->count('source_rows', $from);
            $there = $report->count('already_present', $from) + $report->count('inserted', $from);
            $out[$from] = ['source' => $source, 'target' => $target[$to] ?? 0, 'from_source' => $there, 'all_there' => $there === $source];
        }

        return $out;
    }

    /**
     * Whether the watermark of each mailbox's highest completed run survived: the source's against the target's now.
     *
     * @param array<string, DateTimeImmutable> $source
     * @return list<array{source: string, target: string|null, target_holds_it: bool}>
     */
    private function watermarks(array $source): array
    {
        $target = $this->target->latestWatermarks();
        ksort($source);
        $out = [];
        foreach ($source as $mailbox => $watermark) {
            $held = $target[$mailbox] ?? null;
            $out[] = [
                'source' => $watermark->format('Y-m-d\TH:i:s.u\Z'),
                'target' => $held?->format('Y-m-d\TH:i:s.u\Z'),
                // The platform may have scanned since: a later watermark is the survival of this one, not a loss.
                'target_holds_it' => $held !== null && $held >= $watermark,
            ];
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function mailbox(mixed $value): string
    {
        $text = trim(self::text($value));

        return $text === '' ? ArRowMapper::DEFAULT_MAILBOX : $text;
    }
}
