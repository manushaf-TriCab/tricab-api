<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Conductor\Process;

use TriCab\Api\Conductor\Process\Persistence\InMemoryConductorProcessRepository;
use TriCab\Core\Conductor\Process\Contract\ConductorProcessRepository;

/**
 * A small conductor process store as typed rows: rod -> NX-1W1.6 (RB2) -> NX-16W.3 (WD4) -> NX-32W.3 (BU6) -> NX-1C16
 * (BU1) -> NM-1C16 (TP2), a tinned branch DX-1W.5 (WD5 then TN1) -> DX-1C2.5 (BU2), an odd wire NX-WEIRD on WD3 that
 * does not parse, and VX-1C35 on ST1 made from purchased wire (not traced to rod). The same rows feed the in-memory
 * repository (service tests) and the SQLite fixture store (repository and HTTP tests), so both are held to one answer.
 * cd_need is computed here by summing every BOM path, as cable_routes.explode does, so it always agrees with cd_input.
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
final class FixtureData
{
    public static function inMemory(bool $withRun = true): InMemoryConductorProcessRepository
    {
        return new InMemoryConductorProcessRepository(
            $withRun ? self::run() : null,
            self::machines(),
            self::machineOperations(),
            self::items(),
            self::operations(),
            self::inputs(),
            self::altMachines(),
            self::materials(),
            self::needs(),
        );
    }

    /** @return RunRecord */
    public static function run(): array
    {
        return [
            'builtAt' => '2026-10-28T01:02:03Z', 'dataAsOf' => '2026-10-27', 'windowFrom' => '2025-10-28',
            'sourceName' => 'snapshot-2026-10-28', 'schemaVersion' => 1, 'engineVersion' => '1.0.0',
            'items' => 9, 'finishedItems' => 4, 'tracedToRod' => 8, 'excludedOffMap' => 2,
        ];
    }

    /** @return list<MachineRecord> */
    public static function machines(): array
    {
        $rows = [
            // [machine, d365, stageNo, note, items, total, cancelled, 52w]
            ['RB2', 'RB1', 1, 'D365 calls it RB1', 1, 480, 7, 300],
            ['WD3', 'WD3', 2, null, 1, 3, 0, 1],
            ['WD4', 'WD4', 2, null, 1, 130, 10, 90],
            ['TN1', 'TL1', 2, 'running · D365 TL1', 1, 60, 2, 40],
            ['WD5', 'WD5', 2, 'running in D365', 1, 60, 2, 40],
            ['BU1', 'BU1', 3, null, 1, 391, 0, 250],
            ['BU2', 'BU2', 3, null, 1, 91, 0, 60],
            ['BU6', 'BU6', 3, null, 1, 200, 10, 150],
            ['BU7', 'BU7', 3, '91% of D365 jobs cancelled', 0, 11, 10, 1],
            ['BU5', 'BU5', 3, null, 0, 0, 0, 0],
            ['BU4', 'BU4', 3, null, 0, 0, 0, 0],
            ['TP3', null, 4, 'not in D365', 0, 0, 0, 0],
            ['TP1', 'TP1', 4, null, 0, 0, 0, 0],
            ['TP2', 'TP2', 4, null, 1, 30, 5, 20],
            ['ST1', 'ST1', 4, null, 1, 5, 0, 3],
        ];
        $stages = [1 => 'Rod breaking', 2 => 'Drawing & tinning', 3 => 'Bunching', 4 => 'Stranding & mica taping'];
        $out = [];
        foreach ($rows as $i => [$machine, $d365, $stageNo, $note, $items, $total, $cancelled, $w52]) {
            $spec = $machine === 'RB2'
                ? ['brand' => 'NIEHOFF', 'machineType' => 'Rod breaker', 'inputLimit' => '8 mm soft Cu rod', 'outputCapability' => '1.2 to 2.5 mm wire', 'speedText' => '29 m/s']
                : ['brand' => null, 'machineType' => null, 'inputLimit' => null, 'outputCapability' => null, 'speedText' => null];
            $out[] = [
                'machine' => $machine, 'd365Id' => $d365, 'seq' => $i + 1, 'stageNo' => $stageNo, 'stage' => $stages[$stageNo],
                'posterStatus' => $machine === 'TP3' ? 'new' : 'existing',
                'brand' => $spec['brand'], 'machineType' => $spec['machineType'], 'inputLimit' => $spec['inputLimit'],
                'outputCapability' => $spec['outputCapability'], 'payOff' => null, 'takeUp' => null, 'speedText' => $spec['speedText'],
                'note' => $note, 'items' => $items, 'ordersTotal' => $total, 'ordersCancelled' => $cancelled, 'orders52w' => $w52,
            ];
        }

        return $out;
    }

    /** @return list<MachineOperationRecord> */
    public static function machineOperations(): array
    {
        return [
            ['machine' => 'RB2', 'operation' => 'ROD_BREAK', 'orders' => 473],
            ['machine' => 'TN1', 'operation' => 'WIRE_DRAW', 'orders' => 2],
            ['machine' => 'TN1', 'operation' => 'TINNING', 'orders' => 56],
            ['machine' => 'TN1', 'operation' => 'ANNEAL', 'orders' => 56],
            ['machine' => 'BU6', 'operation' => 'BUNCHING', 'orders' => 190],
        ];
    }

    /** @return list<ItemRecord> */
    public static function items(): array
    {
        $rows = [
            // [item, pool, stage, prefix, material, mica, form, ends, size, kg/m, real, live, 52w, runSize, traces, steps]
            ['NX-1W1.6', '15_WIR', 'wire', 'NX', 'plain', false, 'wires', 1, 1.6, 0.01804, 473, 473, 300, 5000.0, true, 1],
            ['NX-16W.3', '15_WIR', 'wire', 'NX', 'plain', false, 'wires', 16, 0.3, null, 120, 110, 80, 12000.0, true, 2],
            ['DX-1W.5', '15_WIR', 'wire', 'DX', 'tinned', false, 'wires', 1, 0.5, 0.0018, 60, 58, 40, null, true, 2],
            ['NX-WEIRD', '15_WIR', 'wire', 'NX', 'plain', false, 'other', null, null, null, 2, 1, 0, null, true, 2],
            ['NX-32W.3', '20_BUN', 'bunch', 'NX', 'plain', false, 'wires', 32, 0.3, 0.02, 200, 190, 150, 6000.0, true, 3],
            ['NX-1C16', '25_FCON', 'conductor', 'NX', 'plain', false, 'core', 1, 16.0, 0.1361, 391, 391, 250, 2500.5, true, 4],
            ['DX-1C2.5', '25_FCON', 'conductor', 'DX', 'tinned', false, 'core', 1, 2.5, 0.0225, 80, 80, 60, 3000.0, true, 3],
            ['VX-1C35', '25_FCON', 'conductor', 'VX', 'unknown', false, 'core', 1, 35.0, 0.3, 5, 5, 3, 500.0, false, 1],
            ['NM-1C16', '30_TAPE', 'mica_taped', 'NM', 'plain', true, 'core', 1, 16.0, 0.15, 30, 25, 20, 1000.0, true, 5],
        ];
        $out = [];
        foreach ($rows as $i => $r) {
            $out[] = [
                'item' => $r[0], 'pool' => $r[1], 'stage' => $r[2], 'prefix' => $r[3], 'material' => $r[4], 'mica' => $r[5],
                'form' => $r[6], 'ends' => $r[7], 'size' => $r[8], 'diameterMm' => $r[8] === null ? null : round(1.1 * $r[8], 3),
                'kgPerM' => $r[9], 'unit' => 'm', 'orderNo' => sprintf('W%07d', 49500 + $i), 'orderStatus' => 'Released',
                'orderDate' => '2026-10-2' . ($i % 8), 'realOrders' => $r[10], 'liveOrders' => $r[11], 'orders52w' => $r[12],
                'runSize' => $r[13], 'tracesToRod' => $r[14], 'stepCount' => $r[15],
            ];
        }

        return $out;
    }

    /** @return list<OperationRecord> */
    public static function operations(): array
    {
        $rows = [
            ['NX-1W1.6', 1, 10, 'ROD_BREAK', 'RB2'],
            ['NX-16W.3', 1, 10, 'WIRE_DRAW', 'WD4'],
            ['DX-1W.5', 1, 10, 'WIRE_DRAW', 'WD5'],
            ['DX-1W.5', 2, 20, 'TINNING', 'TN1'],
            ['NX-WEIRD', 1, 10, 'WIRE_DRAW', 'WD3'],
            ['NX-32W.3', 1, 10, 'BUNCHING', 'BU6'],
            ['NX-1C16', 1, 10, 'BUNCHING', 'BU1'],
            ['DX-1C2.5', 1, 10, 'BUNCHING', 'BU2'],
            ['VX-1C35', 1, 10, 'ROPESTRAND', 'ST1'],
            ['NM-1C16', 1, 10, 'TAPING', 'TP2'],
        ];

        return array_values(array_map(
            static fn (array $r): array => ['item' => $r[0], 'seq' => $r[1], 'operationNumber' => $r[2], 'operation' => $r[3], 'machine' => $r[4]],
            $rows,
        ));
    }

    /** @return list<InputRecord> */
    public static function inputs(): array
    {
        $rows = [
            ['NX-1W1.6', 1, 'NX-1R8-NEX', 0.01804, 'kg', 1, 'material'],
            ['NX-16W.3', 1, 'NX-1W1.6', 0.5625, 'm', 1, 'made'],
            ['DX-1W.5', 10, 'NX-1W1.6', 0.1, 'm', 1, 'made'],
            ['DX-1W.5', 20, 'TIN_ANODE', 0.0001, 'kg', 1, 'material'],
            ['NX-WEIRD', 1, 'NX-1W1.6', 1.0, 'm', 1, 'made'],
            ['NX-32W.3', 1, 'NX-16W.3', 2.0, 'm', 2, 'made'],
            ['NX-1C16', 1, 'NX-32W.3', 7.02611, 'm', 7, 'made'],
            ['DX-1C2.5', 1, 'DX-1W.5', 13.0, 'm', 13, 'made'],
            ['VX-1C35', 1, 'VX-WIRE', 19.5, 'm', 19, 'material'],
            ['NM-1C16', 2, 'MICA_TAPE', 0.005, 'kg', 1, 'material'],
            ['NM-1C16', 1, 'NX-1C16', 1.0, 'm', 1, 'made'],
        ];

        return array_values(array_map(
            static fn (array $r): array => ['item' => $r[0], 'line' => $r[1], 'inputItem' => $r[2], 'qtyPerUnit' => $r[3], 'unit' => $r[4], 'parallelEnds' => $r[5], 'kind' => $r[6]],
            $rows,
        ));
    }

    /** @return list<AltMachineRecord> */
    public static function altMachines(): array
    {
        $rows = [
            ['NX-1W1.6', 'ROD_BREAK', 'RB2', 473],
            ['NX-16W.3', 'WIRE_DRAW', 'WD4', 100],
            ['NX-16W.3', 'WIRE_DRAW', 'WD3', 3],
            ['NX-16W.3', 'WIRE_DRAW', 'WD5', 7],
            ['DX-1W.5', 'WIRE_DRAW', 'WD5', 58],
            ['DX-1W.5', 'TINNING', 'TN1', 58],
            ['NX-32W.3', 'BUNCHING', 'BU6', 188],
            ['NX-32W.3', 'BUNCHING', 'BU7', 2],
            ['NX-1C16', 'BUNCHING', 'BU1', 380],
            ['NX-1C16', 'BUNCHING', 'BU2', 11],
            ['DX-1C2.5', 'BUNCHING', 'BU2', 80],
            ['NM-1C16', 'TAPING', 'TP2', 25],
        ];

        return array_map(static fn (array $r): array => ['item' => $r[0], 'operation' => $r[1], 'machine' => $r[2], 'orders' => $r[3]], $rows);
    }

    /** @return list<MaterialRecord> */
    public static function materials(): array
    {
        return [
            ['item' => 'NX-1R8-NEX', 'name' => 'Rod PCu 8mm plain annealed', 'unit' => 'kg', 'isRod' => true],
            ['item' => 'TIN_ANODE', 'name' => 'Tin anode', 'unit' => 'kg', 'isRod' => false],
            ['item' => 'MICA_TAPE', 'name' => null, 'unit' => 'kg', 'isRod' => false],
            ['item' => 'VX-WIRE', 'name' => 'Purchased wire', 'unit' => 'm', 'isRod' => false],
        ];
    }

    /** @return list<NeedRecord> */
    public static function needs(): array
    {
        $graph = [];
        foreach (self::inputs() as $in) {
            $graph[$in['item']][] = $in;
        }
        $made = array_column(self::items(), 'unit', 'item');

        $out = [];
        foreach (self::items() as $root) {
            $acc = [$root['item'] => ['unit' => $root['unit'], 'need' => 1.0, 'depth' => 0]];
            self::walk($graph, $root['item'], 1.0, 0, $acc);
            foreach ($acc as $item => $a) {
                $item = (string) $item;
                $out[] = [
                    'root' => $root['item'], 'item' => $item, 'unit' => $a['unit'], 'needPerUnit' => $a['need'], 'depth' => $a['depth'],
                    'kind' => $item === $root['item'] ? 'root' : (isset($made[$item]) ? 'made' : 'material'),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, list<InputRecord>> $graph
     * @param array<string, array{unit: string, need: float, depth: int}> $acc
     */
    private static function walk(array $graph, string $item, float $need, int $depth, array &$acc): void
    {
        foreach ($graph[$item] ?? [] as $in) {
            $key = $in['inputItem'];
            $prev = $acc[$key] ?? ['unit' => $in['unit'], 'need' => 0.0, 'depth' => 0];
            $acc[$key] = ['unit' => $in['unit'], 'need' => $prev['need'] + $need * $in['qtyPerUnit'], 'depth' => max($prev['depth'], $depth + 1)];
            self::walk($graph, $key, $need * $in['qtyPerUnit'], $depth + 1, $acc);
        }
    }
}
