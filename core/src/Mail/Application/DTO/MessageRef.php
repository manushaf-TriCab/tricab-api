<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** Where to fetch a message's body from. */
final readonly class MessageRef
{
    public function __construct(
        public string $messageId,
        public string $mailboxAddress,
        public string $graphMessageId,
    ) {
    }
}
