<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Process\Application\Service;

use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessInvalidRequest;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessItemNotFound;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessMachineNotFound;
use TriCab\Core\Conductor\Process\Application\Query\ItemsFilter;
use TriCab\Core\Conductor\Process\Contract\ConductorProcessRepository;
use TriCab\Core\Conductor\Process\Vocabulary;

/**
 * Validates the request parameters (raw strings from the query string, null when absent or blank), reads table rows
 * from the repository and shapes each endpoint's camelCase payload by the rules of docs/conductor/CONTRACT.md section 5.
 *
 * @phpstan-import-type MachineRecord from ConductorProcessRepository
 * @phpstan-import-type ItemRecord from ConductorProcessRepository
 * @phpstan-import-type AltMachineRecord from ConductorProcessRepository
 */
final class ConductorProcessQueryService
{
    public function __construct(
        private readonly ConductorProcessRepository $repository,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $run = $this->repository->run();

        $byStage = array_fill_keys(Vocabulary::STAGES, 0);
        foreach ($this->repository->items() as $item) {
            if (isset($byStage[$item['stage']])) {
                $byStage[$item['stage']]++;
            }
        }
        $stages = [];
        foreach ($byStage as $stage => $count) {
            $stages[] = ['stage' => $stage, 'items' => $count];
        }

        return [
            'run' => $run === null ? null : [
                'builtAt' => $run['builtAt'],
                'dataAsOf' => $run['dataAsOf'],
                'windowFrom' => $run['windowFrom'],
                'sourceName' => $run['sourceName'],
                'schemaVersion' => $run['schemaVersion'],
                'engineVersion' => $run['engineVersion'],
            ],
            'counts' => [
                'items' => $run['items'] ?? 0,
                'finishedItems' => $run['finishedItems'] ?? 0,
                'tracedToRod' => $run['tracedToRod'] ?? 0,
                'excludedOffMap' => $run['excludedOffMap'] ?? 0,
            ],
            'stages' => $stages,
        ];
    }

    /** @return array<string, mixed> */
    public function machines(): array
    {
        return ['machines' => array_map(self::machineRow(...), $this->repository->machines())];
    }

    /** @return array<string, mixed> */
    public function machine(?string $machine): array
    {
        if ($machine === null || trim($machine) === '') {
            throw new ConductorProcessInvalidRequest('CONDUCTOR_MACHINE_REQUIRED', 'machine is required.');
        }
        $record = $this->findMachine(trim($machine));
        $name = $record['machine'];

        $operations = $this->repository->machineOperations($name);
        usort($operations, static fn (array $a, array $b): int => [$b['orders'], $a['operation']] <=> [$a['orders'], $b['operation']]);

        $items = [];
        $representative = [];
        foreach ($this->repository->itemsRoutedOn($name) as $item) {
            $representative[$item['item']] = true;
            $items[$item['item']] = self::machineItem($item, 'representative', $item['liveOrders']);
        }

        // An item can have the machine as an alternative for more than one operation: its orders are summed.
        $altOrders = [];
        foreach ($this->repository->altMachinesOn($name) as $alt) {
            if (!isset($representative[$alt['item']])) {
                $altOrders[$alt['item']] = ($altOrders[$alt['item']] ?? 0) + $alt['orders'];
            }
        }
        if ($altOrders !== []) {
            foreach ($this->repository->itemsNamed(array_keys($altOrders)) as $item) {
                $items[$item['item']] = self::machineItem($item, 'alternative', $altOrders[$item['item']] ?? 0);
            }
        }

        $items = array_values($items);
        usort($items, static fn (array $a, array $b): int => [self::stageRank($a['stage']), $a['item']] <=> [self::stageRank($b['stage']), $b['item']]);

        return [
            'machine' => self::machineRow($record),
            'operations' => array_map(
                static fn (array $op): array => ['operation' => $op['operation'], 'orders' => $op['orders']],
                $operations,
            ),
            'items' => array_slice($items, 0, Vocabulary::MACHINE_ITEMS_MAX),
        ];
    }

    /** @return array<string, mixed> */
    public function items(?string $q, ?string $stage, ?string $material, ?string $page, ?string $pageSize): array
    {
        $filter = new ItemsFilter(
            $q === null || trim($q) === '' ? null : trim($q),
            self::stages($stage),
            self::material($material),
            self::page($page),
            self::pageSize($pageSize),
        );

        $rows = array_values(array_filter(
            $this->repository->items(),
            static fn (array $item): bool => ($filter->stages === null || in_array($item['stage'], $filter->stages, true))
                && ($filter->material === null || $item['material'] === $filter->material)
                && ($filter->q === null || str_contains(strtolower($item['item']), strtolower($filter->q))),
        ));
        usort($rows, static fn (array $a, array $b): int => [self::stageRank($a['stage']), $a['size'] === null ? 1 : 0, $a['size'] ?? 0.0, $a['item']]
            <=> [self::stageRank($b['stage']), $b['size'] === null ? 1 : 0, $b['size'] ?? 0.0, $b['item']]);

        $total = count($rows);

        return [
            'items' => array_map(self::itemRow(...), array_slice($rows, ($filter->page - 1) * $filter->pageSize, $filter->pageSize)),
            'page' => $filter->page,
            'pageSize' => $filter->pageSize,
            'totalItems' => $total,
            'totalPages' => max(1, intdiv($total + $filter->pageSize - 1, $filter->pageSize)),
        ];
    }

    /** @return array<string, mixed> */
    public function item(?string $item, ?string $length): array
    {
        if ($item === null || trim($item) === '') {
            throw new ConductorProcessInvalidRequest('CONDUCTOR_ITEM_REQUIRED', 'item is required.');
        }
        $len = self::length($length);
        $record = $this->repository->findItem(trim($item)) ?? throw new ConductorProcessItemNotFound('Unknown item.');
        $root = $record['item'];

        $needs = $this->repository->needs($root);
        $made = [];
        $materialNeeds = [];
        foreach ($needs as $need) {
            if ($need['kind'] === 'material') {
                $materialNeeds[] = $need;
            } else {
                $made[] = $need;
            }
        }
        usort($made, static fn (array $a, array $b): int => [$b['depth'], $a['item'], $a['unit']] <=> [$a['depth'], $b['item'], $b['unit']]);

        $names = array_values(array_unique(array_column($made, 'item')));
        $itemsByName = [$root => $record];
        foreach ($this->repository->itemsNamed($names) as $r) {
            $itemsByName[$r['item']] = $r;
        }
        $machines = [];
        foreach ($this->repository->machines() as $m) {
            $machines[$m['machine']] = $m;
        }
        $operations = [];
        foreach ($this->repository->operations($names) as $op) {
            $operations[$op['item']][] = $op;
        }
        $inputs = [];
        foreach ($this->repository->inputs($names) as $in) {
            $inputs[$in['item']][] = $in;
        }
        $alts = [];
        foreach ($this->repository->altMachines($names) as $alt) {
            $alts[$alt['item']][$alt['operation']][] = $alt;
        }

        $steps = [];
        foreach ($made as $i => $need) {
            $node = $itemsByName[$need['item']] ?? null;
            $quantity = $need['needPerUnit'] * $len;
            $kgPerM = $node['kgPerM'] ?? null;
            $ops = $operations[$need['item']] ?? [];
            usort($ops, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
            $ins = $inputs[$need['item']] ?? [];
            usort($ins, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

            $steps[] = [
                'stepNo' => $i + 1,
                'item' => $need['item'],
                'stage' => $node['stage'] ?? null,
                'material' => $node['material'] ?? null,
                'form' => $node['form'] ?? null,
                'ends' => $node['ends'] ?? null,
                'size' => $node['size'] ?? null,
                'unit' => $need['unit'],
                'kgPerM' => $kgPerM,
                'depth' => $need['depth'],
                'quantity' => $quantity,
                'kg' => $need['unit'] === 'm' && $kgPerM !== null ? $quantity * $kgPerM : null,
                'operations' => array_map(
                    static fn (array $op): array => [
                        'seq' => $op['seq'],
                        'operation' => $op['operation'],
                        'machine' => $op['machine'],
                        'd365Id' => $machines[$op['machine']]['d365Id'] ?? null,
                        'stageNo' => $machines[$op['machine']]['stageNo'] ?? null,
                        'alternatives' => self::alternatives($alts[$op['item']][$op['operation']] ?? [], $op['machine']),
                    ],
                    $ops,
                ),
                'inputs' => array_map(
                    static fn (array $in): array => [
                        'item' => $in['inputItem'],
                        'kind' => $in['kind'],
                        'qtyPerUnit' => $in['qtyPerUnit'],
                        'unit' => $in['unit'],
                        'parallelEnds' => $in['parallelEnds'],
                        'quantity' => $in['qtyPerUnit'] * $quantity,
                    ],
                    $ins,
                ),
            ];
        }

        $materialInfo = [];
        foreach ($this->repository->materials(array_values(array_unique(array_column($materialNeeds, 'item')))) as $m) {
            $materialInfo[$m['item']] = $m;
        }
        $materials = array_map(
            static fn (array $need): array => [
                'item' => $need['item'],
                'name' => $materialInfo[$need['item']]['name'] ?? null,
                'unit' => $need['unit'],
                'isRod' => $materialInfo[$need['item']]['isRod'] ?? false,
                'quantity' => $need['needPerUnit'] * $len,
            ],
            $materialNeeds,
        );
        usort($materials, static fn (array $a, array $b): int => [$a['isRod'] ? 0 : 1, $a['item'], $a['unit']] <=> [$b['isRod'] ? 0 : 1, $b['item'], $b['unit']]);

        $parents = $this->repository->inputsConsuming($root);
        $parentStages = [];
        foreach ($this->repository->itemsNamed(array_values(array_unique(array_column($parents, 'item')))) as $p) {
            $parentStages[$p['item']] = $p['stage'];
        }
        $usedBy = [];
        foreach ($parents as $p) {
            if (isset($parentStages[$p['item']])) {
                $usedBy[] = ['item' => $p['item'], 'stage' => $parentStages[$p['item']], 'qtyPerUnit' => $p['qtyPerUnit'], 'unit' => $p['unit'], 'line' => $p['line']];
            }
        }
        usort($usedBy, static fn (array $a, array $b): int => [$a['item'], $a['line']] <=> [$b['item'], $b['line']]);

        return [
            'item' => self::itemRow($record) + [
                'diameterMm' => $record['diameterMm'],
                'orderNo' => $record['orderNo'],
                'orderStatus' => $record['orderStatus'],
                'orderDate' => $record['orderDate'],
                'realOrders' => $record['realOrders'],
                'liveOrders' => $record['liveOrders'],
                'stepCount' => $record['stepCount'],
            ],
            'length' => $len,
            'steps' => $steps,
            'materials' => $materials,
            'usedBy' => array_map(
                static fn (array $u): array => ['item' => $u['item'], 'stage' => $u['stage'], 'qtyPerUnit' => $u['qtyPerUnit'], 'unit' => $u['unit']],
                $usedBy,
            ),
        ];
    }

    /**
     * Poster name or D365 id, case-insensitively: RB1 finds RB2 and TL1 finds TN1.
     *
     * @return MachineRecord
     */
    private function findMachine(string $machine): array
    {
        $wanted = strtoupper($machine);
        $machines = $this->repository->machines();
        foreach ($machines as $m) {
            if (strtoupper($m['machine']) === $wanted) {
                return $m;
            }
        }
        foreach ($machines as $m) {
            if ($m['d365Id'] !== null && strtoupper($m['d365Id']) === $wanted) {
                return $m;
            }
        }

        throw new ConductorProcessMachineNotFound('Unknown machine.');
    }

    /**
     * @param MachineRecord $m
     * @return array<string, mixed>
     */
    private static function machineRow(array $m): array
    {
        return [
            'machine' => $m['machine'],
            'd365Id' => $m['d365Id'],
            'seq' => $m['seq'],
            'stageNo' => $m['stageNo'],
            'stage' => $m['stage'],
            'posterStatus' => $m['posterStatus'],
            'brand' => $m['brand'],
            'machineType' => $m['machineType'],
            'inputLimit' => $m['inputLimit'],
            'outputCapability' => $m['outputCapability'],
            'payOff' => $m['payOff'],
            'takeUp' => $m['takeUp'],
            'speedText' => $m['speedText'],
            'note' => $m['note'],
            'items' => $m['items'],
            'ordersTotal' => $m['ordersTotal'],
            'ordersCancelled' => $m['ordersCancelled'],
            'orders52w' => $m['orders52w'],
            'cancelledShare' => $m['ordersTotal'] > 0 ? (float) $m['ordersCancelled'] / $m['ordersTotal'] : null,
        ];
    }

    /**
     * @param ItemRecord $item
     * @return array{item: string, stage: string, material: string, size: ?float, form: string, role: string, orders: int}
     */
    private static function machineItem(array $item, string $role, int $orders): array
    {
        return [
            'item' => $item['item'],
            'stage' => $item['stage'],
            'material' => $item['material'],
            'size' => $item['size'],
            'form' => $item['form'],
            'role' => $role,
            'orders' => $orders,
        ];
    }

    /**
     * @param ItemRecord $item
     * @return array<string, mixed>
     */
    private static function itemRow(array $item): array
    {
        return [
            'item' => $item['item'],
            'pool' => $item['pool'],
            'stage' => $item['stage'],
            'prefix' => $item['prefix'],
            'material' => $item['material'],
            'mica' => $item['mica'],
            'form' => $item['form'],
            'ends' => $item['ends'],
            'size' => $item['size'],
            'kgPerM' => $item['kgPerM'],
            'unit' => $item['unit'],
            'orders52w' => $item['orders52w'],
            'runSize' => $item['runSize'],
            'tracesToRod' => $item['tracesToRod'],
        ];
    }

    /**
     * The machines that ran an operation for the item, without the step's own machine; most orders first.
     *
     * @param list<AltMachineRecord> $alts
     * @return list<array{machine: string, orders: int}>
     */
    private static function alternatives(array $alts, string $own): array
    {
        $out = [];
        foreach ($alts as $alt) {
            if ($alt['machine'] !== $own) {
                $out[] = ['machine' => $alt['machine'], 'orders' => $alt['orders']];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$b['orders'], $a['machine']] <=> [$a['orders'], $b['machine']]);

        return $out;
    }

    private static function stageRank(string $stage): int
    {
        $rank = array_search($stage, Vocabulary::STAGES, true);

        return $rank === false ? count(Vocabulary::STAGES) : $rank;
    }

    /** @return list<string>|null */
    private static function stages(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $stage = strtolower(trim($raw));
        if ($stage === Vocabulary::STAGE_FINISHED) {
            return Vocabulary::FINISHED_STAGES;
        }
        if (!in_array($stage, Vocabulary::STAGES, true)) {
            throw new ConductorProcessInvalidRequest('CONDUCTOR_PARAM_INVALID', 'stage must be wire, bunch, conductor, mica_taped or finished.');
        }

        return [$stage];
    }

    private static function material(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $material = strtolower(trim($raw));
        if (!in_array($material, Vocabulary::MATERIALS, true)) {
            throw new ConductorProcessInvalidRequest('CONDUCTOR_PARAM_INVALID', 'material must be plain, tinned or unknown.');
        }

        return $material;
    }

    private static function page(?string $raw): int
    {
        if ($raw === null) {
            return 1;
        }
        if (preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < 1) {
            throw new ConductorProcessInvalidRequest('CONDUCTOR_PARAM_INVALID', 'page must be a whole number of 1 or more.');
        }

        return (int) $raw;
    }

    private static function pageSize(?string $raw): int
    {
        if ($raw === null) {
            return Vocabulary::ITEMS_DEFAULT_PAGE_SIZE;
        }
        $max = Vocabulary::ITEMS_MAX_PAGE_SIZE;
        if (preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > $max) {
            throw new ConductorProcessInvalidRequest('CONDUCTOR_PARAM_INVALID', 'pageSize must be a whole number from 1 to ' . $max . '.');
        }

        return (int) $raw;
    }

    /** A plain decimal (no sign, no exponent) above 0 and at most 10,000,000; whole numbers come back as int. */
    private static function length(?string $raw): int|float
    {
        if ($raw === null) {
            return Vocabulary::DEFAULT_LENGTH;
        }
        if (preg_match('/^(\d{1,9}(\.\d*)?|\.\d+)$/', $raw) !== 1) {
            throw new ConductorProcessInvalidRequest('CONDUCTOR_PARAM_INVALID', 'length must be a number above 0 and at most 10,000,000.');
        }
        $value = (float) $raw;
        if ($value <= 0.0 || $value > Vocabulary::MAX_LENGTH) {
            throw new ConductorProcessInvalidRequest('CONDUCTOR_PARAM_INVALID', 'length must be a number above 0 and at most 10,000,000.');
        }

        return floor($value) === $value ? (int) $value : $value;
    }
}
