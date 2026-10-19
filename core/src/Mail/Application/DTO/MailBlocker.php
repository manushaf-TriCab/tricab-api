<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** `MailBlocker`: why an action is refused. The server works these out; the client only shows them. */
final readonly class MailBlocker
{
    public function __construct(public string $kind, public string $message)
    {
    }

    /** @return array{kind: string, message: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'message' => $this->message];
    }
}
