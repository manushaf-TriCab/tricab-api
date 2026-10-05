<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;

/**
 * The quote cannot be sent yet, and nothing was sent (the source's 400 and 409
 * answers from `prepare_quote_push`). Carries the issues in the rep's terms:
 * the line, its code and the reason.
 */
final class RfqSyncBlocked extends RuntimeException
{
    /**
     * @param list<array{lineNumber: ?int, code: string, reason: string, reviewedPrice?: ?float, requiredPrice?: ?float}> $issues
     */
    public function __construct(string $message, public readonly array $issues, public readonly bool $pricesChanged = false)
    {
        parent::__construct($message);
    }
}
