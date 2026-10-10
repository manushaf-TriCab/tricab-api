<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use Closure;
use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Contract\ApOutboundStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApOutboundMessage;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;
use TriCab\Core\Finance\Ap\Domain\ApUnavailable;
use TriCab\Core\Finance\Ap\Domain\ApUpstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Shared\Mail\FinanceMailConflict;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;

/**
 * The composer's server side (`app.py` routes 50 to 56, T6.10): the mailboxes a person may send from, the mail sent about an
 * invoice or an email, a vendor email (optionally with the invoice document) and a reply to a scanned email.
 *
 * Every send goes through {@see deliver()}, which the approvals task calls too for its mail (recorded against an approval
 * batch). It applies, in this order:
 *
 *  1. The sending switch (`FINANCE_AP_SENDING_ENABLED`, decision D79): off is an {@see ApUnavailable} (503) before anything else,
 *     so no record is read and no ledger row or link is written. Tri-AR's D53 does the same.
 *  2. The grant: the mailbox must be one Tri-AP may send from (an active mailbox with an `AP` send grant) that the person's
 *     mailbox scope allows, else an {@see ApForbidden} naming it. The shared sender checks the grant again.
 *  3. The shared sender, with an idempotency key on every send (question Q10): a key already sent is not sent again and the
 *     earlier link comes back.
 *
 * A failed send, and a send that mail being switched off suppressed, are an {@see ApUpstream} (502), never shown as sent; both
 * leave a link whose history row reads `Failed`. The audit event of a success is written in the transaction that records the link.
 *
 * A reply is threaded only when it goes to the original's sender from the original's mailbox (decision D56, fault R8); the
 * shared transport falls back to a standalone message when the original cannot be used.
 */
final class ApMailService
{
    public const NOT_FOUND = 'Not found';
    public const SENDING_OFF = 'Sending is switched off';
    public const MAIL_OFF = 'Mail is switched off';
    public const NO_DOCUMENT = 'The invoice attachment could not be found';
    public const BAD_VENDOR_EMAIL = 'A valid vendor email is required';
    public const BAD_RECIPIENT = 'A valid recipient is required';
    public const NO_SUBJECT_OR_BODY = 'Subject and message are required';

    public const EMAIL_REPLY_SENT = 'email_reply_sent';
    public const VENDOR_EMAIL_SENT = 'vendor_email_sent';

    /** The newest rows a history lists (`S:111-114`). */
    public const INVOICE_HISTORY_LIMIT = 100;
    public const EMAIL_HISTORY_LIMIT = 50;

    private const KEY_LIMIT = 100;

    /** @var Closure(): string */
    private readonly Closure $newId;

    /**
     * @param (callable(): string)|null $newId new 32-hex ids
     */
    public function __construct(
        private readonly ApInvoiceStore $invoices,
        private readonly ApEmailService $emails,
        private readonly ApAttachmentService $attachments,
        private readonly ApOutboundStore $outbound,
        private readonly ApAuditStore $audit,
        private readonly FinanceMailSender $mail,
        private readonly FinanceMailLedger $ledger,
        private readonly FinanceMailboxRegistry $mailboxes,
        private readonly ApTime $time,
        private readonly string $defaultMailbox,
        private readonly bool $sendingEnabled,
        ?callable $newId = null,
    ) {
        $this->newId = Closure::fromCallable($newId ?? static fn (): string => bin2hex(random_bytes(16)));
    }

    /** The configured mailbox (`AP_MAILBOX`), lower-case. */
    public function defaultMailbox(): string
    {
        return strtolower(trim($this->defaultMailbox));
    }

    /** Whether `FINANCE_AP_SENDING_ENABLED` is on. A caller that must still record its work when mail is off checks this first. */
    public function sendingEnabled(): bool
    {
        return $this->sendingEnabled;
    }

    // ---- the routes ----------------------------------------------------------------------

    /**
     * `GET /send-mailboxes`: the mailboxes Tri-AP may send from for this person: active, with a send grant for `AP`, allowed by
     * their mailbox scope. The configured mailbox first, then by address.
     *
     * @return array{mailboxes: list<array{email: string, label: string}>, default: string}
     */
    public function sendMailboxes(ApActor $actor): array
    {
        $default = $this->defaultMailbox();
        $granted = [];
        foreach ($this->mailboxes->all() as $mailbox) {
            if (!$mailbox->canSend(Module::APP_AP) || !$actor->mailboxScope->allowsMailbox($mailbox->address)) {
                continue;
            }
            $email = strtolower(trim($mailbox->address));
            $granted[] = ['email' => $email, 'label' => $mailbox->displayName !== '' ? $mailbox->displayName : $email];
        }
        usort($granted, static fn (array $a, array $b): int => [$a['email'] !== $default, $a['email']] <=> [$b['email'] !== $default, $b['email']]);

        return ['mailboxes' => $granted, 'default' => $default];
    }

    /**
     * `GET /invoices/{id}/outbound-messages`: the 100 newest rows about the invoice, newest first. Approval mail is linked to the
     * batch, so it is not here. A binned invoice has its history.
     *
     * @return list<array<string, mixed>>
     */
    public function invoiceHistory(ApActor $actor, string $invoiceId): array
    {
        $invoice = $this->visibleInvoice($actor, $invoiceId);

        return $this->history(ApOutboundTarget::invoice($invoice->id), self::INVOICE_HISTORY_LIMIT);
    }

    /**
     * `GET /emails/{id}/outbound-messages`: the 50 newest rows about the email, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function emailHistory(ApActor $actor, string $emailId): array
    {
        $email = $this->emails->get($actor, $emailId);

        return $this->history(ApOutboundTarget::email($email->id), self::EMAIL_HISTORY_LIMIT);
    }

    /**
     * `POST /invoices/{id}/send-email`: a vendor email, optionally with the invoice document, answered as the outbound row.
     *
     * @param array<string, mixed> $data `{to, cc, subject, body, from, attach_invoice, attachments, idempotency_key?}`
     * @return array<string, mixed>
     */
    public function sendVendorEmail(ApActor $actor, string $invoiceId, array $data): array
    {
        $this->requireSending();
        $invoice = $this->visibleInvoice($actor, $invoiceId);

        $to = self::recipient($data['to'] ?? null, self::BAD_VENDOR_EMAIL);
        $cc = self::cc($data['cc'] ?? null, $to);
        [$subject, $body] = self::subjectAndBody($data);
        $from = $this->sender($actor, $data['from'] ?? null, $this->defaultMailbox());

        $files = ApMailText::attachments($data['attachments'] ?? null);
        $attach = ApMailText::truthy($data['attach_invoice'] ?? false);
        $attachments = $files;
        if ($attach) {
            $document = $this->document($actor, $invoice);
            $total = strlen($document->content) + array_sum(array_map(static fn (FinanceMailAttachment $f): int => strlen($f->content), $files));
            if ($total > ApMailText::MAX_ATTACHMENT_BYTES) {
                throw new ApInvalid(ApMailText::TOO_BIG);
            }
            if (count($files) + 1 > ApMailText::MAX_ATTACHMENTS) {
                throw new ApInvalid('Attach at most ' . ApMailText::MAX_ATTACHMENTS . ' files');
            }
            $attachments = [$document, ...$files];
        }
        $target = ApOutboundTarget::invoice($invoice->id);
        $key = $this->key('ap-vendor', $target, $data['idempotency_key'] ?? null);
        $names = array_map(static fn (FinanceMailAttachment $f): string => $f->name, $files);

        $outcome = $this->deliver(
            $actor,
            $target,
            new ApOutboundMail($from, [$to], $subject, ApMailText::toHtml($body), $body, $cc, FinanceMailRules::CONTENT_HTML, $attachments, $attach),
            $key,
            fn (ApMailOutcome $sent): ApAuditEvent => $this->event(
                $actor,
                self::VENDOR_EMAIL_SENT,
                $target,
                ['to' => $to, 'cc' => $cc, 'from' => $from, 'subject' => $subject, 'attached' => $attach, 'files' => $names],
            ),
        );

        return self::present($outcome->link, $outcome->delivery);
    }

    /**
     * `POST /emails/{id}/send-reply`: a reply to a scanned email, threaded when it goes to the original's sender from its mailbox.
     * Answered as the outbound row plus `threaded`.
     *
     * @param array<string, mixed> $data `{to, cc, subject, body, from, attachments, idempotency_key?}`
     * @return array<string, mixed>
     */
    public function sendReply(ApActor $actor, string $emailId, array $data): array
    {
        $this->requireSending();
        $email = $this->emails->get($actor, $emailId);

        $to = self::recipient($data['to'] ?? null, self::BAD_RECIPIENT);
        $cc = self::cc($data['cc'] ?? null, $to);
        [$subject, $body] = self::subjectAndBody($data);
        $own = strtolower(trim($email->mailbox));
        $from = $this->sender($actor, $data['from'] ?? null, $own !== '' ? $own : $this->defaultMailbox());
        $files = ApMailText::attachments($data['attachments'] ?? null);

        $target = ApOutboundTarget::email($email->id);
        $key = $this->key('ap-reply', $target, $data['idempotency_key'] ?? null);
        $outcome = $this->deliver(
            $actor,
            $target,
            new ApOutboundMail($from, [$to], $subject, ApMailText::toHtml($body), $body, $cc, FinanceMailRules::CONTENT_HTML, $files, false, self::threadable($email, $to, $from, $own)),
            $key,
            fn (ApMailOutcome $sent): ApAuditEvent => $this->event(
                $actor,
                self::EMAIL_REPLY_SENT,
                $target,
                ['to' => $to, 'cc' => $cc, 'subject' => $subject, 'threaded' => $sent->threaded],
            ),
        );

        return self::present($outcome->link, $outcome->delivery) + ['threaded' => $outcome->threaded];
    }

    // ---- for the approvals task ----------------------------------------------------------

    /**
     * Sends one message and records it against `$target` (an invoice, an email or an approval batch). The reusable path: the
     * sending switch, the grant and scope check and the idempotency key apply as the class comment says.
     *
     * @param (callable(ApMailOutcome): ApAuditEvent)|null $auditOnSent the audit event of a send that went out, recorded with its
     *                                                                  link; not called for a repeated key
     * @throws ApUnavailable the switch is off
     * @throws ApForbidden `$mail->from` is not a mailbox Tri-AP may send from for this person, or the shared sender refused it
     * @throws ApInvalid the shared sender refused the message (recipients, subject, size)
     * @throws ApConflict the key is already used by a send that failed or is in flight
     * @throws ApUpstream the send failed, or mail is switched off (`Mail is switched off`); a `Failed` link was recorded
     */
    public function deliver(ApActor $actor, ApOutboundTarget $target, ApOutboundMail $mail, string $idempotencyKey, ?callable $auditOnSent = null): ApMailOutcome
    {
        $this->requireSending();
        $from = strtolower(trim($mail->from));
        $this->requireGrant($actor, $from);

        $linkId = ($this->newId)();
        try {
            $result = $this->mail->send(new FinanceMailMessage(
                Module::APP_AP,
                $from,
                $mail->to,
                $mail->subject,
                $mail->sentBody,
                $mail->cc,
                [],
                $mail->contentType,
                $mail->attachments,
                $idempotencyKey,
                $actor->userId,
                $mail->replyToMessageId,
            ));
        } catch (FinanceMailTransportFailed $failure) {
            if ($failure->delivery !== null) {
                $this->outbound->add($this->link($linkId, $target, $actor, $failure->delivery, $mail));
            }
            throw new ApUpstream('Failed to send email: ' . $failure->getMessage(), ApUpstream::SEND, $failure);
        } catch (FinanceMailConflict $conflict) {
            throw new ApConflict($conflict->getMessage(), [], $conflict);
        } catch (FinanceMailRefused $refused) {
            throw new ApForbidden($refused->getMessage(), [], $refused);
        } catch (FinanceMailInvalid $invalid) {
            throw new ApInvalid($invalid->getMessage());
        } catch (FinanceMailException $other) {
            throw new ApUpstream('Failed to send email: ' . $other->getMessage(), ApUpstream::SEND, $other);
        }

        if ($result->deduplicated) {
            // The key was sent already: nothing went out, and the first send's link is the answer.
            $earlier = $this->outbound->findByDelivery($result->delivery->id);
            if ($earlier === null) {
                $earlier = $this->link($linkId, $target, $actor, $result->delivery, $mail);
                $this->outbound->add($earlier);
            }

            return new ApMailOutcome($earlier, $result->delivery, false, true);
        }

        $link = $this->link($linkId, $target, $actor, $result->delivery, $mail);
        if ($result->delivery->isSuppressed()) {
            // Mail is off: the ledger says so, the history shows it, and nothing is audited as sent.
            $this->outbound->add($link);
            throw new ApUpstream('Failed to send email: ' . self::MAIL_OFF, ApUpstream::SEND);
        }

        $outcome = new ApMailOutcome($link, $result->delivery, $result->threaded, false);
        $this->outbound->transactional(function () use ($link, $auditOnSent, $outcome): void {
            $this->outbound->add($link);
            if ($auditOnSent !== null) {
                $this->audit->record($auditOnSent($outcome));
            }
        });

        return $outcome;
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function requireSending(): void
    {
        if (!$this->sendingEnabled) {
            throw new ApUnavailable(self::SENDING_OFF);
        }
    }

    /**
     * Checked against the registry and the person's scope even though the composer only offers granted addresses: the request is
     * what actually reaches Graph.
     */
    private function requireGrant(ApActor $actor, string $from): void
    {
        if (!in_array($from, array_column($this->sendMailboxes($actor)['mailboxes'], 'email'), true)) {
            throw new ApForbidden('Tri-AP is not allowed to send from ' . $from . '. Ask a finance administrator to grant it that mailbox.');
        }
    }

    private function sender(ApActor $actor, mixed $from, string $default): string
    {
        $address = strtolower(trim(is_scalar($from) ? (string) $from : ''));
        $address = $address !== '' ? $address : $default;
        $this->requireGrant($actor, $address);

        return $address;
    }

    private function visibleInvoice(ApActor $actor, string $id): ApInvoice
    {
        $invoice = $this->invoices->find($id) ?? throw new ApNotFound(self::NOT_FOUND);
        $actor->mailboxScope->requireRecord($invoice->mailbox, self::NOT_FOUND);

        return $invoice;
    }

    /** The invoice's document as an attachment, named as the invoice names it. */
    private function document(ApActor $actor, ApInvoice $invoice): FinanceMailAttachment
    {
        try {
            $file = $this->attachments->file($actor, $invoice);
        } catch (ApNotFound) {
            throw new ApInvalid(self::NO_DOCUMENT);
        }
        if ($file->bytes === '') {
            throw new ApInvalid(self::NO_DOCUMENT);
        }

        return new FinanceMailAttachment($file->downloadName, $file->bytes, $file->contentType);
    }

    private static function recipient(mixed $value, string $message): string
    {
        $to = strtolower(trim(is_string($value) ? $value : ''));
        if ($to === '' || !str_contains($to, '@') || !ApMailText::isAddress($to)) {
            throw new ApInvalid($message);
        }

        return $to;
    }

    /**
     * @return list<string>
     */
    private static function cc(mixed $value, string $to): array
    {
        $cc = ApMailText::ccList($value, $to);
        $bad = array_values(array_filter($cc, static fn (string $a): bool => !ApMailText::isAddress($a)));
        if ($bad !== []) {
            throw new ApInvalid('Invalid CC address: ' . implode(', ', $bad));
        }

        return $cc;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: string, 1: string}
     */
    private static function subjectAndBody(array $data): array
    {
        $subject = trim(is_scalar($data['subject'] ?? null) ? (string) $data['subject'] : '');
        $body = trim(is_scalar($data['body'] ?? null) ? (string) $data['body'] : '');
        if ($subject === '' || $body === '') {
            throw new ApInvalid(self::NO_SUBJECT_OR_BODY);
        }

        return [$subject, $body];
    }

    /**
     * The id of the Graph message to answer in its conversation, or null for a standalone send: the reply must go to the
     * original's sender (Graph addresses a threaded reply to them and ignores the typed recipient) from the mailbox the message
     * was read from (a Graph id belongs to its mailbox).
     */
    private static function threadable(ApEmail $email, string $to, string $from, string $own): ?string
    {
        if ($email->graphMessageId === '' || $own === '' || $from !== $own || $to !== strtolower(trim($email->senderEmail))) {
            return null;
        }

        return $email->graphMessageId;
    }

    /**
     * `ap-reply:<id>` and `ap-vendor:<id>`: a key of the person's own (`idempotency_key`, at most 100 characters), scoped to the
     * record, makes a repeated request one send; without one the key is new for each request.
     */
    private function key(string $prefix, ApOutboundTarget $target, mixed $client): string
    {
        if ($client === null || $client === '') {
            return $prefix . ':' . ($this->newId)();
        }
        if (!is_string($client) || trim($client) === '' || mb_strlen($client) > self::KEY_LIMIT) {
            throw new ApInvalid('idempotency_key must be text of at most ' . self::KEY_LIMIT . ' characters');
        }

        return $prefix . ':' . $target->id . ':' . trim($client);
    }

    private function link(string $id, ApOutboundTarget $target, ApActor $actor, FinanceMailDelivery $delivery, ApOutboundMail $mail): ApOutboundMessage
    {
        return new ApOutboundMessage($id, $target, $delivery->id, $actor->userId, $mail->storedBody, $mail->attachInvoice, $this->time->now());
    }

    /**
     * @param array<string, mixed> $details
     */
    private function event(ApActor $actor, string $action, ApOutboundTarget $target, array $details): ApAuditEvent
    {
        return new ApAuditEvent($action, $target->entityType(), $target->id, $details, $this->time->now(), $actor->userId, $actor->name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(ApOutboundTarget $target, int $limit): array
    {
        $out = [];
        foreach ($this->outbound->of($target, $limit) as $link) {
            $delivery = $this->ledger->findById($link->deliveryId);
            if ($delivery !== null) {
                $out[] = self::present($link, $delivery);
            }
        }

        return $out;
    }

    /**
     * A link and its ledger row as the source's outbound row (`S:111-114`). `pending` reads `Sending`, `sent` `Sent`, `failed`
     * `Failed`, and `suppressed` `Failed` with the reason (decision D55). `graph_message_id` is never filled, as in the source.
     *
     * @return array<string, mixed>
     */
    public static function present(ApOutboundMessage $link, FinanceMailDelivery $delivery): array
    {
        $status = match ($delivery->status) {
            FinanceMailRules::STATUS_PENDING => 'Sending',
            FinanceMailRules::STATUS_SENT => 'Sent',
            default => 'Failed',
        };
        $error = $delivery->isSuppressed() ? self::MAIL_OFF : $delivery->errorMessage;

        return [
            'id' => $link->id,
            'invoice_id' => $link->target->kind === ApOutboundTarget::INVOICE ? $link->target->id : null,
            'email_id' => $link->target->kind === ApOutboundTarget::EMAIL ? $link->target->id : null,
            'approval_batch_id' => $link->target->kind === ApOutboundTarget::APPROVAL_BATCH ? $link->target->id : null,
            'sender_user_id' => ApPresenter::person($link->senderUserId),
            'from_mailbox' => $delivery->mailbox,
            'to_addresses' => $delivery->to,
            'cc_addresses' => $delivery->cc,
            'subject' => $delivery->subject,
            'body' => $link->body,
            'attach_invoice' => $link->attachInvoice,
            'graph_message_id' => null,
            'status' => $status,
            'error' => $error !== null && $error !== '' ? $error : null,
            'sent_at' => ApPresenter::iso($delivery->sentAt),
            'created_date' => ApPresenter::iso($link->createdAt),
            'updated_date' => ApPresenter::iso($delivery->sentAt ?? $link->createdAt),
        ];
    }
}
