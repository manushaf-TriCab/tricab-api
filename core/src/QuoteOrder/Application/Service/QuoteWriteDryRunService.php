<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

use TriCab\Core\QuoteOrder\Application\Command\PersistQuoteDraft;
use TriCab\Core\QuoteOrder\Domain\Mapping\PersistQuoteDraftMapper;

/**
 * Internal dry-run: maps a validated draft to legacy column shapes without SQL.
 * Not exposed over HTTP.
 */
final class QuoteWriteDryRunService
{
    public function __construct(
        private readonly PersistQuoteDraftMapper $mapper = new PersistQuoteDraftMapper(),
    ) {
    }

    /**
     * @return array{
     *   classification: array<string, string>,
     *   header: array<string, scalar|null>,
     *   lines: list<array{line: array<string, scalar|null>, extended: list<array<string, scalar|null>>}>,
     *   totalNet: string
     * }
     */
    public function preview(PersistQuoteDraft $draft): array
    {
        return $this->mapper->map($draft);
    }
}
