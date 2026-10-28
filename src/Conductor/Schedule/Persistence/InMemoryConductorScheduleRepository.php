<?php

declare(strict_types=1);

namespace TriCab\Api\Conductor\Schedule\Persistence;

use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleStoreUnavailable;
use TriCab\Core\Conductor\Schedule\Contract\ConductorScheduleRepository;

/**
 * Holds the store's tables as typed rows and answers the same queries the SQLite repository does, for tests of the
 * query service and of anything above it. A store that is not "available" answers every call as a missing file does.
 *
 * @phpstan-import-type RunRecord from ConductorScheduleRepository
 * @phpstan-import-type SourceRecord from ConductorScheduleRepository
 * @phpstan-import-type SettingRecord from ConductorScheduleRepository
 * @phpstan-import-type R86Record from ConductorScheduleRepository
 * @phpstan-import-type DemandRecord from ConductorScheduleRepository
 * @phpstan-import-type RestockRecord from ConductorScheduleRepository
 * @phpstan-import-type JobRecord from ConductorScheduleRepository
 * @phpstan-import-type MachineRecord from ConductorScheduleRepository
 * @phpstan-import-type OpRecord from ConductorScheduleRepository
 * @phpstan-import-type PassRecord from ConductorScheduleRepository
 * @phpstan-import-type BatchRecord from ConductorScheduleRepository
 * @phpstan-import-type DrawRecord from ConductorScheduleRepository
 * @phpstan-import-type StockRecord from ConductorScheduleRepository
 * @phpstan-import-type CapacityRecord from ConductorScheduleRepository
 * @phpstan-import-type LoadRecord from ConductorScheduleRepository
 * @phpstan-import-type IssueRecord from ConductorScheduleRepository
 */
final class InMemoryConductorScheduleRepository implements ConductorScheduleRepository
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param RunRecord|null $run
     * @param list<SourceRecord> $sources
     * @param list<SettingRecord> $settings
     * @param list<R86Record> $r86
     * @param list<DemandRecord> $demand
     * @param list<RestockRecord> $restock
     * @param list<JobRecord> $jobs
     * @param list<MachineRecord> $machines
     * @param list<OpRecord> $ops
     * @param list<PassRecord> $passes
     * @param list<BatchRecord> $batches
     * @param list<DrawRecord> $draws
     * @param list<StockRecord> $stock
     * @param list<CapacityRecord> $capacity
     * @param list<LoadRecord> $load
     * @param list<IssueRecord> $issues
     */
    public function __construct(
        private readonly ?array $run = null,
        private readonly array $sources = [],
        private readonly array $settings = [],
        private readonly array $r86 = [],
        private readonly array $demand = [],
        private readonly array $restock = [],
        private readonly array $jobs = [],
        private readonly array $machines = [],
        private readonly array $ops = [],
        private readonly array $passes = [],
        private readonly array $batches = [],
        private readonly array $draws = [],
        private readonly array $stock = [],
        private readonly array $capacity = [],
        private readonly array $load = [],
        private readonly array $issues = [],
        private readonly bool $available = true,
    ) {
    }

    public function run(): ?array
    {
        $this->call('run');

        return $this->run;
    }

    public function sources(): array
    {
        $this->call('sources');

        return $this->sources;
    }

    public function settings(): array
    {
        $this->call('settings');

        return $this->settings;
    }

    public function r86(): array
    {
        $this->call('r86');

        return $this->r86;
    }

    public function demand(): array
    {
        $this->call('demand');

        return $this->demand;
    }

    public function restock(): array
    {
        $this->call('restock');

        return $this->restock;
    }

    public function jobs(): array
    {
        $this->call('jobs');

        return $this->jobs;
    }

    public function machines(): array
    {
        $this->call('machines');

        return $this->machines;
    }

    public function ops(): array
    {
        $this->call('ops');

        return $this->ops;
    }

    public function passes(): array
    {
        $this->call('passes');

        return $this->passes;
    }

    public function batches(): array
    {
        $this->call('batches');

        return $this->batches;
    }

    public function draws(): array
    {
        $this->call('draws');

        return $this->draws;
    }

    public function stock(): array
    {
        $this->call('stock');

        return $this->stock;
    }

    public function capacity(): array
    {
        $this->call('capacity');

        return $this->capacity;
    }

    public function load(): array
    {
        $this->call('load');

        return $this->load;
    }

    public function issues(): array
    {
        $this->call('issues');

        return $this->issues;
    }

    private function call(string $name): void
    {
        $this->calls[] = $name;
        if (!$this->available) {
            throw new ConductorScheduleStoreUnavailable('The store is not available.');
        }
    }
}
