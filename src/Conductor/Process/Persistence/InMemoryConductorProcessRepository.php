<?php

declare(strict_types=1);

namespace TriCab\Api\Conductor\Process\Persistence;

use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessStoreUnavailable;
use TriCab\Core\Conductor\Process\Contract\ConductorProcessRepository;

/**
 * Holds the store's tables as typed rows and answers the same queries the SQLite repository does, for tests of the
 * query service and of anything above it. A store that is not "available" answers every call as a missing file does.
 *
 * @phpstan-import-type RunRecord from ConductorProcessRepository
 * @phpstan-import-type MachineRecord from ConductorProcessRepository
 * @phpstan-import-type MachineOperationRecord from ConductorProcessRepository
 * @phpstan-import-type ItemRecord from ConductorProcessRepository
 * @phpstan-import-type OperationRecord from ConductorProcessRepository
 * @phpstan-import-type InputRecord from ConductorProcessRepository
 * @phpstan-import-type AltMachineRecord from ConductorProcessRepository
 * @phpstan-import-type MaterialRecord from ConductorProcessRepository
 * @phpstan-import-type NeedRecord from ConductorProcessRepository
 */
final class InMemoryConductorProcessRepository implements ConductorProcessRepository
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param RunRecord|null $run
     * @param list<MachineRecord> $machines in seq order
     * @param list<MachineOperationRecord> $machineOperations
     * @param list<ItemRecord> $items
     * @param list<OperationRecord> $operations
     * @param list<InputRecord> $inputs
     * @param list<AltMachineRecord> $altMachines
     * @param list<MaterialRecord> $materials
     * @param list<NeedRecord> $needs
     */
    public function __construct(
        private readonly ?array $run = null,
        private readonly array $machines = [],
        private readonly array $machineOperations = [],
        private readonly array $items = [],
        private readonly array $operations = [],
        private readonly array $inputs = [],
        private readonly array $altMachines = [],
        private readonly array $materials = [],
        private readonly array $needs = [],
        private readonly bool $available = true,
    ) {
    }

    public function run(): ?array
    {
        $this->call('run');

        return $this->run;
    }

    public function machines(): array
    {
        $this->call('machines');

        return $this->machines;
    }

    public function machineOperations(string $machine): array
    {
        $this->call('machineOperations');

        return array_values(array_filter($this->machineOperations, static fn (array $r): bool => $r['machine'] === $machine));
    }

    public function items(): array
    {
        $this->call('items');

        return $this->items;
    }

    public function findItem(string $item): ?array
    {
        $this->call('findItem');
        foreach ($this->items as $r) {
            if (strcasecmp($r['item'], $item) === 0) {
                return $r;
            }
        }

        return null;
    }

    public function itemsNamed(array $items): array
    {
        $this->call('itemsNamed');

        return array_values(array_filter($this->items, static fn (array $r): bool => in_array($r['item'], $items, true)));
    }

    public function itemsRoutedOn(string $machine): array
    {
        $this->call('itemsRoutedOn');
        $names = [];
        foreach ($this->operations as $op) {
            if ($op['machine'] === $machine) {
                $names[] = $op['item'];
            }
        }

        return array_values(array_filter($this->items, static fn (array $r): bool => in_array($r['item'], $names, true)));
    }

    public function altMachinesOn(string $machine): array
    {
        $this->call('altMachinesOn');

        return array_values(array_filter($this->altMachines, static fn (array $r): bool => $r['machine'] === $machine));
    }

    public function needs(string $root): array
    {
        $this->call('needs');

        return array_values(array_filter($this->needs, static fn (array $r): bool => $r['root'] === $root));
    }

    public function operations(array $items): array
    {
        $this->call('operations');

        return array_values(array_filter($this->operations, static fn (array $r): bool => in_array($r['item'], $items, true)));
    }

    public function inputs(array $items): array
    {
        $this->call('inputs');

        return array_values(array_filter($this->inputs, static fn (array $r): bool => in_array($r['item'], $items, true)));
    }

    public function altMachines(array $items): array
    {
        $this->call('altMachines');

        return array_values(array_filter($this->altMachines, static fn (array $r): bool => in_array($r['item'], $items, true)));
    }

    public function materials(array $items): array
    {
        $this->call('materials');

        return array_values(array_filter($this->materials, static fn (array $r): bool => in_array($r['item'], $items, true)));
    }

    public function inputsConsuming(string $item): array
    {
        $this->call('inputsConsuming');

        return array_values(array_filter($this->inputs, static fn (array $r): bool => $r['inputItem'] === $item));
    }

    private function call(string $name): void
    {
        $this->calls[] = $name;
        if (!$this->available) {
            throw new ConductorProcessStoreUnavailable('The store is not available.');
        }
    }
}
