<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqCorrectionMemory;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;

/**
 * Where there is no Azure SQL: parses run with no memory, and Save Review
 * refuses as unavailable rather than pretending to have learned anything.
 */
final class UnconfiguredRfqCorrectionMemory implements RfqCorrectionMemory
{
    public function resolved(): array
    {
        return [];
    }

    public function recordReview(
        string $draftId,
        int $revision,
        array $quoteDetails,
        array $rows,
        array $systemRows,
        string $actorEmail,
        ?string $actorBranch,
        array $learned,
    ): string {
        throw new RfqStoreUnavailable();
    }
}
