<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\RfqAuditEvent;
use TriCab\Core\Rfq\Application\DTO\RfqDetail;
use TriCab\Core\Rfq\Application\DTO\RfqQueueQuery;
use TriCab\Core\Rfq\Application\DTO\RfqRecord;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;

/**
 * Reads RFQs for the queue and the detail. Writes are `RfqDraftStore`'s.
 *
 * Everyone who reads RFQs reads all of them (`RfqAccessPolicy::canSee()`), so
 * there is no scope to apply here; the filters in `RfqQueueQuery` only narrow.
 * The service still checks every returned record against the policy.
 */
interface RfqRepository
{
    /**
     * A page of RFQs under the query's filters, and the total under them. The
     * actor is for `mine` and for the permission check.
     *
     * @return array{items: list<RfqRecord>, total: int}
     */
    public function pageVisibleTo(RfqActor $actor, RfqQueueQuery $query): array;

    /**
     * The RFQ, whether or not the actor may see it — the service decides, so
     * that "not yours" and "does not exist" produce the same 404. Carries
     * `blockedReason` (queue pages do not: it costs a query each).
     */
    public function findRecord(string $publicId): ?RfqRecord;

    /**
     * The RFQ's activity trail, newest first. People are named, never
     * addressed: an email in an event's detail is replaced by the person's
     * display name. The service has already found the RFQ visible.
     *
     * @return list<RfqAuditEvent>
     */
    public function activity(string $publicId, int $limit): array;

    /**
     * The branch codes RFQs carry, upper-cased and sorted, for the queue's
     * branch filter.
     *
     * @return list<string>
     */
    public function branches(): array;

    /**
     * The people RFQs are assigned to, for the queue's assignee filter: each
     * platform user an assignee address maps to, by name, sorted by name.
     * Addresses that map to nobody are not listed (no email leaves the server).
     *
     * @return list<array{userId: int, name: string}>
     */
    public function assignees(): array;

    /**
     * Header, lines, documents, reads and attempts for a record the service
     * has already found visible. `$allowedActions` is the policy's answer for
     * that record, carried into the embedded summary.
     *
     * @param list<string> $allowedActions
     */
    public function findDetail(RfqRecord $record, array $allowedActions): RfqDetail;
}
