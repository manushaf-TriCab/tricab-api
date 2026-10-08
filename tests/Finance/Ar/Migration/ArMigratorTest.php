<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar\Migration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\Migration\InMemoryArMessageIdTranslator;
use TriCab\Api\Finance\Ar\Migration\InMemoryArMigrationTarget;
use TriCab\Api\Finance\Ar\Migration\SqliteArMigrationSource;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Migration\ArAttachmentFolder;
use TriCab\Core\Finance\Ar\Migration\ArMessageIdTranslationFailed;
use TriCab\Core\Finance\Ar\Migration\ArMigrationOptions;
use TriCab\Core\Finance\Ar\Migration\ArMigrationPlan;
use TriCab\Core\Finance\Ar\Migration\ArMigrationRefused;
use TriCab\Core\Finance\Ar\Migration\ArMigrationReport;
use TriCab\Core\Finance\Ar\Migration\ArMigrationUsers;
use TriCab\Core\Finance\Ar\Migration\ArMigrator;
use TriCab\Core\Finance\Ar\Migration\ArRowMapper;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMapping;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentRules;

/**
 * The Tri-AR migration (T5.15) on the in-memory twins, over a SQLite file and an attachments folder the test builds from
 * invented data: every rule of the brief, the repeat run, each refusal, each counted problem, and that the report
 * holds no content.
 */
final class ArMigratorTest extends TestCase
{
    private InventedTriArData $data;

    private InMemoryArMigrationTarget $target;

    private InMemoryFinanceAttachmentStore $files;

    protected function setUp(): void
    {
        $this->data = new InventedTriArData();
        $this->target = new InMemoryArMigrationTarget();
        $this->files = new InMemoryFinanceAttachmentStore();
    }

    /** @param array<string, string> $table old message id => immutable id */
    private function translator(array $table = [], bool $down = false): InMemoryArMessageIdTranslator
    {
        return new InMemoryArMessageIdTranslator($table, $down);
    }

    private function migrator(?InMemoryArMessageIdTranslator $translator = null, ?ArMigrationUsers $users = null): ArMigrator
    {
        return new ArMigrator(
            new SqliteArMigrationSource($this->data->database),
            $this->target,
            new ArAttachmentFolder($this->data->attachments),
            $this->files,
            $users ?? new ArMigrationUsers(),
            $translator,
        );
    }

    private function plan(bool $apply = false, bool $translate = false, bool $without = true, ?InMemoryArMessageIdTranslator $translator = null, ?ArMigrationUsers $users = null): ArMigrationPlan
    {
        return $this->migrator($translator, $users)->prepare(new ArMigrationOptions($apply, $translate, $without && !$translate));
    }

    /** Plans and applies with the message ids blanked (unless a translator is given). */
    private function apply(?InMemoryArMessageIdTranslator $translator = null, ?ArMigrationUsers $users = null): ArMigrationReport
    {
        $migrator = $this->migrator($translator, $users);
        $plan = $migrator->prepare(new ArMigrationOptions(true, $translator !== null, $translator === null));

        return $migrator->apply($plan);
    }

    private function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    /**
     * A record with one attachment and two lines.
     *
     * @return array{0: string, 1: string, 2: list<string>} the record, the attachment and the lines
     */
    private function fullRecord(): array
    {
        $record = $this->data->record();
        $attachment = $this->data->attachment($record);
        // Ids that sort the opposite way to their rows.
        $first = $this->data->line($attachment, $record, ['id' => 'f' . substr($this->data->id(), 1), 'invoice_number' => 'INV-1']);
        $second = $this->data->line($attachment, $record, ['invoice_number' => 'INV-2']);

        return [$record, $attachment, [$first, $second]];
    }

    // ---- plan --------------------------------------------------------------------------

    public function test_a_plan_writes_nothing_anywhere_and_counts_what_it_would_do(): void
    {
        [$record, $attachment] = $this->fullRecord();
        $this->data->record();
        $this->data->scanRun();
        $this->data->option('category', 'other', ['is_system' => 1]);
        $this->data->option('method', 'email', ['is_system' => 1]);

        $plan = $this->plan();
        $report = $plan->report;

        self::assertSame(0, array_sum($this->target->counts()), 'the target is untouched');
        self::assertSame([], $this->files->forRecord('AR', 'email_record', $record));
        self::assertSame(2, $report->count('source_rows', 'email_records'));
        self::assertSame(1, $report->count('source_rows', 'email_attachments'));
        self::assertSame(2, $report->count('source_rows', 'remittance_lines'));
        self::assertSame(2, $report->count('to_insert', 'email_records'));
        self::assertSame(1, $report->count('to_insert', 'email_attachments'));
        self::assertSame(2, $report->count('to_insert', 'remittance_lines'));
        self::assertSame(1, $report->count('to_insert', 'scan_runs'));
        self::assertSame(2, $report->count('to_insert', 'option_lists'));
        self::assertSame(0, $report->count('inserted', 'email_records'));
        self::assertNotSame('', $attachment);
    }

    public function test_a_plan_with_no_decision_about_message_ids_says_apply_would_refuse(): void
    {
        $this->data->record();

        $plan = $this->migrator()->prepare(new ArMigrationOptions());

        self::assertTrue($plan->blocked());
        self::assertSame('undecided', $plan->report->toArray()['message_ids']);
        $this->expectException(ArMigrationRefused::class);
        $this->migrator()->apply($plan);
    }

    public function test_the_report_holds_counts_and_source_ids_and_no_content(): void
    {
        $record = $this->data->record(['subject' => 'Secret subject Zebra', 'customer_name' => 'Zebra Customer Pty Ltd', 'sender_name' => 'Zed Zebra']);
        $this->data->attachment($record, ['file_name' => 'zebra-statement.pdf', 'payer_name' => 'Zebra Payer'], null);   // file missing: a problem
        $bad = $this->data->record(['status' => 'bogus', 'body' => 'Zebra body text']);
        $dup = $this->data->record(['subject' => 'Zebra duplicate']);
        $other = $this->data->record(['subject' => 'Zebra duplicate again']);
        $this->data->outbound($record);
        $translator = $this->translator(['AAMkAD-old-folder-id-' . $dup => 'IMMUTABLE-ZEBRA-1', 'AAMkAD-old-folder-id-' . $other => 'immutable-zebra-1']);

        $plan = $this->plan(translate: true, translator: $translator);
        $report = $plan->report;
        $everything = json_encode($report->toArray(), JSON_THROW_ON_ERROR) . json_encode($report->problems()) . json_encode($report->conflicts()) . json_encode($report->blockers());

        foreach (['Zebra', 'zebra', 'example.test', 'Acme', 'AAMkAD', 'IMMUTABLE', 'immutable', '.pdf', 'ar@tricab.com', 'EFT-'] as $content) {
            self::assertStringNotContainsString($content, $everything, "the report must not carry '{$content}'");
        }
        self::assertStringContainsString($bad, $everything, 'a source row id is shown');
        self::assertGreaterThan(0, $report->problemCount());
        // The id is shown only when it looks like one.
        $report->problem('email_records', 'Zebra Customer Pty Ltd', 'x');
        $problems = $report->problems();
        self::assertSame('(not shown)', $problems[count($problems) - 1]['id']);
    }

    // ---- apply and repeat ---------------------------------------------------------------

    public function test_apply_writes_the_rows_and_the_files_and_a_second_run_writes_nothing(): void
    {
        [$record, $attachment] = $this->fullRecord();
        $this->data->record();
        $this->data->scanRun();
        $this->data->option('category', 'other', ['is_system' => 1]);
        $this->data->deleted('AAMkAD-old-gone');

        $first = $this->apply($this->translator(['AAMkAD-old-folder-id-' . $record => 'IMM-1', 'AAMkAD-old-gone' => 'IMM-GONE']));

        self::assertSame(2, $first->count('inserted', 'email_records'));
        self::assertSame(1, $first->count('inserted', 'email_attachments'));
        self::assertSame(2, $first->count('inserted', 'remittance_lines'));
        self::assertSame(1, $first->count('inserted', 'files'));
        self::assertSame(1, $first->count('inserted', 'scan_runs'));
        self::assertSame(1, $first->count('inserted', 'option_lists'));
        self::assertSame(1, $first->count('inserted', 'deleted_messages'));
        self::assertSame(0, $first->problemCount());
        $files = $this->files->forRecord('AR', 'email_record', $record);
        self::assertCount(1, $files);
        self::assertSame(InventedTriArData::BYTES, $this->files->bytes($files[0]));
        self::assertSame('Remittance Advice.pdf', $files[0]->originalName);
        self::assertSame($attachment, $this->target->attachmentIdsOf($record)[0]);
        $before = $this->target->counts();

        $second = $this->apply($this->translator(['AAMkAD-old-folder-id-' . $record => 'IMM-1', 'AAMkAD-old-gone' => 'IMM-GONE']));

        self::assertSame($before, $this->target->counts());
        self::assertCount(1, $this->files->forRecord('AR', 'email_record', $record));
        foreach (['email_records', 'email_attachments', 'remittance_lines', 'scan_runs', 'option_lists', 'deleted_messages', 'files'] as $table) {
            self::assertSame(0, $second->count('inserted', $table), $table);
        }
        self::assertSame(2, $second->count('already_present', 'email_records'));
        self::assertSame(1, $second->count('already_present', 'email_attachments'));
        self::assertSame(2, $second->count('already_present', 'remittance_lines'));
        self::assertSame(0, $second->problemCount(), 'a record it migrated itself is not a conflict with itself');
    }

    public function test_after_an_apply_the_report_compares_the_source_and_the_target(): void
    {
        $this->fullRecord();
        $this->data->record();

        $report = $this->apply();
        $rows = $report->toArray()['source_vs_target'];

        self::assertIsArray($rows);
        self::assertSame(['source' => 2, 'target' => 2, 'from_source' => 2, 'all_there' => true], $rows['email_records']);
        self::assertSame(['source' => 1, 'target' => 1, 'from_source' => 1, 'all_there' => true], $rows['email_attachments']);
        self::assertSame(['source' => 2, 'target' => 2, 'from_source' => 2, 'all_there' => true], $rows['remittance_lines']);
    }

    public function test_a_repeat_run_finds_a_row_a_person_has_since_edited_and_leaves_it_alone(): void
    {
        $record = $this->data->record(['follow_up_status' => 'needs_review']);
        $this->apply();
        $this->target->records[$record] = $this->target->records[$record]->with(followUpStatus: 'completed');

        $this->apply();

        self::assertSame('completed', $this->target->records[$record]->followUpStatus);
    }

    // ---- field rules --------------------------------------------------------------------

    public function test_the_field_rules(): void
    {
        $long = str_repeat('x', 500);
        $record = $this->data->record([
            'follow_up_status' => 'overdue',
            'due_date' => '2026-10-02T00:00:00+00:00',
            'email_date' => '2026-09-21T12:09:00+10:00',
            'reminder_sent_at' => '2026-10-03T00:00:00.654321+00:00',
            'assigned_person' => 'Pat Example',
            'subject' => $long,
            'customer_name' => $long,
            'sender_name' => $long,
            'body' => str_repeat('b', 30000),
            'status' => 'skipped',
            'classification_confidence' => 0.5,
        ]);
        $tight = $this->data->record(['due_date' => '2026-02-30', 'follow_up_status' => 'in_progress']);
        $junk = $this->data->record(['due_date' => 'next Tuesday']);
        $empty = $this->data->record(['due_date' => '']);

        $report = $this->apply();

        $row = $this->target->records[$record];
        self::assertSame('in_progress', $row->followUpStatus, 'overdue is derived on read');
        self::assertSame('2026-10-02', $row->dueDate);
        self::assertSame('2026-09-21 02:09:00.000000', $row->emailDate->format('Y-m-d H:i:s.u'), 'a local time becomes UTC');
        self::assertSame('2026-10-03 00:00:00.654321', $row->reminderSentAt?->format('Y-m-d H:i:s.u'), 'the reminder mark is kept');
        self::assertSame('Pat Example', $row->assignedName);
        self::assertNull($row->assignedUserId, 'no map, no user id');
        self::assertSame(400, mb_strlen($row->subject));
        self::assertSame(400, mb_strlen($row->customerName));
        self::assertSame(400, mb_strlen($row->senderName));
        self::assertSame(30000, strlen((string) $row->body), 'the body is kept whole');
        self::assertSame('skipped', $row->status);
        self::assertSame(0.5, $row->classificationConfidence);
        self::assertSame('UTC', $row->createdAt->getTimezone()->getName());
        self::assertNull($this->target->records[$tight]->dueDate);
        self::assertNull($this->target->records[$junk]->dueDate);
        self::assertNull($this->target->records[$empty]->dueDate);
        self::assertSame(1, $report->count('findings', 'overdue_to_in_progress'));
        self::assertSame(1, $report->count('findings', 'date_cut_to_ten_characters'));
        self::assertSame(2, $report->count('findings', 'date_not_a_date'));
        self::assertSame(1, $report->count('findings', 'text_cut.subject'));
        self::assertSame(1, $report->count('findings', 'text_cut.customer_name'));
        self::assertSame(1, $report->count('findings', 'text_cut.sender_name'));
    }

    public function test_a_cut_never_splits_a_surrogate_pair(): void
    {
        $record = $this->data->record(['subject' => str_repeat('a', 399) . "\u{1F600}" . 'tail']);

        $this->apply();

        // 399 letters and the first half of an emoji would be 400 units: the pair is dropped, not split.
        self::assertSame(str_repeat('a', 399), $this->target->records[$record]->subject);
    }

    public function test_the_assignee_goes_to_the_name_and_the_user_id_only_when_the_map_resolves_it(): void
    {
        $users = new ArMigrationUsers([
            '17' => new FinancePortalUserMapping('17', 'Lena.Marsh@example.test', 4001, $this->utc('2026-10-01')),
            '18' => new FinancePortalUserMapping('18', 'shared@example.test', 4002, $this->utc('2026-10-01')),
            '19' => new FinancePortalUserMapping('19', 'shared@example.test', 4003, $this->utc('2026-10-01')),
        ]);
        $byEmail = $this->data->record(['assigned_person' => 'lena.marsh@example.test']);
        $byName = $this->data->record(['assigned_person' => 'Lena Marsh']);
        $byPortalId = $this->data->record(['assigned_person' => '18']);
        $ambiguous = $this->data->record(['assigned_person' => 'shared@example.test']);
        $deleted = $this->data->record(['deleted_at' => '2026-10-01T00:00:00+00:00', 'deleted_by' => 'lena.marsh@example.test']);

        $report = $this->apply(users: $users);

        self::assertSame(4001, $this->target->records[$byEmail]->assignedUserId);
        self::assertSame('lena.marsh@example.test', $this->target->records[$byEmail]->assignedName);
        self::assertNull($this->target->records[$byName]->assignedUserId, 'a name is never guessed at');
        self::assertSame('Lena Marsh', $this->target->records[$byName]->assignedName);
        self::assertSame(4002, $this->target->records[$byPortalId]->assignedUserId);
        self::assertNull($this->target->records[$ambiguous]->assignedUserId, 'an address two portal users share is not guessed at');
        self::assertSame(4001, $this->target->records[$deleted]->deletedByUserId);
        self::assertNotNull($this->target->records[$deleted]->deletedAt);
        self::assertSame(2, $report->count('findings', 'assignee_resolved'), 'the address and the portal id');
        self::assertSame(2, $report->count('findings', 'assignee_not_resolved'), 'the name and the shared address');
    }

    public function test_a_value_the_target_would_refuse_is_a_problem_and_the_row_is_not_inserted(): void
    {
        $good = $this->data->record();
        $badStatus = $this->data->record(['status' => 'bogus']);
        $badFollowUp = $this->data->record(['follow_up_status' => 'auto_categorised']);
        $badDate = $this->data->record(['email_date' => 'yesterday-ish']);
        $badConfidence = $this->data->record(['classification_confidence' => 1.5]);
        $withChild = $this->data->record(['status' => 'bogus']);
        $this->data->attachment($withChild);

        $report = $this->apply();

        self::assertSame([$good], array_keys($this->target->records));
        self::assertSame(5, $report->problemCount());
        self::assertSame(2, $report->count('problems', 'email_records.status_not_allowed'));
        self::assertSame(1, $report->count('problems', 'email_records.follow_up_status_not_allowed'));
        self::assertSame(1, $report->count('problems', 'email_records.email_date_invalid'));
        self::assertSame(1, $report->count('problems', 'email_records.classification_confidence_out_of_range'));
        self::assertSame(1, $report->count('skipped', 'email_attachments.record_not_migrated'), 'the attachment of a record left out is left out with it');
        self::assertSame([], $this->files->forRecord('AR', 'email_record', $withChild));
        self::assertNotSame('', $badStatus . $badFollowUp . $badDate . $badConfidence);
    }

    public function test_ids_are_kept_when_they_fit_and_derived_the_same_way_every_time_when_not(): void
    {
        $kept = $this->data->record();
        $legacy = $this->data->record(['id' => 'legacy-record-1']);
        $upper = $this->data->record(['id' => strtoupper('abcdef0123456789abcdef0123456789')]);

        $first = $this->apply();
        $ids = array_keys($this->target->records);
        $second = $this->apply();

        self::assertContains($kept, $ids);
        self::assertNotContains('legacy-record-1', $ids);
        self::assertNotContains('ABCDEF0123456789ABCDEF0123456789', $ids);
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        }
        self::assertCount(3, $ids);
        self::assertSame(2, $first->count('findings', 'ids_derived.email_records'));
        self::assertSame(0, $second->count('inserted', 'email_records'));
        self::assertCount(3, $this->target->records);
        self::assertNotSame('', $legacy . $upper);
    }

    public function test_amounts_are_two_places_and_a_wild_one_is_refused(): void
    {
        $record = $this->data->record();
        $attachment = $this->data->attachment($record, ['total_amount' => 10.005]);
        $this->data->line($attachment, $record, ['gross_amount' => 100.0, 'discount_amount' => 0.1 + 0.2, 'paid_amount' => 99.7]);
        $other = $this->data->attachment($record, ['total_amount' => 1.0E+20]);

        $report = $this->apply();

        $line = array_values($this->target->lines)[0];
        self::assertSame('100.00', $line->grossAmount);
        self::assertSame('0.30', $line->discountAmount);
        self::assertSame('99.70', $line->paidAmount);
        self::assertSame(1, $report->count('problems', 'email_attachments.total_amount_out_of_range'));
        self::assertSame([$attachment], $this->target->attachmentIdsOf($record));
        self::assertNotSame('', $other);
    }

    // ---- message ids (Q1) --------------------------------------------------------------

    public function test_translated_ids_are_kept_and_an_untranslatable_one_keeps_its_row_flagged(): void
    {
        $moved = $this->data->record();
        $gone = $this->data->record();
        $none = $this->data->record(['source_message_id' => null]);
        $translator = $this->translator(['AAMkAD-old-folder-id-' . $moved => 'IMM-MOVED']);

        $report = $this->apply($translator);

        self::assertSame('IMM-MOVED', $this->target->records[$moved]->sourceMessageId);
        self::assertFalse($this->target->records[$moved]->messageUnreachable);
        self::assertNull($this->target->records[$gone]->sourceMessageId);
        self::assertTrue($this->target->records[$gone]->messageUnreachable);
        self::assertNull($this->target->records[$none]->sourceMessageId);
        self::assertTrue($this->target->records[$none]->messageUnreachable);
        self::assertSame(1, $report->count('findings', 'message_unreachable'));
        self::assertSame(1, $report->count('findings', 'no_message_id_in_source'));
        self::assertSame(0, $report->problemCount(), 'an unreachable message is a flag, not a failure');
        self::assertCount(1, $translator->calls, 'one call per mailbox');
        self::assertSame('ar@tricab.com', $translator->calls[0]['mailbox']);
    }

    public function test_without_message_ids_blanks_every_id_and_flags_every_record(): void
    {
        $a = $this->data->record();
        $b = $this->data->record();

        $this->apply();

        foreach ([$a, $b] as $id) {
            self::assertNull($this->target->records[$id]->sourceMessageId);
            self::assertTrue($this->target->records[$id]->messageUnreachable);
        }
    }

    public function test_two_records_translating_to_one_id_insert_the_first_and_report_the_second(): void
    {
        $first = $this->data->record();
        $second = $this->data->record();
        $third = $this->data->record();
        $translator = $this->translator([
            'AAMkAD-old-folder-id-' . $first => 'Same-Immutable-Id',
            'AAMkAD-old-folder-id-' . $second => 'SAME-immutable-ID',
            'AAMkAD-old-folder-id-' . $third => 'Different',
        ]);

        $report = $this->apply($translator);

        self::assertSame([$first, $third], array_keys($this->target->records), 'compared without regard to case, as the unique index does');
        self::assertSame(1, $report->count('problems', 'email_records.duplicate_message_id'));
        self::assertSame($second, $report->problems()[0]['id']);
    }

    public function test_the_translation_service_being_down_stops_the_run_and_flags_nothing(): void
    {
        $this->data->record();

        $this->expectException(ArMessageIdTranslationFailed::class);
        $this->plan(translate: true, translator: $this->translator(down: true));
    }

    public function test_translate_ids_without_a_translator_and_both_options_together_are_refused(): void
    {
        $this->data->record();

        try {
            $this->migrator()->prepare(new ArMigrationOptions(true, true, false));
            self::fail('Expected a refusal.');
        } catch (ArMigrationRefused $refused) {
            self::assertStringContainsString('translator', $refused->getMessage());
        }
        $this->expectException(ArMigrationRefused::class);
        $this->migrator($this->translator())->prepare(new ArMigrationOptions(true, true, true));
    }

    public function test_an_id_too_long_to_store_is_not_cut(): void
    {
        $record = $this->data->record();

        $this->apply($this->translator(['AAMkAD-old-folder-id-' . $record => str_repeat('i', 201)]));

        self::assertNull($this->target->records[$record]->sourceMessageId);
        self::assertTrue($this->target->records[$record]->messageUnreachable);
    }

    // ---- conflicts with what the platform already holds ---------------------------------

    public function test_a_row_the_platform_scanned_itself_with_the_same_message_id_is_a_conflict_and_apply_refuses(): void
    {
        $mine = $this->data->record();
        $clash = $this->data->record();
        $translator = $this->translator(['AAMkAD-old-folder-id-' . $mine => 'IMM-A', 'AAMkAD-old-folder-id-' . $clash => 'IMM-B']);
        $scanned = \TriCab\Api\Tests\Finance\Ar\ArFixtures::record('0000000000000000000000000000beef', sourceMessageId: 'imm-b');
        $this->target->insertRecord($scanned);

        $plan = $this->plan(translate: true, translator: $translator);

        self::assertSame(1, $plan->report->conflictCount());
        self::assertSame($clash, $plan->report->conflicts()[0]['id']);
        self::assertTrue($plan->blocked());
        self::assertSame(1, $plan->report->count('to_insert', 'email_records'), 'the clashing record is not counted as an insert');
        try {
            $this->migrator($translator)->apply($plan);
            self::fail('Expected a refusal.');
        } catch (ArMigrationRefused $refused) {
            self::assertStringContainsString('conflict', $refused->getMessage());
        }
        self::assertSame(1, $this->target->counts()['ar_email_records'], 'nothing was written');
        self::assertSame([], $this->files->forRecord('AR', 'email_record', $mine));
    }

    public function test_an_option_list_that_differs_from_the_targets_is_a_conflict_not_an_overwrite(): void
    {
        $this->data->option('category', 'other', ['label' => 'Everything else', 'color' => 'rose', 'is_system' => 1]);
        $this->data->option('category', 'remittance_advice', ['label' => 'Remittances']);
        $lazy = new ArOption('category', 'other', 'Other', '', 'slate', 0, true, true, $this->utc('2026-10-01'), $this->utc('2026-10-01'));
        $this->target->insertOption($lazy);

        $plan = $this->plan();

        self::assertSame(1, $plan->report->count('conflicts', 'option_lists'));
        self::assertTrue($plan->blocked());
        self::assertSame($lazy, $this->target->options['category/other']);
        self::assertSame(0, $plan->report->count('to_insert', 'option_lists'));
    }

    public function test_the_teams_option_lists_replace_the_defaults_in_an_empty_target_and_are_not_a_conflict_on_a_repeat(): void
    {
        $this->data->option('category', 'other', ['label' => 'Everything else', 'color' => 'rose', 'sort_order' => 9, 'is_system' => 1]);
        $this->data->option('category', 'remittance_advice', ['label' => 'Remittances', 'active' => 0, 'sort_order' => 1]);
        $this->data->option('method', 'phone', ['label' => 'Phone call', 'is_system' => 1]);

        $this->apply();
        $second = $this->apply();

        $row = $this->target->options['category/remittance_advice'];
        self::assertSame('Remittances', $row->label);
        self::assertFalse($row->active);
        self::assertSame(1, $row->sortOrder);
        self::assertSame('rose', $this->target->options['category/other']->color);
        self::assertTrue($this->target->options['method/phone']->isSystem);
        self::assertSame(3, $second->count('already_present', 'option_lists'));
        self::assertSame(0, $second->conflictCount());
    }

    public function test_an_option_with_a_list_the_target_does_not_allow_is_a_problem(): void
    {
        $this->data->option('colour', 'x');
        $this->data->option('category', 'other');

        $report = $this->apply();

        self::assertSame(1, $report->count('problems', 'option_lists.list_name_not_allowed'));
        self::assertCount(1, $this->target->options);
    }

    // ---- tables not migrated -----------------------------------------------------------

    public function test_the_two_tables_that_are_not_migrated_are_counted_and_refuse_apply_when_not_empty(): void
    {
        $record = $this->data->record();
        $this->data->outbound($record);
        $this->data->creditNote();
        $this->data->creditNote();

        $plan = $this->plan();

        self::assertSame(1, $plan->report->count('source_rows', 'outbound_messages'));
        self::assertSame(2, $plan->report->count('source_rows', 'credit_note_requests'));
        self::assertCount(2, $plan->report->blockers());
        try {
            $this->migrator()->apply($plan);
            self::fail('Expected a refusal.');
        } catch (ArMigrationRefused $refused) {
            self::assertStringContainsString('outbound_messages', $refused->getMessage());
            self::assertStringContainsString('credit_note_requests', $refused->getMessage());
        }
        self::assertSame(0, array_sum($this->target->counts()));
    }

    public function test_with_both_tables_empty_nothing_blocks(): void
    {
        $this->data->record();

        self::assertFalse($this->plan()->blocked());
    }

    // ---- attachments -------------------------------------------------------------------

    public function test_two_rows_naming_one_stored_file_are_two_files(): void
    {
        $record = $this->data->record();
        $one = $this->data->attachment($record, ['stored_name' => $record . '/statement.pdf', 'file_name' => 'Statement.pdf']);
        $two = $this->data->attachment($record, ['stored_name' => $record . '/statement.pdf', 'file_name' => 'Statement.pdf'], null);

        $report = $this->apply();

        $files = $this->files->forRecord('AR', 'email_record', $record);
        self::assertCount(2, $files);
        self::assertNotSame($files[0]->id, $files[1]->id);
        self::assertSame($this->files->bytes($files[0]), $this->files->bytes($files[1]));
        self::assertEqualsCanonicalizing([$one, $two], $this->target->attachmentIdsOf($record));
        self::assertSame(2, $report->count('findings', 'attachment_rows_sharing_a_stored_file'));
        self::assertSame(2, $report->count('inserted', 'files'));
    }

    public function test_a_missing_file_is_reported_and_its_row_and_lines_are_left_out_but_the_record_goes_in(): void
    {
        $record = $this->data->record();
        $present = $this->data->attachment($record);
        $missing = $this->data->attachment($record, [], null);
        $this->data->line($missing, $record);
        $this->data->line($missing, $record);

        $report = $this->apply();

        self::assertTrue($this->target->hasRecord($record));
        self::assertSame([$present], $this->target->attachmentIdsOf($record));
        self::assertSame(1, $report->count('problems', 'email_attachments.file_missing'));
        self::assertSame($missing, $report->problems()[0]['id']);
        self::assertSame(2, $report->count('skipped', 'remittance_lines.attachment_not_migrated'));
        self::assertSame(0, count($this->target->lines));
        self::assertSame(1, $report->problemCount());
    }

    public function test_when_the_missing_file_turns_up_a_repeat_adds_just_that_attachment(): void
    {
        $record = $this->data->record();
        $present = $this->data->attachment($record);
        $late = $this->data->attachment($record, ['stored_name' => $record . '/late.pdf'], null);
        $this->data->line($late, $record);
        $this->apply();
        $this->data->file($record . '/late.pdf', InventedTriArData::BYTES);

        $report = $this->apply();

        self::assertEqualsCanonicalizing([$present, $late], $this->target->attachmentIdsOf($record));
        self::assertSame(1, $report->count('inserted', 'email_attachments'));
        self::assertSame(0, $report->count('inserted', 'email_records'));
        self::assertSame(1, $report->count('inserted', 'remittance_lines'));
        self::assertCount(2, $this->files->forRecord('AR', 'email_record', $record));
    }

    public function test_a_path_that_leaves_the_attachments_folder_is_never_followed(): void
    {
        $record = $this->data->record();
        file_put_contents($this->data->dir . DIRECTORY_SEPARATOR . 'outside.pdf', 'outside the folder');
        mkdir($this->data->dir . DIRECTORY_SEPARATOR . 'attachments-evil');
        file_put_contents($this->data->dir . DIRECTORY_SEPARATOR . 'attachments-evil' . DIRECTORY_SEPARATOR . 'x.pdf', 'a sibling that shares the prefix');
        $up = $this->data->attachment($record, ['stored_name' => '../outside.pdf'], null);
        $sibling = $this->data->attachment($record, ['stored_name' => '../attachments-evil/x.pdf'], null);
        $absolute = $this->data->attachment($record, ['stored_name' => $this->data->dir . DIRECTORY_SEPARATOR . 'outside.pdf'], null);
        $drive = $this->data->attachment($record, ['stored_name' => 'C:\\Windows\\win.ini'], null);

        $report = $this->apply();

        self::assertSame(4, $report->count('problems', 'email_attachments.file_outside_folder'));
        self::assertSame(0, $report->count('problems', 'email_attachments.file_missing'));
        self::assertSame([], $this->target->attachmentIdsOf($record));
        self::assertSame([], $this->files->forRecord('AR', 'email_record', $record));
        self::assertNotSame('', $up . $sibling . $absolute . $drive);
    }

    public function test_an_empty_file_and_a_file_over_the_stores_ceiling_are_reported_not_stored(): void
    {
        $record = $this->data->record();
        $empty = $this->data->attachment($record, ['stored_name' => $record . '/empty.pdf'], '');
        $big = $this->data->attachment($record, ['stored_name' => $record . '/big.pdf'], '');
        $handle = fopen($this->data->attachments . DIRECTORY_SEPARATOR . $record . DIRECTORY_SEPARATOR . 'big.pdf', 'wb');
        self::assertIsResource($handle);
        ftruncate($handle, FinanceAttachmentRules::MAX_BYTES + 1);
        fclose($handle);
        $exactly = $this->data->attachment($record, ['stored_name' => $record . '/exactly.pdf'], '');
        $handle = fopen($this->data->attachments . DIRECTORY_SEPARATOR . $record . DIRECTORY_SEPARATOR . 'exactly.pdf', 'wb');
        self::assertIsResource($handle);
        ftruncate($handle, FinanceAttachmentRules::MAX_BYTES);
        fclose($handle);

        $plan = $this->plan();

        self::assertSame(1, $plan->report->count('problems', 'email_attachments.file_empty'));
        self::assertSame(1, $plan->report->count('problems', 'email_attachments.file_too_large'));
        self::assertSame(1, $plan->report->count('to_insert', 'email_attachments'), 'a file of exactly the ceiling is allowed');
        self::assertNotSame('', $empty . $big . $exactly);
    }

    public function test_orphans_in_the_source_are_reported(): void
    {
        $record = $this->data->record();
        $orphanAttachment = $this->data->attachment('ffffffffffffffffffffffffffffff00', [], InventedTriArData::BYTES);
        $attachment = $this->data->attachment($record);
        $orphanLine = $this->data->line('ffffffffffffffffffffffffffffff01', $record);
        $this->data->line($attachment, $record);

        $report = $this->plan()->report;

        self::assertSame(1, $report->count('problems', 'email_attachments.orphan_no_record'));
        self::assertSame(1, $report->count('problems', 'remittance_lines.orphan_no_attachment'));
        self::assertNotSame('', $orphanAttachment . $orphanLine);
    }

    public function test_lines_are_numbered_from_one_per_attachment_in_the_sources_row_order(): void
    {
        $record = $this->data->record();
        $a = $this->data->attachment($record);
        $b = $this->data->attachment($record);
        // Ids that sort the opposite way to their rows.
        $a1 = $this->data->line($a, $record, ['id' => 'ffffffffffffffffffffffffffffff03', 'invoice_number' => 'A-first']);
        $b1 = $this->data->line($b, $record, ['id' => 'ffffffffffffffffffffffffffffff02', 'invoice_number' => 'B-first']);
        $a2 = $this->data->line($a, $record, ['id' => '00000000000000000000000000000001', 'invoice_number' => 'A-second']);
        $a3 = $this->data->line($a, $record, ['id' => '00000000000000000000000000000002', 'invoice_number' => 'A-third']);

        $this->apply();

        $numbered = [];
        foreach ($this->target->lines as $line) {
            $numbered[$line->attachmentId][$line->lineNo] = $line->invoiceNumber;
        }
        self::assertSame([1 => 'A-first', 2 => 'A-second', 3 => 'A-third'], $numbered[$a]);
        self::assertSame([1 => 'B-first'], $numbered[$b]);
        self::assertNotSame('', $a1 . $b1 . $a2 . $a3);
    }

    // ---- transactions ------------------------------------------------------------------

    public function test_a_record_whose_write_fails_goes_in_whole_or_not_at_all_and_its_files_are_taken_out_again(): void
    {
        [$bad, , $lines] = $this->fullRecord();
        [$good] = $this->fullRecord();
        $this->target->failOnLine = $lines[1];

        $report = $this->apply();

        self::assertFalse($this->target->hasRecord($bad));
        self::assertSame([], $this->target->attachmentIdsOf($bad));
        self::assertSame([], $this->files->forRecord('AR', 'email_record', $bad), 'the file written for the failed record is removed again');
        self::assertTrue($this->target->hasRecord($good));
        self::assertCount(1, $this->files->forRecord('AR', 'email_record', $good));
        self::assertSame(1, $report->count('problems', 'email_records.write_failed.RuntimeException'));
        self::assertSame($bad, $report->problems()[0]['id']);
        self::assertSame(1, $report->count('failed', 'email_records'));
        self::assertSame(1, $report->count('failed', 'email_attachments'));
        self::assertSame(2, $report->count('failed', 'remittance_lines'));
        self::assertSame(1, $report->count('inserted', 'email_records'));

        $this->target->failOnLine = null;
        $again = $this->apply();
        self::assertTrue($this->target->hasRecord($bad), 'a repeat writes what failed');
        self::assertSame(1, $again->count('inserted', 'email_records'));
        self::assertSame(0, $again->problemCount());
    }

    public function test_a_file_left_by_a_run_that_died_is_reused_not_duplicated(): void
    {
        [$record, $attachment] = $this->fullRecord();
        $this->apply();
        // As if the run died after the file and before the rows.
        $this->target->lines = [];
        $this->target->attachments = [];
        $this->target->records = [];

        $report = $this->apply();

        self::assertCount(1, $this->files->forRecord('AR', 'email_record', $record));
        self::assertSame(0, $report->problemCount());
        self::assertSame([$attachment], $this->target->attachmentIdsOf($record));
    }

    // ---- scan runs ---------------------------------------------------------------------

    public function test_the_watermark_of_the_highest_completed_run_survives(): void
    {
        $this->data->scanRun(['watermark' => '2026-10-04T20:00:00Z', 'started_at' => '2026-10-04T21:00:00+00:00']);
        $this->data->scanRun(['watermark' => '2026-10-06T20:58:11.250000+00:00']);
        $this->data->scanRun(['watermark' => '2026-10-07T10:00:00Z', 'status' => 'failed']);
        $this->data->scanRun(['watermark' => '2026-10-08T10:00:00Z', 'status' => 'stopped']);

        $report = $this->apply();

        $watermarks = $report->toArray()['watermarks'];
        self::assertSame([['source' => '2026-10-06T20:58:11.250000Z', 'target' => '2026-10-06T20:58:11.250000Z', 'target_holds_it' => true]], $watermarks);
        self::assertSame(4, $report->count('inserted', 'scan_runs'));
        $held = $this->target->latestWatermarks();
        self::assertSame('2026-10-06 20:58:11.250000', $held['ar@tricab.com']->format('Y-m-d H:i:s.u'), 'the next scan starts from here');
    }

    public function test_a_run_still_running_in_the_copy_is_closed_so_it_cannot_hold_the_mailbox(): void
    {
        $run = $this->data->scanRun(['status' => 'running', 'finished_at' => null, 'watermark' => null]);
        $bad = $this->data->scanRun(['status' => 'weird']);

        $report = $this->apply();

        self::assertSame('failed', $this->target->scanRuns[$run]->status);
        self::assertSame(1, $report->count('findings', 'running_scan_run_closed'));
        self::assertSame(1, $report->count('problems', 'scan_runs.scan_status_not_allowed'));
        self::assertNotSame('', $bad);
    }

    public function test_scan_run_text_dates_and_the_requested_by_person_are_converted(): void
    {
        $users = new ArMigrationUsers(['9' => new FinancePortalUserMapping('9', 'op@example.test', 77, $this->utc('2026-10-01'))]);
        $run = $this->data->scanRun(['from_date' => '2026-09-01', 'to_date' => '2026-09-05T00:00:00', 'requested_by' => 'op@example.test']);

        $this->apply(users: $users);

        $row = $this->target->scanRuns[$run];
        self::assertSame('2026-09-01', $row->fromDate);
        self::assertSame('2026-09-05', $row->toDate);
        self::assertSame(77, $row->requestedByUserId);
        self::assertSame('op@example.test', $row->requestedBy);
        self::assertSame('2026-10-06 21:00:00', $row->startedAt->format('Y-m-d H:i:s'));
    }

    // ---- tombstones --------------------------------------------------------------------

    public function test_tombstones_are_migrated_translated_and_skipped_when_ids_are_not(): void
    {
        $this->data->deleted('AAMkAD-gone-1');
        $this->data->deleted('AAMkAD-gone-2');

        $withIds = $this->apply($this->translator(['AAMkAD-gone-1' => 'IMM-G1']));
        self::assertSame(1, $withIds->count('inserted', 'deleted_messages'));
        self::assertSame(1, $withIds->count('skipped', 'deleted_messages.message_unreachable'));
        self::assertArrayHasKey('IMM-G1', $this->target->tombstones);

        $blank = new InMemoryArMigrationTarget();
        $this->target = $blank;
        $without = $this->apply();
        self::assertSame(2, $without->count('skipped', 'deleted_messages.message_ids_not_translated'));
        self::assertSame([], $blank->tombstones);
    }

    // ---- the source --------------------------------------------------------------------

    public function test_a_file_that_is_not_a_tri_ar_database_is_refused_and_a_missing_path_is_not_created(): void
    {
        $missing = $this->data->dir . DIRECTORY_SEPARATOR . 'nothing.sqlite3';
        try {
            new SqliteArMigrationSource($missing);
            self::fail('Expected a refusal.');
        } catch (\InvalidArgumentException) {
            self::assertFileDoesNotExist($missing);
        }
        $other = $this->data->dir . DIRECTORY_SEPARATOR . 'other.sqlite3';
        $pdo = new \PDO('sqlite:' . $other);
        $pdo->exec('CREATE TABLE unrelated (id INTEGER)');
        $pdo = null;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a Tri-AR database');
        new SqliteArMigrationSource($other);
    }

    public function test_the_source_is_opened_read_only(): void
    {
        $this->data->record();
        $before = md5_file($this->data->database);

        $this->apply();

        self::assertSame($before, md5_file($this->data->database));
    }

    public function test_a_source_without_the_bins_columns_still_reads(): void
    {
        $record = $this->data->record();
        $old = $this->data->dir . DIRECTORY_SEPARATOR . 'old.sqlite3';
        copy($this->data->database, $old);
        $pdo = new \PDO('sqlite:' . $old);
        $pdo->exec('ALTER TABLE email_records DROP COLUMN deleted_at');
        $pdo->exec('ALTER TABLE email_records DROP COLUMN deleted_by');
        $pdo = null;
        $migrator = new ArMigrator(new SqliteArMigrationSource($old), $this->target, new ArAttachmentFolder($this->data->attachments), $this->files, new ArMigrationUsers());

        $migrator->apply($migrator->prepare(new ArMigrationOptions(true, false, true)));

        self::assertNull($this->target->records[$record]->deletedAt);
    }

    public function test_the_row_mapper_names_what_it_refuses_by_code_word_only(): void
    {
        $mapper = new ArRowMapper(new ArMigrationReport(), new ArMigrationUsers(), new ArAttachmentFolder($this->data->attachments));

        try {
            $mapper->record(['id' => 'x', 'email_date' => 'Secret Customer Pty Ltd', 'created_date' => '2026-01-01T00:00:00Z'], 'x', null, true);
            self::fail('Expected a bad value.');
        } catch (\TriCab\Core\Finance\Ar\Migration\ArMigrationBadValue $bad) {
            self::assertSame('email_date_invalid', $bad->kind);
            self::assertStringNotContainsString('Secret', $bad->getMessage());
        }
    }
}
