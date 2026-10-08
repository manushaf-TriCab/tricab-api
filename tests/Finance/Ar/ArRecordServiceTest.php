<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use stdClass;
use TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArRecordService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;

/**
 * The register on the in-memory twin: derived overdue, filters, Melbourne days, search, sort, paging,
 * the PATCH whitelist, the Bin and the tiles (T5.4), with the source's tests 10, 11 and 14a to 14e and
 * `a_new_due_date_earns_a_new_reminder` ported. "Today" is 22 September 2026 in Melbourne (UTC+10).
 */
final class ArRecordServiceTest extends TestCase
{
    private InMemoryArStore $store;

    private InMemoryArAssigneeDirectory $assignees;

    private InMemoryFinanceAttachmentStore $files;

    private FixedClock $clock;

    private ArRecordService $service;

    private ArOptionService $options;

    protected function setUp(): void
    {
        $this->clock = FixedClock::utc('2026-09-22 03:00:00');
        $time = new ArTime($this->clock, 'Australia/Melbourne');
        $this->store = new InMemoryArStore();
        $this->assignees = new InMemoryArAssigneeDirectory();
        $this->files = new InMemoryFinanceAttachmentStore();
        $this->options = new ArOptionService($this->store, $time);
        $this->service = new ArRecordService($this->store, $this->options, $this->assignees, $time, $this->files);
    }

    private function add(string $id, mixed ...$over): ArRecord
    {
        $record = ArFixtures::record($id, ...$over);
        $this->store->insert($record);

        return $record;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function ids(array $params = []): array
    {
        return ArFixtures::idsOf($this->service->list($params));
    }

    private function refusal(callable $work): string
    {
        try {
            $work();
        } catch (ArInvalid $failure) {
            return $failure->getMessage();
        }
        self::fail('Expected the work to be refused.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function shown(array $row): string
    {
        $status = $row['follow_up_status'];
        self::assertIsString($status);

        return $status;
    }

    private function reminderOf(string $id): ?\DateTimeImmutable
    {
        return $this->store->stored($id)?->reminderSentAt;
    }

    private function storedStatus(string $id): string
    {
        $stored = $this->store->stored($id);
        self::assertInstanceOf(ArRecord::class, $stored);

        return $stored->followUpStatus;
    }

    // ---- derived overdue (Q8) -----------------------------------------------------------

    public function test_overdue_is_derived_from_the_due_date_and_never_written(): void
    {
        $this->add('a', dueDate: '2026-09-21');                                     // yesterday
        $this->add('b', dueDate: '2026-09-22');                                     // today
        $this->add('c', dueDate: '2026-09-21', followUpStatus: 'needs_review');     // the source turned this one overdue too
        $this->add('d', dueDate: '2026-09-01', followUpStatus: 'completed');        // completed is never overdue
        $this->add('e');                                                            // no due date

        $result = $this->service->list(['sort_by' => 'created_date', 'sort_dir' => 'asc']);

        $records = $result['records'];
        self::assertIsArray($records);
        $byId = array_column($records, 'follow_up_status', 'id');
        self::assertSame(['a' => 'overdue', 'b' => 'in_progress', 'c' => 'overdue', 'd' => 'completed', 'e' => 'in_progress'], $byId);
        self::assertSame('in_progress', $this->storedStatus('a'), 'the read wrote nothing');
        self::assertSame('needs_review', $this->storedStatus('c'), 'a needs_review record keeps its stored status');
        $record = $this->service->get('a');
        self::assertSame('overdue', $record['follow_up_status']);
        self::assertSame('in_progress', $this->storedStatus('a'));
    }

    public function test_the_overdue_line_is_melbourne_midnight_not_utc(): void
    {
        $this->add('a', dueDate: '2026-09-22');

        $this->clock->advance('+10 hours 59 minutes');   // 13:59 UTC = 23:59 on the 22nd in Melbourne
        self::assertSame('in_progress', self::shown($this->service->get('a')));

        $this->clock->advance('+1 minute');               // 14:00 UTC = midnight: the 23rd
        self::assertSame('overdue', self::shown($this->service->get('a')));
    }

    public function test_the_status_filter_and_the_tiles_use_the_derived_status(): void
    {
        $this->add('late', dueDate: '2026-09-01', followUpStatus: 'in_progress');
        $this->add('later', dueDate: '2026-09-02', followUpStatus: 'needs_review');
        $this->add('open', followUpStatus: 'in_progress');
        $this->add('review', followUpStatus: 'needs_review');
        $this->add('done', followUpStatus: 'completed', dueDate: '2026-09-01');

        self::assertEqualsCanonicalizing(['late', 'later'], $this->ids(['follow_up_status' => 'overdue']));
        self::assertSame(['open'], $this->ids(['follow_up_status' => 'in_progress']), 'an overdue record is not counted in progress');
        self::assertSame(['review'], $this->ids(['follow_up_status' => 'needs_review']));
        self::assertSame(['done'], $this->ids(['follow_up_status' => 'completed']));

        $metrics = $this->service->metrics(null, null);
        self::assertSame(5, $metrics['total_emails']);
        self::assertSame(1, $metrics['needs_review']);
        self::assertSame(1, $metrics['in_progress']);
        self::assertSame(2, $metrics['overdue_items']);
        self::assertSame(1, $metrics['completed']);
        self::assertEquals(['overdue' => 2, 'in_progress' => 1, 'needs_review' => 1, 'completed' => 1], $metrics['follow_up_status_counts']);
    }

    // ---- local days (tests 14c to 14e) --------------------------------------------------

    public function test_a_period_is_melbourne_calendar_days_not_utc_ones(): void
    {
        // 31 March 14:00 UTC is already 1 April in Melbourne (AEDT, UTC+11): April's, not March's.
        $this->add('april', emailDate: ArFixtures::utc('2026-03-31 14:00:00'));
        $this->add('march', emailDate: ArFixtures::utc('2026-03-30 14:00:00'));     // 01:00 on the 31st
        $march = ['from_date' => '2026-03-01', 'to_date' => '2026-03-31'];
        $april = ['from_date' => '2026-04-01', 'to_date' => '2026-04-30'];

        self::assertSame(['march'], $this->ids($march));
        self::assertSame(['april'], $this->ids($april));
        self::assertSame(1, $this->service->metrics('2026-03-01', '2026-03-31')['total_emails']);
        self::assertSame(1, $this->service->metrics('2026-04-01', '2026-04-30')['total_emails']);
    }

    public function test_the_period_is_applied_before_the_limit_and_the_total_is_the_period_s(): void
    {
        $this->add('first-minutes', emailDate: ArFixtures::utc('2026-05-14 14:30:00'));   // 00:30 on the 15th (AEST, UTC+10)
        $this->add('last-second', emailDate: ArFixtures::utc('2026-05-15 13:59:59'));     // 23:59:59 on the 15th
        $this->add('day-before', emailDate: ArFixtures::utc('2026-05-14 13:00:00'));      // 23:00 on the 14th
        $this->add('next-day', emailDate: ArFixtures::utc('2026-05-15 14:00:00'));        // midnight: the 16th
        for ($n = 1; $n <= 5; ++$n) {
            $this->add('newer-' . $n, emailDate: ArFixtures::utc('2026-06-0' . $n . ' 01:00:00'));
        }

        $result = $this->service->list(['from_date' => '2026-05-15', 'to_date' => '2026-05-15', 'limit' => '2']);

        $records = $result['records'];
        self::assertIsArray($records);
        $ids = array_column($records, 'id');
        sort($ids);
        self::assertSame(['first-minutes', 'last-second'], $ids);
        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['limit']);
    }

    public function test_one_sided_ranges_filter_and_longer_iso_text_reads_its_first_ten_characters(): void
    {
        $this->add('old', emailDate: ArFixtures::utc('2026-01-10 00:00:00'));
        $this->add('new', emailDate: ArFixtures::utc('2026-08-10 00:00:00'));

        self::assertSame(['new'], $this->ids(['from_date' => '2026-06-01']));
        self::assertSame(['old'], $this->ids(['to_date' => '2026-06-01']));
        self::assertSame(['new'], $this->ids(['from_date' => '2026-06-01T09:30:00Z']));
        self::assertSame(['new', 'old'], $this->ids(['from_date' => '']), 'an empty date is no date');
    }

    public function test_a_malformed_date_is_refused_with_the_sources_words(): void
    {
        foreach (['15/05/2026', '2026-5-15', 'yesterday', '2026-02-30'] as $bad) {
            self::assertSame('Dates must use YYYY-MM-DD', $this->refusal(fn () => $this->service->list(['from_date' => $bad])), $bad);
            self::assertSame('Dates must use YYYY-MM-DD', $this->refusal(fn () => $this->service->list(['to_date' => $bad])), $bad);
            self::assertSame('Dates must use YYYY-MM-DD', $this->refusal(fn () => $this->service->metrics($bad, null)), $bad);
        }
    }

    // ---- search (Q9) --------------------------------------------------------------------

    public function test_search_covers_subject_customer_sender_and_summary_but_not_the_body_unless_asked(): void
    {
        $this->add('s', subject: 'Freight invoice', customerName: 'Other', body: 'xyzzy in the body only');
        $this->add('c', subject: 'Hello', customerName: 'Harbour Cables Pty Ltd');
        $this->add('e', subject: 'Hello', senderEmail: 'pay@harbour.example.test', customerName: 'Other');
        $this->add('n', subject: 'Hello', senderName: 'Harbour Hannah', customerName: 'Other');
        $this->add('m', subject: 'Hello', aiSummary: 'Asks about the Harbour account', customerName: 'Other');
        $this->add('x', subject: 'Nothing', customerName: 'Other');

        self::assertEqualsCanonicalizing(['c', 'e', 'n', 'm'], $this->ids(['search' => 'HARBOUR']));
        self::assertSame(['s'], $this->ids(['search' => 'freight']));
        self::assertSame([], $this->ids(['search' => 'xyzzy']), 'the body is not searched by default');
        self::assertSame(['s'], $this->ids(['search' => 'xyzzy', 'search_body' => '1']));
        self::assertSame(['s'], $this->ids(['search' => 'xyzzy', 'search_body' => 'true']));
        self::assertSame([], $this->ids(['search' => 'xyzzy', 'search_body' => '0']));
    }

    public function test_search_wildcards_match_themselves(): void
    {
        $this->add('pct', subject: '50% off freight');
        $this->add('und', subject: 'a_b ref');
        $this->add('plain', subject: 'abcd ref');

        self::assertSame(['pct'], $this->ids(['search' => '50%']));
        self::assertSame(['und'], $this->ids(['search' => 'a_b']));
        self::assertEqualsCanonicalizing(['pct', 'und', 'plain'], $this->ids(['search' => '']), 'an empty search is no search');
    }

    public function test_the_other_filters_are_exact_or_contain_as_the_source_had_them(): void
    {
        $this->add('a', followUpCategory: 'credit_notes', status: 'sent', customerName: 'Bluewater Boats', assignedName: 'Mia Operator');
        $this->add('b', followUpCategory: 'other', status: 'new', customerName: 'Redwater Yachts', assignedName: 'Max Manager');

        self::assertSame(['a'], $this->ids(['category' => 'credit_notes']));
        self::assertSame(['b'], $this->ids(['status' => 'new']));
        self::assertEqualsCanonicalizing(['a', 'b'], $this->ids(['customer_name' => 'water']));
        self::assertSame(['a'], $this->ids(['customer_name' => 'blue']));
        self::assertSame(['b'], $this->ids(['assigned_person' => 'manager']));
        self::assertSame(['a'], $this->ids(['category' => 'credit_notes', 'customer_name' => 'water']));
    }

    // ---- sort (Q9) and paging (D49) -----------------------------------------------------

    private function sortSet(): void
    {
        $this->add('A', subject: 'Banana', customerName: 'Zed', dueDate: null, classificationConfidence: 0.5, assignedName: 'Una', emailDate: ArFixtures::utc('2026-03-01 00:00:00'), finalOutcome: 'z', followUpStatus: 'completed', senderEmail: 'c@x.test', status: 'sent', followUpCategory: 'b_cat', proposedResponseType: 'phone', createdAt: ArFixtures::utc('2026-03-03 00:00:00'));
        $this->add('B', subject: 'Apple', customerName: 'Yan', dueDate: '2026-10-01', classificationConfidence: 0.9, assignedName: 'Max', emailDate: ArFixtures::utc('2026-03-02 00:00:00'), finalOutcome: 'a', followUpStatus: 'in_progress', senderEmail: 'a@x.test', status: 'new', followUpCategory: 'a_cat', proposedResponseType: 'email', createdAt: ArFixtures::utc('2026-03-01 00:00:00'));
        $this->add('C', subject: 'Cherry', customerName: 'Xi', dueDate: '2026-09-01', classificationConfidence: null, assignedName: '', emailDate: ArFixtures::utc('2026-03-03 00:00:00'), finalOutcome: 'm', followUpStatus: 'in_progress', senderEmail: 'b@x.test', status: 'reviewed', followUpCategory: 'c_cat', proposedResponseType: 'email', createdAt: ArFixtures::utc('2026-03-02 00:00:00'));
    }

    public function test_every_sort_key_orders_by_its_own_column(): void
    {
        $this->sortSet();

        self::assertSame(['C', 'B', 'A'], $this->ids(), 'the default is email_date, newest first');
        self::assertSame(['B', 'A', 'C'], $this->ids(['sort_by' => 'subject', 'sort_dir' => 'asc']));
        self::assertSame(['C', 'A', 'B'], $this->ids(['sort_by' => 'subject', 'sort_dir' => 'desc']));
        self::assertSame(['C', 'B', 'A'], $this->ids(['sort_by' => 'customer_name', 'sort_dir' => 'asc']));
        self::assertSame(['B', 'C', 'A'], $this->ids(['sort_by' => 'sender_email', 'sort_dir' => 'asc']), 'a@, b@, c@');
        self::assertSame(['A', 'C', 'B'], $this->ids(['sort_by' => 'due_date', 'sort_dir' => 'asc']), 'no date first');
        self::assertSame(['C', 'A', 'B'], $this->ids(['sort_by' => 'classification_confidence', 'sort_dir' => 'asc']), 'no confidence first');
        self::assertSame(['C', 'B', 'A'], $this->ids(['sort_by' => 'assigned_person', 'sort_dir' => 'asc']), 'unassigned, Max, Una');
        self::assertSame(['B', 'A', 'C'], $this->ids(['sort_by' => 'follow_up_category', 'sort_dir' => 'asc']));
        self::assertSame(['B', 'C', 'A'], $this->ids(['sort_by' => 'proposed_response_type', 'sort_dir' => 'asc']), 'email, email (tie: by id), phone');
        self::assertSame(['B', 'C', 'A'], $this->ids(['sort_by' => 'status', 'sort_dir' => 'asc']), 'new, reviewed, sent');
        self::assertSame(['B', 'C', 'A'], $this->ids(['sort_by' => 'created_date', 'sort_dir' => 'asc']));
    }

    public function test_the_status_sort_uses_the_derived_status(): void
    {
        $this->sortSet();    // A completed; B in progress, due in the future; C in progress, due 1 September: overdue

        self::assertSame(['A', 'B', 'C'], $this->ids(['sort_by' => 'follow_up_status', 'sort_dir' => 'asc']), 'completed, in_progress, overdue');
        self::assertSame(['C', 'B', 'A'], $this->ids(['sort_by' => 'follow_up_status', 'sort_dir' => 'DESC']));
    }

    public function test_final_outcome_is_not_sortable_and_an_unknown_key_sorts_by_date(): void
    {
        $this->sortSet();

        self::assertSame(['A', 'B', 'C'], $this->ids(['sort_by' => 'final_outcome', 'sort_dir' => 'asc']), 'by date, not by outcome (which would be B, C, A)');
        self::assertSame(['A', 'B', 'C'], $this->ids(['sort_by' => 'password', 'sort_dir' => 'asc']));
        self::assertSame(['A', 'B', 'C'], $this->ids(['sort_by' => 'body', 'sort_dir' => 'asc']));
        self::assertSame(['A', 'B', 'C'], $this->ids(['sort_dir' => 'sideways']), 'anything but desc is ascending');
    }

    public function test_equal_keys_break_on_the_id_in_the_sort_direction(): void
    {
        foreach (['c', 'a', 'b'] as $id) {
            $this->add($id, subject: 'Same');
        }

        self::assertSame(['a', 'b', 'c'], $this->ids(['sort_by' => 'subject', 'sort_dir' => 'asc']));
        self::assertSame(['c', 'b', 'a'], $this->ids(['sort_by' => 'subject', 'sort_dir' => 'desc']));
    }

    public function test_paging_defaults_to_100_clamps_to_1000_and_refuses_nonsense(): void
    {
        for ($n = 1; $n <= 7; ++$n) {
            $this->add('r' . $n, emailDate: ArFixtures::utc('2026-04-0' . $n . ' 00:00:00'));
        }

        $all = $this->service->list([]);
        self::assertSame(100, $all['limit']);
        self::assertSame(0, $all['offset']);
        self::assertSame(7, $all['total']);
        self::assertSame(1000, $this->service->list(['limit' => '5000'])['limit'], 'a larger page is answered as 1,000');
        self::assertSame(1000, $this->service->list(['limit' => '1000'])['limit']);

        $second = $this->service->list(['limit' => '3', 'offset' => '3', 'sort_dir' => 'asc']);
        $records = $second['records'];
        self::assertIsArray($records);
        self::assertSame(['r4', 'r5', 'r6'], array_column($records, 'id'));
        self::assertSame(7, $second['total'], 'the total is of the filter, not of the page');
        self::assertSame([], $this->service->list(['offset' => '50'])['records']);

        self::assertSame('limit must be at least 1', $this->refusal(fn () => $this->service->list(['limit' => '0'])));
        self::assertSame('limit must be at least 1', $this->refusal(fn () => $this->service->list(['limit' => '-1'])));
        self::assertSame('limit must be a whole number', $this->refusal(fn () => $this->service->list(['limit' => 'many'])));
        self::assertSame('limit must be a whole number', $this->refusal(fn () => $this->service->list(['limit' => '1.5'])));
        self::assertSame('offset must be 0 or more', $this->refusal(fn () => $this->service->list(['offset' => '-1'])));
        self::assertSame('offset must be a whole number', $this->refusal(fn () => $this->service->list(['offset' => 'x'])));
    }

    // ---- one record and the list's shape ------------------------------------------------

    public function test_a_list_row_has_no_body_and_one_record_has_it_with_its_attachments_metadata_only(): void
    {
        $this->add('a', body: 'The full text of the message.');
        $this->store->setAttachmentRows('a', [[
            'id' => 'att1', 'email_record_id' => 'a', 'file_name' => 'remittance.pdf', 'content_type' => 'application/pdf', 'size_bytes' => 1234,
            'is_remittance' => true, 'extraction_status' => 'extracted', 'line_items' => [['id' => 'l1', 'invoice_number' => 'INV-1', 'paid_amount' => 10.5]],
        ]]);

        $list = $this->service->list([]);
        $records = ArFixtures::rows($list['records']);
        self::assertArrayNotHasKey('body', $records[0]);
        self::assertArrayNotHasKey('attachments', $records[0]);

        $one = $this->service->get('a');
        self::assertSame('The full text of the message.', $one['body']);
        $attachments = ArFixtures::rows($one['attachments']);
        self::assertSame('remittance.pdf', $attachments[0]['file_name']);
        self::assertArrayNotHasKey('stored_name', $attachments[0]);
        self::assertArrayNotHasKey('blob_path', $attachments[0]);
    }

    public function test_a_record_has_the_sources_keys_and_the_new_ones(): void
    {
        $this->add('a', dueDate: '2026-10-01', creditNoteRequestId: null, assignedUserId: 12, assignedName: 'Mia Operator', messageUnreachable: true, sourceMessageId: null);

        $row = $this->service->get('a');

        foreach (['id', 'email_date', 'customer_name', 'sender_email', 'sender_name', 'subject', 'body', 'ai_summary', 'follow_up_category', 'assigned_person', 'proposed_response_type', 'proposed_response', 'follow_up_status', 'due_date', 'final_outcome', 'source_message_id', 'mailbox', 'classification_confidence', 'status', 'assigned_sales_rep_email', 'credit_note_request_id', 'reminder_sent_at', 'deleted_at', 'deleted_by', 'created_date', 'updated_date', 'attachments'] as $key) {
            self::assertArrayHasKey($key, $row, $key);
        }
        self::assertSame('2026-09-21T02:09:00Z', $row['email_date']);
        self::assertSame('2026-09-21T02:10:00.000000+00:00', $row['created_date']);
        self::assertSame('2026-10-01', $row['due_date']);
        self::assertSame('Mia Operator', $row['assigned_person']);
        self::assertSame('12', $row['assigned_user_id']);
        self::assertTrue($row['original_message_unreachable']);
        self::assertNull($row['source_message_id']);
        self::assertSame('', $row['credit_note_request_id']);
        self::assertNull($row['deleted_at']);
        self::assertNull($row['deleted_by']);
    }

    public function test_an_unknown_id_is_not_found_on_every_route(): void
    {
        $actor = new ArActor(1, 'Mia', 'mia@example.test', []);

        foreach ([
            fn () => $this->service->get('nope'),
            fn () => $this->service->update('nope', ['status' => 'sent']),
            fn () => $this->service->delete($actor, 'nope', false),
            fn () => $this->service->delete($actor, 'nope', true),
            fn () => $this->service->restore('nope'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected not found.');
            } catch (ArNotFound $failure) {
                self::assertSame('Record not found', $failure->getMessage());
            }
        }
    }

    // ---- edit (D48) ---------------------------------------------------------------------

    public function test_the_pills_edit_and_unknown_values_are_refused_(): void
    {
        $this->add('a', followUpCategory: 'other');

        $row = $this->service->update('a', ['follow_up_category' => 'release_customer_orders', 'proposed_response_type' => 'phone', 'follow_up_status' => 'in_progress']);

        self::assertSame('release_customer_orders', $row['follow_up_category']);
        self::assertSame('phone', $row['proposed_response_type']);
        self::assertSame('in_progress', $row['follow_up_status']);
        self::assertSame("Invalid follow_up_category: 'not_a_category'", $this->refusal(fn () => $this->service->update('a', ['follow_up_category' => 'not_a_category'])));
        self::assertSame("Invalid proposed_response_type: 'fax'", $this->refusal(fn () => $this->service->update('a', ['proposed_response_type' => 'fax'])));
        self::assertSame("Invalid follow_up_status: 'overdue'", $this->refusal(fn () => $this->service->update('a', ['follow_up_status' => 'overdue'])));
        self::assertSame("Invalid follow_up_status: 'bogus'", $this->refusal(fn () => $this->service->update('a', ['follow_up_status' => 'bogus'])));
        self::assertSame('Invalid follow_up_category: None', $this->refusal(fn () => $this->service->update('a', ['follow_up_category' => null])));
        self::assertSame('Invalid follow_up_category: 5', $this->refusal(fn () => $this->service->update('a', ['follow_up_category' => 5])));
        self::assertSame("Invalid status: 'archived'", $this->refusal(fn () => $this->service->update('a', ['status' => 'archived'])));
        self::assertSame('release_customer_orders', $this->store->stored('a')?->followUpCategory, 'a refused edit changes nothing');
    }

    public function test_status_takes_the_four_values_including_skipped(): void
    {
        $this->add('a');

        foreach (['reviewed', 'sent', 'skipped', 'new'] as $status) {
            self::assertSame($status, $this->service->update('a', ['status' => $status])['status']);
        }
    }

    public function test_an_option_added_in_settings_can_be_assigned_until_it_is_archived(): void
    {
        $this->add('a');
        $this->options->create('category', ['label' => 'Warranty Claim']);
        self::assertSame('warranty_claim', $this->service->update('a', ['follow_up_category' => 'warranty_claim'])['follow_up_category']);

        $this->options->update('category', 'warranty_claim', ['active' => false]);
        self::assertSame("Invalid follow_up_category: 'warranty_claim'", $this->refusal(fn () => $this->service->update('a', ['follow_up_category' => 'warranty_claim'])));
        self::assertSame('warranty_claim', $this->store->stored('a')?->followUpCategory, 'an archived option stays on the record that carries it');
        self::assertSame('Edited', $this->service->update('a', ['final_outcome' => 'Edited'])['final_outcome'], 'and the record can still be edited');
    }

    public function test_only_the_editable_fields_are_written(): void
    {
        $before = $this->add('a', mailbox: 'ar@tricab.com', sourceMessageId: 'graph-id-1', body: 'Original body');

        $row = $this->service->update('a', [
            'body' => 'Rewritten', 'source_message_id' => 'graph-id-2', 'mailbox' => 'someone@example.test', 'token' => 'x', 'token_expires_at' => '2030-01-01',
            'reminder_sent_at' => '2030-01-01T00:00:00Z', 'id' => 'other', 'email_date' => '2020-01-01T00:00:00Z', 'sender_email' => 'evil@example.test',
            'created_date' => '2020-01-01', 'deleted_at' => '2020-01-01', 'classification_confidence' => 0.01, 'credit_note_request_id' => 'zzz',
            'final_outcome' => 'Done',
        ]);

        self::assertSame('Done', $row['final_outcome']);
        $after = $this->store->stored('a');
        self::assertInstanceOf(ArRecord::class, $after);
        self::assertSame('Original body', $after->body);
        self::assertSame('graph-id-1', $after->sourceMessageId);
        self::assertSame('ar@tricab.com', $after->mailbox);
        self::assertNull($after->reminderSentAt);
        self::assertNull($after->deletedAt);
        self::assertSame('accounts@acme.example.test', $after->senderEmail);
        self::assertEquals($before->emailDate, $after->emailDate);
        self::assertEquals($before->createdAt, $after->createdAt);
        self::assertSame(0.91, $after->classificationConfidence);
        self::assertNull($after->creditNoteRequestId);
        self::assertSame('a', $after->id);
    }

    public function test_an_edit_with_nothing_editable_writes_nothing(): void
    {
        $this->add('a');
        $before = $this->store->stored('a');

        $row = $this->service->update('a', ['body' => 'x', 'mailbox' => 'y']);

        self::assertSame('a', $row['id']);
        self::assertEquals($before, $this->store->stored('a'), 'not even updated_date moved');
        self::assertSame('a', $this->service->update('a', [])['id']);
    }

    public function test_the_text_fields_are_text_and_bounded(): void
    {
        $this->add('a', customerName: 'Old Name');

        self::assertSame('', $this->service->update('a', ['final_outcome' => null])['final_outcome']);
        self::assertSame('42', $this->service->update('a', ['customer_name' => 42])['customer_name']);
        self::assertSame('customer_name must be at most 400 characters', $this->refusal(fn () => $this->service->update('a', ['customer_name' => str_repeat('n', 401)])));
        self::assertSame('assigned_sales_rep_email must be at most 320 characters', $this->refusal(fn () => $this->service->update('a', ['assigned_sales_rep_email' => str_repeat('e', 321)])));
        self::assertSame('final_outcome must be text', $this->refusal(fn () => $this->service->update('a', ['final_outcome' => ['x']])));
        self::assertSame('Rep', $this->service->update('a', ['assigned_sales_rep_email' => 'Rep'])['assigned_sales_rep_email']);
        self::assertSame(str_repeat('n', 400), $this->service->update('a', ['customer_name' => str_repeat('n', 400)])['customer_name']);
    }

    // ---- the due date (tests 11 and the reminder) ---------------------------------------

    public function test_overdue_follows_the_due_date_and_cannot_be_picked(): void
    {
        $this->add('a', followUpCategory: 'invoice_statement_requests');

        self::assertSame('in_progress', $this->service->get('a')['follow_up_status']);
        self::assertSame('in_progress', self::shown($this->service->update('a', ['due_date' => '2026-09-22'])), 'due today is not yet overdue');
        self::assertSame('overdue', self::shown($this->service->update('a', ['due_date' => '2026-09-21'])));
        self::assertSame(1, $this->service->metrics(null, null)['overdue_items']);
        self::assertSame('in_progress', self::shown($this->service->update('a', ['due_date' => '2026-10-01'])), 'moving it out clears it');
        $done = $this->service->update('a', ['due_date' => '2026-09-01', 'follow_up_status' => 'completed']);
        self::assertSame('completed', $done['follow_up_status'], 'completing it is never undone');
        self::assertSame("Invalid follow_up_status: 'overdue'", $this->refusal(fn () => $this->service->update('a', ['follow_up_status' => 'overdue'])));
        self::assertSame('completed', $this->storedStatus('a'));
    }

    public function test_the_due_date_may_be_cleared_and_must_be_a_real_date(): void
    {
        $this->add('a', dueDate: '2026-09-01');

        self::assertSame('overdue', $this->service->get('a')['follow_up_status']);
        $cleared = $this->service->update('a', ['due_date' => '']);
        self::assertSame('', $cleared['due_date']);
        self::assertSame('in_progress', $cleared['follow_up_status']);
        self::assertSame('2026-10-05', $this->service->update('a', ['due_date' => '2026-10-05T09:00:00Z'])['due_date']);
        self::assertSame('', $this->service->update('a', ['due_date' => null])['due_date']);
        foreach (['05/10/2026', '2026-13-01', 'soon', 20261005] as $bad) {
            self::assertSame('Due date must be yyyy-MM-dd', $this->refusal(fn () => $this->service->update('a', ['due_date' => $bad])), (string) $bad);
        }
    }

    public function test_a_new_due_date_earns_a_new_reminder_and_nothing_else_does(): void
    {
        $this->add('a', dueDate: '2026-12-10', reminderSentAt: ArFixtures::utc('2026-09-20 00:00:00'));

        $this->service->update('a', ['final_outcome' => 'unrelated edit']);
        self::assertNotNull($this->reminderOf('a'), 'an unrelated edit keeps the reminder');
        $this->service->update('a', ['due_date' => '2026-12-10']);
        self::assertNotNull($this->reminderOf('a'), 'the same date again keeps it too');
        $this->service->update('a', ['due_date' => '2026-12-20']);
        self::assertNull($this->reminderOf('a'), 'a different due date clears it');
    }

    // ---- the assignee (D43) -------------------------------------------------------------

    public function test_a_record_is_assigned_by_id_by_full_name_or_by_address_and_cleared_by_empty(): void
    {
        $this->assignees->add(12, 'Mia Operator', 'mia@example.test');
        $this->assignees->add(13, 'Max Manager', 'max@example.test');
        $this->add('a');

        $byId = $this->service->update('a', ['assigned_user_id' => '12']);
        self::assertSame('Mia Operator', $byId['assigned_person']);
        self::assertSame('12', $byId['assigned_user_id']);

        $byName = $this->service->update('a', ['assigned_person' => 'max manager']);
        self::assertSame('Max Manager', $byName['assigned_person']);
        self::assertSame('13', $byName['assigned_user_id']);

        $byAddress = $this->service->update('a', ['assigned_person' => 'MIA@example.test']);
        self::assertSame('12', $byAddress['assigned_user_id']);

        $cleared = $this->service->update('a', ['assigned_person' => '']);
        self::assertSame('', $cleared['assigned_person']);
        self::assertNull($cleared['assigned_user_id']);

        $this->service->update('a', ['assigned_user_id' => 13]);
        self::assertNull($this->service->update('a', ['assigned_user_id' => null])['assigned_user_id']);
    }

    public function test_an_unknown_or_ambiguous_assignee_is_refused_and_a_migrated_free_text_name_can_stay(): void
    {
        $this->assignees->add(12, 'Sam Same', 'sam1@example.test');
        $this->assignees->add(13, 'Sam Same', 'sam2@example.test');
        $this->add('a', assignedName: 'Front Desk', assignedUserId: null);

        self::assertSame("Unknown assignee: 'Nobody Here'", $this->refusal(fn () => $this->service->update('a', ['assigned_person' => 'Nobody Here'])));
        self::assertSame("More than one person is called 'Sam Same'; choose by address", $this->refusal(fn () => $this->service->update('a', ['assigned_person' => 'Sam Same'])));
        self::assertSame('12', $this->service->update('a', ['assigned_person' => 'sam1@example.test'])['assigned_user_id']);
        self::assertSame('The assignee must be someone who can work records in Tri-AR', $this->refusal(fn () => $this->service->update('a', ['assigned_user_id' => '999'])));
        self::assertSame('The assignee must be someone who can work records in Tri-AR', $this->refusal(fn () => $this->service->update('a', ['assigned_user_id' => 'abc'])));

        $this->add('b', assignedName: 'Front Desk');
        $same = $this->service->update('b', ['assigned_person' => 'Front Desk']);
        self::assertSame('Front Desk', $same['assigned_person'], 'sending the stored name back is not an error');
    }

    // ---- the Bin (tests 14a, 14b) -------------------------------------------------------

    public function test_delete_moves_to_the_bin_and_restore_brings_it_back(): void
    {
        $this->add('a');
        $this->add('b');
        $actor = new ArActor(7, 'Mia Operator', 'Mia@Example.test', []);

        $result = $this->service->delete($actor, 'a', false);

        self::assertTrue($result['in_bin']);
        $record = $result['record'];
        self::assertIsArray($record);
        self::assertSame('mia@example.test', $record['deleted_by'], 'the address, as the source kept it');
        self::assertNotNull($record['deleted_at']);
        self::assertSame(7, $this->store->stored('a')?->deletedByUserId);
        self::assertSame(['b'], $this->ids());
        self::assertSame(['a'], $this->ids(['deleted' => '1']));
        self::assertSame(1, $this->service->metrics(null, null)['total_emails']);
        self::assertSame(1, $this->service->metrics(null, null)['in_bin']);
        self::assertSame('a', $this->service->get('a')['id'], 'a binned record can still be read');

        $restored = $this->service->restore('a');
        self::assertNull($restored['deleted_at']);
        self::assertNull($restored['deleted_by']);
        self::assertEqualsCanonicalizing(['a', 'b'], $this->ids());
        self::assertSame([], $this->ids(['deleted' => 'true']));
    }

    public function test_binning_a_binned_record_changes_nothing_and_restoring_a_live_one_is_harmless(): void
    {
        $this->add('a');
        $first = new ArActor(7, 'Mia', 'mia@example.test', []);
        $second = new ArActor(8, 'Max', 'max@example.test', []);
        $this->service->delete($first, 'a', false);
        $binnedAt = $this->store->stored('a')?->deletedAt;
        $this->clock->advance('+1 hour');

        $this->service->delete($second, 'a', false);

        self::assertEquals($binnedAt, $this->store->stored('a')?->deletedAt);
        self::assertSame('mia@example.test', $this->store->stored('a')?->deletedBy);
        $this->service->restore('a');
        self::assertSame('a', $this->service->restore('a')['id']);
    }

    public function test_permanent_delete_only_from_the_bin(): void
    {
        $this->add('a');
        $actor = new ArActor(7, 'Mia', 'mia@example.test', []);

        try {
            $this->service->delete($actor, 'a', true);
            self::fail('A live record cannot be deleted for good.');
        } catch (ArConflict $failure) {
            self::assertSame('Move the record to the Bin before deleting it permanently', $failure->getMessage());
            self::assertSame(409, $failure->httpStatus());
        }
        self::assertNotNull($this->store->stored('a'));
    }

    public function test_permanent_delete_removes_the_children_the_files_and_leaves_a_tombstone(): void
    {
        $this->add('a', sourceMessageId: 'graph-id-1');
        $this->add('b', sourceMessageId: 'graph-id-2');
        $this->store->setAttachmentRows('a', [['id' => 'att1', 'line_items' => []]]);
        $this->store->addOutboundRow('a', 'out1');
        $this->store->setAttachmentRows('b', [['id' => 'att2', 'line_items' => []]]);
        $this->files->put(new FinanceAttachment('f1', 'AR', 'email_record', 'a', 'AR/2026/f1', 'remittance.pdf', 'application/pdf', 3, str_repeat('0', 64), null, ArFixtures::utc('2026-09-21 00:00:00'), ArFixtures::utc('2026-09-21 00:00:00')), 'PDF');
        $this->files->put(new FinanceAttachment('f2', 'AR', 'email_record', 'b', 'AR/2026/f2', 'other.pdf', 'application/pdf', 3, str_repeat('0', 64), null, ArFixtures::utc('2026-09-21 00:00:00'), ArFixtures::utc('2026-09-21 00:00:00')), 'PDF');
        $actor = new ArActor(7, 'Mia', 'mia@example.test', []);
        $this->service->delete($actor, 'a', false);

        $result = $this->service->delete($actor, 'a', true);

        self::assertSame(['in_bin' => false, 'deleted' => true], $result);
        self::assertNull($this->store->stored('a'));
        self::assertSame(0, $this->store->attachmentCount('a'));
        self::assertSame(0, $this->store->outboundCount('a'));
        self::assertSame([], $this->files->forRecord('AR', 'email_record', 'a'), 'the file and its row are gone');
        self::assertCount(1, $this->files->forRecord('AR', 'email_record', 'b'), 'another record keeps its file');
        self::assertSame(1, $this->store->attachmentCount('b'));
        self::assertSame(['graph-id-1'], array_keys($this->store->tombstones()));
        self::assertNotNull($this->store->stored('b'));
    }

    public function test_a_record_without_a_message_id_leaves_no_tombstone_and_a_purged_record_is_gone(): void
    {
        $this->add('a', sourceMessageId: null, messageUnreachable: true);
        $actor = new ArActor(7, 'Mia', 'mia@example.test', []);
        $this->service->delete($actor, 'a', false);

        $this->service->delete($actor, 'a', true);

        self::assertSame([], $this->store->tombstones());
        $this->expectException(ArNotFound::class);
        $this->service->restore('a');
    }

    /** True once the purge has taken the record out, as the work in the failing transaction sees it. */
    private function purgedInThisTransaction(): bool
    {
        return $this->store->find('a') === null;
    }

    public function test_a_failed_purge_leaves_the_record_in_the_bin(): void
    {
        $this->add('a', sourceMessageId: 'graph-id-1');
        $actor = new ArActor(7, 'Mia', 'mia@example.test', []);
        $this->service->delete($actor, 'a', false);

        $failed = false;
        try {
            $this->store->transactional(function (): void {
                $this->store->purge('a', ArFixtures::utc('2026-09-22 03:00:00'));
                if ($this->purgedInThisTransaction()) {
                    throw new \RuntimeException('connection lost');
                }
            });
        } catch (\RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed, 'The work was made to fail.');

        self::assertNotNull($this->store->stored('a'), 'rolled back with the tombstone');
        self::assertSame([], $this->store->tombstones());
    }

    // ---- the tiles (tests 14c, 14d) -----------------------------------------------------

    public function test_the_tiles_follow_the_period_and_the_bin_and_credit_notes_do_not(): void
    {
        $this->add('m1', emailDate: ArFixtures::utc('2026-03-02 08:00:00'), followUpStatus: 'needs_review', followUpCategory: 'other');
        $this->add('m2', emailDate: ArFixtures::utc('2026-03-09 08:00:00'), followUpStatus: 'needs_review', followUpCategory: 'credit_notes');
        $this->add('j1', emailDate: ArFixtures::utc('2026-06-15 08:00:00'), followUpStatus: 'completed', followUpCategory: 'other');
        $this->add('bin', emailDate: ArFixtures::utc('2026-03-05 08:00:00'), deletedAt: ArFixtures::utc('2026-03-06 00:00:00'));
        $this->store->setCreditNoteCounts(['form_sent' => 2, 'approved' => 1]);

        $march = $this->service->metrics('2026-03-01', '2026-03-31');

        self::assertSame(2, $march['total_emails'], 'the binned one is not counted');
        self::assertSame(2, $march['needs_review']);
        self::assertSame(0, $march['completed']);
        self::assertEquals(['other' => 1, 'credit_notes' => 1], $march['category_counts']);
        self::assertEquals(['needs_review' => 2], $march['follow_up_status_counts']);
        self::assertSame(1, $march['in_bin']);
        self::assertEquals(['form_sent' => 2, 'approved' => 1], $march['credit_note_counts']);

        $june = $this->service->metrics('2026-06-01', '2026-06-30');
        self::assertSame(1, $june['total_emails']);
        self::assertSame(1, $june['completed']);
        self::assertSame(1, $june['in_bin'], 'the bin count ignores the period');

        self::assertSame(3, $this->service->metrics(null, null)['total_emails']);
        self::assertSame(2, $this->service->metrics(null, '2026-03-31')['total_emails'], 'a bound on one side filters');
        self::assertSame(1, $this->service->metrics('2026-06-01', null)['total_emails']);
    }

    public function test_an_empty_register_has_json_objects_not_lists(): void
    {
        $metrics = $this->service->metrics(null, null);

        self::assertSame(0, $metrics['total_emails']);
        self::assertInstanceOf(stdClass::class, $metrics['category_counts']);
        self::assertInstanceOf(stdClass::class, $metrics['follow_up_status_counts']);
        self::assertInstanceOf(stdClass::class, $metrics['credit_note_counts']);
        self::assertSame('{}', json_encode($metrics['credit_note_counts']));
    }

    public function test_the_message_id_is_unique(): void
    {
        $this->add('a', sourceMessageId: 'same');

        $this->expectException(ArConflict::class);
        $this->add('b', sourceMessageId: 'SAME');
    }

    public function test_a_used_option_is_counted_across_the_register_including_the_bin(): void
    {
        $this->add('a', followUpCategory: 'credit_notes', proposedResponseType: 'phone');
        $this->add('b', followUpCategory: 'credit_notes', deletedAt: ArFixtures::utc('2026-09-01 00:00:00'));

        $lists = $this->options->lists();

        $category = array_column($lists['category'], 'usage_count', 'value');
        self::assertSame(2, $category['credit_notes']);
        $method = array_column($lists['method'], 'usage_count', 'value');
        self::assertSame(1, $method['phone']);
        self::assertSame(1, $method['email']);
        self::assertSame(['deleted' => false, 'archived' => true, 'usage_count' => 2], $this->options->delete('category', 'credit_notes'));
    }
}
