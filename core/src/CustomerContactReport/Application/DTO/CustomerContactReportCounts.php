<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

final readonly class CustomerContactReportCounts
{
    public function __construct(
        public int $submitted,
        public int $assigned,
        public int $completed,
        public int $closed,
    ) {
    }

    public static function zero(): self
    {
        return new self(0, 0, 0, 0);
    }
}
