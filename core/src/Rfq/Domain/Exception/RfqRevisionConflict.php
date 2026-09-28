<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;

/**
 * A save based on a revision that is no longer current: somebody else saved
 * first. Nothing was written. Maps to 409, carrying the current revision so
 * the editor can offer to reload. The source's `DraftConflict`.
 */
final class RfqRevisionConflict extends RuntimeException
{
    public function __construct(public readonly int $currentRevision, public readonly int $expectedRevision)
    {
        parent::__construct(sprintf(
            'This RFQ was saved by somebody else (revision %d; this save was based on %d). Reload it before saving.',
            $currentRevision,
            $expectedRevision,
        ));
    }
}
