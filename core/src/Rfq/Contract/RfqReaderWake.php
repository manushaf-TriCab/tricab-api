<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

/**
 * Word to the background reader that a read has been queued. The worker polls
 * every minute; without this it would have to ask the database each time, and
 * a serverless database asked every minute never pauses.
 *
 * Signalling is best effort and never fails the upload: the job row is the
 * record, and the worker's periodic check finds a job whose signal was lost.
 */
interface RfqReaderWake
{
    public function signal(): void;
}
