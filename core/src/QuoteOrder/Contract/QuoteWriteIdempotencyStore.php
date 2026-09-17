<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Contract;

use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;

/**
 * Platform-side (Azure) idempotency for future POST /api/v1/quotes.
 * Do not store keys on legacy tqoth — keep MySQL quote id as SoR only.
 */
interface QuoteWriteIdempotencyStore
{
    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?QuoteWriteIdempotencyRecord;

    public function record(QuoteWriteIdempotencyRecord $record): void;
}
