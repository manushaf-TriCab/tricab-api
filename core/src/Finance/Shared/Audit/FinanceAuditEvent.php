<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Audit;

use DateTimeImmutable;

/**
 * One row of `finance.audit_events`: who did what, to what, when. The details are
 * facts about the action (counts, a slot, a short error), never content.
 */
final readonly class FinanceAuditEvent
{
    public const APPLICATION_FINANCE = 'FINANCE';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $action,
        public string $entityType,
        public ?string $entityId,
        public array $details,
        public DateTimeImmutable $at,
        public ?int $actorUserId = null,
        public string $application = self::APPLICATION_FINANCE,
    ) {
    }
}
