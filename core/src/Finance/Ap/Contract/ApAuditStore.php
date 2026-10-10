<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;

/**
 * Tri-AP's audit trail (`finance.invoice_audit_events`). Append-only. It joins the transaction the invoice store opened on
 * the same connection, so an event and the change it describes commit together. Every later Tri-AP task that writes one of
 * the thirteen events (emails, vendor match, mail, approvals) records it here.
 */
interface ApAuditStore
{
    public function record(ApAuditEvent $event): void;

    /**
     * The events of one entity, oldest first.
     *
     * @return list<ApAuditEvent>
     */
    public function forEntity(string $entityType, string $entityId): array;
}
