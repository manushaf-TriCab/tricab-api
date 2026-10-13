<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskMailLinks;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;

/**
 * Asks the reviewer to review a task (item 5 of TRI-TASK-UX-PLAN.md). Since 13 Oct 2026 the statuses are Active,
 * On Hold and Done, so there is no "Ready for review" to move into: the preparer presses Send for review and this
 * emails the reviewer, who marks the task Done.
 *
 * Only for a live task that is not Done and has a reviewer who is a member other than the person asking, and only
 * when the person asking is its preparer or may change any task. Every press sends (`review-request:<task>:<new
 * id>`). Sent from the person's own address when Tri-Task sends as the user (`FINANCE_TASK_SEND_AS_USER`), else
 * from `FINANCE_TASK_MAIL_FROM`, and linked to the task as a `task_email`.
 */
final class TaskReviewRequestNotifier
{
    public const SENT = 'sent';
    public const SUPPRESSED = 'suppressed';
    public const FAILED = 'failed';

    public function __construct(
        private readonly FinanceTaskMemberDirectory $members,
        private readonly FinanceMailSender $mail,
        private readonly TaskMailLinks $links,
        private readonly TaskTime $time,
        private readonly string $from,
        private readonly bool $sendAsUser = false,
    ) {
    }

    /**
     * @param array<string, mixed> $task the task as the API returns it, one the person may see
     * @return array{status: string, error?: string}
     */
    public function request(TaskActor $actor, array $task): array
    {
        $id = is_string($task['id'] ?? null) ? $task['id'] : '';
        $preparerId = is_string($task['preparer_id'] ?? null) && ctype_digit($task['preparer_id']) ? (int) $task['preparer_id'] : null;
        $reviewerId = is_string($task['reviewer_id'] ?? null) && ctype_digit($task['reviewer_id']) ? (int) $task['reviewer_id'] : null;
        if ($preparerId !== $actor->userId && !$actor->can(Module::PERMISSION_TASK_TASKS_UPDATE)) {
            throw new TaskForbidden('Only the preparer can send this task for review');
        }
        if (($task['is_deleted'] ?? false) === true || ($task['status'] ?? '') === 'completed') {
            throw new TaskInvalid('This task is done or in the Bin');
        }
        if ($reviewerId === null) {
            throw new TaskInvalid('This task has no reviewer. Choose one first.');
        }
        if ($reviewerId === $actor->userId) {
            throw new TaskInvalid('You are this task\'s reviewer: mark it Done when it is ready');
        }
        $reviewer = $this->members->find($reviewerId);
        if ($reviewer === null || trim($reviewer->email) === '') {
            throw new TaskInvalid('The reviewer has no email address');
        }
        $own = strtolower(trim($actor->email));
        $sender = $this->sendAsUser ? $own : strtolower(trim($this->from));
        if ($sender === '' || ($this->sendAsUser && filter_var($sender, FILTER_VALIDATE_EMAIL) === false)) {
            throw new TaskUnavailable($this->sendAsUser ? 'Your account has no email address to send from' : 'No sending mailbox is configured');
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
            . '<p style="margin:0 0 16px;color:#334155;font-size:14px;">Open the task in Tri-Task and mark it Done, or reply with what needs changing.</p>'
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
                'review-request:' . $id . ':' . TaskRules::newId(),
                $actor->userId,
                null,
                $this->sendAsUser,
            ));
        } catch (FinanceMailTransportFailed $failure) {
            if ($failure->delivery !== null) {
                $this->link($id, $failure->delivery->id);
            }

            return ['status' => self::FAILED, 'error' => $failure->getMessage()];
        } catch (FinanceMailException $failure) {
            return ['status' => self::FAILED, 'error' => $failure->getMessage()];
        }
        $this->link($id, $result->delivery->id);

        return ['status' => $result->delivery->isSuppressed() ? self::SUPPRESSED : self::SENT];
    }

    private function link(string $taskId, string $deliveryId): void
    {
        $this->links->insert(new TaskMailLink(TaskRules::newId(), TaskMailLink::KIND_TASK_EMAIL, $taskId, $deliveryId, $this->time->now()));
    }
}
