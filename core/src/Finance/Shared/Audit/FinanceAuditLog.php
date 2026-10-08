<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Audit;

/** Where finance records what happened. Append-only. */
interface FinanceAuditLog
{
    public function record(FinanceAuditEvent $event): void;
}
