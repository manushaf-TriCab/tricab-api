<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use TriCab\Core\Rfq\Domain\ValueObject\Priority;

/**
 * What a workflow save decided, from the draft as the store found it under
 * its lock. `storeStatus` is the `status` column value to write, or null to
 * leave the column alone: the column also carries readiness (`ready_to_sync`)
 * and the last sync's failure (`failed`), which a save that does not change
 * the workflow status must not overwrite.
 */
final class WorkflowChange
{
    /**
     * @param array<string, mixed> $detail the `workflow_updated` activity's detail
     */
    public function __construct(
        public readonly ?string $storeStatus,
        public readonly Priority $priority,
        public readonly array $detail,
    ) {
    }
}
