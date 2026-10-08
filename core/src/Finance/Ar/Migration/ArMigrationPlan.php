<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Domain\ArOption;

/** Everything a run would write, and the report of what was found while working it out. */
final readonly class ArMigrationPlan
{
    /**
     * @param list<ArMigrationUnit> $units
     * @param list<ArMigratedScanRun> $scanRuns
     * @param list<ArOption> $options
     * @param list<array{id: string, at: DateTimeImmutable}> $tombstones
     * @param array<string, DateTimeImmutable> $sourceWatermarks mailbox => the highest completed run's watermark in the source
     */
    public function __construct(
        public ArMigrationReport $report,
        public array $units,
        public array $scanRuns,
        public array $options,
        public array $tombstones,
        public array $sourceWatermarks,
    ) {
    }

    /** True when `--apply` would be refused. */
    public function blocked(): bool
    {
        return $this->report->blockers() !== [];
    }
}
