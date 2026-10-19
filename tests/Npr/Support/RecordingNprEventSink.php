<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr\Support;

use TriCab\Core\Npr\Contract\NprEventSink;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;

final class RecordingNprEventSink implements NprEventSink
{
    /** @var list<NprWorkflowEvent> */
    public array $events = [];

    public function record(NprWorkflowEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (NprWorkflowEvent $event): string => $event->name, $this->events);
    }
}
