<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApEmailArrival;
use TriCab\Core\Finance\Ap\Domain\ApEmailAttachment;
use TriCab\Core\Finance\Ap\Domain\ApEmailQuery;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;

/**
 * The AP Mailbox's server side (`app.py` routes 33, 34, 38 and 39, `store.py` emails), without HTTP or SQL (T6.6): the list
 * with each email's invoices, the counts, a reclassification and the three bulk actions. The live body and attachments are
 * {@see ApMessageService}'s.
 *
 *  - The period is the exact local days in the Tri-AP zone ({@see ApTime::dayBounds()}); one date alone is that day, both
 *    missing is no bound. The scope, the period and the page are applied in the query; the order is
 *    `received_at DESC, id DESC`, so paging neither repeats nor skips a row.
 *  - `invoice_ids` and `duplicate_invoice_ids` are built for the whole page at once (D75): one page query, the invoices'
 *    links in at most two chunks of 500 emails, the duplicates in one, however many rows there are.
 *  - A bad `limit` or `offset` is a 422 (R1), never "no limit". Every out-of-scope email is a 404 (Q11, R11).
 *  - None of the actions touches an invoice: not its status, its Bin state or its links.
 *
 * For the scan (T6.7) and the mail tasks (T6.10) it also offers {@see register()}, {@see applyScanResult()},
 * {@see recordAttachment()}, {@see linkAttachment()}, {@see findByMessage()} and {@see get()}, which take no HTTP.
 */
final class ApEmailService
{
    public const NOT_FOUND = 'Not found';
    public const BAD_DATE = 'Dates must use YYYY-MM-DD';
    public const FROM_AFTER_TO = 'From date cannot be after to date';
    public const BAD_CLASSIFICATION = 'classification must be Invoice or Other';
    public const BAD_ACTION = 'action must be classify, bin or restore';
    public const BAD_IDS = 'ids must be a non-empty list of email ids';
    public const TOO_MANY = 'At most 1000 emails at a time';
    public const BAD_REASON = 'reason must be text';
    public const LONG_REASON = 'reason must be at most 1000 characters';

    public const BULK_LIMIT = 1000;

    public const RECLASSIFIED = 'email_reclassified';
    public const BINNED = 'email_binned';
    public const RESTORED = 'email_restored';

    public function __construct(
        private readonly ApEmailStore $emails,
        private readonly ApInvoiceStore $invoices,
        private readonly ApAuditStore $audit,
        private readonly ApTime $time,
    ) {
    }

    // ---- the routes ----------------------------------------------------------------------

    /**
     * `GET /emails`. Query: `fromDate`, `toDate` (`yyyy-MM-dd`), `classification` (`Invoice` or `Other`; an empty value is an
     * error), `deleted` (`1` or `true` for the Bin), `limit` (default 500, at most 1,000) and `offset`.
     *
     * @param array<string, mixed> $params the query string
     * @return list<array<string, mixed>>
     */
    public function list(ApActor $actor, array $params): array
    {
        [$start, $end] = $this->period($params);
        $classification = $params['classification'] ?? null;
        if ($classification !== null && !in_array($classification, ApEmail::SETTABLE_CLASSIFICATIONS, true)) {
            throw new ApInvalid(self::BAD_CLASSIFICATION);
        }
        $limit = self::limit($params['limit'] ?? null);
        $offset = self::offset($params['offset'] ?? null);

        $rows = $this->emails->page(new ApEmailQuery(
            self::flag($params['deleted'] ?? null),
            is_string($classification) ? $classification : null,
            $start,
            $end,
            $actor->mailboxScope->mailboxes(),
            $limit,
            $offset,
        ));
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (ApEmail $row): string => $row->id, $rows);
        $own = $this->invoices->invoiceIdsByEmail($ids);
        $resent = $this->emails->duplicateInvoiceIdsByEmail($ids);

        $out = [];
        foreach ($rows as $row) {
            $mine = $own[$row->id] ?? [];
            $duplicates = array_values(array_diff(array_values(array_unique($resent[$row->id] ?? [])), $mine));
            $out[] = ApEmailPresenter::email($row, [...$mine, ...$duplicates], $duplicates);
        }

        return $out;
    }

    /**
     * `GET /emails/stats`: the live emails of the period and scope by classification. `errors` is always 0 in practice.
     *
     * @param array<string, mixed> $params the query string
     * @return array{total: int, invoice: int, other: int, errors: int}
     */
    public function stats(ApActor $actor, array $params): array
    {
        [$start, $end] = $this->period($params);

        return $this->emails->counts($start, $end, $actor->mailboxScope->mailboxes());
    }

    /**
     * `PATCH /emails/{id}`: a person's reclassification, on a live or binned email. Sets the classification, the reason (or
     * `Manually classified`) and `review_status` `Verified`; the confidence, the linked invoices and the Bin are untouched.
     * Writes `email_reclassified` `{from, to, reason}`. Answers the updated row (no `invoice_ids`).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function reclassify(ApActor $actor, string $id, array $body): array
    {
        $email = $this->get($actor, $id);
        $classification = $body['classification'] ?? null;
        if (!in_array($classification, ApEmail::SETTABLE_CLASSIFICATIONS, true)) {
            throw new ApInvalid(self::BAD_CLASSIFICATION);
        }
        $reason = self::reason($body['reason'] ?? null);
        $now = $this->time->now();

        $this->emails->transactional(function () use ($actor, $email, $classification, $reason, $now): void {
            $this->emails->update($email->id, self::reclassification($classification, $reason), $now);
            $this->record($actor, self::RECLASSIFIED, $email->id, ['from' => $email->classification, 'to' => $classification, 'reason' => $reason], $now);
        });

        return ApEmailPresenter::email($this->stored($email->id));
    }

    /**
     * `POST /emails/bulk`: `classify`, `bin` or `restore` over up to 1,000 ids, in one transaction. Validation, in order:
     * `action`, `ids` (a non-empty list of text), the count (before de-duplication), `classification` (classify only).
     *
     * Ids are de-duplicated keeping the first; an email that is missing or out of scope is skipped and counted, never an
     * error. `classify` always applies and writes `email_reclassified` with `bulk: true`. `bin` and `restore` on an email
     * already in that state count in `updated` and change and audit nothing. No invoice is touched.
     *
     * @param array<string, mixed> $body
     * @return array{updated: int, skipped: int, emails: list<array<string, mixed>>}
     */
    public function bulk(ApActor $actor, array $body): array
    {
        $action = $body['action'] ?? null;
        $ids = $body['ids'] ?? null;
        if (!is_string($action) || !in_array($action, ['classify', 'bin', 'restore'], true)) {
            throw new ApInvalid(self::BAD_ACTION);
        }
        if (!is_array($ids) || $ids === [] || !array_is_list($ids)) {
            throw new ApInvalid(self::BAD_IDS);
        }
        $texts = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new ApInvalid(self::BAD_IDS);
            }
            $texts[] = $id;
        }
        if (count($texts) > self::BULK_LIMIT) {
            throw new ApInvalid(self::TOO_MANY);
        }
        $classification = $body['classification'] ?? null;
        if ($action === 'classify' && !in_array($classification, ApEmail::SETTABLE_CLASSIFICATIONS, true)) {
            throw new ApInvalid(self::BAD_CLASSIFICATION);
        }
        $reason = $action === 'classify' ? self::reason($body['reason'] ?? null) : null;

        $unique = array_values(array_unique($texts));
        $found = $this->emails->findMany($unique);
        $now = $this->time->now();
        $touched = [];
        $skipped = 0;

        $this->emails->transactional(function () use ($actor, $action, $classification, $reason, $unique, $found, $now, &$touched, &$skipped): void {
            foreach ($unique as $id) {
                $email = $found[$id] ?? null;
                if ($email === null || !$actor->mailboxScope->allowsRecord($email->mailbox)) {
                    ++$skipped;
                    continue;
                }
                $touched[] = $id;
                if ($action === 'classify' && is_string($classification)) {
                    $this->emails->update($id, self::reclassification($classification, $reason), $now);
                    $this->record($actor, self::RECLASSIFIED, $id, ['from' => $email->classification, 'to' => $classification, 'bulk' => true], $now);
                } elseif ($action === 'bin' && !$email->isBinned()) {
                    $this->emails->bin($id, $actor->userId, $actor->who(), $now);
                    $this->record($actor, self::BINNED, $id, ['subject' => $email->subject], $now);
                } elseif ($action === 'restore' && $email->isBinned()) {
                    $this->emails->restore($id, $now);
                    $this->record($actor, self::RESTORED, $id, ['subject' => $email->subject], $now);
                }
            }
        });

        $rows = [];
        $current = $this->emails->findMany($touched);
        foreach ($touched as $id) {
            if (isset($current[$id])) {
                $rows[] = ApEmailPresenter::email($current[$id]);
            }
        }

        return ['updated' => count($touched), 'skipped' => $skipped, 'emails' => $rows];
    }

    // ---- for the scan (T6.7) and the mail tasks (T6.10) -----------------------------------------

    /**
     * One email, live or binned, for a person: `Not found` when it is missing or outside their mailbox scope (the same
     * answer, so nothing leaks). The reply and the live reads start here.
     */
    public function get(ApActor $actor, string $id): ApEmail
    {
        $email = $this->emails->find($id) ?? throw new ApNotFound(self::NOT_FOUND);
        $actor->mailboxScope->requireRecord($email->mailbox, self::NOT_FOUND);

        return $email;
    }

    /** The email scanned from this message (any case of mailbox and id), or null; no person, no scope. */
    public function findByMessage(string $mailbox, string $graphMessageId): ?ApEmail
    {
        return $this->emails->findByMessage(strtolower(trim($mailbox)), $graphMessageId);
    }

    /**
     * The scan's first step for a message (`upsert_email`): stores it as `Other`, `Pending classification`, `Unverified` when
     * it is new; when it was seen before, refreshes what Graph says about it (sender, subject, time, preview, the run) and
     * keeps the classification, the review status, the proposed reply and the Bin as they are. Texts are cut to their
     * columns; a scan never fails on length.
     */
    public function register(ApEmailArrival $arrival): ApEmail
    {
        $mailbox = self::cut(strtolower(trim($arrival->mailbox)), ApEmail::TEXT_LIMITS['mailbox']);
        $facts = [
            'received_at' => $arrival->receivedAt,
            'sender_name' => self::cut($arrival->senderName, ApEmail::TEXT_LIMITS['sender_name']),
            'sender_email' => self::cut($arrival->senderEmail, ApEmail::TEXT_LIMITS['sender_email']),
            'subject' => self::cut($arrival->subject, ApEmail::TEXT_LIMITS['subject']),
            'body_preview' => self::cut($arrival->bodyPreview, ApEmail::TEXT_LIMITS['body_preview']),
            'web_link' => self::cut($arrival->webLink, ApEmail::TEXT_LIMITS['web_link']),
            'has_attachments' => $arrival->hasAttachments,
            'scan_run_id' => $arrival->scanRunId,
        ];
        $now = $this->time->now();

        $existing = $this->emails->findByMessage($mailbox, $arrival->graphMessageId);
        if ($existing === null) {
            $email = (new ApEmail(ApRules::newId(), self::cut($arrival->graphMessageId, ApEmail::TEXT_LIMITS['graph_message_id']), $mailbox, $now, $now))
                ->withColumns($facts + ['classification_reason' => ApEmail::PENDING_REASON]);
            try {
                $this->emails->insert($email);

                return $this->stored($email->id);
            } catch (ApConflict) {
                // Another request stored the same message first: the pair is unique, so refresh that one.
                $existing = $this->emails->findByMessage($mailbox, $arrival->graphMessageId) ?? throw new ApNotFound(self::NOT_FOUND);
            }
        }
        $this->emails->update($existing->id, $facts, $now);

        return $this->stored($existing->id);
    }

    /**
     * Writes what the scan decided about an email (the `email_updates` of `scan process`): any of `classification`
     * (`Invoice`, `Other`), `classification_reason` (cut to 1,000), `classification_confidence` (0 to 1), `review_status`
     * and `proposed_response`. The rules for WHEN to write them (a Verified email keeps its classification) are the scan's.
     *
     * @param array<string, mixed> $columns
     */
    public function applyScanResult(string $id, array $columns): ApEmail
    {
        $allowed = ['classification', 'classification_reason', 'classification_confidence', 'review_status', 'proposed_response'];
        $unknown = array_diff(array_keys($columns), $allowed);
        if ($unknown !== []) {
            throw new ApInvalid('Not a scan column: ' . implode(', ', $unknown));
        }
        $write = [];
        foreach ($columns as $column => $value) {
            $write[$column] = match ($column) {
                'classification' => in_array($value, ApEmail::CLASSIFICATIONS, true) ? $value : throw new ApInvalid(self::BAD_CLASSIFICATION),
                'review_status' => in_array($value, ApEmail::REVIEW_STATUSES, true) ? $value : throw new ApInvalid('Unknown review status'),
                'classification_reason' => is_string($value) ? self::cut($value, ApEmail::REASON_LIMIT) : null,
                'classification_confidence' => is_int($value) || is_float($value) ? round(min(max((float) $value, 0.0), 1.0), 8) : null,
                default => is_string($value) ? $value : null,
            };
        }
        $this->emails->update($id, $write, $this->time->now());

        return $this->stored($id);
    }

    /**
     * Records a file the scan looked at (`upsert_attachment`): a new row, or, when the email already has a row for this Graph
     * attachment id, that row with the new facts. A file with no Graph id always adds a row.
     */
    public function recordAttachment(
        string $emailId,
        ?string $graphAttachmentId,
        string $name,
        string $contentType,
        ?int $size,
        ?string $contentHash = null,
        ?string $fileId = null,
        bool $isInvoice = false,
        ?string $invoiceId = null,
        ?string $duplicateOfInvoiceId = null,
    ): ApEmailAttachment {
        $now = $this->time->now();
        $graphId = $graphAttachmentId !== null && $graphAttachmentId !== '' ? $graphAttachmentId : null;
        if ($graphId !== null && mb_strlen($graphId) > ApEmailAttachment::TEXT_LIMITS['graph_attachment_id']) {
            // A cut id would match the wrong file: the row is kept without one, and the file name identifies it.
            $graphId = null;
        }
        $facts = [
            'name' => self::cut($name, ApEmailAttachment::TEXT_LIMITS['name']),
            'content_type' => self::cut($contentType, ApEmailAttachment::TEXT_LIMITS['content_type']),
            'size' => $size,
            'content_hash' => $contentHash,
            'file_id' => $fileId,
            'is_invoice' => $isInvoice,
            'invoice_id' => $invoiceId,
            'duplicate_of_invoice_id' => $duplicateOfInvoiceId,
        ];
        $existing = $this->emails->findAttachment($emailId, $graphId);
        if ($existing !== null) {
            $this->emails->updateAttachment($existing->id, $facts, $now);

            return $existing->withColumns($facts)->with(updatedAt: $now);
        }
        $row = (new ApEmailAttachment(ApRules::newId(), $emailId, $now, $now, $graphId))->withColumns($facts);
        $this->emails->saveAttachment($row);

        return $row;
    }

    /**
     * Links a file row to the invoice captured from it (`invoiceId`) or to the invoice an exact copy of it already was
     * (`duplicateOfInvoiceId`, the AP Mailbox's `duplicate_invoice_ids`).
     */
    public function linkAttachment(string $attachmentId, bool $isInvoice, ?string $invoiceId, ?string $duplicateOfInvoiceId): void
    {
        $this->emails->updateAttachment($attachmentId, [
            'is_invoice' => $isInvoice,
            'invoice_id' => $invoiceId,
            'duplicate_of_invoice_id' => $duplicateOfInvoiceId,
        ], $this->time->now());
    }

    /**
     * @return list<ApEmailAttachment>
     */
    public function attachmentsOf(string $emailId): array
    {
        return $this->emails->attachmentsOf($emailId);
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function stored(string $id): ApEmail
    {
        return $this->emails->find($id) ?? throw new ApNotFound(self::NOT_FOUND);
    }

    /**
     * `fromDate`/`toDate` as the UTC instants of the local days: one date alone is that day, none is no bound.
     *
     * @param array<string, mixed> $params
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function period(array $params): array
    {
        $from = self::dayParam($params['fromDate'] ?? null);
        $to = self::dayParam($params['toDate'] ?? null);
        if ($from === null && $to === null) {
            return [null, null];
        }
        $first = self::strictDay($from ?? $to);
        $last = self::strictDay($to ?? $from);
        if ($first > $last) {
            throw new ApInvalid(self::FROM_AFTER_TO);
        }

        return $this->time->dayBounds($first, $last);
    }

    /** A `yyyy-MM-dd` query value; empty is none (the source's `if not from_date`). */
    private static function dayParam(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : throw new ApInvalid(self::BAD_DATE);
    }

    private static function strictDay(?string $value): string
    {
        return ($value === null ? null : ApDates::strict($value)) ?? throw new ApInvalid(self::BAD_DATE);
    }

    /** `limit`: a whole number from 1; absent is 500; more than 1,000 is 1,000. Never "no limit" (R1). */
    private static function limit(mixed $value): int
    {
        if ($value === null || $value === '') {
            return ApEmailQuery::DEFAULT_LIMIT;
        }
        $number = self::whole($value, 'limit');
        if ($number < 1) {
            throw new ApInvalid('limit must be at least 1');
        }

        return min($number, ApEmailQuery::MAX_LIMIT);
    }

    /** `offset`: a whole number; absent is 0 and a negative one is 0 (the source's `max(int(offset), 0)`). */
    private static function offset(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return max(self::whole($value, 'offset'), 0);
    }

    private static function whole(mixed $value, string $name): int
    {
        if (is_string($value) && preg_match('/^[+-]?\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return is_int($value) ? $value : throw new ApInvalid($name . ' must be a whole number');
    }

    /** `?deleted=1` or `?deleted=true`: anything else lists the live emails. */
    private static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 'true';
    }

    /**
     * The person's reason, trimmed; null for none (the source's `body.get("reason")`, empty counting as none).
     */
    private static function reason(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ApInvalid(self::BAD_REASON);
        }
        $text = ApRules::strip($value);
        if (mb_strlen($text) > ApEmail::REASON_LIMIT) {
            throw new ApInvalid(self::LONG_REASON);
        }

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private static function reclassification(string $classification, ?string $reason): array
    {
        return [
            'classification' => $classification,
            'classification_reason' => $reason ?? ApEmail::MANUAL_REASON,
            'review_status' => ApEmail::VERIFIED,
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(ApActor $actor, string $action, string $id, array $details, DateTimeImmutable $at): void
    {
        $this->audit->record(new ApAuditEvent($action, ApAuditEvent::ENTITY_EMAIL, $id, $details, $at, $actor->userId, $actor->name));
    }

    private static function cut(string $text, int $width): string
    {
        return mb_strlen($text) > $width ? mb_substr($text, 0, $width) : $text;
    }
}
