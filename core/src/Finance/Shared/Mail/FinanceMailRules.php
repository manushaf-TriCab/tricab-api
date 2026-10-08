<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * The limits and shapes the finance portal's Mail Service enforces today
 * (`_work-portal-entra-20261005/server/app.py`, `mail_send`), kept as they are so
 * an app moved across sends what it sent before.
 */
final class FinanceMailRules
{
    /** Per list (to, cc, bcc) and for the three together. */
    public const MAX_RECIPIENTS = 50;

    public const MAX_SUBJECT_LENGTH = 500;

    /** 1 MiB of UTF-8. */
    public const MAX_BODY_BYTES = 1048576;

    public const MAX_ATTACHMENTS = 10;

    /** 3 MiB in total, decoded. */
    public const MAX_ATTACHMENT_BYTES = 3145728;

    public const MAX_ATTACHMENT_NAME_LENGTH = 255;

    public const MAX_IDEMPOTENCY_KEY_LENGTH = 200;

    /** The ledger keeps this much of a failure's message. */
    public const MAX_ERROR_LENGTH = 500;

    public const CONTENT_HTML = 'HTML';

    public const CONTENT_TEXT = 'TEXT';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Not sent because mail is switched off. The key may be sent again later. */
    public const STATUS_SUPPRESSED = 'suppressed';

    private function __construct()
    {
    }
}
