<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Schedule\Application\Query;

/** A validated filter for the operation list. {@see $machine} is the store's exact D365 id. */
final readonly class OpsFilter
{
    public function __construct(
        public ?string $machine,
        public ?bool $late,
        public ?string $item,
        public int $page,
        public int $pageSize,
    ) {
    }
}
