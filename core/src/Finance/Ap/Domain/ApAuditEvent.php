<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.invoice_audit_events`: what a person did to an invoice, an email or an approval batch (the source's
 * `audit_log`). The thirteen actions are listed in T6.4 B6; `details` is a JSON object of facts.
 */
final readonly class ApAuditEvent
{
    public const ENTITY_INVOICE = 'invoice';
    public const ENTITY_EMAIL = 'email';
    public const ENTITY_APPROVAL_BATCH = 'approval_batch';

    public const DUPLICATE_OVERRIDE = 'duplicate_override';
    public const INVOICE_BINNED = 'invoice_binned';
    public const INVOICE_RESTORED = 'invoice_restored';
    public const INVOICE_DELETED_PERMANENTLY = 'invoice_deleted_permanently';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $action,
        public string $entityType,
        public string $entityId,
        public array $details,
        public DateTimeImmutable $at,
        public ?int $actorUserId = null,
        public string $actorName = '',
    ) {
    }
}
