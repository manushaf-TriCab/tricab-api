<?php

declare(strict_types=1);

namespace TriCab\Api\Conductor\Process\Persistence;

use PDO;
use PDOException;
use TriCab\Api\Config\Settings;
use TriCab\Core\Conductor\Process\Application\Exception\ConductorProcessStoreUnavailable;
use TriCab\Core\Conductor\Process\Contract\ConductorProcessRepository;
use TriCab\Core\Conductor\Process\Vocabulary;

/**
 * Reads the conductor process store (core/python/conductor_process/schema.sql) through a read-only PDO connection.
 * Nothing is opened until the first call, so a missing store costs nothing until someone asks for data.
 * The engine replaces the file in one rename, so a connection sees either the old store or the new one, whole.
 *
 * @phpstan-import-type RunRecord from ConductorProcessRepository
 * @phpstan-import-type MachineRecord from ConductorProcessRepository
 * @phpstan-import-type ItemRecord from ConductorProcessRepository
 * @phpstan-import-type OperationRecord from ConductorProcessRepository
 * @phpstan-import-type InputRecord from ConductorProcessRepository
 * @phpstan-import-type AltMachineRecord from ConductorProcessRepository
 */
final class SqliteConductorProcessRepository implements ConductorProcessRepository
{
    private const ITEM_COLUMNS = 'item, pool, stage, prefix, material, mica, form, ends, size, diameter_mm, kg_per_m, unit, order_no, '
        . 'order_status, order_date, real_orders, live_orders, orders_52w, run_size, traces_to_rod, step_count';

    private ?PDO $pdo = null;

    public function __construct(private readonly string $file)
    {
    }

    public static function fromSettings(Settings $settings): self
    {
        $path = trim($settings->conductorProcessDbPath);
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            $path = ($home === false || $home === '' ? '' : rtrim($home, '/')) . substr($path, 1);
        }
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
        if (!$absolute) {
            $path = dirname(__DIR__, 4) . '/' . ltrim($path, '/\\');
        }

        return new self($path);
    }

    public function run(): ?array
    {
        $r = $this->rows('SELECT * FROM cd_run WHERE run_id = 1')[0] ?? null;
        if ($r === null) {
            return null;
        }

        return [
            'builtAt' => self::s($r['built_at']),
            'dataAsOf' => self::s($r['data_as_of']),
            'windowFrom' => self::s($r['window_from']),
            'sourceName' => self::s($r['source_name']),
            'schemaVersion' => self::i($r['schema_version']),
            'engineVersion' => self::s($r['engine_version']),
            'items' => self::i($r['items']),
            'finishedItems' => self::i($r['finished_items']),
            'tracedToRod' => self::i($r['traced_to_rod']),
            'excludedOffMap' => self::i($r['excluded_off_map']),
        ];
    }

    public function machines(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cd_machine ORDER BY seq') as $r) {
            $out[] = [
                'machine' => self::s($r['machine']),
                'd365Id' => self::sn($r['d365_id']),
                'seq' => self::i($r['seq']),
                'stageNo' => self::i($r['stage_no']),
                'stage' => self::s($r['stage']),
                'posterStatus' => self::sn($r['poster_status']),
                'brand' => self::sn($r['brand']),
                'machineType' => self::sn($r['machine_type']),
                'inputLimit' => self::sn($r['input_limit']),
                'outputCapability' => self::sn($r['output_capability']),
                'payOff' => self::sn($r['pay_off']),
                'takeUp' => self::sn($r['take_up']),
                'speedText' => self::sn($r['speed_text']),
                'note' => self::sn($r['note']),
                'items' => self::i($r['items']),
                'ordersTotal' => self::i($r['orders_total']),
                'ordersCancelled' => self::i($r['orders_cancelled']),
                'orders52w' => self::i($r['orders_52w']),
            ];
        }

        return $out;
    }

    public function machineOperations(string $machine): array
    {
        $out = [];
        foreach ($this->rows('SELECT machine, operation, orders FROM cd_machine_operation WHERE machine = ?', [$machine]) as $r) {
            $out[] = ['machine' => self::s($r['machine']), 'operation' => self::s($r['operation']), 'orders' => self::i($r['orders'])];
        }

        return $out;
    }

    public function items(): array
    {
        return array_map(self::item(...), $this->rows('SELECT ' . self::ITEM_COLUMNS . ' FROM cd_item'));
    }

    public function findItem(string $item): ?array
    {
        $rows = $this->rows('SELECT ' . self::ITEM_COLUMNS . ' FROM cd_item WHERE item = ? COLLATE NOCASE ORDER BY item LIMIT 1', [$item]);

        return isset($rows[0]) ? self::item($rows[0]) : null;
    }

    public function itemsNamed(array $items): array
    {
        if ($items === []) {
            return [];
        }

        return array_map(self::item(...), $this->rows('SELECT ' . self::ITEM_COLUMNS . ' FROM cd_item WHERE item IN (' . self::marks($items) . ')', $items));
    }

    public function itemsRoutedOn(string $machine): array
    {
        return array_map(self::item(...), $this->rows(
            'SELECT ' . self::ITEM_COLUMNS . ' FROM cd_item WHERE item IN (SELECT item FROM cd_operation WHERE machine = ?)',
            [$machine],
        ));
    }

    public function altMachinesOn(string $machine): array
    {
        return array_map(self::alt(...), $this->rows('SELECT item, operation, machine, orders FROM cd_alt_machine WHERE machine = ?', [$machine]));
    }

    public function needs(string $root): array
    {
        $out = [];
        foreach ($this->rows('SELECT root, item, unit, need_per_unit, depth, kind FROM cd_need WHERE root = ?', [$root]) as $r) {
            $out[] = [
                'root' => self::s($r['root']),
                'item' => self::s($r['item']),
                'unit' => self::s($r['unit']),
                'needPerUnit' => self::f($r['need_per_unit']),
                'depth' => self::i($r['depth']),
                'kind' => self::s($r['kind']),
            ];
        }

        return $out;
    }

    public function operations(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $out = [];
        foreach ($this->rows('SELECT item, seq, operation_number, operation, machine FROM cd_operation WHERE item IN (' . self::marks($items) . ') ORDER BY item, seq', $items) as $r) {
            $out[] = [
                'item' => self::s($r['item']),
                'seq' => self::i($r['seq']),
                'operationNumber' => self::i($r['operation_number']),
                'operation' => self::s($r['operation']),
                'machine' => self::s($r['machine']),
            ];
        }

        return $out;
    }

    public function inputs(array $items): array
    {
        if ($items === []) {
            return [];
        }

        return array_map(self::input(...), $this->rows(
            'SELECT item, line, input_item, qty_per_unit, unit, parallel_ends, kind FROM cd_input WHERE item IN (' . self::marks($items) . ') ORDER BY item, line',
            $items,
        ));
    }

    public function altMachines(array $items): array
    {
        if ($items === []) {
            return [];
        }

        return array_map(self::alt(...), $this->rows(
            'SELECT item, operation, machine, orders FROM cd_alt_machine WHERE item IN (' . self::marks($items) . ')',
            $items,
        ));
    }

    public function materials(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $out = [];
        foreach ($this->rows('SELECT item, name, unit, is_rod FROM cd_material WHERE item IN (' . self::marks($items) . ')', $items) as $r) {
            $out[] = ['item' => self::s($r['item']), 'name' => self::sn($r['name']), 'unit' => self::sn($r['unit']), 'isRod' => self::b($r['is_rod'])];
        }

        return $out;
    }

    public function inputsConsuming(string $item): array
    {
        return array_map(self::input(...), $this->rows(
            'SELECT item, line, input_item, qty_per_unit, unit, parallel_ends, kind FROM cd_input WHERE input_item = ? ORDER BY item, line',
            [$item],
        ));
    }

    /**
     * @param array<string, mixed> $r
     * @return ItemRecord
     */
    private static function item(array $r): array
    {
        return [
            'item' => self::s($r['item']),
            'pool' => self::s($r['pool']),
            'stage' => self::s($r['stage']),
            'prefix' => self::s($r['prefix']),
            'material' => self::s($r['material']),
            'mica' => self::b($r['mica']),
            'form' => self::s($r['form']),
            'ends' => self::iNull($r['ends']),
            'size' => self::fNull($r['size']),
            'diameterMm' => self::fNull($r['diameter_mm']),
            'kgPerM' => self::fNull($r['kg_per_m']),
            'unit' => self::s($r['unit']),
            'orderNo' => self::s($r['order_no']),
            'orderStatus' => self::s($r['order_status']),
            'orderDate' => self::s($r['order_date']),
            'realOrders' => self::i($r['real_orders']),
            'liveOrders' => self::i($r['live_orders']),
            'orders52w' => self::i($r['orders_52w']),
            'runSize' => self::fNull($r['run_size']),
            'tracesToRod' => self::b($r['traces_to_rod']),
            'stepCount' => self::i($r['step_count']),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return InputRecord
     */
    private static function input(array $r): array
    {
        return [
            'item' => self::s($r['item']),
            'line' => self::i($r['line']),
            'inputItem' => self::s($r['input_item']),
            'qtyPerUnit' => self::f($r['qty_per_unit']),
            'unit' => self::s($r['unit']),
            'parallelEnds' => self::i($r['parallel_ends']),
            'kind' => self::s($r['kind']),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return AltMachineRecord
     */
    private static function alt(array $r): array
    {
        return ['item' => self::s($r['item']), 'operation' => self::s($r['operation']), 'machine' => self::s($r['machine']), 'orders' => self::i($r['orders'])];
    }

    /** @param list<string> $items */
    private static function marks(array $items): string
    {
        return implode(', ', array_fill(0, count($items), '?'));
    }

    private function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!is_file($this->file) || !is_readable($this->file)) {
            throw new ConductorProcessStoreUnavailable('The store file is missing or unreadable.');
        }
        try {
            $pdo = new PDO('sqlite:' . $this->file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // The class constants moved to Pdo\Sqlite in PHP 8.4 and the old ones are deprecated from 8.5.
                self::constant('ATTR_OPEN_FLAGS', 'SQLITE_ATTR_OPEN_FLAGS') => self::constant('OPEN_READONLY', 'SQLITE_OPEN_READONLY'),
            ]);
            $version = $pdo->query('PRAGMA user_version');
            // An empty cd_run is allowed (the run endpoint answers run: null); a missing table is not.
            $runs = $pdo->query('SELECT COUNT(*) FROM cd_run');
            if ($version === false || $runs === false) {
                throw new ConductorProcessStoreUnavailable('The store cannot be queried.');
            }
            if ((int) $version->fetchColumn() !== Vocabulary::SCHEMA_VERSION) {
                throw new ConductorProcessStoreUnavailable('The store has another schema version.');
            }
        } catch (PDOException $e) {
            throw new ConductorProcessStoreUnavailable('The store cannot be opened: ' . $e->getMessage(), 0, $e);
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
            throw new ConductorProcessStoreUnavailable('A store query failed: ' . $e->getMessage(), 0, $e);
        }

        return $rows;
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
