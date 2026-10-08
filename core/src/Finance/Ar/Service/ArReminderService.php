<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use Closure;
use Throwable;
use TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * One pass of the overdue reminders (T5.11): each open register item past its due date, not yet reminded, gets one mail to
 * the person it is assigned to, from the AR mailbox (the source's `dispatch_overdue_reminders`).
 *
 * A record with nobody to remind (no assignee, or one with no address) is left alone and counted as `unassigned`: it is
 * not marked, so it is reminded once a person is assigned, and it does not use up the cap. The source mailed the AR
 * mailbox itself for these; with most of the migrated register unassigned that would be hundreds of mails to the team's
 * own inbox (runbook decision 2, 8 Oct 2026).
 *
 * A reminder is marked sent only when the mail really went. A switched-off mail service stops the pass after one ledger
 * row, three failures in a row stop it too (Graph is down), a refused grant fails the run, and at most `$cap` go in a pass
 * so a backlog drains over days (decision D61). The key is per record, due date and day, so a repeat on the same day finds
 * the earlier send, and a failed one is retried tomorrow under a new key.
 */
final class ArReminderService
{
    public const CAP = 200;
    public const STOP_AFTER_FAILURES = 3;
    /** How many overdue records one pass looks at to find `$cap` that have somebody to remind. */
    public const LOOK_AT = 5000;

    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param (callable(string): void)|null $log written to `error_log` unless a test passes its own
     */
    public function __construct(
        private readonly ArRecordStore $records,
        private readonly ArAssigneeDirectory $assignees,
        private readonly FinanceMailSender $mail,
        private readonly ArTime $time,
        private readonly string $mailbox,
        private readonly int $cap = self::CAP,
        ?callable $log = null,
    ) {
        $this->log = Closure::fromCallable($log ?? static function (string $line): void {
            error_log($line);
        });
    }

    /**
     * @return array{due: int, sent: int, suppressed: int, failed: int, skipped: int, unassigned: int, capped: bool}
     * @throws \RuntimeException when the mailbox may not send, so the job run says why
     */
    public function dispatch(): array
    {
        $today = $this->time->today();
        $unassigned = 0;
        $due = [];
        $recipients = [];
        foreach ($this->records->overdueAwaitingReminder($today, max(self::LOOK_AT, $this->cap + 1)) as $record) {
            $recipient = $this->recipient($record);
            if ($recipient === null) {
                ++$unassigned;
                continue;
            }
            $due[] = $record;
            $recipients[$record->id] = $recipient;
        }
        // More than the cap in hand means "there is more", without a second query.
        $capped = count($due) > $this->cap;
        $due = array_slice($due, 0, $this->cap);

        $result = ['due' => count($due), 'sent' => 0, 'suppressed' => 0, 'failed' => 0, 'skipped' => 0, 'unassigned' => $unassigned, 'capped' => $capped];
        $failures = 0;
        foreach ($due as $record) {
            try {
                $sent = $this->mail->send(new FinanceMailMessage(
                    Module::APP_AR,
                    strtolower(trim($this->mailbox)),
                    [$recipients[$record->id]],
                    ArReminderMail::subject($record),
                    ArReminderMail::body($record),
                    [],
                    [],
                    FinanceMailRules::CONTENT_HTML,
                    [],
                    'ar-reminder:' . $record->id . ':' . $record->dueDate . ':' . $today,
                    null,
                ));
            } catch (FinanceMailRefused $refused) {
                throw new \RuntimeException($refused->getMessage(), 0, $refused);
            } catch (FinanceMailTransportFailed $failure) {
                ++$result['failed'];
                ($this->log)('Tri-AR overdue reminder not sent for record ' . $record->id . ' (' . $failure::class . ')');
                if (++$failures >= self::STOP_AFTER_FAILURES) {
                    $result['skipped'] = count($due) - $result['sent'] - $result['suppressed'] - $result['failed'];
                    break;
                }
                continue;
            } catch (FinanceMailException $other) {
                // A message the shared service refused as invalid: this record cannot be reminded, the rest can.
                ++$result['failed'];
                ($this->log)('Tri-AR overdue reminder refused for record ' . $record->id . ' (' . $other::class . ')');
                continue;
            }
            $failures = 0;
            if ($sent->delivery->isSuppressed()) {
                ++$result['suppressed'];
                $result['skipped'] = count($due) - $result['sent'] - $result['suppressed'] - $result['failed'];
                break;
            }
            try {
                $this->records->markReminderSent($record->id, $this->time->now());
            } catch (Throwable $failure) {
                // The mail went; tomorrow's key differs, so say so loudly rather than let it send twice unseen.
                ($this->log)('Tri-AR overdue reminder sent but not marked for record ' . $record->id . ' (' . $failure::class . ')');
            }
            ++$result['sent'];
        }

        return $result;
    }

    /**
     * The assignee's address through the id (finding 16), else a migrated assignee that is an address, else nobody.
     */
    private function recipient(ArRecord $record): ?string
    {
        if ($record->assignedUserId !== null) {
            $assignee = $this->assignees->find($record->assignedUserId);
            if ($assignee !== null && ArMailText::isAddress(strtolower(trim($assignee->email)))) {
                return strtolower(trim($assignee->email));
            }
        }
        $name = strtolower(trim($record->assignedName));
        if ($name !== '' && ArMailText::isAddress($name)) {
            return $name;
        }

        return null;
    }
}
