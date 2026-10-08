<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

use RuntimeException;

/**
 * A read failed. The message is safe to show a person: it names what went wrong and
 * never carries a secret, a token or the content of a message.
 */
class MailboxReadFailed extends RuntimeException
{
}
