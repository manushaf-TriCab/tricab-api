<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use TriCab\Core\Npr\Contract\NprEventSink;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;

/** Drops every event (repositories built without email). */
final class NullNprEventSink implements NprEventSink
{
    public function record(NprWorkflowEvent $event, NprRequest $saved, int $activityId, int $requestId): void
    {
    }
}
