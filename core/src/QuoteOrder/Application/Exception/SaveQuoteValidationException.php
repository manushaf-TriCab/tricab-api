<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;

final class SaveQuoteValidationException extends RuntimeException
{
    /**
     * @param list<string> $blockers
     */
    public function __construct(
        string $message,
        public readonly array $blockers = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $blockers
     */
    public static function because(string $reason, array $blockers = []): self
    {
        return new self($reason, $blockers);
    }
}
