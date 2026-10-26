<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2ExtractedInvoice;
use TriCab\Core\Finance\Ap2\Ai\Ap2MailContext;
use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2DraftWriter;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceExtractor;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory;
use TriCab\Core\Finance\Ap2\Contract\Ap2StatementStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Conflict;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invalid;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailAttachment;
use TriCab\Core\Finance\Ap2\Domain\Ap2NotFound;
use TriCab\Core\Finance\Ap2\Domain\Ap2Upstream;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Shared\Mail\FinanceMailConflict;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use TriCab\Core\Finance\Shared\Mail\FinanceMailResult;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * The inbox: list, open, edit, draft, send and turn into an invoice. The mailbox is never touched here.
 *
 * Rules a write follows:
 *  - The server clears `draftReviewed` whenever the draft, the To (`replyTo`), the CC, the supplier or the draft
 *    attachments actually change, whatever the body says about `draftReviewed` in the same request. A send always
 *    needs a fresh review. Changing the supplier also clears the draft attachments and `approvalId` (the prototype).
 *  - A mail that has been sent keeps its draft: those fields are a 409; category, owner and status may still change.
 *  - Send needs: sending switched on (FINANCE_AP2_SENDING_ENABLED), a reviewed draft, valid To and CC, not already
 *    sent, and a current Approved Email approval when the mail has one. It goes from the AP mailbox as a reply in the
 *    supplier's own thread, with an idempotency key made from the mail and what is being sent, and marks the mail
 *    Done and sent. The test-recipient guard lives in the mail transport, so it applies to this send too.
 */
final class Ap2MailService
{
    public const TABS = ['all', 'progress', 'done', 'hold'];

    public function __construct(
        private readonly Ap2MailStore $mail,
        private readonly Ap2SupplierStore $suppliers,
        private readonly Ap2StatementStore $statements,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2ApprovalStore $approvals,
        private readonly Ap2Snapshots $snapshots,
        private readonly Ap2PersonDirectory $people,
        private readonly Ap2InvoiceService $invoices,
        private readonly Ap2FileService $files,
        private readonly Ap2DraftWriter $writer,
        private readonly Ap2InvoiceExtractor $extractor,
        private readonly FinanceMailSender $sender,
        private readonly Ap2Transactions $transactions,
        private readonly ApTime $time,
        private readonly string $mailbox,
        private readonly bool $sendingEnabled,
    ) {
    }

    /**
     * `GET /mail?tab&from&to`
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function list(array $params): array
    {
        $tab = is_string($params['tab'] ?? null) && $params['tab'] !== '' ? $params['tab'] : 'all';
        if (!in_array($tab, self::TABS, true)) {
            throw new Ap2Invalid('Unknown tab', Ap2Failure::details(['tab must be one of ' . implode(', ', self::TABS)]));
        }
        $input = new Ap2Input(['from' => $params['from'] ?? null, 'to' => $params['to'] ?? null]);
        $input->date('from');
        $input->date('to');
        if ($input->issues() !== []) {
            throw new Ap2Invalid('The dates are invalid', Ap2Failure::details($input->issues()));
        }
        $v = $input->values();
        [$start, $end] = $this->time->dayBounds(is_string($v['from'] ?? null) ? $v['from'] : null, is_string($v['to'] ?? null) ? $v['to'] : null);
        $status = ['progress' => 'In progress', 'done' => 'Done', 'hold' => 'On hold'][$tab] ?? null;

        $rows = array_values(array_filter($this->mail->all($start, $end), static fn (Ap2Mail $m): bool => $status === null || $m->status === $status));
        $files = $this->mail->attachmentsOfMany(array_map(static fn (Ap2Mail $m): string => $m->id, $rows));
        $statementOf = $this->statementsByMail();

        return array_map(fn (Ap2Mail $m): array => Ap2Presenter::mail($m, $files[$m->id] ?? [], $statementOf[$m->id] ?? null), $rows);
    }

    /**
     * `GET /mail/{id}`: the whole message with its body. Opening it marks it read (Tri-AP2's own flag, not the mailbox's).
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $mail = $this->found($id);
        if (!$mail->read) {
            $mail = $mail->with(read: true, updatedAt: $this->time->now());
            $this->mail->updateMail($mail);
        }

        return $this->present($mail, true);
    }

    /**
     * `PATCH /mail/{id}`
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(Ap2Actor $actor, string $id, array $body): array
    {
        $before = $this->found($id);
        $attachments = $this->mail->attachmentsOf($before->id);
        $input = new Ap2Input($body);
        $input->choice('category', Ap2Mail::CATEGORIES);
        $input->choice('status', Ap2Mail::STATUSES);
        $input->userId('ownerUserId');
        $input->text('draft', 20000);
        $input->flag('draftReviewed');
        $input->addresses('cc', 1000);
        $input->addresses('replyTo', 320);
        $issues = $input->issues();
        $values = $input->values();

        $supplierId = $before->supplierId;
        if (array_key_exists('supplierId', $body)) {
            $raw = $body['supplierId'];
            if ($raw === null || $raw === '') {
                $supplierId = null;
            } elseif (is_string($raw) && preg_match('/^[0-9a-f]{32}$/', $raw) === 1) {
                $supplierId = $raw;
            } else {
                $issues[] = 'supplierId must be an id or null';
            }
        }
        $draftAttachmentIds = null;
        if (array_key_exists('draftAttachmentIds', $body)) {
            $raw = $body['draftAttachmentIds'];
            $own = array_map(static fn (Ap2MailAttachment $a): string => $a->id, $attachments);
            if (!is_array($raw) || !array_is_list($raw)) {
                $issues[] = 'draftAttachmentIds must be a list of attachment ids';
            } else {
                $ids = [];
                foreach ($raw as $one) {
                    if (!is_string($one) || !in_array($one, $own, true)) {
                        $issues[] = 'draftAttachmentIds must be attachments of this email';
                        break;
                    }
                    if (!in_array($one, $ids, true)) {
                        $ids[] = $one;
                    }
                }
                $draftAttachmentIds = $ids;
            }
        }
        if ($issues !== []) {
            throw new Ap2Invalid('The email has invalid fields', Ap2Failure::details(array_values(array_unique($issues))));
        }

        $owner = $before->owner;
        if (array_key_exists('ownerUserId', $values)) {
            $userId = $values['ownerUserId'];
            $owner = null;
            if ($userId !== null) {
                $owner = is_int($userId) ? $this->people->find($userId) : null;
                if ($owner === null) {
                    throw new Ap2Invalid('The owner is not a Tri-AP2 user', Ap2Failure::details(['ownerUserId is not an active Tri-AP2 user']));
                }
            }
        }
        $supplier = null;
        if ($supplierId !== null) {
            $supplier = $this->suppliers->find($supplierId)
                ?? throw new Ap2Invalid('The supplier does not exist', Ap2Failure::details(['supplierId does not match a supplier']));
        }

        $supplierMoved = $supplierId !== $before->supplierId;
        $changed = $before->with(
            category: is_string($values['category'] ?? null) ? $values['category'] : $before->category,
            status: is_string($values['status'] ?? null) ? $values['status'] : $before->status,
            owner: $owner,
            supplierId: $supplierId,
            draft: is_string($values['draft'] ?? null) ? $values['draft'] : $before->draft,
            cc: is_string($values['cc'] ?? null) ? $values['cc'] : $before->cc,
            replyTo: is_string($values['replyTo'] ?? null) ? $values['replyTo'] : $before->replyTo,
        );
        if ($supplierMoved) {
            $changed = $changed->with(draftAttachmentIds: [], approvalId: null);
        }
        if ($draftAttachmentIds !== null) {
            $changed = $changed->with(draftAttachmentIds: $draftAttachmentIds);
        }

        $guarded = $changed->draft !== $before->draft
            || $changed->replyTo !== $before->replyTo
            || $changed->cc !== $before->cc
            || $changed->supplierId !== $before->supplierId
            || $changed->draftAttachmentIds !== $before->draftAttachmentIds;
        if ($before->sent && $guarded) {
            throw new Ap2Conflict('The email has been sent: its draft can no longer change', Ap2Failure::details(['The reply was sent; draft, To, CC, supplier and attachments are locked']));
        }
        $reviewed = is_bool($values['draftReviewed'] ?? null) ? $values['draftReviewed'] : $before->draftReviewed;
        if ($reviewed && trim($changed->draft) === '') {
            throw new Ap2Invalid('There is no draft to review', Ap2Failure::details(['draftReviewed needs a draft']));
        }
        $changed = $changed->with(draftReviewed: $guarded ? false : $reviewed);

        if ($changed == $before) {
            return $this->present($before, false);
        }
        $now = $this->time->now();
        $changed = $changed->with(updatedAt: $now);

        $this->transactions->run(function () use ($before, $changed, $actor, $now, $supplier, $attachments, $supplierMoved): void {
            $this->mail->updateMail($changed);
            if ($supplier === null || !$changed->recordHistory) {
                return;
            }
            if ($supplierMoved) {
                $this->note($actor, $now, $supplier->id, $changed->id, 'Email linked to supplier', $changed->subject);
            }
            if ($changed->category !== $before->category) {
                $this->note($actor, $now, $supplier->id, $changed->id, 'Email category changed', $changed->subject . ' · ' . $before->category . ' → ' . $changed->category);
            }
            if ($changed->status !== $before->status) {
                $this->note($actor, $now, $supplier->id, $changed->id, 'Email status changed', $changed->subject . ' · ' . $before->status . ' → ' . $changed->status);
            }
            if (($changed->owner->userId ?? null) !== ($before->owner->userId ?? null)) {
                $this->note($actor, $now, $supplier->id, $changed->id, 'Email owner changed', $changed->subject . ' · ' . ($changed->owner->name ?? 'no owner'));
            }
            foreach (array_diff($before->draftAttachmentIds, $changed->draftAttachmentIds) as $removed) {
                $name = '';
                foreach ($attachments as $a) {
                    if ($a->id === $removed) {
                        $name = $a->name;
                    }
                }
                if (!$supplierMoved) {
                    $this->note($actor, $now, $supplier->id, $changed->id, 'Draft attachment removed', $changed->subject . ' · ' . $name, ['attachmentId' => $removed]);
                }
            }
        });

        return $this->present($changed, false);
    }

    /**
     * `GET /mail/{id}/attachments/{aid}/file`
     */
    public function attachmentFile(string $id, string $attachmentId, ?int $viewerUserId): Ap2File
    {
        $this->found($id);
        $attachment = preg_match('/^[0-9a-f]{32}$/', $attachmentId) === 1 ? $this->mail->attachment($id, $attachmentId) : null;
        if ($attachment === null || $attachment->fileId === null) {
            throw new Ap2NotFound('Attachment not found');
        }

        return $this->files->read($attachment->fileId, $viewerUserId);
    }

    /**
     * `POST /mail/{id}/draft`: `{instruction?}` → `{draft}`. Nothing is saved.
     *
     * @param array<string, mixed> $body
     * @return array{draft: string}
     */
    public function draft(string $id, array $body): array
    {
        $mail = $this->found($id);
        $input = new Ap2Input($body);
        $input->text('instruction', 1000);
        if ($input->issues() !== []) {
            throw new Ap2Invalid('The instruction is invalid', Ap2Failure::details($input->issues()));
        }
        $instruction = $input->values()['instruction'] ?? null;
        $supplier = $mail->supplierId === null ? null : $this->suppliers->find($mail->supplierId);
        $invoice = $mail->invoiceId === null ? null : $this->invoices->get($mail->invoiceId);
        $issues = [];
        foreach (is_array($invoice['validation'] ?? null) ? $invoice['validation'] : [] as $issue) {
            if (is_string($issue)) {
                $issues[] = $issue;
            }
        }

        $text = $this->writer->draft(
            $this->context($mail),
            $supplier === null ? null : Ap2SupplierMatcher::ref($supplier),
            $invoice,
            $issues,
            is_string($instruction) && $instruction !== '' ? $instruction : null,
        );

        return ['draft' => $text];
    }

    /**
     * `POST /mail/{id}/send`
     *
     * @return array<string, mixed> the mail as `GET /mail/{id}` shows it, plus `threaded`
     */
    public function send(Ap2Actor $actor, string $id): array
    {
        $mail = $this->found($id);
        $issues = [];
        if (!$this->sendingEnabled) {
            throw new Ap2Conflict('Sending is switched off', Ap2Failure::details(['Email sending is not enabled on this host (FINANCE_AP2_SENDING_ENABLED)']));
        }
        if ($mail->sent) {
            throw new Ap2Conflict('The email has already been sent', Ap2Failure::details(['This reply was already sent']));
        }
        if (!$mail->draftReviewed) {
            $issues[] = 'The draft must be reviewed before it is sent';
        }
        if (trim($mail->draft) === '') {
            $issues[] = 'There is no draft to send';
        }
        $to = self::recipients($mail->replyTo, 'To', $issues, true);
        $cc = self::recipients($mail->cc, 'CC', $issues, false);
        $issues = array_merge($issues, $this->approvalIssues($mail));
        if ($issues !== []) {
            throw new Ap2Conflict('The email cannot be sent yet', Ap2Failure::details($issues));
        }

        $attachments = [];
        $names = $this->mail->attachmentsOf($mail->id);
        foreach ($mail->draftAttachmentIds as $attachmentId) {
            foreach ($names as $a) {
                if ($a->id === $attachmentId && $a->fileId !== null) {
                    $file = $this->files->read($a->fileId, $actor->userId);
                    $attachments[] = new FinanceMailAttachment($a->name, $file->bytes, $a->contentType !== '' ? $a->contentType : 'application/octet-stream');
                }
            }
        }
        if (count($attachments) !== count($mail->draftAttachmentIds)) {
            throw new Ap2Conflict('The email cannot be sent yet', Ap2Failure::details(['A chosen attachment is no longer available']));
        }

        $subject = preg_match('/^\s*re:/i', $mail->subject) === 1 ? $mail->subject : 'Re: ' . $mail->subject;
        $key = 'ap2-reply:' . $mail->id . ':' . substr(hash('sha256', json_encode([$mail->draft, $to, $cc, $mail->draftAttachmentIds, $subject], JSON_THROW_ON_ERROR)), 0, 24);
        $result = $this->deliver(fn (string $k): FinanceMailMessage => new FinanceMailMessage(
            Module::APP_AP2,
            $this->mailbox,
            $to,
            $subject,
            self::html($mail->draft),
            $cc,
            [],
            FinanceMailRules::CONTENT_HTML,
            $attachments,
            $k,
            $actor->userId,
            $mail->graphMessageId,
        ), $key);
        if ($result->delivery->isSuppressed()) {
            throw new Ap2Conflict('Nothing was sent', Ap2Failure::details(['Mail sending is switched off on this host (FINANCE_MAIL_ENABLED), so the reply was not sent']));
        }

        $now = $this->time->now();
        $sent = $mail->with(sent: true, sentAt: $now, status: 'Done', updatedAt: $now);
        $this->transactions->run(function () use ($sent, $actor, $now, $result, $to, $cc, $subject): void {
            $this->mail->updateMail($sent);
            if ($sent->supplierId !== null) {
                $this->history->append(new Ap2HistoryEntry(
                    bin2hex(random_bytes(16)),
                    $sent->supplierId,
                    'Email sent',
                    'Reply to ' . implode(', ', $to) . ' · ' . $subject,
                    $actor->person(),
                    $now,
                    null,
                    $sent->id,
                    ['to' => $to, 'cc' => $cc, 'subject' => $subject, 'deliveryId' => $result->delivery->id, 'threaded' => $result->threaded, 'deduplicated' => $result->deduplicated],
                ));
            }
        });

        return $this->present($sent, true) + ['threaded' => $result->threaded];
    }

    /**
     * `POST /mail/{id}/create-invoice`: an invoice from the first PDF or image attachment, linked both ways.
     *
     * @return array<string, mixed> the invoice
     */
    public function createInvoice(Ap2Actor $actor, string $id): array
    {
        $mail = $this->found($id);
        if ($mail->invoiceId !== null) {
            throw new Ap2Conflict('An invoice was already created from this email', Ap2Failure::details(['Invoice ' . $mail->invoiceId . ' already exists for this email']));
        }
        $supplier = $mail->supplierId === null ? null : $this->suppliers->find($mail->supplierId);
        if ($supplier === null) {
            throw new Ap2Invalid('The email has no supplier', Ap2Failure::details(['Link the email to a supplier before creating an invoice']));
        }
        $document = null;
        foreach ($this->mail->attachmentsOf($mail->id) as $a) {
            if ($a->fileId !== null && self::isDocument($a)) {
                $document = $a;
                break;
            }
        }
        if ($document === null || $document->fileId === null) {
            throw new Ap2Conflict('The email has no invoice document', Ap2Failure::details(['The email has no PDF or image attachment to read']));
        }
        $file = $this->files->read($document->fileId, $actor->userId);
        try {
            $read = $this->extractor->extract($file->bytes, $document->contentType, $document->name, $supplier->name);
        } catch (Ap2AiUnavailable) {
            $read = Ap2ExtractedInvoice::none();
        }

        $invoice = $this->transactions->run(function () use ($actor, $supplier, $read, $mail, $document, $file): Ap2Invoice {
            $invoice = $this->invoices->createFromSource($actor, $supplier, self::fields($read), $mail->id, $document->fileId, $document->name, $file->sha256, $read->overall > 0.0 ? max(0.0, min(1.0, $read->overall)) : null);
            $this->mail->updateMail($mail->with(invoiceId: $invoice->id, updatedAt: $this->time->now()));

            return $invoice;
        });

        return $this->invoices->get($invoice->id);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function note(Ap2Actor $actor, DateTimeImmutable $at, string $supplierId, string $mailId, string $type, string $detail, array $extra = []): void
    {
        $this->history->append(new Ap2HistoryEntry(bin2hex(random_bytes(16)), $supplierId, $type, $detail, $actor->person(), $at, null, $mailId, $extra));
    }

    private function found(string $id): Ap2Mail
    {
        $mail = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->mail->findMail($id) : null;
        if ($mail === null) {
            throw new Ap2NotFound('Email not found');
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    private function present(Ap2Mail $mail, bool $withBody): array
    {
        return Ap2Presenter::mail($mail, $this->mail->attachmentsOf($mail->id), $this->statementsByMail()[$mail->id] ?? null, $withBody);
    }

    /** @return array<string, string> mail id => statement id */
    private function statementsByMail(): array
    {
        $out = [];
        foreach ($this->statements->all() as $s) {
            if ($s->mailId !== null && !isset($out[$s->mailId])) {
                $out[$s->mailId] = $s->id;
            }
        }

        return $out;
    }

    private function context(Ap2Mail $mail): Ap2MailContext
    {
        return new Ap2MailContext(
            $mail->id,
            $mail->subject,
            $mail->from,
            $mail->body,
            $mail->category,
            Ap2Presenter::time($mail->receivedAt),
            array_map(static fn (Ap2MailAttachment $a): string => $a->name, $this->mail->attachmentsOf($mail->id)),
        );
    }

    /** @return list<string> why the mail's Email approval does not allow a send (none when it has no approval) */
    private function approvalIssues(Ap2Mail $mail): array
    {
        if ($mail->approvalId === null) {
            return [];
        }
        $approval = $this->approvals->get($mail->approvalId);
        if ($approval === null) {
            return [];
        }
        $status = $approval->effectiveStatus($this->time->now());
        if ($status !== 'Approved') {
            return ['The email approval is ' . $status . '; it must be Approved before the reply is sent'];
        }

        return $this->snapshots->isCurrent($approval) ? [] : ['Content changed. Request fresh approval.'];
    }

    /**
     * Sends under the key; a key whose earlier send failed is retried under a new one (the ledger's rule), a send still
     * pending is refused.
     *
     * @param callable(string): FinanceMailMessage $message
     */
    private function deliver(callable $message, string $key): FinanceMailResult
    {
        try {
            try {
                return $this->sender->send($message($key));
            } catch (FinanceMailConflict $earlier) {
                if ($earlier->delivery->status === FinanceMailRules::STATUS_PENDING) {
                    throw new Ap2Conflict('A send for this email is already in progress', Ap2Failure::details(['Wait a moment and open the email again to see whether it went']), $earlier);
                }

                return $this->sender->send($message($key . ':retry-' . bin2hex(random_bytes(4))));
            }
        } catch (FinanceMailRefused $refused) {
            throw new Ap2Conflict('Tri-AP2 may not send from ' . $this->mailbox, Ap2Failure::details([$refused->getMessage()]), $refused);
        } catch (FinanceMailInvalid $invalid) {
            throw new Ap2Invalid($invalid->getMessage(), Ap2Failure::details([$invalid->getMessage()]), $invalid);
        } catch (FinanceMailTransportFailed $failed) {
            throw new Ap2Upstream('The email could not be sent', Ap2Failure::details(['The mail service failed; nothing was marked sent. Try again.']), $failed);
        }
    }

    /**
     * @param list<string> $issues
     * @return list<string>
     */
    private static function recipients(string $list, string $label, array &$issues, bool $required): array
    {
        $out = [];
        $before = count($issues);
        foreach (preg_split('/[;,]/', $list) ?: [] as $one) {
            if (trim($one) === '') {
                continue;
            }
            $address = Ap2SupplierMatcher::address($one);
            if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $issues[] = $label . ' has an invalid email address: ' . trim($one);
            } elseif (!in_array($address, $out, true)) {
                $out[] = $address;
            }
        }
        if ($required && $out === [] && count($issues) === $before) {
            $issues[] = $label . ' needs an email address';
        }

        return $out;
    }

    private static function html(string $text): string
    {
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;white-space:normal">' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</div>';
    }

    private static function isDocument(Ap2MailAttachment $a): bool
    {
        $type = strtolower($a->contentType);

        return $type === 'application/pdf'
            || str_starts_with($type, 'image/')
            || preg_match('/\.(pdf|png|jpe?g|tiff?|webp)$/i', $a->name) === 1;
    }

    /**
     * What the extraction found, as invoice properties (only what it found, and only values that are valid).
     *
     * @return array<string, mixed>
     */
    private static function fields(Ap2ExtractedInvoice $r): array
    {
        $out = [];
        foreach (['number' => 100, 'po' => 100, 'creditRef' => 100, 'entity' => 200] as $key => $max) {
            $v = $r->{$key};
            if (is_string($v) && trim($v) !== '') {
                $out[$key] = mb_substr(trim($v), 0, $max);
            }
        }
        foreach (['issued', 'due'] as $key) {
            $v = $r->{$key};
            $d = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
            if ($d !== false && $d->format('Y-m-d') === $v) {
                $out[$key] = $v;
            }
        }
        if (is_string($r->currency) && in_array($r->currency, Ap2Invoice::CURRENCIES, true)) {
            $out['currency'] = $r->currency;
        }
        foreach (['net', 'tax', 'total', 'credit', 'creditNet', 'creditTax'] as $key) {
            $v = $r->{$key};
            if (is_float($v) && is_finite($v) && $v >= 0 && $v <= 9999999999999.0) {
                $out[$key] = round($v, 2);
            }
        }
        foreach (['quantity', 'poUnitPrice'] as $key) {
            $v = $r->{$key};
            if (is_float($v) && is_finite($v) && $v >= 0 && $v <= 9999999999999.0) {
                $out[$key] = round($v, 4);
            }
        }
        $out['kind'] = isset($out['po']) ? 'Goods PO' : 'No PO';

        return $out;
    }
}
