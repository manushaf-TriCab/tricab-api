<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\QuoteOrder\Application\Service\QuoteWriteAuditLogger;

final class RecordingQuoteWriteAuditLogger implements QuoteWriteAuditLogger
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function log(array $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }
}
