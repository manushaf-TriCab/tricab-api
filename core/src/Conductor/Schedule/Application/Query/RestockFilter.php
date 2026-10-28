<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Schedule\Application\Query;

/** A validated filter for the restock list. Null means "not given". */
final readonly class RestockFilter
{
    public function __construct(
        public ?string $status,
        public ?string $material,
        public ?bool $scheduled,
    ) {
    }
}
