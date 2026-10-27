<?php

declare(strict_types=1);

namespace TriCab\Core\CableRoutes\Application\Query;

final readonly class MachineItemsFilter
{
    public function __construct(
        public ?string $code,
        public int $page,
        public int $pageSize,
    ) {
    }
}
