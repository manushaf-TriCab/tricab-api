<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Contract;

/**
 * MySQL-side PlatformOperationId → quoteId correlation written in the same TX
 * as tqoth insert. Required for crash recovery after MySQL commit.
 *
 * Schema (unapplied proposal): tc.quote_write_correlation{_dev}
 * Do not overload tqoth.cref or tqoti.ref.
 */
interface QuoteWriteCorrelationRepository
{
    public function insert(string $platformOperationId, string $quoteId): void;

    public function findQuoteIdByPlatformOperationId(string $platformOperationId): ?string;
}
