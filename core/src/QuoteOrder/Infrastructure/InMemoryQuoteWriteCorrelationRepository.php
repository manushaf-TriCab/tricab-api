<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository;

final class InMemoryQuoteWriteCorrelationRepository implements QuoteWriteCorrelationRepository
{
    /** @var array<string, string> */
    private array $byOperationId = [];

    public function insert(string $platformOperationId, string $quoteId): void
    {
        $this->byOperationId[$platformOperationId] = $quoteId;
    }

    public function findQuoteIdByPlatformOperationId(string $platformOperationId): ?string
    {
        return $this->byOperationId[$platformOperationId] ?? null;
    }
}
