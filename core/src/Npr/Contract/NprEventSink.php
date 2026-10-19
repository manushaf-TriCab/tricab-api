<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;

/**
 * Receives one event per workflow transition, INSIDE the transition's own
 * transaction (plan N2.4: the outbox row commits, or rolls back, with the
 * business change). The repository calls it after the header, the answers and
 * the Activity row are written, passing the request as it now stands.
 *
 * An exception here undoes the whole transition, so an implementation writes
 * to the same store and never sends anything.
 */
interface NprEventSink
{
    /**
     * @param int $requestId  storage key of the request (0 where there is none)
     * @param int $activityId storage key of the transition's Activity row (0 where there is none)
     */
    public function record(NprWorkflowEvent $event, NprRequest $saved, int $activityId, int $requestId): void;
}
