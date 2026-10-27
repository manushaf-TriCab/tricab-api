<?php

declare(strict_types=1);

namespace TriCab\Api\CableRoutes\Persistence;

use PDO;
use PDOException;
use TriCab\Api\Config\Settings;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesItemNotFound;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesMachineNotFound;
use TriCab\Core\CableRoutes\Application\Exception\CableRoutesStoreUnavailable;
use TriCab\Core\CableRoutes\Application\Query\ChecksFilter;
use TriCab\Core\CableRoutes\Application\Query\ItemsFilter;
use TriCab\Core\CableRoutes\Application\Query\MachineItemsFilter;
use TriCab\Core\CableRoutes\Contract\CableRoutesRepository;
use TriCab\Core\CableRoutes\Vocabulary;

/**
 * Reads the cable routes store (core/python/cable_routes/schema.sql) through a read-only PDO connection.
 * Nothing is opened until the first call, so a missing store costs nothing until someone asks for data.
 * The engine replaces the file in one rename, so a connection sees either the old store or the new one, whole.
 */
final class SqliteCableRoutesRepository implements CableRoutesRepository
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $file)
    {
    }

    public static function fromSettings(Settings $settings): self
    {
        $path = trim($settings->cableRoutesDbPath);
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            $path = ($home === false || $home === '' ? '' : rtrim($home, '/')) . substr($path, 1);
        }
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
        if (!$absolute) {
            $path = dirname(__DIR__, 3) . '/' . ltrim($path, '/\\');
        }

        return new self($path);
    }

    public function run(): array
    {
        $run = $this->row('SELECT * FROM cr_run WHERE run_id = 1');
        if ($run === null) {
            throw new CableRoutesStoreUnavailable('cr_run has no row.');
        }

        $byStatus = [];
        foreach ($this->rows('SELECT trace_status, COUNT(*) AS n FROM cr_finished GROUP BY trace_status') as $r) {
            $byStatus[self::s($r['trace_status'])] = self::i($r['n']);
        }
        $traceStatus = [];
        foreach (Vocabulary::TRACE_STATUSES as $status) {
            $traceStatus[] = ['status' => $status, 'count' => $byStatus[$status] ?? 0];
        }

        $byReason = [];
        foreach ($this->rows("SELECT reason, COUNT(*) AS n FROM cr_finished WHERE trace_status = 'untraceable' GROUP BY reason") as $r) {
            $byReason[self::s($r['reason'])] = self::i($r['n']);
        }
        $reasons = [];
        foreach (Vocabulary::UNTRACEABLE_REASONS as $reason) {
            $reasons[] = ['reason' => $reason, 'count' => $byReason[$reason] ?? 0];
        }

        return [
            'run' => [
                'builtAt' => self::s($run['built_at']),
                'dataAsOf' => self::s($run['data_as_of']),
                'sourceName' => self::s($run['source_name']),
                'schemaVersion' => self::i($run['schema_version']),
                'engineVersion' => self::s($run['engine_version']),
            ],
            'counts' => [
                'finishedItems' => self::i($run['finished_total']),
                'tracedItems' => self::i($run['finished_traced']),
                'madeItems' => self::i($run['made_items']),
                'parsedFit' => self::i($run['parsed_fit']),
                'parsedOther' => self::i($run['parsed_other']),
                'cycleEdgesCut' => self::i($run['cycle_edges_cut']),
                'checkRows' => self::i($run['check_rows']),
            ],
            'traceStatus' => $traceStatus,
            'untraceableReasons' => $reasons,
        ];
    }

    public function codes(): array
    {
        $ranges = [];
        foreach ($this->rows('SELECT code, range_name, description, voltage, insulation_jacket, conductor, max_temp_c FROM cr_range ORDER BY seq') as $r) {
            $ranges[self::s($r['code'])][] = [
                'range' => self::s($r['range_name']),
                'description' => self::sn($r['description']),
                'voltage' => self::sn($r['voltage']),
                'insulationJacket' => self::sn($r['insulation_jacket']),
                'conductor' => self::sn($r['conductor']),
                'maxTempC' => self::fNull($r['max_temp_c']),
            ];
        }

        $families = [];
        foreach ($this->rows('SELECT fc.code, fc.family FROM cr_family_code fc JOIN cr_family f ON f.family = fc.family ORDER BY f.seq, fc.family') as $r) {
            $families[self::s($r['code'])][] = self::s($r['family']);
        }

        $codes = [];
        foreach ($this->rows('SELECT code, description, in_poster, finished_items, traced_items FROM cr_code ORDER BY code') as $r) {
            $code = self::s($r['code']);
            $codes[] = [
                'code' => $code,
                'description' => self::sn($r['description']),
                'inPoster' => self::b($r['in_poster']),
                'ranges' => $ranges[$code] ?? [],
                'families' => $families[$code] ?? [],
                'finishedItems' => self::i($r['finished_items']),
                'tracedItems' => self::i($r['traced_items']),
            ];
        }

        $run = $this->row('SELECT parsed_fit, parsed_other FROM cr_run WHERE run_id = 1');
        if ($run === null) {
            throw new CableRoutesStoreUnavailable('cr_run has no row.');
        }

        return [
            'codes' => $codes,
            'parse' => ['fit' => self::i($run['parsed_fit']), 'other' => self::i($run['parsed_other'])],
        ];
    }

    public function items(ItemsFilter $filter): array
    {
        [$baseWhere, $baseParams] = self::itemsBase($filter);

        $constructionsWhere = $baseWhere;
        $facetConstructions = [];
        foreach ($this->rows(
            'SELECT construction, COUNT(*) AS n FROM cr_finished WHERE ' . $constructionsWhere
            . " GROUP BY construction ORDER BY (construction = 'other'), construction",
            $baseParams,
        ) as $r) {
            $facetConstructions[] = ['construction' => self::s($r['construction']), 'count' => self::i($r['n'])];
        }

        $statusCounts = [];
        foreach ($this->rows('SELECT trace_status, COUNT(*) AS n FROM cr_finished WHERE ' . $baseWhere . ' GROUP BY trace_status', $baseParams) as $r) {
            $statusCounts[self::s($r['trace_status'])] = self::i($r['n']);
        }
        $facetStatus = [];
        foreach (Vocabulary::TRACE_STATUSES as $status) {
            if (isset($statusCounts[$status])) {
                $facetStatus[] = ['traceStatus' => $status, 'count' => $statusCounts[$status]];
            }
        }

        $where = $baseWhere;
        $params = $baseParams;
        if ($filter->construction !== null) {
            $where .= ' AND UPPER(construction) = UPPER(?)';
            $params[] = $filter->construction;
        }
        if ($filter->traceStatus !== null) {
            $where .= ' AND trace_status = ?';
            $params[] = $filter->traceStatus;
        }

        $total = $this->count('SELECT COUNT(*) FROM cr_finished WHERE ' . $where, $params);
        $items = [];
        foreach ($this->rows(
            'SELECT item, code, construction, colour, diameter_mm, kg_per_m, unit, pool, trace_status, reason FROM cr_finished WHERE '
            . $where . ' ORDER BY item LIMIT ? OFFSET ?',
            [...$params, $filter->pageSize, ($filter->page - 1) * $filter->pageSize],
        ) as $r) {
            $items[] = [
                'item' => self::s($r['item']),
                'code' => self::s($r['code']),
                'construction' => self::s($r['construction']),
                'colour' => self::s($r['colour']),
                'diameterMm' => self::fNull($r['diameter_mm']),
                'kgPerM' => self::fNull($r['kg_per_m']),
                'unit' => self::s($r['unit']),
                'pool' => self::s($r['pool']),
                'traceStatus' => self::s($r['trace_status']),
                'reason' => self::sn($r['reason']),
            ];
        }

        return [
            'items' => $items,
            'facets' => ['constructions' => $facetConstructions, 'traceStatus' => $facetStatus],
        ] + self::paging($filter->page, $filter->pageSize, $total);
    }

    public function item(string $item): array
    {
        $finished = $this->row('SELECT * FROM cr_finished WHERE item = ?', [$item]);
        if ($finished === null) {
            throw new CableRoutesItemNotFound('Unknown item.');
        }
        $run = $this->row('SELECT data_as_of FROM cr_run WHERE run_id = 1');
        if ($run === null) {
            throw new CableRoutesStoreUnavailable('cr_run has no row.');
        }

        $machines = $this->machineIndex();
        $tree = "SELECT DISTINCT item FROM cr_need WHERE finished = ? AND kind IN ('finished', 'made')";
        $code = self::s($finished['code']);

        // One entry per item: the longest depth and the need in each unit.
        $depths = [];
        $needs = [];
        foreach ($this->rows(
            "SELECT item, unit, need_per_unit, depth FROM cr_need WHERE finished = ? AND kind IN ('finished', 'made')",
            [$item],
        ) as $r) {
            $key = self::s($r['item']);
            $depths[$key] = max($depths[$key] ?? 0, self::i($r['depth']));
            $needs[$key][] = ['unit' => self::s($r['unit']), 'perUnit' => self::f($r['need_per_unit'])];
        }
        if ($depths === []) {
            $depths[$item] = 0;
        }

        $made = [];
        foreach ($this->rows('SELECT * FROM cr_item WHERE item IN (' . $tree . ')', [$item]) as $r) {
            $made[self::s($r['item'])] = $r;
        }

        $steps = [];
        foreach ($this->rows('SELECT item, seq, operation, machine FROM cr_operation WHERE item IN (' . $tree . ') ORDER BY item, seq', [$item]) as $r) {
            $steps[self::s($r['item'])][] = [
                'seq' => self::i($r['seq']),
                'operation' => self::s($r['operation']),
                'machine' => self::s($r['machine']),
            ];
        }

        $alts = [];
        foreach ($this->rows(
            'SELECT item, operation, machine, orders FROM cr_alt_machine WHERE item IN (' . $tree . ') ORDER BY item, orders DESC, machine, operation',
            [$item],
        ) as $r) {
            $alts[self::s($r['item'])][] = [
                'operation' => self::s($r['operation']),
                'machine' => self::s($r['machine']),
                'orders' => self::i($r['orders']),
            ];
        }

        $materialInputs = [];
        foreach ($this->rows(
            "SELECT i.item, i.input_item, i.qty_per_unit, i.unit, m.name FROM cr_input i LEFT JOIN cr_material m ON m.item = i.input_item WHERE i.kind = 'material' AND i.item IN ("
            . $tree . ') ORDER BY i.item, i.line',
            [$item],
        ) as $r) {
            $materialInputs[self::s($r['item'])][] = [
                'item' => self::s($r['input_item']),
                'name' => self::sn($r['name']),
                'qtyPerUnit' => self::f($r['qty_per_unit']),
                'unit' => self::s($r['unit']),
            ];
        }

        $nodes = [];
        foreach ($depths as $key => $depth) {
            $nodeItem = (string) $key;
            $row = $made[$nodeItem] ?? null;
            $isFinished = $nodeItem === $item;
            $unit = $row !== null ? self::s($row['unit']) : ($isFinished ? self::s($finished['unit']) : '');

            $ownMachines = [];
            $nodeSteps = [];
            foreach ($steps[$nodeItem] ?? [] as $s) {
                $ownMachines[$s['operation']][$s['machine']] = true;
                $nodeSteps[] = ['seq' => $s['seq'], 'operation' => $s['operation'], 'machine' => self::machineRef($machines, $s['machine'])];
            }
            $altMachines = [];
            foreach ($alts[$nodeItem] ?? [] as $a) {
                if (isset($ownMachines[$a['operation']][$a['machine']])) {
                    continue;
                }
                $altMachines[] = ['operation' => $a['operation'], 'machine' => self::machineRef($machines, $a['machine']), 'orders' => $a['orders']];
            }

            $need = $needs[$nodeItem] ?? [];
            usort($need, static fn (array $a, array $b): int => [$a['unit'] !== $unit, $a['unit']] <=> [$b['unit'] !== $unit, $b['unit']]);

            $nodes[] = [
                'item' => $nodeItem,
                'kind' => $isFinished ? 'finished' : 'made',
                'pool' => $row !== null ? self::s($row['pool']) : ($isFinished ? self::s($finished['pool']) : ''),
                'depth' => $depth,
                'diameterMm' => $row !== null ? self::fNull($row['diameter_mm']) : ($isFinished ? self::fNull($finished['diameter_mm']) : null),
                'kgPerM' => $row !== null ? self::fNull($row['kg_per_m']) : ($isFinished ? self::fNull($finished['kg_per_m']) : null),
                'unit' => $unit,
                'order' => $row === null ? null : [
                    'orderNo' => self::s($row['order_no']),
                    'status' => self::s($row['order_status']),
                    'date' => self::s($row['order_date']),
                    'realOrders' => self::i($row['real_orders']),
                    'liveOrders' => self::i($row['live_orders']),
                ],
                'steps' => $nodeSteps,
                'altMachines' => $altMachines,
                'need' => $need,
                'materialInputs' => $materialInputs[$nodeItem] ?? [],
            ];
        }
        usort($nodes, static fn (array $a, array $b): int => [$a['depth'], $a['item']] <=> [$b['depth'], $b['item']]);

        // An edge the engine cut as a cycle points back up the tree, so its input is never deeper than its parent.
        $edges = [];
        foreach ($this->rows(
            "SELECT item, input_item, qty_per_unit, unit, parallel_ends FROM cr_input WHERE kind = 'made' AND item IN (" . $tree
            . ') AND input_item IN (' . $tree . ') ORDER BY item, input_item, line',
            [$item, $item],
        ) as $r) {
            $parent = self::s($r['item']);
            $input = self::s($r['input_item']);
            if (($depths[$input] ?? -1) <= ($depths[$parent] ?? 0)) {
                continue;
            }
            $edges[] = [
                'parent' => $parent,
                'input' => $input,
                'qtyPerUnit' => self::f($r['qty_per_unit']),
                'unit' => self::s($r['unit']),
                'parallelEnds' => self::i($r['parallel_ends']),
            ];
        }

        $stepGroups = [];
        foreach ($nodes as $node) {
            $nodeItem = $node['item'];
            foreach ($steps[$nodeItem] ?? [] as $s) {
                $key = $s['operation'] . "\0" . $s['machine'];
                $g = $stepGroups[$key] ?? ['operation' => $s['operation'], 'machine' => $s['machine'], 'items' => [], 'maxDepth' => 0, 'minSeq' => $s['seq']];
                $g['items'][] = $nodeItem;
                $g['maxDepth'] = max($g['maxDepth'], $depths[$nodeItem] ?? 0);
                $g['minSeq'] = min($g['minSeq'], $s['seq']);
                $stepGroups[$key] = $g;
            }
        }
        $groups = array_values($stepGroups);
        usort($groups, static fn (array $a, array $b): int => [-$a['maxDepth'], $a['minSeq'], $a['operation'], $a['machine']] <=> [-$b['maxDepth'], $b['minSeq'], $b['operation'], $b['machine']]);
        $stepList = [];
        foreach ($groups as $n => $g) {
            $stepList[] = [
                'stepNo' => $n + 1,
                'operation' => $g['operation'],
                'machine' => self::machineRef($machines, $g['machine']),
                'items' => array_values(array_unique($g['items'])),
                'maxDepth' => $g['maxDepth'],
            ];
        }

        $materials = [];
        foreach ($this->rows(
            "SELECT n.item, n.unit, n.need_per_unit, n.depth, n.is_rod, m.name, COALESCE(m.conductor, 0) AS conductor FROM cr_need n LEFT JOIN cr_material m ON m.item = n.item WHERE n.finished = ? AND n.kind = 'material' ORDER BY n.is_rod DESC, (n.unit <> 'kg'), n.unit, n.need_per_unit DESC, n.item",
            [$item],
        ) as $r) {
            $materials[] = [
                'item' => self::s($r['item']),
                'name' => self::sn($r['name']),
                'unit' => self::s($r['unit']),
                'perUnit' => self::f($r['need_per_unit']),
                'isRod' => self::b($r['is_rod']),
                'conductor' => self::b($r['conductor']),
                'depth' => self::i($r['depth']),
            ];
        }

        $codeKinds = "'" . implode("', '", Vocabulary::CODE_LEVEL_CHECK_KINDS) . "'";
        $checks = array_map(
            self::checkRow(...),
            $this->rows(
                'SELECT * FROM cr_check WHERE item = ? OR item IN (' . $tree . ') OR (item IS NULL AND code = ? AND kind IN (' . $codeKinds . ')) ORDER BY '
                . self::checkOrder(),
                [$item, $item, $code],
            ),
        );

        $reasonItems = [];
        foreach (explode(',', self::s($finished['reason_items'])) as $part) {
            if (trim($part) !== '') {
                $reasonItems[] = trim($part);
            }
        }

        return [
            'item' => [
                'item' => $item,
                'code' => $code,
                'construction' => self::s($finished['construction']),
                'colour' => self::s($finished['colour']),
                'pool' => self::s($finished['pool']),
                'diameterMm' => self::fNull($finished['diameter_mm']),
                'kgPerM' => self::fNull($finished['kg_per_m']),
                'unit' => self::s($finished['unit']),
                'traceStatus' => self::s($finished['trace_status']),
                'reason' => self::sn($finished['reason']),
                'reasonItems' => $reasonItems,
                'stepCount' => self::i($finished['step_count']),
                'maxDepth' => self::i($finished['max_depth']),
            ],
            'dataAsOf' => self::s($run['data_as_of']),
            'nodes' => $nodes,
            'edges' => $edges,
            'steps' => $stepList,
            'materials' => $materials,
            'checks' => $checks,
        ];
    }

    public function machines(): array
    {
        $limits = $this->limits();
        $out = [];
        foreach ($this->rows('SELECT * FROM cr_machine WHERE hidden = 0 ORDER BY (stage_no IS NULL), stage_no, machine') as $r) {
            $out[] = self::machineCard($r, $limits);
        }

        return ['machines' => $out];
    }

    public function machine(string $id, MachineItemsFilter $filter): array
    {
        $row = $this->row('SELECT * FROM cr_machine WHERE UPPER(machine) = UPPER(?)', [$id])
            ?? $this->row('SELECT * FROM cr_machine WHERE UPPER(poster_name) = UPPER(?)', [$id]);
        if ($row === null || self::b($row['hidden'])) {
            throw new CableRoutesMachineNotFound('Unknown machine.');
        }
        $machine = self::s($row['machine']);

        $card = self::machineCard($row, $this->limits()) + [
            'outputCapability' => self::sn($row['output_capability']),
            'payOff' => self::sn($row['pay_off']),
            'takeUp' => self::sn($row['take_up']),
            'otherNotes' => self::sn($row['other_notes']),
            'legibility' => self::sn($row['legibility']),
        ];

        $codes = [];
        foreach ($this->rows(
            'SELECT f.code, COUNT(*) AS n FROM cr_machine_finished mf JOIN cr_finished f ON f.item = mf.finished WHERE mf.machine = ? GROUP BY f.code ORDER BY n DESC, f.code',
            [$machine],
        ) as $r) {
            $codes[] = ['code' => self::s($r['code']), 'finishedItems' => self::i($r['n'])];
        }

        $where = 'mf.machine = ?';
        $params = [$machine];
        if ($filter->code !== null) {
            $where .= ' AND UPPER(f.code) = UPPER(?)';
            $params[] = $filter->code;
        }
        $total = $this->count('SELECT COUNT(*) FROM cr_machine_finished mf JOIN cr_finished f ON f.item = mf.finished WHERE ' . $where, $params);
        $items = [];
        foreach ($this->rows(
            'SELECT f.item, f.code, f.construction, f.colour, f.diameter_mm, mf.steps FROM cr_machine_finished mf JOIN cr_finished f ON f.item = mf.finished WHERE '
            . $where . ' ORDER BY f.item LIMIT ? OFFSET ?',
            [...$params, $filter->pageSize, ($filter->page - 1) * $filter->pageSize],
        ) as $r) {
            $items[] = [
                'item' => self::s($r['item']),
                'code' => self::s($r['code']),
                'construction' => self::s($r['construction']),
                'colour' => self::s($r['colour']),
                'diameterMm' => self::fNull($r['diameter_mm']),
                'steps' => self::i($r['steps']),
            ];
        }

        $topInputs = [];
        foreach ($this->rows(
            'SELECT i.input_item, MIN(i.kind) AS kind, MIN(i.unit) AS unit, m.name, COUNT(DISTINCT i.item) AS n FROM cr_input i LEFT JOIN cr_material m ON m.item = i.input_item '
            . 'WHERE i.item IN (SELECT item FROM cr_operation WHERE machine = ?) GROUP BY i.input_item ORDER BY n DESC, i.input_item LIMIT ' . Vocabulary::TOP_INPUTS,
            [$machine],
        ) as $r) {
            $topInputs[] = [
                'item' => self::s($r['input_item']),
                'name' => self::sn($r['name']),
                'kind' => self::s($r['kind']),
                'unit' => self::s($r['unit']),
                'madeItems' => self::i($r['n']),
            ];
        }

        $checks = array_map(
            self::checkRow(...),
            $this->rows('SELECT * FROM cr_check WHERE machine = ? ORDER BY ' . self::checkOrder(), [$machine]),
        );

        return [
            'machine' => $card,
            'codes' => $codes,
            'items' => ['items' => $items] + self::paging($filter->page, $filter->pageSize, $total),
            'topInputs' => $topInputs,
            'checks' => $checks,
        ];
    }

    public function checks(ChecksFilter $filter): array
    {
        $where = '1 = 1';
        $params = [];
        if ($filter->code !== null) {
            $where .= ' AND UPPER(code) = UPPER(?)';
            $params[] = $filter->code;
        }

        $byKind = [];
        foreach ($this->rows('SELECT kind, COUNT(*) AS n FROM cr_check WHERE ' . $where . ' GROUP BY kind', $params) as $r) {
            $byKind[self::s($r['kind'])] = self::i($r['n']);
        }
        $counts = [];
        foreach (Vocabulary::CHECK_KINDS as $kind => $label) {
            $counts[] = ['kind' => $kind, 'label' => $label, 'count' => $byKind[$kind] ?? 0];
        }

        if ($filter->kind !== null) {
            $where .= ' AND kind = ?';
            $params[] = $filter->kind;
        }
        $total = $this->count('SELECT COUNT(*) FROM cr_check WHERE ' . $where, $params);
        $rows = array_map(
            self::checkRow(...),
            $this->rows(
                'SELECT * FROM cr_check WHERE ' . $where . ' ORDER BY ' . self::checkOrder() . ' LIMIT ? OFFSET ?',
                [...$params, $filter->pageSize, ($filter->page - 1) * $filter->pageSize],
            ),
        );

        return ['counts' => $counts, 'kind' => $filter->kind, 'rows' => $rows] + self::paging($filter->page, $filter->pageSize, $total);
    }

    /**
     * The WHERE for the code and q filters, which the facets are counted over.
     *
     * @return array{0: string, 1: list<string|int>}
     */
    private static function itemsBase(ItemsFilter $filter): array
    {
        $where = '1 = 1';
        $params = [];
        if ($filter->code !== null) {
            $where .= ' AND UPPER(code) = UPPER(?)';
            $params[] = $filter->code;
        }
        if ($filter->q !== null) {
            $where .= " AND item LIKE ? ESCAPE '\\'";
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter->q) . '%';
        }

        return [$where, $params];
    }

    private static function checkOrder(): string
    {
        $case = 'CASE kind';
        $n = 0;
        foreach (array_keys(Vocabulary::CHECK_KINDS) as $kind) {
            $case .= " WHEN '" . $kind . "' THEN " . $n++;
        }

        return $case . ' END, code, machine, item, check_id';
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function checkRow(array $r): array
    {
        return [
            'checkId' => self::i($r['check_id']),
            'kind' => self::s($r['kind']),
            'item' => self::sn($r['item']),
            'code' => self::sn($r['code']),
            'machine' => self::sn($r['machine']),
            'operation' => self::sn($r['operation']),
            'detail' => self::s($r['detail']),
            'affected' => self::iNull($r['affected']),
            'total' => self::iNull($r['total']),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, array{minMm: ?float, maxMm: ?float, note: ?string}> $limits
     * @return array<string, mixed>
     */
    private static function machineCard(array $r, array $limits): array
    {
        $machine = self::s($r['machine']);

        return [
            'machine' => $machine,
            'posterName' => self::sn($r['poster_name']),
            'd365Id' => self::sn($r['d365_id']),
            'onPoster' => self::b($r['in_poster']),
            'd365Status' => self::s($r['d365_status']),
            'site' => self::sn($r['site']),
            'stageNo' => self::iNull($r['stage_no']),
            'stage' => self::sn($r['stage']),
            'posterStatus' => self::sn($r['poster_status']),
            'brand' => self::sn($r['brand']),
            'machineType' => self::sn($r['machine_type']),
            'inputLimit' => self::sn($r['input_limit']),
            'speedText' => self::sn($r['speed_text']),
            'speedMPerMin' => self::fNull($r['speed_m_per_min']),
            'limit' => $limits[$machine] ?? null,
            'madeItems' => self::i($r['made_items']),
            'finishedItems' => self::i($r['finished_items']),
            'codes' => self::i($r['codes']),
        ];
    }

    /** @return array<string, array{minMm: ?float, maxMm: ?float, note: ?string}> */
    private function limits(): array
    {
        $out = [];
        foreach ($this->rows('SELECT machine, min_mm, max_mm, note FROM cr_machine_limit') as $r) {
            $out[self::s($r['machine'])] = ['minMm' => self::fNull($r['min_mm']), 'maxMm' => self::fNull($r['max_mm']), 'note' => self::sn($r['note'])];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function machineIndex(): array
    {
        $out = [];
        foreach ($this->rows('SELECT machine, poster_name, stage_no, in_poster, hidden, d365_status FROM cr_machine') as $r) {
            $onPoster = self::b($r['in_poster']);
            $out[self::s($r['machine'])] = [
                'machine' => self::s($r['machine']),
                'posterName' => $onPoster ? self::sn($r['poster_name']) : null,
                'stageNo' => $onPoster ? self::iNull($r['stage_no']) : null,
                'onPoster' => $onPoster,
                'retired' => self::b($r['hidden']) || self::s($r['d365_status']) === 'retired',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $index
     * @return array<string, mixed>
     */
    private static function machineRef(array $index, string $machine): array
    {
        return $index[$machine] ?? ['machine' => $machine, 'posterName' => null, 'stageNo' => null, 'onPoster' => false, 'retired' => false];
    }

    /** @return array{page: int, pageSize: int, totalItems: int, totalPages: int} */
    private static function paging(int $page, int $pageSize, int $total): array
    {
        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'totalItems' => $total,
            'totalPages' => max(1, intdiv($total + $pageSize - 1, $pageSize)),
        ];
    }

    private function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!is_file($this->file) || !is_readable($this->file)) {
            throw new CableRoutesStoreUnavailable('The store file is missing or unreadable.');
        }
        try {
            $pdo = new PDO('sqlite:' . $this->file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // The class constants moved to Pdo\Sqlite in PHP 8.4 and the old ones are deprecated from 8.5.
                self::constant('ATTR_OPEN_FLAGS', 'SQLITE_ATTR_OPEN_FLAGS') => self::constant('OPEN_READONLY', 'SQLITE_OPEN_READONLY'),
            ]);
            $version = $pdo->query('PRAGMA user_version');
            $runs = $pdo->query('SELECT COUNT(*) FROM cr_run');
            if ($version === false || $runs === false) {
                throw new CableRoutesStoreUnavailable('The store cannot be queried.');
            }
            if ((int) $version->fetchColumn() !== Vocabulary::SCHEMA_VERSION) {
                throw new CableRoutesStoreUnavailable('The store has another schema version.');
            }
            if ((int) $runs->fetchColumn() < 1) {
                throw new CableRoutesStoreUnavailable('The store has no run row.');
            }
        } catch (PDOException $e) {
            throw new CableRoutesStoreUnavailable('The store cannot be opened: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo = $pdo;
    }

    private static function constant(string $new, string $old): int
    {
        $value = defined('Pdo\Sqlite::' . $new) ? constant('Pdo\Sqlite::' . $new) : constant('PDO::' . $old);

        return is_int($value) ? $value : 0;
    }

    /**
     * @param list<string|int> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        try {
            $statement = $this->connection()->prepare($sql);
            $statement->execute($params);
            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new CableRoutesStoreUnavailable('A store query failed: ' . $e->getMessage(), 0, $e);
        }

        return $rows;
    }

    /**
     * @param list<string|int> $params
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $params = []): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }

    /** @param list<string|int> $params */
    private function count(string $sql, array $params): int
    {
        try {
            $statement = $this->connection()->prepare($sql);
            $statement->execute($params);

            return self::i($statement->fetchColumn());
        } catch (PDOException $e) {
            throw new CableRoutesStoreUnavailable('A store query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function s(mixed $v): string
    {
        return is_string($v) || is_int($v) || is_float($v) ? (string) $v : '';
    }

    private static function sn(mixed $v): ?string
    {
        return $v === null ? null : self::s($v);
    }

    private static function i(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function iNull(mixed $v): ?int
    {
        return $v === null ? null : self::i($v);
    }

    private static function f(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private static function fNull(mixed $v): ?float
    {
        return $v === null ? null : self::f($v);
    }

    private static function b(mixed $v): bool
    {
        return self::i($v) === 1;
    }
}
