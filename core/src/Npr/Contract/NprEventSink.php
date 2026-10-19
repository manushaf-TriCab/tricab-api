<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Contract;

use TriCab\Core\Npr\Domain\NprWorkflowEvent;

/** Receives one event per committed workflow transition (N2.4 binds the email outbox here). */
interface NprEventSink
{
    public function record(NprWorkflowEvent $event): void;
}
