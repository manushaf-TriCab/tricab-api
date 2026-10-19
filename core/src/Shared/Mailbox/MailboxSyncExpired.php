<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

/**
 * Graph answered 410 Gone: a delta token (or the link it resumed from) is no longer valid and the sync
 * has to start again from a time window.
 */
final class MailboxSyncExpired extends MailboxReadFailed
{
}
