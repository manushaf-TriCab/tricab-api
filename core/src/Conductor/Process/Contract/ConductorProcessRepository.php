<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Process\Contract;

/**
 * Read access to the conductor process store (core/python/conductor_process/schema.sql). Unlike Cable routes, the
 * repository only hands back typed table rows (camelCase keys, booleans as bool); every rule that shapes an endpoint
 * payload (docs/conductor/CONTRACT.md, section 5) lives in ConductorProcessQueryService, so the SQLite and in-memory
 * repositories cannot drift apart on behaviour.
 *
 * Every method may throw ConductorProcessStoreUnavailable. Item and machine names passed in are exact store values.
 *
 * @phpstan-type RunRecord array{builtAt: string, dataAsOf: string, windowFrom: string, sourceName: string, schemaVersion: int, engineVersion: string, items: int, finishedItems: int, tracedToRod: int, excludedOffMap: int}
 * @phpstan-type MachineRecord array{machine: string, d365Id: ?string, seq: int, stageNo: int, stage: string, posterStatus: ?string, brand: ?string, machineType: ?string, inputLimit: ?string, outputCapability: ?string, payOff: ?string, takeUp: ?string, speedText: ?string, note: ?string, items: int, ordersTotal: int, ordersCancelled: int, orders52w: int}
 * @phpstan-type MachineOperationRecord array{machine: string, operation: string, orders: int}
 * @phpstan-type ItemRecord array{item: string, pool: string, stage: string, prefix: string, material: string, mica: bool, form: string, ends: ?int, size: ?float, diameterMm: ?float, kgPerM: ?float, unit: string, orderNo: string, orderStatus: string, orderDate: string, realOrders: int, liveOrders: int, orders52w: int, runSize: ?float, tracesToRod: bool, stepCount: int}
 * @phpstan-type OperationRecord array{item: string, seq: int, operationNumber: int, operation: string, machine: string}
 * @phpstan-type InputRecord array{item: string, line: int, inputItem: string, qtyPerUnit: float, unit: string, parallelEnds: int, kind: string}
 * @phpstan-type AltMachineRecord array{item: string, operation: string, machine: string, orders: int}
 * @phpstan-type MaterialRecord array{item: string, name: ?string, unit: ?string, isRod: bool}
 * @phpstan-type NeedRecord array{root: string, item: string, unit: string, needPerUnit: float, depth: int, kind: string}
 */
interface ConductorProcessRepository
{
    /** @return RunRecord|null null when cd_run has no row */
    public function run(): ?array;

    /** @return list<MachineRecord> all map machines, in seq order */
    public function machines(): array;

    /** @return list<MachineOperationRecord> the operations Dynamics routes on one machine */
    public function machineOperations(string $machine): array;

    /** @return list<ItemRecord> every cd_item row (a few thousand at most), in no particular order */
    public function items(): array;

    /** @return ItemRecord|null the item whose number matches case-insensitively */
    public function findItem(string $item): ?array;

    /**
     * @param list<string> $items
     * @return list<ItemRecord>
     */
    public function itemsNamed(array $items): array;

    /** @return list<ItemRecord> items whose representative route has an operation on the machine */
    public function itemsRoutedOn(string $machine): array;

    /** @return list<AltMachineRecord> cd_alt_machine rows for the machine */
    public function altMachinesOn(string $machine): array;

    /** @return list<NeedRecord> the explosion of one item (cd_need rows with that root) */
    public function needs(string $root): array;

    /**
     * @param list<string> $items
     * @return list<OperationRecord>
     */
    public function operations(array $items): array;

    /**
     * @param list<string> $items
     * @return list<InputRecord>
     */
    public function inputs(array $items): array;

    /**
     * @param list<string> $items
     * @return list<AltMachineRecord>
     */
    public function altMachines(array $items): array;

    /**
     * @param list<string> $items
     * @return list<MaterialRecord>
     */
    public function materials(array $items): array;

    /** @return list<InputRecord> cd_input rows that consume the item (its direct parents) */
    public function inputsConsuming(string $item): array;
}
