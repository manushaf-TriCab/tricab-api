<?php

declare(strict_types=1);

namespace TriCab\Core\CableRoutes\Application\Query;

final readonly class ChecksFilter
{
    public function __construct(
        public ?string $kind,
        public ?string $code,
        public int $page,
        public int $pageSize,
    ) {
    }
}
