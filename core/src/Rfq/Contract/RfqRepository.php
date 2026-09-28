<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\RfqDetail;
use TriCab\Core\Rfq\Application\DTO\RfqQueueQuery;
use TriCab\Core\Rfq\Application\DTO\RfqRecord;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;

/**
 * Reads RFQs. Writes arrive with the Phase 2 schema, behind their own methods
 * with revision checks.
 *
 * **Implementations apply the scope rule themselves**, in the query, exactly as
 * `RfqAccessPolicy::canSee()` states it: a page and its total must be computed
 * over visible rows only, or a count leaks what the rows hide. The service
 * re-checks every returned record against the policy and fails loudly on a
 * mismatch, so a repository that gets this wrong breaks a test rather than
 * quietly widening a queue.
 */
interface RfqRepository
{
    /**
     * @return array{items: list<RfqRecord>, total: int}
     */
    public function pageVisibleTo(RfqActor $actor, RfqQueueQuery $query): array;

    /**
     * The RFQ, whether or not the actor may see it — the service decides, so
     * that "not yours" and "does not exist" produce the same 404.
     */
    public function findRecord(string $publicId): ?RfqRecord;

    /**
     * Header, lines, documents, reads and attempts for a record the service
     * has already found visible. `$allowedActions` is the policy's answer for
     * that record, carried into the embedded summary.
     *
     * @param list<string> $allowedActions
     */
    public function findDetail(RfqRecord $record, array $allowedActions): RfqDetail;
}
