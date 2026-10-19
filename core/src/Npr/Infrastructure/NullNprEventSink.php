<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use TriCab\Core\Npr\Contract\NprEventSink;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;

/** The default binding until email exists (plan N2.4): drops every event. */
final class NullNprEventSink implements NprEventSink
{
    public function record(NprWorkflowEvent $event): void
    {
    }
}
