<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskMailLinks;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;

/**
 * Tells a preparer that a due date was requested of them (12 Oct 2026, item 7 of TRI-TASK-UX-PLAN.md): "Request due
 * date from assignee" used to show only a badge. Called after a task is saved, with the task as the API returns it.
 *
 * Sent once per task and preparer (key `due-date-request:<task id>:<user id>`), from `FINANCE_TASK_MAIL_FROM`, and only
 * while the request stands: a live task that is not completed, has no due date, and whose preparer is a member other
 * than the person saving it. Linked to the task as a `task_email`. A failure to notify never fails the save: the
 * outcome is returned, and nothing is thrown.
 */
final class TaskDueDateRequestNotifier
{
    public const SENT = 'sent';
    public const SUPPRESSED = 'suppressed';
    public const SKIPPED = 'skipped';
    public const NOT_NEEDED = 'not_needed';
    public const FAILED = 'failed';

    public function __construct(
        private readonly FinanceTaskMemberDirectory $members,
        private readonly FinanceMailSender $mail,
        private readonly FinanceMailLedger $ledger,
        private readonly TaskMailLinks $links,
        private readonly TaskTime $time,
        private readonly string $from,
    ) {
    }

    /** @param array<string, mixed> $task */
    public function notify(TaskActor $actor, array $task): string
    {
        $preparerId = is_string($task['preparer_id'] ?? null) && ctype_digit($task['preparer_id']) ? (int) $task['preparer_id'] : null;
        $id = is_string($task['id'] ?? null) ? $task['id'] : '';
        if (
            $id === ''
            || ($task['due_date_requested'] ?? false) !== true
            || ($task['due_date'] ?? '') !== ''
            || ($task['status'] ?? '') === 'completed'
            || ($task['is_deleted'] ?? false) === true
            || $preparerId === null
            || $preparerId === $actor->userId
        ) {
            return self::NOT_NEEDED;
        }
        $preparer = $this->members->find($preparerId);
        $sender = strtolower(trim($this->from));
        if ($preparer === null || trim($preparer->email) === '' || $sender === '') {
            return self::NOT_NEEDED;
        }
        $key = $this->keyFor('due-date-request:' . $id . ':' . $preparerId);
        if ($key === null) {
            return self::SKIPPED;
        }

        $name = is_string($task['name'] ?? null) ? $task['name'] : '';
        $project = is_string($task['project_name'] ?? null) ? $task['project_name'] : '';
        $asker = $actor->name !== '' ? $actor->name : $actor->email;
        $esc = TaskDailySummaryJob::esc(...);
        $body = '<div style="font-family:Inter,Arial,sans-serif;max-width:680px;margin:0 auto;color:#0f172a;">'
            . '<h2 style="font-size:20px;margin:0 0 4px;">Please set a due date</h2>'
            . '<p style="margin:0 0 16px;color:#334155;font-size:14px;">' . $esc($asker) . ' has asked you to set a due date for '
            . '<strong>' . $esc($name) . '</strong>' . ($project !== '' ? ' in ' . $esc($project) : '') . '.</p>'
            . '<p style="margin:0 0 16px;color:#334155;font-size:14px;">Open the task in Tri-Task and choose its due date.</p>'
            . '<p style="margin-top:24px;color:#94a3b8;font-size:12px;">Sent automatically by Tri-Task.</p>'
            . '</div>';

        try {
            $result = $this->mail->send(new FinanceMailMessage(
                Module::APP_TASK,
                $sender,
                [strtolower(trim($preparer->email))],
                'Please set a due date: ' . $name,
                $body,
                [],
                [],
                FinanceMailRules::CONTENT_HTML,
                [],
                $key,
                $actor->userId,
            ));
        } catch (FinanceMailTransportFailed $failure) {
            if ($failure->delivery !== null) {
                $this->link($id, $failure->delivery->id);
            }

            return self::FAILED;
        } catch (FinanceMailException) {
            return self::FAILED;
        }
        $this->link($id, $result->delivery->id);
        if ($result->delivery->isSuppressed()) {
            return self::SUPPRESSED;
        }

        return $result->deduplicated ? self::SKIPPED : self::SENT;
    }

    private function link(string $taskId, string $deliveryId): void
    {
        $this->links->insert(new TaskMailLink(TaskRules::newId(), TaskMailLink::KIND_TASK_EMAIL, $taskId, $deliveryId, $this->time->now()));
    }

    /** Null once sent; `…:retryN` after a failed attempt; a suppressed key is reused (as the daily summary). */
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
