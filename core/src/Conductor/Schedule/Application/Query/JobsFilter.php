<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Schedule\Application\Query;

/** A validated filter for the job list. Null means "not given". */
final readonly class JobsFilter
{
    public function __construct(
        public ?string $status,
        public ?bool $late,
        public int $page,
        public int $pageSize,
    ) {
    }
}
