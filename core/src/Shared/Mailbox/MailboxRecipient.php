<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

/** An address on a message, in lower case, with the display name the sender gave it. */
final readonly class MailboxRecipient
{
    public function __construct(
        public string $address,
        public string $name = '',
    ) {
    }
}
