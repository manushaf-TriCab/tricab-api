<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr\Support;

use TriCab\Core\Npr\Contract\NprEventSink;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;

/** Remembers each event and passes it on to the real sink (when there is one), as the repository does. */
final class RecordingNprEventSink implements NprEventSink
{
    /** @var list<NprWorkflowEvent> */
    public array $events = [];

    public function __construct(private readonly ?NprEventSink $inner = null)
    {
    }

    public function record(NprWorkflowEvent $event, NprRequest $saved, int $activityId, int $requestId): void
    {
        $this->inner?->record($event, $saved, $activityId, $requestId);
        $this->events[] = $event;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (NprWorkflowEvent $event): string => $event->name, $this->events);
    }
}
