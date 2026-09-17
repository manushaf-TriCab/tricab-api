<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

/**
 * Result of a successful transactional draft persist.
 * quoteId is the MySQL tqoth AUTO_INCREMENT value (authoritative quote number).
 */
final readonly class PersistedQuote
{
    /**
     * @param list<int> $lineIds tqoti AUTO_INCREMENT ids in command order
     */
    public function __construct(
        public string $quoteId,
        public string $statusCode,
        public string $totalNet,
        public array $lineIds,
    ) {
    }
}
