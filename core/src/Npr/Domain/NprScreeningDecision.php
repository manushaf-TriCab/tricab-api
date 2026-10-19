<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

final readonly class NprScreeningDecision
{
    public function __construct(
        public string $outcome,
        public string $rationale,
        public string $conditions,
    ) {
    }
}
