<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;


/**
 * `GET /api/v1/rfqs` — contract `rfq.queue.v1`.
 *
 * `total` counts the RFQs under the filters, computed on the server rather
 * than from one page of rows. `branches` are the branch codes RFQs carry, for
 * the branch filter; RFQs with none are filtered as `none`. `assignees` are
 * the people RFQs are assigned to (`{userId, name}`), for the assignee filter;
 * unassigned RFQs are filtered as `none`. Queue KPIs are
 * **not** in this contract: they join as a statistics endpoint in Phase 4.5.
 */
final class RfqQueuePage
{
    /**
     * @param list<RfqSummary> $items
     * @param list<string>     $branches
     * @param list<array{userId: int, name: string}> $assignees
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $branches,
        public readonly array $assignees = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (RfqSummary $s): array => $s->toArray(), $this->items),
            'total' => $this->total,
            'page' => $this->page,
            'pageSize' => $this->pageSize,
            'pages' => max(1, (int) ceil($this->total / max(1, $this->pageSize))),
            'branches' => $this->branches,
            'assignees' => $this->assignees,
        ];
    }
}
