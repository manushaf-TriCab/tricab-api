<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Service;

/**
 * Safe write audit metadata only — no credentials, no full pricing dumps.
 */
interface QuoteWriteAuditLogger
{
    /**
     * @param array{
     *   operation: string,
     *   success: bool,
     *   quoteId?: string|null,
     *   actorKey?: string|null,
     *   idempotencyKeyHash?: string|null,
     *   errorClass?: string|null
     * } $event
     */
    public function log(array $event): void;
}
