<?php

declare(strict_types=1);

namespace TriCab\Core\Expense;

/**
 * Marker for the Tri Expense bounded context (receipt-centric).
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
}