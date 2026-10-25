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
use TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Domain\TaskRecord;

/**
 * The 08:00 email (decision D23), `server/summary.py` as a registered job: every live task that is not
 * completed and is due on or before today in the Tri-Task time zone, as one HTML email per recipient in
 * `finance.task_report_recipients`, sent from `FINANCE_TASK_MAIL_FROM` with no actor (the platform needs no
 * borrowed actor to look names up).
 *
 * Safe to repeat the same day: the idempotency key is `daily-summary:<date>:<email>`; a key already `sent`
 * is skipped, and one that `failed` (or died `pending`) goes out again under `…:retry1`, `…:retry2` because the
 * shared ledger refuses to reuse it (F1.4). A `suppressed` key (mail switched off) is not a send: it is counted
 * as suppressed, never as sent, and may be sent later under the same key. One bad address does not stop the
 * rest, and a partial failure fails the run so the audit row says so. Nothing is sent when there are no
 * recipients or nothing is due; with no sender configured the run fails (never guesses one).
 *
 * Returns counts only, never content.
 */
final class TaskDailySummaryJob implements FinanceJob
{
    public const NAME = 'task.daily-summary';

    public const TIME = '08:00';

    private const TD = 'style="padding:8px 10px;border-bottom:1px solid #eef2f7;"';
    private const TH = 'style="padding:8px 10px;"';

    public function __construct(
        private readonly TaskStore $tasks,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly TaskReportRecipientStore $recipients,
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
        $overdue = [];
        $dueToday = [];
        foreach ($this->tasks->dueOnOrBefore($today) as $task) {
            if (($task->dueDate ?? '') < $today) {
                $overdue[] = $task;
            } else {
                $dueToday[] = $task;
            }
        }
        $facts = ['overdue' => count($overdue), 'dueToday' => count($dueToday)];

        $recipients = $this->recipients->all();
        if ($recipients === []) {
            return ['status' => 'no_recipients', 'sent' => 0] + $facts;
        }
        if ($overdue === [] && $dueToday === []) {
            return ['status' => 'nothing_due', 'sent' => 0] + $facts;
        }
        $sender = strtolower(trim($this->from));
        if ($sender === '') {
            throw new RuntimeException('No sending mailbox is configured (FINANCE_TASK_MAIL_FROM)');
        }

        $names = [];
        foreach ($this->members->members() as $member) {
            $names[$member->userId] = $member->name !== '' ? $member->name : $member->email;
        }
        [$subject, $body] = self::message($overdue, $dueToday, $today, $names);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $suppressed = 0;
        foreach ($recipients as $recipient) {
            $key = $this->keyFor('daily-summary:' . $today . ':' . $recipient->email);
            if ($key === null) {
                ++$skipped;   // already sent today
                continue;
            }
            try {
                $result = $this->mail->send(new FinanceMailMessage(
                    Module::APP_TASK,
                    $sender,
                    [$recipient->email],
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
                    $this->links->insert(new TaskMailLink(TaskRules::newId(), TaskMailLink::KIND_DAILY_SUMMARY, null, $failure->delivery->id, $this->time->now()));
                }
                ++$failed;

                continue;
            } catch (FinanceMailException) {
                ++$failed;   // one bad address must not stop the rest

                continue;
            }
            $this->links->insert(new TaskMailLink(TaskRules::newId(), TaskMailLink::KIND_DAILY_SUMMARY, null, $result->delivery->id, $this->time->now()));
            if ($result->delivery->isSuppressed()) {
                ++$suppressed;
            } elseif ($result->deduplicated) {
                ++$skipped;
            } else {
                ++$sent;
            }
        }
        $facts = ['sent' => $sent, 'failed' => $failed, 'suppressed' => $suppressed, 'skipped' => $skipped] + $facts;
        if ($failed > 0) {
            throw new RuntimeException(sprintf('%d of %d daily summary emails failed (%d sent, %d skipped, %d suppressed)', $failed, count($recipients), $sent, $skipped, $suppressed));
        }

        return ['status' => $suppressed > 0 && $sent === 0 ? 'suppressed' : 'sent'] + $facts;
    }

    /**
     * The key to send under, or null when `$base` has already been sent (`idempotency_key_for`): the first
     * attempt uses `$base`; each later attempt after a failed or interrupted one uses `$base:retryN`. A
     * suppressed one is reused: the shared service reopens it.
     */
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

    // ==================================================================================
    // The message (`build_message`, ported: the strings, the escaping, the dash for empties, `%d %b %Y`)
    // ==================================================================================

    /**
     * @param list<TaskRecord> $overdue
     * @param list<TaskRecord> $dueToday
     * @param array<int, string> $names
     * @param string $title the heading; the preparer's own email (TaskPreparerDigestJob) passes its own
     * @param string $subjectLead the subject before " — N overdue, …"
     * @return array{0: string, 1: string} subject and HTML body
     */
    public static function message(
        array $overdue,
        array $dueToday,
        string $today,
        array $names,
        string $title = 'Tri-Task Daily Task Summary',
        string $subjectLead = 'Tri-Task Daily Summary',
    ): array {
        $muted = 'color:#64748b;font-size:13px;';
        $body = '<div style="font-family:Inter,Arial,sans-serif;max-width:680px;margin:0 auto;color:#0f172a;">'
            . '<h2 style="font-size:20px;margin:0 0 4px;">' . self::esc($title) . '</h2>'
            . '<p style="margin:0 0 16px;' . $muted . '">' . self::esc($today) . ' — tasks needing attention</p>'
            . ($overdue !== [] ? self::section('Overdue', $overdue, $names) : "<p style='" . $muted . "'>No overdue tasks.</p>")
            . ($dueToday !== [] ? self::section('Due Today', $dueToday, $names) : "<p style='" . $muted . "margin-top:16px;'>No tasks due today.</p>")
            . '<p style="margin-top:24px;color:#94a3b8;font-size:12px;">Sent automatically by Tri-Task.</p>'
            . '</div>';
        $subject = sprintf('%s — %d overdue, %d due today (%s)', $subjectLead, count($overdue), count($dueToday), $today);

        return [$subject, $body];
    }

    /**
     * @param list<TaskRecord> $items
     * @param array<int, string> $names
     */
    private static function section(string $title, array $items, array $names): string
    {
        $rows = '';
        foreach ($items as $task) {
            $rows .= self::row($task, $names);
        }

        return '<h3 style="margin:24px 0 8px;font-size:15px;color:#0f172a;">' . self::esc($title) . ' (' . count($items) . ')</h3>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;color:#334155;">'
            . '<thead><tr style="background:#f8fafc;text-align:left;color:#64748b;">'
            . '<th ' . self::TH . '>Task</th><th ' . self::TH . '>Area</th><th ' . self::TH . '>Preparer</th><th ' . self::TH . '>Reviewer</th>'
            . '<th ' . self::TH . '>Due</th><th ' . self::TH . '>Progress</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** @param array<int, string> $names */
    private static function row(TaskRecord $t, array $names): string
    {
        $area = $t->financeStatementArea !== '' ? $t->financeStatementArea : '—';
        $preparer = self::peopleNames($t->preparerUserIds, $names);
        $reviewer = self::peopleNames($t->reviewerUserIds, $names);

        return '<tr>'
            . '<td ' . self::TD . '>' . self::esc($t->name) . '</td>'
            . '<td ' . self::TD . '>' . self::esc($area) . '</td>'
            . '<td ' . self::TD . '>' . self::esc($preparer) . '</td>'
            . '<td ' . self::TD . '>' . self::esc($reviewer) . '</td>'
            . '<td ' . self::TD . '>' . self::esc(self::formatDate($t->dueDate ?? '')) . '</td>'
            . '<td ' . self::TD . '>' . self::esc($t->progress . '%') . '</td>'
            . '</tr>';
    }

    /**
     * @param list<int> $userIds
     * @param array<int, string> $names
     */
    private static function peopleNames(array $userIds, array $names): string
    {
        $out = [];
        foreach ($userIds as $id) {
            $out[] = $names[$id] ?? '—';
        }

        return $out === [] ? '—' : implode(', ', $out);
    }

    /** `_fmt_date`: `06 Oct 2026`, a dash for none, and the text itself (escaped) when it is not a date. */
    public static function formatDate(string $value): string
    {
        if ($value === '') {
            return '—';
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return self::esc($value);
        }

        return $parsed->format('d M Y');
    }

    /** Python's `html.escape(..., quote=True)`: `&`, `<`, `>`, `"` and `'` (as `&#x27;`). */
    public static function esc(string $value): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#x27;'], $value);
    }
}
