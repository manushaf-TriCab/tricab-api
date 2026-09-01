<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Contract;

/**
 * Read-only access to legacy tc.rating rows (LOCAL_LEGACY_PARITY_BASELINE).
 */
interface CableRatingRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array;
}
