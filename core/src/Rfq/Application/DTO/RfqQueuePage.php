<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use TriCab\Core\Rfq\Domain\ValueObject\RfqScope;

/**
 * `GET /api/v1/rfqs` — contract `rfq.queue.v1`.
 *
 * `total` counts what the caller may see under the filters, computed on the
 * server; the scaffold counted the whole list in the browser, which is how a
 * cross-branch total leaks (invariant 1). Queue KPIs are **not** in this
 * contract: they join as a scoped statistics endpoint in Phase 4.5 rather
 * than being derived from one page of rows.
 */
final class RfqQueuePage
{
    /**
     * @param list<RfqSummary> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly RfqScope $scope,
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
            'scope' => $this->scope->toString(),
        ];
    }
}
