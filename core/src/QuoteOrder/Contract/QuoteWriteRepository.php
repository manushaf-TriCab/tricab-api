<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Contract;

use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Application\DTO\PersistedQuote;

/**
 * Persist an already-authorized, server-priced draft into legacy MySQL.
 * Controllers must never call this until Phase 4C registers a write route.
 */
interface QuoteWriteRepository
{
    public function persistDraft(PersistQuoteDraft $draft): PersistedQuote;
}
