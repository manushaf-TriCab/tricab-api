<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Schedule\Application\Query;

/** A validated filter for the issue list. Null means "not given". */
final readonly class IssuesFilter
{
    public function __construct(
        public ?string $kind,
        public ?string $severity,
    ) {
    }
}
