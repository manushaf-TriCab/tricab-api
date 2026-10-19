<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Exception;

use TriCab\Core\Mail\Application\DTO\MailBlocker;

/** The server-side guard refused the action for this thread's state (plan I4.2). Nothing was changed. */
final class MailActionBlocked extends MailFailure
{
    /** @param non-empty-list<MailBlocker> $blockers */
    public function __construct(public readonly array $blockers, string $message = 'That action is blocked for this conversation.')
    {
        parent::__construct($message);
    }
}
