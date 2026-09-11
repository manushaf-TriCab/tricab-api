<?php

declare(strict_types=1);

namespace TriCab\Core\Expense;

/**
 * Marker for the Tri Expense bounded context (receipt-centric).
 *
 * Finance authorization decision (Phase 5):
 *   ExpenseProfile.IsFinance is authoritative at request time.
 *   The Graph Tricab-Finance group is diagnostic/sync only — not a live hard
 *   dependency per request.
 * Request-time authorization therefore resolves finance capability from the
 * profile alone, so a Graph outage or throttle cannot lock Finance out of the
 * module. See {@see \TriCab\Core\Expense\Application\Service\ExpenseAccessService}.
 */
final class Module
{
    public const NAME = 'Expense';

    /** Existing launcher permission — module entry only; profile still required. */
    public const PERMISSION_ACCESS = 'TRIEXPENSE.ACCESS';

    public const RECYCLE_DAYS = 30;
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'heic', 'heif'];

    public const GST_INCLUSIVE = 'inclusive';
    public const GST_EXCLUSIVE = 'exclusive';
    public const GST_UNKNOWN = 'unknown';

    public const SUBMIT_DRAFT_THEN_SUBMIT = 'draft_then_submit';
    public const SUBMIT_IMMEDIATE = 'immediate';

    public const BLOB_CONTAINER = 'triexpense';

    /** Auto-match: absolute amount tolerance (AUD/NZD units). */
    public const MATCH_AMOUNT_TOLERANCE = '0.10';

    /** Auto-match: receipt date may differ from transaction date by this many days. */
    public const MATCH_DATE_DAYS = 3;

    /** Offset suggestion/apply: max calendar days between the two transaction dates. */
    public const OFFSET_MAX_DAYS = 60;

    /** Bank-feed transaction type excluded from offset (not from auto-match — legacy quirk). */
    public const TYPE_PAYMENT = 'payment';

    /** Near-miss diagnostic floor (does not change match classification). */
    public const NEAR_MISS_FLOOR = '5.00';

    /** Near-miss diagnostic fraction of amount (max with floor). */
    public const NEAR_MISS_FRACTION = '0.20';

    public const RESOLUTION_WRITTEN_OFF = 'written_off';
    public const RESOLUTION_EXCEPTION = 'exception';
    public const RESOLUTION_CHASED = 'chased';
    public const RESOLUTION_MARKED_CASH = 'marked_cash';
    public const RESOLUTION_FORCE_APPROVED = 'force_approved';
    public const RESOLUTION_FORCE_REJECTED = 'force_rejected';
    public const RESOLUTION_OFFSET = 'offset';

    public const COUNTRY_AU = 'AU';
    public const COUNTRY_NZ = 'NZ';

    /** OCR input ceiling — below MAX_UPLOAD_BYTES because the document is sent inline. */
    public const OCR_MAX_BYTES = 6 * 1024 * 1024;

    /** Multi-page statements are not receipts; refuse them rather than billing for pages. */
    public const OCR_MAX_PDF_PAGES = 5;

    /** @var array<string, string> extension => mime type accepted by OCR */
    public const OCR_ALLOWED_MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
    ];

    /** Reminder-log types — part of the monthly digest idempotency key. */
    public const REMINDER_TYPE_MANAGER_PENDING = 'manager_pending';
    public const REMINDER_TYPE_MANAGER_MONTHLY_DIGEST = 'manager_monthly_digest';
    public const REMINDER_TYPE_MISSING_RECEIPTS = 'missing_receipts';

    /** Legacy cron ran the manager digest in Melbourne local time on day 1. */
    public const SCHEDULER_TIMEZONE = 'Australia/Melbourne';

    /** @var list<string> */
    public const BANK_RESOLUTIONS = [
        self::RESOLUTION_WRITTEN_OFF,
        self::RESOLUTION_EXCEPTION,
        self::RESOLUTION_CHASED,
    ];

    /** @var list<string> */
    public const RECEIPT_FINANCE_RESOLUTIONS = [
        self::RESOLUTION_MARKED_CASH,
        self::RESOLUTION_EXCEPTION,
        self::RESOLUTION_CHASED,
        self::RESOLUTION_FORCE_APPROVED,
        self::RESOLUTION_FORCE_REJECTED,
    ];
}