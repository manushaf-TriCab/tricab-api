<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;

/** Tri-AP's audit events held in memory: the tests', and a database-less run's. */
final class InMemoryApAuditStore implements ApAuditStore
{
    /** @var list<ApAuditEvent> */
    private array $events = [];

    public function record(ApAuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function forEntity(string $entityType, string $entityId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (ApAuditEvent $event): bool => $event->entityType === $entityType && $event->entityId === $entityId,
        ));
    }

    /** @return list<ApAuditEvent> every event, oldest first */
    public function all(): array
    {
        return $this->events;
    }
}
