<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Contract;

/**
 * Read-only variant availability lookup.
 *
 * AVAILABILITY_SOURCE_REVIEW: Azure stock table not yet authoritative.
 * Temporary legacy mapping uses tpro.stkst / warehouse codes 2302=STOCKED, 2301=MAKE.
 * Stub implementations return UNKNOWN until wired.
 */
interface VariantAvailabilityRepository
{
    /**
     * @return 'STOCKED'|'MAKE'|'UNKNOWN'
     */
    public function findAvailability(string $variantPublicId): string;
}
