<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Domain\Exception;

/**
 * Something the action needs is not available now: Graph (not configured, or failing) or the
 * RFQ draft service. Retryable, and nothing was changed.
 */
final class MailUnavailable extends MailFailure
{
    public const BODY = 'body';
    public const DRAFTS = 'drafts';

    public function __construct(string $message, public readonly string $what = self::BODY, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
