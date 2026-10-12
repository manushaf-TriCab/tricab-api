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
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;

/**
 * Tells the reviewer a task is ready for them (13 Oct 2026, item 5 of TRI-TASK-UX-PLAN.md). Called after a task is
 * saved, with the task as the API returns it.
 *
 * Sent once for each move into Ready for review: the key is `review-request:<history row id>`, so saving the task
 * again sends nothing, and sending it back and resubmitting it sends again. Only while the task is live, Ready for
 * review, and has a reviewer who is a member other than the person saving it. From `FINANCE_TASK_MAIL_FROM`, linked
 * to the task as a `task_email`. A failure never fails the save.
 */
final class TaskReviewRequestNotifier
{
    public const SENT = 'sent';
    public const SUPPRESSED = 'suppressed';
    public const SKIPPED = 'skipped';
    public const NOT_NEEDED = 'not_needed';
    public const FAILED = 'failed';

    public function __construct(
        private readonly TaskStore $tasks,
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
        $reviewerId = is_string($task['reviewer_id'] ?? null) && ctype_digit($task['reviewer_id']) ? (int) $task['reviewer_id'] : null;
        $id = is_string($task['id'] ?? null) ? $task['id'] : '';
        if ($id === '' || ($task['status'] ?? '') !== 'ready_for_review' || ($task['is_deleted'] ?? false) === true
            || $reviewerId === null || $reviewerId === $actor->userId) {
            return self::NOT_NEEDED;
        }
        $reviewer = $this->members->find($reviewerId);
        $sender = strtolower(trim($this->from));
        if ($reviewer === null || trim($reviewer->email) === '' || $sender === '') {
            return self::NOT_NEEDED;
        }
        $move = null;
        foreach ($this->tasks->historyFor($id) as $row) {   // newest first
            if ($row->toStatus === 'ready_for_review') {
                $move = $row;
                break;
            }
        }
        if ($move === null) {
            return self::NOT_NEEDED;
        }
        $key = $this->keyFor('review-request:' . $move->id);
        if ($key === null) {
            return self::SKIPPED;
        }

        $esc = TaskDailySummaryJob::esc(...);
        $name = is_string($task['name'] ?? null) ? $task['name'] : '';
        $project = is_string($task['project_name'] ?? null) ? $task['project_name'] : '';
        $by = $actor->name !== '' ? $actor->name : $actor->email;
        $due = is_string($task['due_date'] ?? null) && $task['due_date'] !== '' ? TaskDailySummaryJob::formatDate($task['due_date']) : '';
        $body = '<div style="font-family:Inter,Arial,sans-serif;max-width:680px;margin:0 auto;color:#0f172a;">'
            . '<h2 style="font-size:20px;margin:0 0 4px;">Ready for your review</h2>'
            . '<p style="margin:0 0 16px;color:#334155;font-size:14px;">' . $esc($by) . ' has sent <strong>' . $esc($name) . '</strong>'
            . ($project !== '' ? ' in ' . $esc($project) : '') . ' to you for review' . ($due !== '' ? ' (due ' . $due . ')' : '') . '.</p>'
            . '<p style="margin:0 0 16px;color:#334155;font-size:14px;">Open the task in Tri-Task: mark it Done, or set it back to Working on it with a note.</p>'
            . '<p style="margin-top:24px;color:#94a3b8;font-size:12px;">Sent automatically by Tri-Task.</p>'
            . '</div>';

        try {
            $result = $this->mail->send(new FinanceMailMessage(
                Module::APP_TASK,
                $sender,
                [strtolower(trim($reviewer->email))],
                'Ready for your review: ' . $name,
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

    /** Null once sent; `…:retryN` after a failed attempt; a suppressed key is reused. */
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
