<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;
use TriCab\Core\Rfq\Application\DTO\SyncAttemptView;

/**
 * An earlier attempt for this RFQ may have created a quote nobody has the
 * number of, so no new one is started (invariant 8). Maps to 409.
 */
final class RfqSyncNeedsReconciliation extends RuntimeException
{
    public function __construct(public readonly SyncAttemptView $attempt)
    {
        parent::__construct('An earlier sync for this RFQ was interrupted and its outcome in 4js is unknown. Check 4js for an existing quote before syncing again; this RFQ cannot be synced from here until that attempt is resolved.');
    }
}
