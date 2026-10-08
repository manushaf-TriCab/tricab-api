<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

use DateTimeImmutable;

/**
 * What `GET /records` asks of the store, already validated: filters, the local-day bounds as UTC
 * instants (`start` inclusive, `end` exclusive), the search, a sort key from {@see SORTS}, and a
 * page. `today` is the date "overdue" is judged against (the Tri-AR zone's today).
 */
final readonly class ArRecordQuery
{
    /** The sort keys the register accepts (question Q9): bounded columns only, `final_outcome` is not one. */
    public const SORTS = [
        'email_date', 'customer_name', 'sender_email', 'subject', 'follow_up_category', 'assigned_person',
        'proposed_response_type', 'follow_up_status', 'due_date', 'classification_confidence', 'status',
        'created_date', 'deleted_at',
    ];

    public const MAX_LIMIT = 1000;
    public const DEFAULT_LIMIT = 100;

    public function __construct(
        public bool $deleted,
        public ?string $category,
        public ?string $followUpStatus,
        public ?string $status,
        public ?string $customerName,
        public ?string $assignedPerson,
        public ?DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public ?string $search,
        public bool $searchBody,
        public string $sortBy,
        public bool $descending,
        public int $limit,
        public int $offset,
        public string $today,
    ) {
    }
}
