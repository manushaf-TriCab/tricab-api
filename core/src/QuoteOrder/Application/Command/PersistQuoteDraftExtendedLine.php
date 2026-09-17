<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Command;

/**
 * Optional tqote row. Pack/reel parity remains PARTIAL — supply only when validated.
 */
final readonly class PersistQuoteDraftExtendedLine
{
    public function __construct(
        public string $unitsPerPack,
        public string $packCount,
        public ?string $comments = null,
    ) {
    }
}
