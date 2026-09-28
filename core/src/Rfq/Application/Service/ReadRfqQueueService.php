<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use LogicException;
use TriCab\Core\Rfq\Application\DTO\RfqDetail;
use TriCab\Core\Rfq\Application\DTO\RfqQueuePage;
use TriCab\Core\Rfq\Application\DTO\RfqQueueQuery;
use TriCab\Core\Rfq\Application\DTO\RfqSummary;
use TriCab\Core\Rfq\Contract\RfqRepository;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;

/**
 * The queue and one RFQ, scoped to the actor.
 *
 * "Not visible" and "does not exist" are the same answer — null, and a 404 at
 * the endpoint — so a caller cannot learn another branch's RFQ exists by
 * guessing its id (source `test_dashboard_api::…cross_branch_concealment`).
 */
final class ReadRfqQueueService
{
    /** A v4-shaped UUID, lower-case. Anything else is not an RFQ id. */
    private const PUBLIC_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly RfqRepository $rfqs,
    ) {
    }

    public function page(RfqActor $actor, RfqQueueQuery $query): RfqQueuePage
    {
        $result = $this->rfqs->pageVisibleTo($actor, $query);

        $items = [];
        foreach ($result['items'] as $record) {
            // Defence in depth, and loud on purpose: a repository whose SQL
            // disagrees with the policy is a scope leak, and a 500 in a test
            // is how it should be found — not a quietly shortened page.
            if (!RfqAccessPolicy::canSee($actor, $record->ownership)) {
                throw new LogicException('RFQ repository returned a record outside the actor\'s scope.');
            }
            $items[] = new RfqSummary($record, RfqAccessPolicy::allowedActions($actor, $record->ownership));
        }

        return new RfqQueuePage(
            $items,
            $result['total'],
            $query->page,
            $query->pageSize,
            RfqAccessPolicy::effectiveScope($actor),
        );
    }

    public function detail(RfqActor $actor, string $publicId): ?RfqDetail
    {
        $publicId = strtolower(trim($publicId));
        if (preg_match(self::PUBLIC_ID_PATTERN, $publicId) !== 1) {
            return null;
        }

        $record = $this->rfqs->findRecord($publicId);
        if ($record === null || !RfqAccessPolicy::canSee($actor, $record->ownership)) {
            return null;
        }

        return $this->rfqs->findDetail($record, RfqAccessPolicy::allowedActions($actor, $record->ownership));
    }
}
