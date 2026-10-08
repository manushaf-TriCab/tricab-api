<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use Closure;
use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArCreditNote;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArUnavailable;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Mail\FinanceMailException;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;

/**
 * Credit-note requests (T5.10, source routes 23 to 26): a request recorded against a register item, the link mailed to
 * the sales rep, the list, the edit, and sending the link again. The rep's side is {@see ArPublicFormService}.
 *
 * The token is made here, its SHA-256 is all that is stored (question Q6), and the link leaves only in the answer to
 * create or resend and in the mail. A mail that did not go is reported (question Q13), never swallowed: the request
 * exists either way, and the answer says `mail_sent: false` with the reason so the page can offer "send again".
 */
final class ArCreditNoteService
{
    public const NOT_FOUND = 'Credit note request not found';
    public const MAIL_OFF = 'Mail is switched off';
    public const MAX_LIMIT = 1000;
    public const DEFAULT_LIMIT = 100;

    /** What a PATCH may change besides the status. */
    private const EDITABLE_TEXT = ['customer_name', 'customer_email', 'sales_rep_name', 'sales_rep_email', 'invoice_number', 'reason', 'original_request'];

    /** @var Closure(): string */
    private readonly Closure $newId;

    /** @var Closure(): string */
    private readonly Closure $newToken;

    /**
     * @param (callable(): string)|null $newId new 32-hex ids
     * @param (callable(): string)|null $newToken new URL-safe tokens
     */
    public function __construct(
        private readonly ArCreditNoteStore $notes,
        private readonly ArRecordStore $records,
        private readonly FinanceMailSender $mail,
        private readonly FinanceAccessScopeReader $scopes,
        private readonly ArTime $time,
        private readonly string $mailbox,
        private readonly string $publicOrigin,
        private readonly int $linkHours,
        private readonly bool $sendingEnabled,
        ?callable $newId = null,
        ?callable $newToken = null,
    ) {
        $this->newId = Closure::fromCallable($newId ?? static fn (): string => bin2hex(random_bytes(16)));
        $this->newToken = Closure::fromCallable($newToken ?? static fn (): string => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    /**
     * `POST /credit-notes`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> `{success, credit_note_request, form_url, mail_sent, mail_error}`
     */
    public function create(ArActor $actor, array $data): array
    {
        $this->requireSending();
        $customer = self::text($data['customer_name'] ?? null, 400);
        $repEmail = strtolower(self::text($data['sales_rep_email'] ?? null, 320));
        if ($customer === '' || $repEmail === '') {
            throw new ArInvalid('customer_name and sales_rep_email are required');
        }
        if (!ArMailText::isAddress($repEmail)) {
            throw new ArInvalid('Invalid email address: ' . $repEmail);
        }
        $recordId = self::text($data['email_record_id'] ?? null, 32);
        $record = null;
        if ($recordId !== '') {
            $record = $this->records->find($recordId) ?? throw new ArNotFound(ArMailService::RECORD_NOT_FOUND);
        }
        $now = $this->time->now();
        $token = ($this->newToken)();
        $note = new ArCreditNote(
            ($this->newId)(),
            $record?->id,
            $customer,
            self::text($data['customer_email'] ?? null, 320),
            self::text($data['sales_rep_name'] ?? null, 400),
            $repEmail,
            self::text($data['invoice_number'] ?? null, 100),
            self::amount($data['credit_amount'] ?? null),
            self::text($data['reason'] ?? null, 20000),
            self::text($data['original_request'] ?? null, 20000),
            hash('sha256', $token),
            $now->modify('+' . $this->linkHours . ' hours'),
            ArCreditNote::FORM_SENT,
            $now,
            null,
            null,
            $now,
            $now,
        );
        $this->records->transactional(function () use ($note, $record, $repEmail, $now): void {
            $this->notes->insertCreditNote($note);
            if ($record !== null) {
                // The source's: the register item moves on whether or not the mail went.
                $this->records->update($record->with(
                    status: 'reviewed',
                    assignedSalesRepEmail: $repEmail,
                    creditNoteRequestId: $note->id,
                    followUpStatus: ArRecord::STATUS_IN_PROGRESS,
                    updatedAt: $now,
                ));
            }
        });

        return $this->issued($actor, $note, $token);
    }

    /**
     * `POST /credit-notes/{id}/resend`: a new token and expiry (the old link stops working at once) and the mail again.
     *
     * @return array<string, mixed>
     */
    public function resend(ArActor $actor, string $id): array
    {
        $this->requireSending();
        $note = $this->notes->findCreditNote($id) ?? throw new ArNotFound(self::NOT_FOUND);
        $token = ($this->newToken)();
        $now = $this->time->now();
        $expires = $now->modify('+' . $this->linkHours . ' hours');
        if (!$note->isOpen() || !$this->notes->replaceCreditNoteToken($id, hash('sha256', $token), $expires, $now)) {
            throw new ArConflict('This request has already been submitted');
        }
        $fresh = $this->notes->findCreditNote($id) ?? throw new ArNotFound(self::NOT_FOUND);

        return $this->issued($actor, $fresh, $token);
    }

    /**
     * `GET /credit-notes`.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $status = self::queryText($query['status'] ?? null);
        if ($status !== null && !in_array($status, ArCreditNote::STATUSES, true)) {
            throw new ArInvalid('Invalid status: ' . $status);
        }
        $limit = self::wholeNumber($query['limit'] ?? null, self::DEFAULT_LIMIT, 1, 'limit');
        $offset = self::wholeNumber($query['offset'] ?? null, 0, 0, 'offset');
        $limit = min($limit, self::MAX_LIMIT);
        [$rows, $total] = $this->notes->pageCreditNotes($status, self::queryText($query['customer_name'] ?? null), self::queryText($query['sales_rep_email'] ?? null), $limit, $offset);

        return ['records' => array_map(self::view(...), $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return self::view($this->notes->findCreditNote($id) ?? throw new ArNotFound(self::NOT_FOUND));
    }

    /**
     * `PATCH /credit-notes/{id}`: the status and the editable fields only.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(string $id, array $body): array
    {
        $note = $this->notes->findCreditNote($id) ?? throw new ArNotFound(self::NOT_FOUND);
        if (array_key_exists('status', $body) && $body['status'] !== null && $body['status'] !== '') {
            $status = is_string($body['status']) ? $body['status'] : '';
            if (!in_array($status, ArCreditNote::STATUSES, true)) {
                throw new ArInvalid('Invalid status: ' . (is_scalar($body['status']) ? (string) $body['status'] : '?'));
            }
        } else {
            $status = null;
        }
        $changes = [];
        $limits = ['customer_name' => 400, 'customer_email' => 320, 'sales_rep_name' => 400, 'sales_rep_email' => 320, 'invoice_number' => 100, 'reason' => 20000, 'original_request' => 20000];
        $props = ['customer_name' => 'customerName', 'customer_email' => 'customerEmail', 'sales_rep_name' => 'salesRepName', 'sales_rep_email' => 'salesRepEmail', 'invoice_number' => 'invoiceNumber', 'reason' => 'reason', 'original_request' => 'originalRequest'];
        foreach (self::EDITABLE_TEXT as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = self::text($body[$field], $limits[$field]);
            if ($field === 'customer_name' && $value === '') {
                throw new ArInvalid('customer_name is required');
            }
            if ($field === 'sales_rep_email') {
                $value = strtolower($value);
                if ($value === '' || !ArMailText::isAddress($value)) {
                    throw new ArInvalid('Invalid email address: ' . $value);
                }
            }
            $changes[$props[$field]] = $value;
        }
        if (array_key_exists('credit_amount', $body)) {
            $changes['creditAmount'] = self::amount($body['credit_amount']);
        }
        if ($status !== null && $status !== $note->status) {
            $changes['status'] = $status;
            if ($note->reviewedAt === null && in_array($status, ArCreditNote::REVIEWED, true)) {
                $changes['reviewedAt'] = $this->time->now();
            }
        }
        if ($changes === []) {
            return self::view($note);
        }
        $changed = $note->with(...$changes, updatedAt: $this->time->now());
        $this->notes->updateCreditNote($changed);

        return self::view($changed);
    }

    // ---- helpers -------------------------------------------------------------------------

    private function requireSending(): void
    {
        if (!$this->sendingEnabled) {
            throw new ArUnavailable(ArMailService::SENDING_OFF);
        }
        if (trim($this->publicOrigin) === '') {
            throw new ArUnavailable('The public web address for credit note forms is not set (FINANCE_AR_PUBLIC_ORIGIN)');
        }
    }

    /**
     * The answer to create and resend: the request, the link once, and whether the mail went.
     *
     * @return array<string, mixed>
     */
    private function issued(ArActor $actor, ArCreditNote $note, string $token): array
    {
        $url = rtrim($this->publicOrigin, '/') . '/finance/ar/credit-note-form?id=' . rawurlencode($note->id) . '&token=' . rawurlencode($token);
        $error = $this->mailLink($actor, $note, $url);

        return [
            'success' => true,
            'credit_note_request' => self::view($note),
            'form_url' => $url,
            'mail_sent' => $error === null,
            'mail_error' => $error,
        ];
    }

    /** Mails the link; null when it went, otherwise why it did not. */
    private function mailLink(ArActor $actor, ArCreditNote $note, string $url): ?string
    {
        $from = strtolower(trim($this->mailbox));
        if (!$this->scopes->forUser($actor->userId, Module::APP_AR)->allowsMailbox($from)) {
            return 'Tri-AR is not allowed to send from ' . $from . '. Ask a finance administrator to grant it that mailbox.';
        }
        try {
            $result = $this->mail->send(new FinanceMailMessage(
                Module::APP_AR,
                $from,
                [$note->salesRepEmail],
                ArCreditNoteMail::linkSubject($note),
                ArCreditNoteMail::linkBody($note, $url, $this->linkHours),
                [],
                [],
                FinanceMailRules::CONTENT_HTML,
                [],
                'ar-credit-note-link:' . substr($note->tokenHash, 0, 16),
                $actor->userId,
            ));
        } catch (FinanceMailException $failure) {
            return mb_substr($failure->getMessage(), 0, 300);
        }

        return $result->delivery->isSuppressed() ? self::MAIL_OFF : null;
    }

    /**
     * A request as the page reads it: the source's columns, with the token gone.
     *
     * @return array<string, mixed>
     */
    public static function view(ArCreditNote $n): array
    {
        return [
            'id' => $n->id,
            'email_record_id' => $n->emailRecordId ?? '',
            'customer_name' => $n->customerName,
            'customer_email' => $n->customerEmail,
            'sales_rep_name' => $n->salesRepName,
            'sales_rep_email' => $n->salesRepEmail,
            'invoice_number' => $n->invoiceNumber,
            'credit_amount' => $n->creditAmount,
            'reason' => $n->reason,
            'original_request' => $n->originalRequest,
            'token_expires_at' => ArPresenter::iso($n->tokenExpiresAt),
            'status' => $n->status,
            'form_sent_date' => ArPresenter::iso($n->formSentDate) ?? '',
            'submitted_date' => ArPresenter::iso($n->submittedDate) ?? '',
            'reviewed_at' => ArPresenter::iso($n->reviewedAt) ?? '',
            'created_date' => ArPresenter::iso($n->createdAt),
            'updated_date' => ArPresenter::iso($n->updatedAt),
        ];
    }

    /** A trimmed string of at most `$max` characters; anything that is not a scalar is empty. */
    public static function text(mixed $value, int $max): string
    {
        $text = is_scalar($value) && $value !== false ? trim((string) $value) : '';

        return mb_substr($text, 0, $max);
    }

    /**
     * A credit amount: a number of at least 0, to the cent; empty is 0 (the source's `float(x or 0.0)`).
     *
     * @throws ArInvalid
     */
    public static function amount(mixed $value): float
    {
        if ($value === null || $value === '' || $value === false) {
            return 0.0;
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
            throw new ArInvalid('credit_amount must be a number');
        }
        $amount = (float) (is_string($value) ? trim($value) : $value);
        if (!is_finite($amount) || $amount < 0 || $amount > 1.0E12) {
            throw new ArInvalid('credit_amount must be a number');
        }

        return round($amount, 2);
    }

    private static function queryText(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    private static function wholeNumber(mixed $value, int $default, int $min, string $name): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) && !is_int($value) || !preg_match('/^-?\d{1,9}$/', (string) $value)) {
            throw new ArInvalid($name . ' must be a whole number');
        }
        $n = (int) $value;
        if ($n < $min) {
            throw new ArInvalid($name . ' must be at least ' . $min);
        }

        return $n;
    }
}
