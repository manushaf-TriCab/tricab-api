<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use Closure;
use Throwable;
use TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore;
use TriCab\Core\Finance\Ar\Contract\ArRateLimiter;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArCreditNote;
use TriCab\Core\Finance\Ar\Domain\ArGone;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArRateLimited;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;

/**
 * The sales reps' credit-note form (T5.10, source routes 27 and 28): the plan's one route family with no sign-in
 * (question Q7). What stands in for sign-in is the token (compared by hash, in constant time), its expiry, single
 * use, and a limit on attempts per address and per form.
 *
 * An unknown form and a wrong token are the same answer, so a caller learns nothing about which ids exist; an expired
 * link and a used one say so (H2), because those are the two cases the rep can do something about.
 */
final class ArPublicFormService
{
    public const INVALID_LINK = 'Invalid form link';
    public const ALREADY_SUBMITTED = 'This form has already been submitted';
    public const EXPIRED = 'This credit note form link has expired';
    public const MISSING = 'Missing form ID or security token';
    public const TOO_MANY = 'Too many attempts. Try again later.';

    /** Attempts in the window: generous per address (a shared front end can look like one address), tight per form. */
    public const IP_LIMIT = 60;
    public const FORM_LIMIT = 15;
    public const WINDOW_SECONDS = 900;

    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param (callable(string): void)|null $log written to `error_log` unless a test passes its own
     */
    public function __construct(
        private readonly ArCreditNoteStore $notes,
        private readonly ArRecordStore $records,
        private readonly ArRateLimiter $limiter,
        private readonly FinanceMailSender $mail,
        private readonly ArTime $time,
        private readonly string $mailbox,
        private readonly bool $sendingEnabled,
        ?callable $log = null,
    ) {
        $this->log = Closure::fromCallable($log ?? static function (string $line): void {
            error_log($line);
        });
    }

    /**
     * `GET /public/credit-note-form`.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function show(?string $clientIp, array $query): array
    {
        $note = $this->open($clientIp, $query['id'] ?? null, $query['token'] ?? null);

        return [
            'id' => $note->id,
            'customer_name' => $note->customerName,
            'customer_email' => $note->customerEmail,
            'sales_rep_name' => $note->salesRepName,
            'original_request' => $note->originalRequest,
            'invoice_number' => $note->invoiceNumber,
            'credit_amount' => $note->creditAmount,
            'reason' => $note->reason,
            'status' => $note->status,
        ];
    }

    /**
     * `POST /public/credit-note-form`: single use.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function submit(?string $clientIp, array $body): array
    {
        $note = $this->open($clientIp, $body['id'] ?? null, $body['token'] ?? null);
        if (!$note->isOpen()) {
            throw new ArConflict(self::ALREADY_SUBMITTED);
        }
        $invoice = ArCreditNoteService::text($body['invoice_number'] ?? null, 100);
        $reason = ArCreditNoteService::text($body['reason'] ?? null, 5000);
        $name = ArCreditNoteService::text($body['sales_rep_name'] ?? null, 400);
        $amount = ArCreditNoteService::amount($body['credit_amount'] ?? null);
        $name = $name !== '' ? $name : $note->salesRepName;
        $now = $this->time->now();

        // One conditional update: of two simultaneous submits only one finds the request still open.
        $won = $this->notes->submitCreditNote($note->id, $invoice, $amount, $reason, $name, $now);
        if (!$won) {
            throw new ArConflict(self::ALREADY_SUBMITTED);
        }
        $submitted = $note->with(invoiceNumber: $invoice, creditAmount: $amount, reason: $reason, salesRepName: $name, status: ArCreditNote::SUBMITTED, submittedDate: $now, updatedAt: $now);

        if ($note->emailRecordId !== null) {
            $record = $this->records->find($note->emailRecordId);
            if ($record !== null) {
                $who = $name !== '' ? $name : $note->salesRepEmail;
                $this->records->update($record->with(
                    followUpStatus: ArRecord::STATUS_IN_PROGRESS,
                    finalOutcome: sprintf('Credit note form submitted by %s for invoice %s ($%.2f)', $who, $invoice, $amount),
                    updatedAt: $now,
                ));
            }
        }
        $this->notifyTeam($submitted);

        return ['success' => true, 'record' => [
            'id' => $submitted->id,
            'status' => $submitted->status,
            'invoice_number' => $invoice,
            'credit_amount' => $amount,
            'reason' => $reason,
            'sales_rep_name' => $name,
            'submitted_date' => ArPresenter::iso($now),
        ]];
    }

    /**
     * The shared checks of both routes: the limit, the link, the token, the expiry.
     */
    private function open(?string $clientIp, mixed $rawId, mixed $rawToken): ArCreditNote
    {
        $id = is_string($rawId) ? trim($rawId) : '';
        $token = is_string($rawToken) ? trim($rawToken) : '';
        $this->limit($clientIp, $id);
        if ($id === '' || $token === '') {
            throw new ArInvalid(self::MISSING);
        }
        $note = mb_strlen($id) <= 64 ? $this->notes->findCreditNote($id) : null;
        // The same answer for an unknown form and a wrong token (decision D59); a long token is not hashed at all.
        if ($note === null || strlen($token) > 200 || !hash_equals($note->tokenHash, hash('sha256', $token))) {
            throw new ArNotFound(self::INVALID_LINK);
        }
        if ($this->time->now() > $note->tokenExpiresAt) {
            throw new ArGone(self::EXPIRED);
        }

        return $note;
    }

    private function limit(?string $clientIp, string $formId): void
    {
        $ip = ($clientIp === null || trim($clientIp) === '') ? 'unknown' : trim($clientIp);
        $allowed = $this->limiter->allow('ar-form-ip:' . $ip, self::IP_LIMIT, self::WINDOW_SECONDS);
        if ($formId !== '') {
            // Always counted, so one form cannot be hammered from many addresses; the id is hashed, never stored as sent.
            $allowed = $this->limiter->allow('ar-form-id:' . substr(hash('sha256', $formId), 0, 24), self::FORM_LIMIT, self::WINDOW_SECONDS) && $allowed;
        }
        if (!$allowed) {
            throw new ArRateLimited(self::TOO_MANY);
        }
    }

    /** Tells the team a form came in: to the AR mailbox itself, best effort, and only while sending is on (decision D60). */
    private function notifyTeam(ArCreditNote $note): void
    {
        if (!$this->sendingEnabled) {
            return;
        }
        $mailbox = strtolower(trim($this->mailbox));
        try {
            $this->mail->send(new FinanceMailMessage(
                Module::APP_AR,
                $mailbox,
                [$mailbox],
                ArCreditNoteMail::submittedSubject($note),
                ArCreditNoteMail::submittedBody($note),
                [],
                [],
                FinanceMailRules::CONTENT_HTML,
                [],
                'ar-credit-note-submitted:' . $note->id,
                null,
            ));
        } catch (Throwable $failure) {
            // Never the rep's problem: their form is in. Class only, no content.
            ($this->log)('Tri-AR credit note team notice not sent (' . $failure::class . ')');
        }
    }
}
