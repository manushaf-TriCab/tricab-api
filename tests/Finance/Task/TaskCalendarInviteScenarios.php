<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use TriCab\Api\Tests\Finance\Mail\RecordingFinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Task\Contract\TaskCalendarInvites;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskCalendarInviteService;
use TriCab\Core\Finance\Task\Service\TaskService;

/**
 * Calendar invites by due date (Nick Han, 23 Oct 2026), run against the in-memory twin and the SQL store alike. The
 * using test supplies the services, a recording mail transport, the store, and people: `una` (11, her own tasks only),
 * `max` (12, sees all), `rae` (13), `ivy` (16), and `nomail` (14, a member with no address).
 */
trait TaskCalendarInviteScenarios
{
    private TaskService $tasks;

    private TaskCalendarInviteService $invites;

    private RecordingFinanceMailTransport $transport;

    private TaskCalendarInvites $store;

    private TaskActor $una;

    private TaskActor $max;

    /**
     * @param array<string, mixed> $data
     * @return string the new task's id
     */
    private function make(array $data = []): string
    {
        $task = $this->tasks->create($this->max, $data + ['name' => 'Bank rec', 'due_date' => '2026-10-30', 'preparer_ids' => ['11', '16'], 'reviewer_ids' => ['13', '11']]);
        self::assertIsString($task['id']);

        return $task['id'];
    }

    /** @return array<string, mixed> */
    private function row(TaskActor $by, string $id): array
    {
        return $this->tasks->get($by, $id);
    }

    /** @return array<string, mixed>|null the task's `calendar_invite` as the person sees it */
    private function inviteOf(TaskActor $by, string $id): ?array
    {
        $invite = $this->tasks->get($by, $id)['calendar_invite'] ?? null;
        if ($invite === null) {
            return null;
        }
        self::assertIsArray($invite);
        $out = [];
        foreach ($invite as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /** The ICS text of the n-th message sent. */
    private function ics(int $n = 0): string
    {
        $attachment = $this->transport->sent[$n]->attachments[0] ?? null;
        self::assertNotNull($attachment);

        return $attachment->content;
    }

    /** @return list<string> the unfolded content lines */
    private static function lines(string $ics): array
    {
        return explode("\r\n", rtrim(preg_replace('/\r\n[ \t]/', '', $ics) ?? '', "\r\n"));
    }

    public function test_an_invite_is_an_all_day_event_on_the_due_date_to_every_assignee_once_from_the_senders_own_mailbox(): void
    {
        $id = $this->make();

        $out = $this->invites->send($this->una, [$id], 'https://tricab.ai');

        self::assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], array_intersect_key($out, ['sent' => 1, 'skipped' => 1, 'failed' => 1]));
        self::assertCount(1, $this->transport->sent, 'one email per task');
        $message = $this->transport->sent[0];
        self::assertSame('una@example.test', $message->mailbox, 'her own mailbox, never a shared one');
        self::assertTrue($message->ownMailbox);
        self::assertSame(11, $message->actorUserId);
        self::assertEqualsCanonicalizing(['una@example.test', 'ivy@example.test', 'rae@example.test'], $message->to, 'every preparer and reviewer, Una (both) once');
        self::assertCount(3, $message->to);
        self::assertSame('Calendar invite: Bank rec (due 30 Oct 2026)', $message->subject);
        self::assertStringContainsString('Due: Friday 30 October 2026', $message->body);
        self::assertStringContainsString('https://tricab.ai/finance/task/?task=' . $id, $message->body);
        self::assertCount(1, $message->attachments);
        self::assertSame('invite.ics', $message->attachments[0]->name);
        self::assertSame('application/ics', $message->attachments[0]->contentType, 'attached for clients that want a file');
        self::assertNotNull($message->calendar, 'and carried as the message own text/calendar part');
        self::assertSame($this->ics(), $message->calendar->ics);
        self::assertStringContainsString('Due: Friday 30 October 2026', $message->calendar->text);
        self::assertCount(1, $this->transport->mime, 'sent as MIME');
        self::assertStringContainsString('Content-Type: text/calendar; charset=UTF-8; method=REQUEST', $this->transport->mime[0]);
        self::assertStringContainsString("From: una@example.test\r\n", $this->transport->mime[0]);

        $ics = $this->ics();
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $ics), 'CRLF only');
        $lines = self::lines($ics);
        foreach (['BEGIN:VCALENDAR', 'VERSION:2.0', 'METHOD:REQUEST', 'BEGIN:VEVENT', 'UID:tri-task-' . $id . '@tricab.ai', 'SEQUENCE:0',
            'DTSTART;VALUE=DATE:20261030', 'DTEND;VALUE=DATE:20261031', 'SUMMARY:Due: Bank rec', 'URL:https://tricab.ai/finance/task/?task=' . $id,
            'ORGANIZER;CN="Una User":mailto:una@example.test', 'TRANSP:TRANSPARENT', 'END:VEVENT', 'END:VCALENDAR'] as $expected) {
            self::assertContains($expected, $lines);
        }
        $attendees = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'ATTENDEE')));
        self::assertCount(3, $attendees);
        self::assertContains('ATTENDEE;CN="Ivy User";CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:ivy@example.test', $attendees);
    }

    public function test_what_was_sent_is_kept_per_task_and_shows_on_the_task_and_the_list_and_none_before(): void
    {
        $id = $this->make();
        $before = $this->inviteOf($this->max, $id);
        self::assertNull($before, 'no invite yet');

        $this->invites->send($this->una, [$id]);

        $invite = $this->inviteOf($this->max, $id);
        self::assertNotNull($invite);
        self::assertSame(['2026-10-30', 0, '11', 3, false], [$invite['due_date'], $invite['sequence'], $invite['sent_by_id'], $invite['recipient_count'], $invite['out_of_date']]);
        self::assertIsString($invite['sent_at']);
        self::assertStringEndsWith('+00:00', $invite['sent_at']);
        $listed = array_values(array_filter($this->tasks->list($this->max), static fn (array $t): bool => $t['id'] === $id));
        self::assertEquals($invite, $listed[0]['calendar_invite']);
        $stored = $this->store->find($id);
        self::assertSame(['11', 'una@example.test', 'tri-task-' . $id . '@tricab.ai'], [(string) $stored?->sentBy, $stored?->organizerEmail, $stored?->uid]);
        self::assertEqualsCanonicalizing(['una@example.test', 'ivy@example.test', 'rae@example.test'], $stored->recipients ?? []);
    }

    public function test_sending_again_after_the_due_date_changes_updates_the_same_event_with_the_next_sequence(): void
    {
        $id = $this->make();
        $this->invites->send($this->una, [$id]);

        $this->tasks->update($this->max, $id, ['due_date' => '2026-11-06']);
        $stale = $this->inviteOf($this->max, $id);
        self::assertNotNull($stale);
        self::assertTrue($stale['out_of_date'], 'the due date moved since the invite');
        self::assertSame('2026-10-30', $stale['due_date']);

        $out = $this->invites->send($this->una, [$id]);

        self::assertSame(1, $out['sent']);
        self::assertTrue($out['results'][0]['update']);
        self::assertFalse($out['results'][0]['organizer_changed']);
        $first = self::lines($this->ics(0));
        $second = self::lines($this->ics(1));
        self::assertContains('UID:tri-task-' . $id . '@tricab.ai', $second);
        self::assertContains('SEQUENCE:1', $second);
        self::assertContains('DTSTART;VALUE=DATE:20261106', $second);
        self::assertContains('DTEND;VALUE=DATE:20261107', $second);
        self::assertSame(array_values(array_filter($first, static fn (string $l): bool => str_starts_with($l, 'UID:'))), array_values(array_filter($second, static fn (string $l): bool => str_starts_with($l, 'UID:'))));
        self::assertSame('Updated calendar invite: Bank rec (due 6 Nov 2026)', $this->transport->sent[1]->subject);
        self::assertStringContainsString('it was 30 Oct 2026', $this->transport->sent[1]->body);

        $fresh = $this->inviteOf($this->max, $id);
        self::assertNotNull($fresh);
        self::assertSame(['2026-11-06', 1, false], [$fresh['due_date'], $fresh['sequence'], $fresh['out_of_date']]);
        self::assertCount(1, $this->store->all(), 'one record per task, not one per send');

        $this->invites->send($this->una, [$id]);
        self::assertContains('SEQUENCE:2', self::lines($this->ics(2)), 'a plain re-send still counts up');
    }

    public function test_an_update_sent_by_someone_else_keeps_the_event_but_says_the_organizer_changed(): void
    {
        $id = $this->make(['preparer_ids' => ['11', '12']]);
        $this->invites->send($this->una, [$id]);

        $out = $this->invites->send($this->max, [$id]);

        self::assertTrue($out['results'][0]['organizer_changed']);
        self::assertSame('max@example.test', $this->transport->sent[1]->mailbox);
        self::assertContains('ORGANIZER;CN="Max Manager":mailto:max@example.test', self::lines($this->ics(1)));
        self::assertContains('UID:tri-task-' . $id . '@tricab.ai', self::lines($this->ics(1)));
    }

    public function test_tasks_without_a_due_date_nobody_or_an_address_are_skipped_with_the_reason_and_the_rest_still_go(): void
    {
        $dated = $this->make();
        $undated = $this->make(['name' => 'No date', 'due_date' => null]);
        $nobody = $this->make(['name' => 'Nobody', 'preparer_ids' => [], 'reviewer_ids' => []]);
        $noAddress = $this->make(['name' => 'No address', 'preparer_ids' => ['14'], 'reviewer_ids' => []]);
        $partly = $this->make(['name' => 'Partly', 'preparer_ids' => ['14', '16'], 'reviewer_ids' => []]);

        $out = $this->invites->send($this->max, [$undated, $dated, $nobody, $noAddress, $partly]);

        self::assertSame([2, 3, 0], [$out['sent'], $out['skipped'], $out['failed']]);
        self::assertSame(
            [TaskCalendarInviteService::NO_DUE_DATE, null, TaskCalendarInviteService::NOBODY, TaskCalendarInviteService::NO_ADDRESSES, null],
            array_map(static fn (array $r): mixed => $r['reason'] ?? null, $out['results']),
        );
        self::assertCount(2, $this->transport->sent);
        self::assertSame(['ivy@example.test'], $this->transport->sent[1]->to, 'the person with no address is left out, and named');
        self::assertSame(['Out Of Mail'], $out['results'][4]['missing_email']);
        self::assertNull($this->inviteOf($this->max, $undated));
        self::assertCount(2, $this->store->all());
    }

    public function test_a_task_in_the_bin_is_skipped(): void
    {
        $id = $this->make();
        $this->tasks->delete($this->max, $id);

        $out = $this->invites->send($this->max, [$id]);

        self::assertSame([0, 1], [$out['sent'], $out['skipped']]);
        self::assertSame(TaskCalendarInviteService::IN_THE_BIN, $out['results'][0]['reason']);
        self::assertSame([], $this->transport->sent);
    }

    public function test_a_task_the_person_may_not_see_stops_the_whole_request_and_sends_nothing(): void
    {
        $mine = $this->make();
        $theirs = $this->make(['name' => 'Theirs', 'preparer_ids' => ['16'], 'reviewer_ids' => ['13']]);

        $this->expectException(TaskNotFound::class);
        try {
            $this->invites->send($this->una, [$mine, $theirs]);
        } finally {
            self::assertSame([], $this->transport->sent);
            self::assertSame([], $this->store->all());
        }
    }

    public function test_a_failed_send_is_reported_and_records_nothing_and_the_next_task_still_goes(): void
    {
        $first = $this->make(['name' => 'First']);
        $second = $this->make(['name' => 'Second']);
        $this->transport->failWith = new FinanceMailTransportFailed('Graph said no');

        $out = $this->invites->send($this->una, [$first]);
        self::assertSame([0, 0, 1], [$out['sent'], $out['skipped'], $out['failed']]);
        self::assertSame('Graph said no', $out['results'][0]['error']);
        self::assertNull($this->inviteOf($this->max, $first));

        $this->transport->failWith = null;
        $out = $this->invites->send($this->una, [$first, $second]);
        self::assertSame(2, $out['sent']);
        self::assertSame(0, $this->store->find($first)?->sequence, 'the failed attempt used no sequence');
    }

    public function test_the_request_is_checked_ids_a_cap_of_twenty_and_a_plain_origin(): void
    {
        $id = $this->make();
        foreach ([null, [], 'x', [''], [['a']]] as $bad) {
            try {
                $this->invites->send($this->una, $bad);
                self::fail('Expected a refusal.');
            } catch (TaskInvalid) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->invites->send($this->una, array_map(static fn (int $n): string => str_pad((string) $n, 32, '0', STR_PAD_LEFT), range(1, 21)));
            self::fail('Expected the cap.');
        } catch (TaskInvalid $e) {
            self::assertSame('Up to 20 tasks at once', $e->getMessage());
        }
        foreach (['javascript:alert(1)', 'https://evil.test/path', 'https://a b', "https://x.test\r\nBcc: a@b.test"] as $origin) {
            try {
                $this->invites->send($this->una, [$id], $origin);
                self::fail('Expected a refusal for ' . $origin);
            } catch (TaskInvalid) {
                $this->addToAssertionCount(1);
            }
        }
        $this->invites->send($this->una, [$id], 'http://localhost:5173/');
        self::assertStringContainsString('http://localhost:5173/finance/task/?task=' . $id, $this->transport->sent[0]->body);
        self::assertCount(1, $this->transport->sent);
    }

    public function test_a_name_with_commas_semicolons_quotes_and_accents_is_escaped_and_long_lines_are_folded(): void
    {
        $name = 'Rapprochement bancaire, mois; "clôture" \\ fin' . str_repeat(' très long', 12);
        $id = $this->make(['name' => $name]);

        $this->invites->send($this->una, [$id]);

        $raw = $this->ics();
        foreach (explode("\r\n", rtrim($raw, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'folded at 75 octets: ' . $line);
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'no multi-byte character is cut');
        }
        $summary = array_values(array_filter(self::lines($raw), static fn (string $l): bool => str_starts_with($l, 'SUMMARY:')));
        self::assertStringStartsWith('SUMMARY:Due: Rapprochement bancaire\\, mois\; "clôture" \\\\ fin très long', $summary[0]);
        self::assertSame(1, count($summary), 'one summary line once unfolded');
    }
}
