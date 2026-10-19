<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** What recording a message did. `created` is false when Graph delivered a message again. */
final readonly class RecordedMessage
{
    public function __construct(
        public string $messageId,
        public string $threadId,
        public bool $created,
    ) {
    }
}
