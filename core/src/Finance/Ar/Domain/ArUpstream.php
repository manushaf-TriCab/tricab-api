<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

use Throwable;

/**
 * Something Tri-AR depends on failed: the mailbox, the model, or the mail service. The source answered 502 for
 * each. `extra.reason` says which (`mailbox`, `ai`, `send`) so a page can word it; the message is safe to show
 * (never a secret or message content).
 */
final class ArUpstream extends ArFailure
{
    public const MAILBOX = 'mailbox';
    public const AI = 'ai';
    public const SEND = 'send';

    public function __construct(string $message, string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, ['reason' => $reason], $previous);
    }

    public function httpStatus(): int
    {
        return 502;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_UPSTREAM_FAILED';
    }
}
