<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Audit;

use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;

/** The audit log held in memory: the tests', and a database-less run's. */
final class InMemoryFinanceAuditLog implements FinanceAuditLog
{
    /** @var list<FinanceAuditEvent> */
    public array $events = [];

    public function record(FinanceAuditEvent $event): void
    {
        $this->events[] = $event;
    }
}
