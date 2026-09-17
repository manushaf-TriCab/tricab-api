<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use RuntimeException;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository;

/** Default wiring until MySQL correlation table is approved/applied. */
final class NullQuoteWriteCorrelationRepository implements QuoteWriteCorrelationRepository
{
    public function insert(string $platformOperationId, string $quoteId): void
    {
        throw new RuntimeException(
            'Quote write correlation is not configured. Apply tc.quote_write_correlation(_dev) before enabling writes.',
        );
    }

    public function findQuoteIdByPlatformOperationId(string $platformOperationId): ?string
    {
        return null;
    }
}
