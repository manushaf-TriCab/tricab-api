<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\QuoteOrder\Application\Service\QuoteWriteAuditLogger;

final class NullQuoteWriteAuditLogger implements QuoteWriteAuditLogger
{
    public function log(array $event): void
    {
    }
}
