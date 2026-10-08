<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Service\ArOverdueRemindersJob;
use TriCab\Core\Finance\Ar\Service\ArReminderService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * The overdue reminders (T5.11) on the twins and a transport that sends nothing: which records, who gets it, what the mail
 * says (escaped), marking only on a real send, a repeat sending nothing, the stops, the cap and the order. "Today" is
 * 8 October 2026 in Melbourne. Every address is invented.
 */
final class ArReminderServiceTest extends TestCase
{
    private const MAILBOX = 'ar@example.test';

    private InMemoryArStore $store;

    private InMemoryArAssigneeDirectory $assignees;

    private InMemoryFinanceMailLedger $ledger;

    private RecordingReplyMailTransport $transport;

    private FixedClock $clock;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->store = new InMemoryArStore();
        $this->assignees = new InMemoryArAssigneeDirectory();
        // Every record `add()` makes is assigned to this person unless a test says otherwise.
        $this->assignees->add(1, 'Default Person', 'default@example.test');
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingReplyMailTransport();
        // 02:00 UTC is 13:00 in Melbourne (daylight saving), the same calendar day.
        $this->clock = FixedClock::utc('2026-10-08 02:00:00');
    }

    private function service(?FinanceMailTransport $transport = null, int $cap = ArReminderService::CAP, bool $granted = true, ?InMemoryArStore $store = null): ArReminderService
    {
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', self::MAILBOX, 'Accounts Receivable', 'AR', true, ['AR' => ['read' => true, 'send' => $granted]]),
        ]);
        $mail = new FinanceMailService($registry, $this->ledger, $transport ?? $this->transport, fn (): DateTimeImmutable => $this->clock->now());

        return new ArReminderService($store ?? $this->store, $this->assignees, $mail, new ArTime($this->clock, 'Australia/Melbourne'), 'AR@Example.test', $cap, function (string $line): void {
            $this->log[] = $line;
        });
    }

    private function add(string $id, mixed ...$over): ArRecord
    {
        $record = ArFixtures::record($id, ...($over + ['followUpStatus' => 'in_progress', 'dueDate' => '2026-10-07', 'assignedUserId' => 1, 'assignedName' => 'Default Person']));
        $this->store->insert($record);

        return $record;
    }

    private function reminded(string $id): ?DateTimeImmutable
    {
        return $this->store->stored($id)?->reminderSentAt;
    }

    /**
     * Where each mail went, in the order sent.
     *
     * @return list<string>
     */
    private function mailedTo(): array
    {
        return array_map(static fn ($m): string => implode(',', $m->to), $this->transport->standalone);
    }

    // ---- which records -------------------------------------------------------------------

    public function test_only_open_records_past_their_due_date_that_are_live_and_not_yet_reminded_get_one(): void
    {
        $this->add('yesterday', dueDate: '2026-10-07');
        $this->add('review', dueDate: '2026-09-01', followUpStatus: 'needs_review');
        $this->add('today', dueDate: '2026-10-08');
        $this->add('tomorrow', dueDate: '2026-10-09');
        $this->add('nodate', dueDate: null);
        $this->add('done', dueDate: '2026-09-01', followUpStatus: 'completed');
        $this->add('binned', dueDate: '2026-09-01', deletedAt: ArFixtures::utc('2026-10-01 00:00:00'));
        $this->add('reminded', dueDate: '2026-09-01', reminderSentAt: ArFixtures::utc('2026-10-02 00:00:00'));

        $result = $this->service()->dispatch();

        self::assertSame(['due' => 2, 'sent' => 2, 'suppressed' => 0, 'failed' => 0, 'skipped' => 0, 'unassigned' => 0, 'capped' => false], $result);
        self::assertCount(2, $this->transport->standalone);
        foreach (['yesterday', 'review'] as $id) {
            self::assertNotNull($this->reminded($id), $id . ' is marked');
        }
        foreach (['today', 'tomorrow', 'nodate', 'done'] as $id) {
            self::assertNull($this->reminded($id), $id . ' is not reminded');
        }
    }

    public function test_the_order_is_oldest_due_first_then_by_id(): void
    {
        $this->add('b', dueDate: '2026-10-01', customerName: 'Bee');
        $this->add('a', dueDate: '2026-10-01', customerName: 'Ay');
        $this->add('c', dueDate: '2026-09-15', customerName: 'Sea');

        $this->service()->dispatch();

        self::assertSame(
            ['OVERDUE ACTION: Follow-up required for Sea (Purchase order 77363)', 'OVERDUE ACTION: Follow-up required for Ay (Purchase order 77363)', 'OVERDUE ACTION: Follow-up required for Bee (Purchase order 77363)'],
            array_map(static fn ($m): string => $m->subject, $this->transport->standalone),
        );
    }

    public function test_a_second_pass_finds_nothing_to_send(): void
    {
        $this->add('a');
        $service = $this->service();

        $service->dispatch();
        $second = $service->dispatch();

        self::assertSame(0, $second['due']);
        self::assertCount(1, $this->transport->standalone);
    }

    public function test_a_new_due_date_earns_a_new_reminder_and_an_unrelated_edit_does_not(): void
    {
        $this->add('a');
        $this->service()->dispatch();
        self::assertNotNull($this->reminded('a'));

        // The register's own PATCH rules (T5.4): the same date keeps it, a different one clears it.
        $records = new \TriCab\Core\Finance\Ar\Service\ArRecordService(
            $this->store,
            new \TriCab\Core\Finance\Ar\Service\ArOptionService($this->store, new ArTime($this->clock, 'Australia/Melbourne')),
            $this->assignees,
            new ArTime($this->clock, 'Australia/Melbourne'),
            new \TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore(),
        );
        $records->update('a', ['final_outcome' => 'noted']);
        self::assertNotNull($this->reminded('a'), 'an unrelated edit keeps the reminder');
        $records->update('a', ['due_date' => '2026-10-06']);
        self::assertNull($this->reminded('a'), 'a new due date earns a new one');

        $this->clock->advance('+1 day');
        $second = $this->service()->dispatch();
        self::assertSame(1, $second['sent']);
        self::assertCount(2, $this->transport->standalone);
    }

    // ---- who ------------------------------------------------------------------------------

    public function test_the_assignee_is_reached_through_the_id_a_migrated_address_next_and_nobody_else_is_mailed(): void
    {
        $this->assignees->add(9, 'Una User', 'Una@Example.test');
        $this->add('byid', assignedUserId: 9, assignedName: 'Una User', dueDate: '2026-10-01');
        $this->add('gone', assignedUserId: 12, assignedName: 'Left Company', dueDate: '2026-10-02');
        $this->add('address', assignedUserId: null, assignedName: 'Migrated@Example.test', dueDate: '2026-10-03');
        $this->add('name', assignedUserId: null, assignedName: 'Just A Name', dueDate: '2026-10-04');
        $this->add('nobody', assignedUserId: null, assignedName: '', dueDate: '2026-10-05');

        $result = $this->service()->dispatch();

        self::assertSame(['una@example.test', 'migrated@example.test'], $this->mailedTo(), 'the AR mailbox is never mailed its own reminders');
        self::assertSame([2, 2, 3], [$result['due'], $result['sent'], $result['unassigned']]);
        foreach (['gone', 'name', 'nobody'] as $id) {
            self::assertNull($this->reminded($id), $id . ' is not marked, so it is reminded once somebody is assigned');
        }
    }

    public function test_records_with_nobody_to_remind_do_not_use_up_the_cap(): void
    {
        foreach (range(1, 4) as $n) {
            $this->add('u' . $n, assignedUserId: null, assignedName: '', dueDate: '2026-09-0' . $n);
        }
        $this->add('later', dueDate: '2026-09-20');

        $result = $this->service(cap: 2)->dispatch();

        self::assertSame([1, 1, 4, false], [$result['due'], $result['sent'], $result['unassigned'], $result['capped']]);
        self::assertNotNull($this->reminded('later'));
    }

    public function test_the_id_wins_over_a_name_that_looks_like_someone_elses_address(): void
    {
        $this->assignees->add(9, 'Una User', 'una@example.test');
        $this->add('a', assignedUserId: 9, assignedName: 'someone.else@example.test');

        $this->service()->dispatch();

        self::assertSame(['una@example.test'], $this->mailedTo());
    }

    // ---- the mail --------------------------------------------------------------------------

    public function test_the_mail_is_from_the_ar_mailbox_with_no_actor_and_a_per_day_key(): void
    {
        $this->add('a', dueDate: '2026-10-01');

        $this->service()->dispatch();

        $mail = $this->transport->standalone[0];
        self::assertSame(['AR', 'ar@example.test', null, 'HTML', 'ar-reminder:a:2026-10-01:2026-10-08'], [$mail->application, $mail->mailbox, $mail->actorUserId, $mail->contentType, $mail->idempotencyKey]);
        self::assertSame([], $this->transport->replies);
    }

    public function test_every_value_is_escaped_and_the_subject_is_one_line(): void
    {
        $this->add('a', customerName: '<b>Acme</b> & Co', subject: "Order <script>x</script>\r\nBcc: evil@example.test", followUpCategory: 'a<i>b', dueDate: '2026-10-01');

        $this->service()->dispatch();

        $mail = $this->transport->standalone[0];
        self::assertSame('OVERDUE ACTION: Follow-up required for <b>Acme</b> & Co (Order <script>x</script> Bcc: evil@example.test)', $mail->subject, 'a subject is text, on one line');
        self::assertStringContainsString('&lt;b&gt;Acme&lt;/b&gt; &amp; Co', $mail->body);
        self::assertStringContainsString('Order &lt;script&gt;x&lt;/script&gt;', $mail->body);
        self::assertStringContainsString('a&lt;i&gt;b', $mail->body);
        self::assertStringNotContainsString('<script', $mail->body);
        self::assertStringNotContainsString('<b>Acme', $mail->body);
        self::assertStringContainsString('has elapsed its due date (2026-10-01)', $mail->body);
        self::assertStringContainsString('<strong>Status:</strong> overdue', $mail->body);
        self::assertStringContainsString('review and complete this action in the Tri-AR Register', $mail->body);
    }

    public function test_a_nameless_customer_is_customer_and_a_long_subject_is_cut(): void
    {
        $this->add('a', customerName: '  ', subject: str_repeat('s', 700), dueDate: '2026-10-01');

        $this->service()->dispatch();

        $subject = $this->transport->standalone[0]->subject;
        self::assertStringStartsWith('OVERDUE ACTION: Follow-up required for Customer (', $subject);
        self::assertSame(500, mb_strlen($subject));
    }

    // ---- outcomes --------------------------------------------------------------------------

    public function test_with_mail_switched_off_nothing_is_marked_and_the_pass_stops_after_one_ledger_row(): void
    {
        $this->add('a', dueDate: '2026-10-01');
        $this->add('b', dueDate: '2026-10-02');
        $this->add('c', dueDate: '2026-10-03');

        $result = $this->service(new DisabledFinanceMailTransport())->dispatch();

        self::assertSame(['due' => 3, 'sent' => 0, 'suppressed' => 1, 'failed' => 0, 'skipped' => 2, 'unassigned' => 0, 'capped' => false], $result);
        self::assertCount(1, $this->ledger->all());
        self::assertNull($this->reminded('a'));
    }

    public function test_a_failed_send_is_not_marked_and_is_retried_the_next_day_under_a_new_key(): void
    {
        $this->add('a', dueDate: '2026-10-01');
        $this->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        $first = $this->service()->dispatch();

        self::assertSame([1, 1, 0], [$first['due'], $first['failed'], $first['sent']]);
        self::assertNull($this->reminded('a'));
        self::assertCount(1, $this->log);
        self::assertStringNotContainsString('Purchase order', $this->log[0], 'the log names the record and the error class, never its content');

        $this->transport->failWith = null;
        $this->clock->advance('+1 day');
        $second = $this->service()->dispatch();

        self::assertSame(1, $second['sent']);
        self::assertNotNull($this->reminded('a'));
        self::assertSame('ar-reminder:a:2026-10-01:2026-10-09', $this->transport->standalone[0]->idempotencyKey);
    }

    public function test_three_failures_in_a_row_stop_the_pass_and_a_success_resets_the_count(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $id) {
            $this->add($id, dueDate: '2026-10-0' . ($i + 1));
        }
        $this->transport->failWith = new FinanceMailTransportFailed('down');

        $result = $this->service()->dispatch();

        self::assertSame(['due' => 5, 'sent' => 0, 'suppressed' => 0, 'failed' => 3, 'skipped' => 2, 'unassigned' => 0, 'capped' => false], $result);
    }

    public function test_a_refused_grant_fails_the_run_with_the_reason(): void
    {
        $this->add('a');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AR is not allowed to send from the mailbox ar@example.test.');
        $this->service(granted: false)->dispatch();
    }

    public function test_a_mail_that_went_but_could_not_be_marked_is_logged_and_the_same_day_cannot_send_it_twice(): void
    {
        $store = new class() extends InMemoryArStore {
            public function markReminderSent(string $id, DateTimeImmutable $at): bool
            {
                throw new RuntimeException('database down');
            }
        };
        $store->insert(ArFixtures::record('a', followUpStatus: 'in_progress', dueDate: '2026-10-01', assignedUserId: 1, assignedName: 'Default Person'));
        $service = $this->service(store: $store);

        $first = $service->dispatch();
        $second = $service->dispatch();

        self::assertSame(1, $first['sent']);
        self::assertSame(1, $second['sent'], 'the record is still due, so it is picked again');
        self::assertCount(1, $this->transport->standalone, 'but the ledger already holds that day\'s key, so nothing went twice');
        self::assertCount(2, $this->log);
        self::assertStringContainsString('sent but not marked', $this->log[0]);
    }

    public function test_at_most_the_cap_go_in_a_pass_oldest_first_and_the_rest_follow(): void
    {
        foreach (range(1, 5) as $n) {
            $this->add('r' . $n, dueDate: '2026-09-0' . $n);
        }

        $first = $this->service(cap: 2)->dispatch();
        self::assertSame(['due' => 2, 'sent' => 2, 'capped' => true], ['due' => $first['due'], 'sent' => $first['sent'], 'capped' => $first['capped']]);
        self::assertSame(['r1', 'r2'], array_values(array_filter(['r1', 'r2', 'r3', 'r4', 'r5'], fn (string $id): bool => $this->reminded($id) !== null)));

        $this->clock->advance('+1 day');
        $this->service(cap: 2)->dispatch();
        $last = $this->service(cap: 2)->dispatch();
        self::assertSame(['due' => 1, 'sent' => 1, 'capped' => false], ['due' => $last['due'], 'sent' => $last['sent'], 'capped' => $last['capped']]);
        self::assertCount(5, $this->transport->standalone);
    }

    // ---- the job ---------------------------------------------------------------------------

    public function test_the_job_runs_at_08_00_in_the_ar_zone_and_reports_counts_only(): void
    {
        $this->add('a', dueDate: '2026-10-01', customerName: 'Secret Customer');
        $job = new ArOverdueRemindersJob($this->service(), 'Australia/Melbourne');

        self::assertSame('ar.overdue-reminders', $job->name());
        self::assertEquals(FinanceJobSchedule::dailyAt('08:00', 'Australia/Melbourne'), $job->schedule());
        self::assertEquals(FinanceJobSchedule::dailyAt('08:00', 'Australia/Perth'), (new ArOverdueRemindersJob($this->service(), 'Australia/Perth'))->schedule());
        $facts = $job->run(new DateTimeImmutable('2026-10-08 00:00:00', new DateTimeZone('UTC')));

        self::assertSame(['due' => 1, 'sent' => 1, 'suppressed' => 0, 'failed' => 0, 'skipped' => 0, 'unassigned' => 0, 'capped' => false], $facts);
        self::assertStringNotContainsString('Secret', json_encode($facts, JSON_THROW_ON_ERROR));
        self::assertGreaterThan('ar.mailbox-scan', $job->name(), 'the registry runs jobs in name order, so reminders follow the scan');
    }
}
