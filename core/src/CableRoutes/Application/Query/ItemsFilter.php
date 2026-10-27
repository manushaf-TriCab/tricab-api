<?php

declare(strict_types=1);

namespace TriCab\Core\CableRoutes\Application\Query;

/**
 * A validated filter for the finished-item list. Null means "not given".
 */
final readonly class ItemsFilter
{
    public function __construct(
        public ?string $code,
        public ?string $construction,
        public ?string $q,
        public ?string $traceStatus,
        public int $page,
        public int $pageSize,
    ) {
    }
}
