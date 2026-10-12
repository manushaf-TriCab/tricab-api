<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskMailLinks;
use TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;

/**
 * Task email and reminders (routes 44 to 46), from `app.py` (`send_mailboxes`, `send_mail`,
 * `task_mail`) and `mailing.py`, over the shared finance mail service (decision D22).
 *
 * Kept from the source: at most 20 emails a request, all or nothing on validation (the first
 * problem is named; every stray address is named); each email needs a task the caller may see
 * (404 otherwise), a recipient, a subject and a body; every address a Tri-Task member or a
 * daily-summary recipient; `kind` is `task_email` or `reminder`; the body is plain text turned
 * into HTML; attachments arrive as base64, at most 10 and 3 MB together; results are **per
 * email** and a failed one does not stop the rest.
 *
 * Changed for the platform: the From mailbox must be one Tri-Task holds a send grant on **and**
 * one the person's mailbox scope allows (F1.10); the idempotency key `task-<kind>:<link id>` is
 * stored in the shared ledger (the source made the key but kept nothing to reuse); the body of a
 * sent mail is kept nowhere (Q5), only a link row to the ledger; and **a suppressed send (mail
 * switched off) is not a send**: it is reported per email as "Mail is switched off", never as sent.
 *
 * Since 13 Oct 2026 (Nick Han: "can we send from user email"), with `$sendAsUser` a person may also send
 * from their own address, offered first and as the default; never from anyone else's.
 */
final class TaskMailService
{
    public const MAX_EMAILS = 20;

    public const MAIL_OFF = 'Mail is switched off';

    public function __construct(
        private readonly TaskService $tasks,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly TaskReportRecipientStore $recipients,
        private readonly FinanceMailSender $mail,
        private readonly FinanceMailLedger $ledger,
        private readonly FinanceMailboxRegistry $mailboxes,
        private readonly FinanceAccessScopeReader $scopes,
        private readonly TaskMailLinks $links,
        private readonly TaskTime $time,
        private readonly string $defaultFrom = '',
        private readonly bool $sendAsUser = false,
    ) {
    }

    /** The configured sending mailbox, lower-case, or "" (`FINANCE_TASK_MAIL_FROM`; no fallback). */
    public function defaultFrom(): string
    {
        return strtolower(trim($this->defaultFrom));
    }

    /**
     * The mailboxes Tri-Task may send from for this person: active, with a send grant for `TASK`,
     * and allowed by their mailbox scope (no scope rows means all). The default one first. With
     * `$sendAsUser`, the person's own address comes before them all and is the default.
     *
     * @return array{mailboxes: list<array{email: string, label: string}>, default: string}
     */
    public function sendMailboxes(TaskActor $actor): array
    {
        $scope = $this->scopes->forUser($actor->userId, Module::APP_TASK);
        $own = $this->ownAddress($actor);
        $default = $own ?? $this->defaultFrom();
        $granted = [];
        foreach ($this->mailboxes->all() as $mailbox) {
            if (!$mailbox->canSend(Module::APP_TASK) || !$scope->allowsMailbox($mailbox->address)) {
                continue;
            }
            $email = strtolower(trim($mailbox->address));
            if ($email === $own) {
                continue;
            }
            $granted[] = ['email' => $email, 'label' => $mailbox->displayName !== '' ? $mailbox->displayName : $email];
        }
        usort($granted, static fn (array $a, array $b): int => [$a['email'] !== $default, $a['email']] <=> [$b['email'] !== $default, $b['email']]);
        if ($own !== null) {
            array_unshift($granted, ['email' => $own, 'label' => trim($actor->name) !== '' ? trim($actor->name) : $own]);
        }

        return ['mailboxes' => $granted, 'default' => $default];
    }

    /**
     * What has been sent about a task, newest first: the subject, recipients and status the ledger
     * holds, never a body. 404 for a task the caller may not see.
     *
     * @return list<array<string, mixed>>
     */
    public function history(TaskActor $actor, string $taskId): array
    {
        $this->tasks->requireVisible($actor, $taskId);
        $out = [];
        foreach ($this->links->forTask($taskId) as $link) {
            $delivery = $this->ledger->findById($link->deliveryId);
            if ($delivery === null) {
                continue;
            }
            $out[] = [
                'id' => $link->id,
                'kind' => $link->kind,
                'from' => $delivery->mailbox,
                'to' => $delivery->to,
                'cc' => $delivery->cc,
                'subject' => $delivery->subject,
                'status' => $delivery->status,
                'error' => $delivery->errorMessage ?? '',
                'sent_at' => TaskPresenter::iso($delivery->sentAt),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data `{kind, from, emails: [{task_id, to, cc, subject, body}], attachments}`
     * @return array{sent: int, results: list<array<string, mixed>>}
     */
    public function send(TaskActor $actor, array $data): array
    {
        $kind = $data['kind'] ?? null;
        if ($kind !== TaskMailLink::KIND_TASK_EMAIL && $kind !== TaskMailLink::KIND_REMINDER) {
            throw new TaskInvalid('kind must be task_email or reminder');
        }
        $emails = $data['emails'] ?? null;
        if (!is_array($emails) || $emails === [] || !array_is_list($emails)) {
            throw new TaskInvalid('No emails to send');
        }
        if (count($emails) > self::MAX_EMAILS) {
            throw new TaskInvalid('Up to ' . self::MAX_EMAILS . ' emails at once');
        }

        $from = $data['from'] ?? '';
        $sender = strtolower(TaskRules::strip(is_scalar($from) && $from !== '' && $from !== false ? (string) $from : $this->defaultFrom()));
        if ($sender === '') {
            throw new TaskUnavailable('No sending mailbox is configured');
        }
        $allowedSenders = array_column($this->sendMailboxes($actor)['mailboxes'], 'email');
        if (!in_array($sender, $allowedSenders, true)) {
            throw new TaskForbidden('Tri-Task is not allowed to send from ' . $sender . '. Ask a finance administrator to grant it that mailbox.');
        }
        $ownMailbox = $sender === $this->ownAddress($actor);
        $attachments = TaskMailText::attachments($data['attachments'] ?? null);

        $allowed = [];
        foreach ($this->members->members() as $member) {
            $allowed[strtolower($member->email)] = true;
        }
        foreach ($this->recipients->all() as $recipient) {
            $allowed[strtolower($recipient->email)] = true;
        }

        $prepared = [];
        foreach ($emails as $item) {
            if (!is_array($item)) {
                throw new TaskInvalid('Each email must be an object');
            }
            $taskId = $item['task_id'] ?? '';
            $taskId = is_scalar($taskId) && $taskId !== false ? (string) $taskId : '';
            if ($taskId === '') {
                throw new TaskInvalid('Every email needs a task_id');
            }
            $this->tasks->requireVisible($actor, $taskId);
            $to = TaskMailText::addressList($item['to'] ?? null);
            $cc = TaskMailText::addressList($item['cc'] ?? null);
            $rawSubject = $item['subject'] ?? '';
            $subject = TaskRules::strip(is_scalar($rawSubject) ? (string) $rawSubject : '');
            $rawBody = $item['body'] ?? '';
            $body = is_scalar($rawBody) ? (string) $rawBody : '';
            if ($to === [] || $subject === '' || TaskRules::strip($body) === '') {
                throw new TaskInvalid('Every email needs a recipient, subject and body');
            }
            $stray = [];
            foreach ([...$to, ...$cc] as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || !isset($allowed[$address])) {
                    $stray[] = $address;
                }
            }
            if ($stray !== []) {
                throw new TaskInvalid('These addresses are not Tri-Task members or summary recipients: ' . implode(', ', $stray));
            }
            $prepared[] = [$taskId, $to, $cc, $subject, $body];
        }

        $results = [];
        $sent = 0;
        foreach ($prepared as [$taskId, $to, $cc, $subject, $body]) {
            $linkId = TaskRules::newId();
            $label = implode(', ', $to);
            try {
                $result = $this->mail->send(new FinanceMailMessage(
                    Module::APP_TASK,
                    $sender,
                    $to,
                    $subject,
                    TaskMailText::toHtml($body),
                    $cc,
                    [],
                    FinanceMailRules::CONTENT_HTML,
                    $attachments,
                    'task-' . $kind . ':' . $linkId,
                    $actor->userId,
                    null,
                    $ownMailbox,
                ));
            } catch (FinanceMailTransportFailed $failure) {
                // Per-email results, as the Base44 functions gave: this one failed, the rest still go.
                if ($failure->delivery !== null) {
                    $this->links->insert(new TaskMailLink($linkId, $kind, $taskId, $failure->delivery->id, $this->time->now()));
                }
                $results[] = ['to' => $label, 'ok' => false, 'error' => $failure->getMessage()];

                continue;
            } catch (FinanceMailException $failure) {
                $results[] = ['to' => $label, 'ok' => false, 'error' => $failure->getMessage()];

                continue;
            }
            $this->links->insert(new TaskMailLink($linkId, $kind, $taskId, $result->delivery->id, $this->time->now()));
            if ($result->delivery->isSuppressed()) {
                $results[] = ['to' => $label, 'ok' => false, 'error' => self::MAIL_OFF, 'suppressed' => true];

                continue;
            }
            ++$sent;
            $results[] = ['to' => $label, 'ok' => true];
        }

        return ['sent' => $sent, 'results' => $results];
    }

    /** The person's own address when they may send from it, lower-case; else null. */
    private function ownAddress(TaskActor $actor): ?string
    {
        $email = strtolower(trim($actor->email));

        return $this->sendAsUser && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
