<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

use DateTimeImmutable;

/**
 * What happened, once per committed transition: the seam N2.4 (email) hangs on.
 * Names are the events of npr-portal `config/notifications.json`.
 * `firstTsdHandoff` is true for the endorsement that first reaches TSD (the
 * only one Robby is copied on).
 */
final readonly class NprWorkflowEvent
{
    public const SALES_SUBMITTED = 'sales_submitted';
    public const SALES_RESUBMITTED = 'sales_resubmitted';
    public const MANAGER_ENDORSED = 'manager_endorsed';
    public const MANAGER_RETURNED = 'manager_returned';
    public const MANAGER_REJECTED = 'manager_rejected';
    public const TSD_ACKNOWLEDGED = 'tsd_acknowledged';
    public const TSD_ASSIGNED = 'tsd_assigned';
    public const TSD_RETURNED = 'tsd_returned';
    public const ASSESSMENT_SAVED = 'assessment_saved';
    public const SCREENING_REQUESTED = 'screening_requested';
    public const SCREENING_DECIDED = 'screening_decided';

    public function __construct(
        public string $name,
        public string $requestPublicId,
        public string $requestNumber,
        public int $revision,
        public NprPerson $actor,
        public ?RequestStatus $fromStatus,
        public RequestStatus $toStatus,
        public string $summary,
        public bool $firstTsdHandoff,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
