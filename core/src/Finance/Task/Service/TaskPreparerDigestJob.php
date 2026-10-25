<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskMailLinks;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Domain\TaskRecord;

/**
 * Each preparer's own 08:00 email (12 Oct 2026, item 12 of TRI-TASK-UX-PLAN.md): the tasks they prepare that are
 * overdue or due today, in the daily summary's layout (Nick's template), sent from `FINANCE_TASK_MAIL_FROM`.
 *
 * The same tasks as {@see TaskDailySummaryJob}, split by preparer; a preparer with nothing due, a task with no
 * preparer, and a preparer who is not a Tri-Task member (no address) get nothing. The key is
 * `preparer-digest:<date>:<user id>`, retried and suppressed as the daily summary's are; each send is linked to every
 * task it lists as a `reminder`, so a task's correspondence shows the reminder. Registered only while
 * `FINANCE_TASK_PREPARER_DIGEST_ENABLED` is on.
 *
 * Returns counts only, never content.
 */
final class TaskPreparerDigestJob implements FinanceJob
{
    public const NAME = 'task.preparer-digest';

    public const TIME = '08:00';

    public function __construct(
        private readonly TaskStore $tasks,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly FinanceMailSender $mail,
        private readonly FinanceMailLedger $ledger,
        private readonly TaskMailLinks $links,
        private readonly TaskTime $time,
        private readonly string $from,
        private readonly string $timezone = 'Australia/Melbourne',
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function schedule(): FinanceJobSchedule
    {
        return FinanceJobSchedule::dailyAt(self::TIME, $this->timezone);
    }

    public function run(DateTimeImmutable $now): array
    {
        $today = $this->time->todayAt($now);
        /** @var array<int, array{overdue: list<TaskRecord>, dueToday: list<TaskRecord>}> $byPreparer */
        $byPreparer = [];
        foreach ($this->tasks->dueOnOrBefore($today) as $task) {
            // A task with several preparers is in each one's own email (23 Oct 2026).
            foreach ($task->preparerUserIds as $preparerId) {
                $byPreparer[$preparerId] ??= ['overdue' => [], 'dueToday' => []];
                $byPreparer[$preparerId][($task->dueDate ?? '') < $today ? 'overdue' : 'dueToday'][] = $task;
            }
        }
        if ($byPreparer === []) {
            return ['status' => 'nothing_due', 'sent' => 0, 'preparers' => 0];
        }
        $sender = strtolower(trim($this->from));
        if ($sender === '') {
            throw new RuntimeException('No sending mailbox is configured (FINANCE_TASK_MAIL_FROM)');
        }

        $names = [];
        $emails = [];
        foreach ($this->members->members() as $member) {
            $names[$member->userId] = $member->name !== '' ? $member->name : $member->email;
            $emails[$member->userId] = $member->email;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $suppressed = 0;
        $noAddress = 0;
        foreach ($byPreparer as $userId => $due) {
            $to = strtolower(trim($emails[$userId] ?? ''));
            if ($to === '') {
                ++$noAddress;
                continue;
            }
            $key = $this->keyFor('preparer-digest:' . $today . ':' . $userId);
            if ($key === null) {
                ++$skipped;   // already sent today
                continue;
            }
            [$subject, $body] = TaskDailySummaryJob::message($due['overdue'], $due['dueToday'], $today, $names, 'Your Tri-Task tasks', 'Your Tri-Task tasks');
            $listed = [...$due['overdue'], ...$due['dueToday']];
            try {
                $result = $this->mail->send(new FinanceMailMessage(
                    Module::APP_TASK,
                    $sender,
                    [$to],
                    $subject,
                    $body,
                    [],
                    [],
                    FinanceMailRules::CONTENT_HTML,
                    [],
                    $key,
                    null,
                ));
            } catch (FinanceMailTransportFailed $failure) {
                if ($failure->delivery !== null) {
                    $this->link($listed, $failure->delivery->id);
                }
                ++$failed;

                continue;
            } catch (FinanceMailException) {
                ++$failed;   // one bad address must not stop the rest

                continue;
            }
            $this->link($listed, $result->delivery->id);
            if ($result->delivery->isSuppressed()) {
                ++$suppressed;
            } elseif ($result->deduplicated) {
                ++$skipped;
            } else {
                ++$sent;
            }
        }
        $facts = ['sent' => $sent, 'failed' => $failed, 'suppressed' => $suppressed, 'skipped' => $skipped, 'noAddress' => $noAddress, 'preparers' => count($byPreparer)];
        if ($failed > 0) {
            throw new RuntimeException(sprintf('%d of %d preparer emails failed (%d sent, %d skipped, %d suppressed)', $failed, count($byPreparer), $sent, $skipped, $suppressed));
        }

        return ['status' => $suppressed > 0 && $sent === 0 ? 'suppressed' : 'sent'] + $facts;
    }

    /** @param list<TaskRecord> $tasks */
    private function link(array $tasks, string $deliveryId): void
    {
        foreach ($tasks as $task) {
            $this->links->insert(new TaskMailLink(TaskRules::newId(), TaskMailLink::KIND_REMINDER, $task->id, $deliveryId, $this->time->now()));
        }
    }

    /** As {@see TaskDailySummaryJob}: null when `$base` was sent, `$base:retryN` after a failed attempt. */
    private function keyFor(string $base): ?string
    {
        $key = $base;
        $attempts = 0;
        while (($row = $this->ledger->findByIdempotencyKey(Module::APP_TASK, $key)) !== null) {
            if ($row->isSent()) {
                return null;
            }
            if ($row->isSuppressed()) {
                return $key;
            }
            ++$attempts;
            $key = $base . ':retry' . $attempts;
        }

        return $key;
    }
}
