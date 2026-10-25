<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Shared\Mail\FinanceMailCalendar;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskCalendarInvites;
use TriCab\Core\Finance\Task\Contract\TaskMailLinks;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskCalendarInvite;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Domain\TaskRecord;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;

/**
 * Calendar invites by due date (Nick Han, 23 Oct 2026: "can select one task or tasks to send calendar invites by due
 * date"; Manusha's decisions the same day).
 *
 * The email is a MIME message whose own `text/calendar; method=REQUEST` part is the invite (so Outlook shows a meeting request with
 * Accept, Tentative and Decline in the message), with `invite.ics` also attached for other clients; a transport that cannot send
 * MIME sends a normal email with the attachment alone.
 *
 * Each task with a due date gets one **all-day event on that date**, an email carrying an iCalendar invite
 * to **every preparer and reviewer**, sent through the same finance mail path as the reminders and from the same mailbox rule: the signed-in person's own address with
 * `FINANCE_TASK_SEND_AS_USER`, never a shared or system one. The UID is stable per task and the SEQUENCE goes up on
 * each re-send, so a changed due date **updates the same event** in people's calendars. What was sent is kept per task
 * ({@see TaskCalendarInvites}), which is what shows "Invite sent for 30 Oct", spots an invite whose due date has since
 * moved, and drives the "Has calendar invites" filter.
 *
 * A task with no due date, in the Bin, or with nobody to invite is skipped with the reason, and the rest still go.
 * One whose mail fails is reported as failed and records nothing. **A suppressed send (mail switched off) is not a
 * send**, as for reminders. Whoever may send reminders (`FINANCE.TASK.MAIL.SEND`, checked by the route) may send these.
 * A task deleted after an invite went is not cancelled in people's calendars (nothing sends `CANCEL`).
 */
final class TaskCalendarInviteService
{
    public const MAX_TASKS = 20;

    public const NO_DUE_DATE = 'It has no due date';

    public const IN_THE_BIN = 'It is in the Bin';

    public const NOBODY = 'Nobody is assigned to it';

    public const NO_ADDRESSES = 'Nobody assigned to it has an email address';

    /** Used when the page does not say where it is served from. */
    public const DEFAULT_ORIGIN = 'https://tricab.ai';

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskMailService $mailService,
        private readonly TaskSetupStore $setup,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly FinanceMailSender $mail,
        private readonly TaskMailLinks $links,
        private readonly TaskCalendarInvites $invites,
        private readonly TaskTime $time,
    ) {
    }

    /**
     * @param mixed $ids the task ids (at most {@see MAX_TASKS})
     * @param string $origin where the pages are served from, for the link to each task ("" for {@see DEFAULT_ORIGIN})
     * @return array{sent: int, skipped: int, failed: int, results: list<array<string, mixed>>}
     */
    public function send(TaskActor $actor, mixed $ids, string $origin = ''): array
    {
        $ids = self::ids($ids);
        $origin = self::origin($origin);
        $sender = $this->sender($actor);

        // All or nothing on what can be checked up front: a task the caller may not see (404) stops the request.
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = $this->tasks->requireVisible($actor, $id);
        }

        $people = [];
        foreach ($this->members->members() as $member) {
            $people[$member->userId] = $member;
        }
        $projects = $this->setup->projectNames();
        $existing = $this->invites->all();
        $results = [];
        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($rows as $task) {
            $skip = $this->skipReason($task, $people);
            if ($skip !== null) {
                ++$counts['skipped'];
                $results[] = ['task_id' => $task->id, 'name' => $task->name, 'ok' => false, 'skipped' => true, 'reason' => $skip];

                continue;
            }
            $result = $this->sendOne($actor, $sender, $task, $people, $projects[$task->projectId ?? ''] ?? '', $origin, $existing[$task->id] ?? null);
            ++$counts[$result['skipped'] ?? false ? 'skipped' : (($result['ok'] ?? false) ? 'sent' : 'failed')];
            $results[] = $result;
        }

        return $counts + ['results' => $results];
    }

    /**
     * The mailbox the invites go from: the signed-in person's own address (or the configured one when sending as a
     * shared mailbox is how Tri-Task is set up), and only one this person may send from.
     */
    private function sender(TaskActor $actor): string
    {
        $mailboxes = $this->mailService->sendMailboxes($actor);
        $from = $mailboxes['default'];
        if ($from === '') {
            throw new TaskUnavailable('Your account has no email address to send from');
        }
        if (!in_array($from, array_column($mailboxes['mailboxes'], 'email'), true)) {
            throw new TaskForbidden('Tri-Task is not allowed to send from ' . $from . '.');
        }

        return $from;
    }

    /** @param array<int, \TriCab\Core\Finance\Task\Domain\TaskMember> $people */
    private function skipReason(TaskRecord $task, array $people): ?string
    {
        if ($task->deletedAt !== null) {
            return self::IN_THE_BIN;
        }
        if ($task->dueDate === null || trim($task->dueDate) === '' || DateTimeImmutable::createFromFormat('!Y-m-d', $task->dueDate) === false) {
            return self::NO_DUE_DATE;
        }
        if ($task->everyone() === []) {
            return self::NOBODY;
        }
        if ($this->attendees($task, $people) === []) {
            return self::NO_ADDRESSES;
        }

        return null;
    }

    /**
     * Every preparer and reviewer with a valid address, each once.
     *
     * @param array<int, \TriCab\Core\Finance\Task\Domain\TaskMember> $people
     * @return list<array{name: string, email: string}>
     */
    private function attendees(TaskRecord $task, array $people): array
    {
        $out = [];
        $seen = [];
        foreach ($task->everyone() as $userId) {
            $member = $people[$userId] ?? null;
            $email = $member === null ? '' : strtolower(trim($member->email));
            if ($member === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $out[] = ['name' => trim($member->name), 'email' => $email];
        }

        return $out;
    }

    /**
     * @param array<int, \TriCab\Core\Finance\Task\Domain\TaskMember> $people
     * @return array<string, mixed>
     */
    private function sendOne(TaskActor $actor, string $sender, TaskRecord $task, array $people, string $project, string $origin, ?TaskCalendarInvite $previous): array
    {
        $dueDate = (string) $task->dueDate;
        $attendees = $this->attendees($task, $people);
        $missing = [];
        foreach ($task->everyone() as $userId) {
            $member = $people[$userId] ?? null;
            $email = $member === null ? '' : strtolower(trim($member->email));
            if ($member === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $missing[] = $member !== null && trim($member->name) !== '' ? trim($member->name) : 'Person ' . $userId;
            }
        }
        $now = $this->time->now();
        $uid = $previous !== null ? $previous->uid : TaskIcs::uid($task->id);
        $sequence = $previous === null ? 0 : $previous->sequence + 1;
        $isUpdate = $previous !== null;
        $url = $origin . '/finance/task/?task=' . rawurlencode($task->id);
        $senderName = trim($actor->name) !== '' ? trim($actor->name) : $sender;
        $pretty = self::longDate($dueDate);
        $dueLabel = self::shortDate($dueDate);

        $ics = TaskIcs::invite(
            $uid,
            $sequence,
            $dueDate,
            'Due: ' . $task->name,
            self::description($task->name, $project, $pretty, $url),
            $url,
            ['name' => $senderName, 'email' => $sender],
            $attendees,
            $now,
        );
        $body = self::body($task->name, $project, $pretty, $url, $senderName, $isUpdate ? self::shortDate($previous->dueDateSent) : null);
        $subject = ($isUpdate ? 'Updated calendar invite: ' : 'Calendar invite: ') . $task->name . ' (due ' . $dueLabel . ')';
        $recipients = array_column($attendees, 'email');
        $linkId = TaskRules::newId();
        $base = ['task_id' => $task->id, 'name' => $task->name, 'missing_email' => $missing];
        try {
            $result = $this->mail->send(new FinanceMailMessage(
                Module::APP_TASK,
                $sender,
                $recipients,
                $subject,
                TaskMailText::toHtml($body),
                [],
                [],
                FinanceMailRules::CONTENT_HTML,
                [new FinanceMailAttachment('invite.ics', $ics, 'application/ics')],
                'task-' . TaskMailLink::KIND_CALENDAR_INVITE . ':' . $linkId,
                $actor->userId,
                null,
                true,
                new FinanceMailCalendar($ics, $body),
            ));
        } catch (FinanceMailTransportFailed $failure) {
            if ($failure->delivery !== null) {
                $this->links->insert(new TaskMailLink($linkId, TaskMailLink::KIND_CALENDAR_INVITE, $task->id, $failure->delivery->id, $now));
            }

            return $base + ['ok' => false, 'error' => $failure->getMessage()];
        } catch (FinanceMailException $failure) {
            return $base + ['ok' => false, 'error' => $failure->getMessage()];
        }
        $this->links->insert(new TaskMailLink($linkId, TaskMailLink::KIND_CALENDAR_INVITE, $task->id, $result->delivery->id, $now));
        if ($result->delivery->isSuppressed()) {
            return $base + ['ok' => false, 'error' => TaskMailService::MAIL_OFF, 'suppressed' => true];
        }

        $invite = new TaskCalendarInvite($task->id, $uid, $sequence, $dueDate, $actor->userId, $sender, $recipients, $now);
        $this->invites->save($invite);

        return $base + [
            'ok' => true,
            'update' => $isUpdate,
            'recipients' => $recipients,
            // An update from someone other than the first sender may be ignored by Outlook: the organizer must match.
            'organizer_changed' => $previous !== null && strtolower($previous->organizerEmail) !== $sender,
            'calendar_invite' => TaskPresenter::calendarInvite($invite, $dueDate),
        ];
    }

    private static function description(string $name, string $project, string $due, string $url): string
    {
        return $name . ($project !== '' ? "\nProject: " . $project : '') . "\nDue: " . $due . "\nThe task is here: " . $url;
    }

    private static function body(string $name, string $project, string $due, string $url, string $senderName, ?string $wasDue): string
    {
        $lines = [
            'Hi,',
            $wasDue === null
                ? 'This is a calendar invite for the due date of a task in Tri-Task.'
                : 'The due date of this task has changed (it was ' . $wasDue . '). This invite updates the event already in your calendar.',
            'Task: ' . $name . ($project !== '' ? "\nProject: " . $project : '') . "\nDue: " . $due,
            'The task is here: ' . $url,
            'Please open the attached invite.ics to add or update the event in your calendar. It is an all-day event on the due date.',
            'Thank you.' . "\n" . $senderName,
        ];

        return implode("\n\n", $lines);
    }

    /** "Friday 30 October 2026". */
    private static function longDate(string $date): string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $day === false ? $date : $day->format('l j F Y');
    }

    /** "30 Oct 2026". */
    private static function shortDate(string $date): string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $day === false ? $date : $day->format('j M Y');
    }

    /** @return list<string> */
    private static function ids(mixed $ids): array
    {
        if (!is_array($ids) || $ids === [] || !array_is_list($ids)) {
            throw new TaskInvalid('Send a list of task ids');
        }
        $out = [];
        foreach ($ids as $id) {
            $id = is_string($id) || is_int($id) ? (string) $id : '';
            if ($id === '') {
                throw new TaskInvalid('Every task id must be filled in');
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        if (count($out) > self::MAX_TASKS) {
            throw new TaskInvalid('Up to ' . self::MAX_TASKS . ' tasks at once');
        }

        return $out;
    }

    /** The page's origin (`https://tricab.ai`, or `http://localhost:5173` in development), no path. */
    private static function origin(string $origin): string
    {
        $origin = rtrim(trim($origin), '/');
        if ($origin === '') {
            return self::DEFAULT_ORIGIN;
        }
        if (preg_match('~^https?://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:\d{1,5})?$~', $origin) !== 1) {
            throw new TaskInvalid('link_origin must be the address the page is served from, such as https://tricab.ai');
        }

        return $origin;
    }
}
