<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

/** Reading is off, not configured, or Graph could not be reached (or kept failing). Retryable. */
final class MailboxReadUnavailable extends MailboxReadFailed
{
}
