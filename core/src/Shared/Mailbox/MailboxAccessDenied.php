<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

/**
 * Graph refused the registration (401 or 403): usually `Mail.Read` has no admin consent,
 * or an Application Access Policy leaves this mailbox out. A deployment fault, not the
 * caller's.
 */
final class MailboxAccessDenied extends MailboxReadFailed
{
}
