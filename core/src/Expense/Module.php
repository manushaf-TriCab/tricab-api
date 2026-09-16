<?php

declare(strict_types=1);

namespace TriCab\Core\Expense;

/**
 * Marker for the Tri Expense bounded context (receipt-centric).
 *
 * Authorization cutover ({@see \TriCab\Core\Expense\Application\ExpensePermissionMode}):
 *   legacy / hybrid → ExpenseProfile.IsFinance / IsExpenseAdmin still consulted
 *   permissions     → TRIEXPENSE.FINANCE / ADMIN / APPROVE / AUDIT are authoritative
 *
 * Graph Tricab-Finance remains diagnostic/sync only on the request path.
 * See {@see \TriCab\Core\Expense\Application\Service\ExpenseAccessService}.
 */
final class Module
{
    public const NAME = 'Expense';

    /** Module entry — own receipts create/view/edit/submit/delete (status rules apply). */
    public const PERMISSION_ACCESS = 'TRIEXPENSE.ACCESS';

    /** Manager approval features; receipt action still requires assignment (or Finance). */
    public const PERMISSION_APPROVE = 'TRIEXPENSE.APPROVE';

    /** Finance operations and Finance approval override. */
    public const PERMISSION_FINANCE = 'TRIEXPENSE.FINANCE';

    /** TriExpense access-configuration admin via User Management — not Finance. */
    public const PERMISSION_ADMIN = 'TRIEXPENSE.ADMIN';

    /** TriExpense audit read access. Prefer role composition with Finance. */
    public const PERMISSION_AUDIT = 'TRIEXPENSE.AUDIT';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_ACCESS,
        self::PERMISSION_APPROVE,
        self::PERMISSION_FINANCE,
        self::PERMISSION_ADMIN,
        self::PERMISSION_AUDIT,
    ];

    public const ROLE_USER = 'TRIEXPENSE_USER';
    public const ROLE_APPROVER = 'TRIEXPENSE_APPROVER';
    public const ROLE_FINANCE = 'TRIEXPENSE_FINANCE';
    public const ROLE_ADMINISTRATOR = 'TRIEXPENSE_ADMINISTRATOR';

    /** @var list<string> */
    public const ROLES = [
        self::ROLE_USER,
        self::ROLE_APPROVER,
        self::ROLE_FINANCE,
        self::ROLE_ADMINISTRATOR,
    ];

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