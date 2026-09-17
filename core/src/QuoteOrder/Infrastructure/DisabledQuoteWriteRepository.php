<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Application\DTO\PersistedQuote;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteDisabledException;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteRepository;

/**
 * Default wiring when QUOTES_ORDERS_LEGACY_WRITE_ENABLED is false.
 * Fail before any SQL can execute.
 */
final class DisabledQuoteWriteRepository implements QuoteWriteRepository
{
    public function persistDraft(PersistQuoteDraft $draft): PersistedQuote
    {
        throw QuoteWriteDisabledException::disabled();
    }
}
