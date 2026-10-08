<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use stdClass;
use TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArRecordQuery;
use Throwable;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;

/**
 * The register's server side (`store.py` `list_email_records`, `update_email_record`,
 * `get_dashboard_metrics`, `bin_email_record`, `restore_email_record`, `purge_email_record`;
 * `app.py` routes 6, 7, 10, 17, 18 and 31), with the decided changes of Phase 5:
 *
 *  - "overdue" is derived on read in the Tri-AR zone and never written (Q8);
 *  - the sort list is bounded and `final_outcome` is not on it (Q9);
 *  - the search covers the body only when asked (Q9);
 *  - a PATCH accepts only the fields a person edits (D48);
 *  - the page size has a maximum of 1,000 (D49).
 *
 * Attachment bytes, the original-message body, scanning, AI, mail and credit-note requests are later
 * tasks: a record read returns each attachment's metadata only.
 */
final class ArRecordService
{
    public const NOT_FOUND = 'Record not found';
    public const BAD_DATE = 'Dates must use YYYY-MM-DD';
    public const ATTACHMENT_APP = 'AR';
    public const ATTACHMENT_RECORD_TYPE = 'email_record';

    private const MAX_CUSTOMER_NAME = 400;
    private const MAX_EMAIL = 320;

    /** The free-text fields a person edits, with the column each is: field => limit (null for none). */
    private const TEXT_FIELDS = [
        'final_outcome' => null,
        'ai_summary' => null,
        'proposed_response' => null,
        'customer_name' => self::MAX_CUSTOMER_NAME,
        'assigned_sales_rep_email' => self::MAX_EMAIL,
    ];

    public function __construct(
        private readonly ArRecordStore $records,
        private readonly ArOptionService $options,
        private readonly ArAssigneeDirectory $assignees,
        private readonly ArTime $time,
        private readonly FinanceAttachmentStore $files,
        private readonly ?FinanceAuditLog $audit = null,
    ) {
    }

    /**
     * `GET /records`.
     *
     * @param array<string, mixed> $params the query string
     * @return array<string, mixed> `{records, total, limit, offset}`
     */
    public function list(array $params): array
    {
        $limit = self::wholeNumber($params['limit'] ?? null, ArRecordQuery::DEFAULT_LIMIT, 'limit');
        if ($limit < 1) {
            throw new ArInvalid('limit must be at least 1');
        }
        $limit = min($limit, ArRecordQuery::MAX_LIMIT);
        $offset = self::wholeNumber($params['offset'] ?? null, 0, 'offset');
        if ($offset < 0) {
            throw new ArInvalid('offset must be 0 or more');
        }
        [$start, $end] = $this->time->dayBounds(
            ArRules::dateOrNull($params['from_date'] ?? null, self::BAD_DATE),
            ArRules::dateOrNull($params['to_date'] ?? null, self::BAD_DATE),
        );
        $sort = self::filter($params['sort_by'] ?? null) ?? 'email_date';
        $direction = self::filter($params['sort_dir'] ?? null) ?? 'desc';
        $today = $this->time->today();

        [$records, $total] = $this->records->page(new ArRecordQuery(
            ArRules::flag($params['deleted'] ?? null),
            self::filter($params['category'] ?? null),
            self::filter($params['follow_up_status'] ?? null),
            self::filter($params['status'] ?? null),
            self::filter($params['customer_name'] ?? null),
            self::filter($params['assigned_person'] ?? null),
            $start,
            $end,
            self::filter($params['search'] ?? null),
            ArRules::flag($params['search_body'] ?? null),
            in_array($sort, ArRecordQuery::SORTS, true) ? $sort : 'email_date',
            strtolower($direction) === 'desc',
            $limit,
            $offset,
            $today,
        ));

        return [
            'records' => array_map(static fn (ArRecord $r): array => ArPresenter::record($r, $today, false), $records),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * `GET /records/{id}`: the record (binned or live) with its attachments' metadata.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $record = $this->records->find($id) ?? throw new ArNotFound(self::NOT_FOUND);

        return ArPresenter::record($record, $this->time->today(), true) + ['attachments' => $this->records->attachmentsOf($id)];
    }

    /**
     * `PATCH /records/{id}` (decision D48): only the fields a person edits; every other key is ignored and never written.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the bare record
     */
    public function update(string $id, array $payload): array
    {
        $record = $this->records->find($id) ?? throw new ArNotFound(self::NOT_FOUND);
        $changes = [];

        foreach (['follow_up_category' => ['category', 'followUpCategory'], 'proposed_response_type' => ['method', 'proposedResponseType']] as $field => [$list, $property]) {
            if (array_key_exists($field, $payload)) {
                $changes[$property] = $this->option($field, $list, $payload[$field]);
            }
        }
        if (array_key_exists('follow_up_status', $payload)) {
            $changes['followUpStatus'] = self::oneOf('follow_up_status', $payload['follow_up_status'], ArRecord::SETTABLE_FOLLOW_UP_STATUSES);
        }
        if (array_key_exists('status', $payload)) {
            $changes['status'] = self::oneOf('status', $payload['status'], ArRecord::STATUSES);
        }
        if (array_key_exists('assigned_user_id', $payload) || array_key_exists('assigned_person', $payload)) {
            $changes += $this->assignee($record, $payload);
        }
        if (array_key_exists('due_date', $payload)) {
            $due = ArRules::dateOrNull($payload['due_date'], 'Due date must be yyyy-MM-dd');
            if ($due !== $record->dueDate) {
                $changes['dueDate'] = $due;
                // A new due date is a new deadline, so it earns a new reminder.
                $changes['reminderSentAt'] = null;
            }
        }
        foreach (self::TEXT_FIELDS as $field => $limit) {
            if (array_key_exists($field, $payload)) {
                $changes[self::property($field)] = self::text($field, $payload[$field], $limit);
            }
        }

        if ($changes === []) {
            return ArPresenter::record($record, $this->time->today(), true);
        }
        $this->records->update($record->with(...$changes, updatedAt: $this->time->now()));

        return ArPresenter::record($this->records->find($id) ?? throw new ArNotFound(self::NOT_FOUND), $this->time->today(), true);
    }

    /**
     * `DELETE /records/{id}`: into the Bin, or for good (`$permanent`) from the Bin.
     *
     * @return array<string, mixed>
     */
    public function delete(ArActor $actor, string $id, bool $permanent): array
    {
        $record = $this->records->find($id) ?? throw new ArNotFound(self::NOT_FOUND);
        if (!$permanent) {
            $this->records->bin($id, $actor->userId, $actor->who(), $this->time->now());
            $binned = $this->records->find($id) ?? throw new ArNotFound(self::NOT_FOUND);

            return ['in_bin' => true, 'record' => ArPresenter::record($binned, $this->time->today(), true)];
        }
        if ($record->deletedAt === null) {
            throw new ArConflict('Move the record to the Bin before deleting it permanently');
        }
        $now = $this->time->now();
        $this->records->transactional(function () use ($id, $now): void {
            $this->records->purge($id, $now);
        });
        // The rows that named the files are gone; now the files (bytes and file-store rows) go. If that fails the
        // record cannot come back, so the delete stands, but the files are owned by nobody: say so, and say where.
        try {
            $this->files->deleteForRecord(self::ATTACHMENT_APP, self::ATTACHMENT_RECORD_TYPE, $id);
        } catch (Throwable $failure) {
            $this->noteOrphanedFiles($actor, $id, $failure);

            return ['in_bin' => false, 'deleted' => true, 'files_orphaned' => true];
        }

        return ['in_bin' => false, 'deleted' => true];
    }

    /**
     * `POST /records/{id}/restore`.
     *
     * @return array<string, mixed>
     */
    public function restore(string $id): array
    {
        $this->records->find($id) ?? throw new ArNotFound(self::NOT_FOUND);
        $this->records->restore($id, $this->time->now());

        return ArPresenter::record($this->records->find($id) ?? throw new ArNotFound(self::NOT_FOUND), $this->time->today(), true);
    }

    /**
     * `GET /dashboard/metrics`: the tiles follow the chosen local-day range; the Bin and the credit-note
     * counts never do.
     *
     * @return array<string, mixed>
     */
    public function metrics(mixed $fromDate, mixed $toDate): array
    {
        [$start, $end] = $this->time->dayBounds(
            ArRules::dateOrNull($fromDate, self::BAD_DATE),
            ArRules::dateOrNull($toDate, self::BAD_DATE),
        );
        $counts = $this->records->metrics($start, $end, $this->time->today());
        $statuses = $counts['statuses'];

        return [
            'total_emails' => $counts['total'],
            'needs_review' => $statuses[ArRecord::STATUS_NEEDS_REVIEW] ?? 0,
            'in_progress' => $statuses[ArRecord::STATUS_IN_PROGRESS] ?? 0,
            'overdue_items' => $statuses[ArRecord::STATUS_OVERDUE] ?? 0,
            'completed' => $statuses[ArRecord::STATUS_COMPLETED] ?? 0,
            'in_bin' => $counts['in_bin'],
            'category_counts' => self::map($counts['categories']),
            'follow_up_status_counts' => self::map($statuses),
            'credit_note_counts' => self::map($counts['credit_notes']),
        ];
    }

    /** An audit row (facts only: the record id, the error class and a short message) so the files can be found and removed. */
    private function noteOrphanedFiles(ArActor $actor, string $recordId, Throwable $failure): void
    {
        $message = mb_substr(preg_replace('/\s+/', ' ', $failure->getMessage()) ?? '', 0, 200);
        if ($this->audit === null) {
            error_log('Tri-AR: files of purged record ' . $recordId . ' were not removed (' . $failure::class . ')');

            return;
        }
        try {
            $this->audit->record(new FinanceAuditEvent(
                'ar.record.files_orphaned',
                self::ATTACHMENT_RECORD_TYPE,
                $recordId,
                ['error' => $failure::class, 'message' => $message],
                $this->time->now(),
                $actor->userId,
                'AR',
            ));
        } catch (Throwable $auditFailure) {
            error_log('Tri-AR: files of purged record ' . $recordId . ' were not removed (' . $failure::class . ') and the audit row failed (' . $auditFailure::class . ')');
        }
    }

    // ---- helpers ------------------------------------------------------------------------

    /**
     * A JSON object even when empty (a PHP empty array would encode as a list).
     *
     * @param array<string, int> $counts
     * @return array<string, int>|stdClass
     */
    private static function map(array $counts): array|stdClass
    {
        return $counts === [] ? new stdClass() : $counts;
    }

    /** A query-string text that is present and not empty (the source's `if value:`); anything else is none. */
    private static function filter(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A whole number from the query string, or `$default` when absent. */
    private static function wholeNumber(mixed $value, int $default, string $name): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ArInvalid($name . ' must be a whole number');
    }

    /** `Invalid <field>: <repr>` as the source's PATCH says it, for a value the field does not take. */
    private static function invalid(string $field, mixed $value): ArInvalid
    {
        return new ArInvalid('Invalid ' . $field . ': ' . self::repr($value));
    }

    private static function repr(mixed $value): string
    {
        return match (true) {
            is_string($value) => ArRules::repr($value),
            $value === null => 'None',
            is_bool($value) => $value ? 'True' : 'False',
            is_int($value), is_float($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '?',
        };
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(string $field, mixed $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw self::invalid($field, $value);
        }

        return $value;
    }

    /** A value of an ACTIVE option of the list: archived options stay on the records that carry them but cannot be newly assigned. */
    private function option(string $field, string $list, mixed $value): string
    {
        if (!is_string($value) || !in_array($value, $this->options->activeValues($list), true)) {
            throw self::invalid($field, $value);
        }

        return $value;
    }

    private static function text(string $field, mixed $value, ?int $limit): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value)) {
            throw new ArInvalid($field . ' must be text');
        }
        $text = is_bool($value) ? ($value ? 'True' : 'False') : (string) $value;
        if ($limit !== null && mb_strlen($text) > $limit) {
            throw new ArInvalid($field . ' must be at most ' . $limit . ' characters');
        }

        return $text;
    }

    /** `final_outcome` to `finalOutcome`. */
    private static function property(string $field): string
    {
        return lcfirst(str_replace('_', '', ucwords($field, '_')));
    }

    /**
     * The assignee (decision D43, T5.0 finding 16): `assigned_user_id` names a person; the page's
     * `assigned_person` is their full name or address, resolved against the assignee list. Empty clears.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> record properties to change
     */
    private function assignee(ArRecord $record, array $payload): array
    {
        $clear = ['assignedUserId' => null, 'assignedName' => ''];
        if (array_key_exists('assigned_user_id', $payload)) {
            $raw = $payload['assigned_user_id'];
            if ($raw === null || $raw === '' || $raw === false || $raw === 0 || $raw === '0') {
                return $clear;
            }
            $id = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^[1-9]\d{0,17}$/', trim($raw)) === 1 ? (int) trim($raw) : 0);
            $person = $id > 0 ? $this->assignees->find($id) : null;
            if ($person === null) {
                throw new ArInvalid('The assignee must be someone who can work records in Tri-AR');
            }

            return ['assignedUserId' => $person->userId, 'assignedName' => $person->displayName()];
        }

        $given = ArRules::textOrEmpty($payload['assigned_person'] ?? null);
        if ($given === '') {
            return $clear;
        }
        if ($given === $record->assignedName) {
            return [];
        }
        $matches = [];
        foreach ($this->assignees->assignees() as $person) {
            if (mb_strtolower($person->displayName()) === mb_strtolower($given) || strtolower($person->email) === strtolower($given)) {
                $matches[$person->userId] = $person;
            }
        }
        if (count($matches) > 1) {
            throw new ArInvalid('More than one person is called ' . ArRules::repr($given) . '; choose by address');
        }
        $person = reset($matches);
        if ($person === false) {
            throw new ArInvalid('Unknown assignee: ' . ArRules::repr($given));
        }

        return ['assignedUserId' => $person->userId, 'assignedName' => $person->displayName()];
    }
}
