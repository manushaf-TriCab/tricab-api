<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO\Analytics;

/** One row of the workload table while it is being counted. */
final class WorkloadTally
{
    public int $total = 0;
    public int $open = 0;
    public int $ready = 0;
    public int $overdue = 0;
    public int $sent = 0;

    /** @var list<float> */
    public array $turnaround = [];

    /**
     * @param 'person'|'unassigned'|'unmatched' $kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $userId,
        public readonly string $name,
    ) {
    }
}
