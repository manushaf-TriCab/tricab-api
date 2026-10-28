<?php

declare(strict_types=1);

namespace TriCab\Api\Conductor\Schedule\Persistence;

use PDO;
use PDOException;
use TriCab\Api\Config\Settings;
use TriCab\Core\Conductor\Schedule\Application\Exception\ConductorScheduleStoreUnavailable;
use TriCab\Core\Conductor\Schedule\Contract\ConductorScheduleRepository;
use TriCab\Core\Conductor\Schedule\Vocabulary;

/**
 * Reads the conductor schedule store (core/python/conductor_schedule/schema.sql) through a read-only PDO connection.
 * Nothing is opened until the first call, so a missing store costs nothing until someone asks for data.
 * The engine replaces the file in one rename, so a connection sees either the old store or the new one, whole.
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
final class SqliteConductorScheduleRepository implements ConductorScheduleRepository
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $file)
    {
    }

    public static function fromSettings(Settings $settings): self
    {
        $path = trim($settings->conductorScheduleDbPath);
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
        $r = $this->rows('SELECT * FROM cs_run WHERE run_id = 1')[0] ?? null;
        if ($r === null) {
            return null;
        }

        return [
            'schemaVersion' => self::i($r['schema_version']),
            'builtAt' => self::s($r['built_at']),
            'engineVersion' => self::s($r['engine_version']),
            'sourceName' => self::s($r['source_name']),
            'today' => self::s($r['today']),
            'horizonEnd' => self::s($r['horizon_end']),
            'dataAsOf' => self::s($r['data_as_of']),
            'demand' => self::s($r['demand']),
            'rateWindow' => self::s($r['rate_window']),
            'settingsHash' => self::s($r['settings_hash']),
            'settingsSource' => self::s($r['settings_source']),
            'inventoryFile' => self::s($r['inventory_file']),
            'inventoryExported' => self::sn($r['inventory_exported']),
            'r86OrderFile' => self::s($r['r86_order_file']),
            'r86OrderFrom' => self::s($r['r86_order_from']),
            'r86OrderPrinted' => self::s($r['r86_order_printed']),
            'r86OrderWeeks' => self::f($r['r86_order_weeks']),
            'r86InvoiceFile' => self::s($r['r86_invoice_file']),
            'r86InvoiceFrom' => self::s($r['r86_invoice_from']),
            'r86InvoicePrinted' => self::s($r['r86_invoice_printed']),
            'r86InvoiceWeeks' => self::f($r['r86_invoice_weeks']),
            'restockItems' => self::i($r['restock_items']),
            'releaseItems' => self::i($r['release_items']),
            'releaseRuns' => self::i($r['release_runs']),
            'releaseM' => self::f($r['release_m']),
            'releaseKg' => self::f($r['release_kg']),
            'jobs' => self::i($r['jobs']),
            'jobsScheduled' => self::i($r['jobs_scheduled']),
            'ops' => self::i($r['ops']),
            'passes' => self::i($r['passes']),
            'batches' => self::i($r['batches']),
            'lateOps' => self::i($r['late_ops']),
        ];
    }

    public function sources(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_source') as $r) {
            $out[] = ['name' => self::s($r['name']), 'role' => self::s($r['role']), 'asOf' => self::sn($r['as_of']), 'rows' => self::iNull($r['rows'])];
        }

        return $out;
    }

    public function settings(): array
    {
        $out = [];
        foreach ($this->rows('SELECT key, value FROM cs_setting') as $r) {
            $out[] = ['key' => self::s($r['key']), 'value' => json_decode(self::s($r['value']), false)];
        }

        return $out;
    }

    public function r86(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_r86') as $r) {
            $out[] = [
                'doc' => self::s($r['doc']),
                'material' => self::s($r['material']),
                'mica' => self::i($r['mica']),
                'csaMm2' => self::f($r['csa_mm2']),
                'metres' => self::f($r['metres']),
                'weeklyM' => self::f($r['weekly_m']),
                'placedM' => self::f($r['placed_m']),
                'unplacedM' => self::f($r['unplaced_m']),
            ];
        }

        return $out;
    }

    public function demand(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_demand') as $r) {
            $out[] = [
                'item' => self::s($r['item']),
                'material' => self::s($r['material']),
                'mica' => self::b($r['mica']),
                'csaMm2' => self::fNull($r['csa_mm2']),
                'source' => self::s($r['source']),
                'weeklyUseM' => self::f($r['weekly_use_m']),
                'issueWeeklyM' => self::f($r['issue_weekly_m']),
                'issueWeekly52wM' => self::f($r['issue_weekly_52w_m']),
                'unplaced' => self::b($r['unplaced']),
            ];
        }

        return $out;
    }

    public function restock(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_restock') as $r) {
            $out[] = [
                'item' => self::s($r['item']),
                'form' => self::s($r['form']),
                'material' => self::s($r['material']),
                'mica' => self::b($r['mica']),
                'csaMm2' => self::fNull($r['csa_mm2']),
                'kgPerM' => self::fNull($r['kg_per_m']),
                'weeklyUseM' => self::f($r['weekly_use_m']),
                'kanbanM' => self::f($r['kanban_m']),
                'kanbanAssumed' => self::b($r['kanban_assumed']),
                'runs52w' => self::i($r['runs_52w']),
                'triggerM' => self::f($r['trigger_m']),
                'physicalM' => self::f($r['physical_m']),
                'incomingM' => self::f($r['incoming_m']),
                'positionM' => self::f($r['position_m']),
                'weeksOnHand' => self::fNull($r['weeks_on_hand']),
                'releaseRuns' => self::i($r['release_runs']),
                'releaseM' => self::f($r['release_m']),
                'releaseKg' => self::fNull($r['release_kg']),
                'status' => self::s($r['status']),
                'scheduled' => self::b($r['scheduled']),
                'note' => self::sn($r['note']),
            ];
        }

        return $out;
    }

    public function jobs(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_job') as $r) {
            $out[] = [
                'jobId' => self::i($r['job_id']),
                'item' => self::s($r['item']),
                'runNo' => self::i($r['run_no']),
                'metres' => self::f($r['metres']),
                'dueDate' => self::s($r['due_date']),
                'status' => self::s($r['status']),
            ];
        }

        return $out;
    }

    public function machines(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_machine ORDER BY stage_order') as $r) {
            $out[] = [
                'machine' => self::s($r['machine']),
                'posterName' => self::s($r['poster_name']),
                'name' => self::s($r['name']),
                'calendar' => self::s($r['calendar']),
                'calendarSource' => self::s($r['calendar_source']),
                'efficiency' => self::f($r['efficiency']),
                'hoursPerWeek' => self::f($r['hours_per_week']),
                'stageOrder' => self::i($r['stage_order']),
            ];
        }

        return $out;
    }

    public function ops(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_op') as $r) {
            $out[] = [
                'opId' => self::i($r['op_id']),
                'item' => self::s($r['item']),
                'pool' => self::s($r['pool']),
                'metres' => self::f($r['metres']),
                'requiredBy' => self::s($r['required_by']),
                'startAt' => self::s($r['start_at']),
                'endAt' => self::s($r['end_at']),
                'lateDays' => self::i($r['late_days']),
                'rodKg' => self::fNull($r['rod_kg']),
                'outKg' => self::fNull($r['out_kg']),
                'passCount' => self::i($r['pass_count']),
                'stockM' => self::f($r['stock_m']),
            ];
        }

        return $out;
    }

    public function passes(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_pass') as $r) {
            $eligible = json_decode(self::s($r['eligible']), true);
            $out[] = [
                'passId' => self::i($r['pass_id']),
                'opId' => self::i($r['op_id']),
                'parentPassId' => self::iNull($r['parent_pass_id']),
                'item' => self::s($r['item']),
                'pool' => self::s($r['pool']),
                'depth' => self::i($r['depth']),
                'machine' => self::s($r['machine']),
                'eligible' => is_array($eligible) ? array_values(array_map(self::s(...), $eligible)) : [],
                'operation' => self::s($r['operation']),
                'quantity' => self::f($r['quantity']),
                'unit' => self::s($r['unit']),
                'baseRate' => self::f($r['base_rate']),
                'operationFactor' => self::f($r['operation_factor']),
                'productFactor' => self::f($r['product_factor']),
                'setupH' => self::f($r['setup_h']),
                'runH' => self::f($r['run_h']),
                'startAt' => self::s($r['start_at']),
                'endAt' => self::s($r['end_at']),
                'batchId' => self::iNull($r['batch_id']),
                'stockM' => self::f($r['stock_m']),
            ];
        }

        return $out;
    }

    public function batches(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_batch') as $r) {
            $out[] = [
                'batchId' => self::i($r['batch_id']),
                'item' => self::s($r['item']),
                'pool' => self::s($r['pool']),
                'machine' => self::s($r['machine']),
                'operation' => self::s($r['operation']),
                'metres' => self::f($r['metres']),
                'batchRule' => self::s($r['batch_rule']),
                'allocatedM' => self::f($r['allocated_m']),
                'surplusM' => self::f($r['surplus_m']),
                'baseRate' => self::f($r['base_rate']),
                'setupH' => self::f($r['setup_h']),
                'runH' => self::f($r['run_h']),
                'startAt' => self::s($r['start_at']),
                'endAt' => self::s($r['end_at']),
                'opCount' => self::i($r['op_count']),
                'reason' => self::s($r['reason']),
            ];
        }

        return $out;
    }

    public function draws(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_draw') as $r) {
            $out[] = [
                'batchId' => self::i($r['batch_id']),
                'consumerKind' => self::s($r['consumer_kind']),
                'consumerId' => self::i($r['consumer_id']),
                'metres' => self::f($r['metres']),
            ];
        }

        return $out;
    }

    public function stock(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_stock') as $r) {
            $out[] = [
                'item' => self::s($r['item']),
                'point' => self::s($r['point']),
                'pool' => self::s($r['pool']),
                'unit' => self::s($r['unit']),
                'onHandM' => self::fNull($r['on_hand_m']),
                'usedM' => self::fNull($r['used_m']),
                'onHandKg' => self::fNull($r['on_hand_kg']),
                'usedKg' => self::fNull($r['used_kg']),
                'incomingM' => self::fNull($r['incoming_m']),
                'incomingKg' => self::fNull($r['incoming_kg']),
                'kgPerM' => self::fNull($r['kg_per_m']),
                'weeklyUse' => self::fNull($r['weekly_use']),
            ];
        }

        return $out;
    }

    public function capacity(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_capacity') as $r) {
            $out[] = ['machine' => self::s($r['machine']), 'week' => self::s($r['week']), 'availableH' => self::f($r['available_h'])];
        }

        return $out;
    }

    public function load(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_load') as $r) {
            $out[] = ['machine' => self::s($r['machine']), 'week' => self::s($r['week']), 'hours' => self::f($r['hours'])];
        }

        return $out;
    }

    public function issues(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM cs_issue') as $r) {
            $out[] = [
                'item' => self::s($r['item']),
                'kind' => self::s($r['kind']),
                'severity' => self::s($r['severity']),
                'detail' => self::s($r['detail']),
                'count' => self::i($r['count']),
            ];
        }

        return $out;
    }

    private function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!is_file($this->file) || !is_readable($this->file)) {
            throw new ConductorScheduleStoreUnavailable('The store file is missing or unreadable.');
        }
        try {
            $pdo = new PDO('sqlite:' . $this->file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // The class constants moved to Pdo\Sqlite in PHP 8.4 and the old ones are deprecated from 8.5.
                self::constant('ATTR_OPEN_FLAGS', 'SQLITE_ATTR_OPEN_FLAGS') => self::constant('OPEN_READONLY', 'SQLITE_OPEN_READONLY'),
            ]);
            $version = $pdo->query('PRAGMA user_version');
            $runs = $pdo->query('SELECT COUNT(*) FROM cs_run');
            if ($version === false || $runs === false) {
                throw new ConductorScheduleStoreUnavailable('The store cannot be queried.');
            }
            if ((int) $version->fetchColumn() !== Vocabulary::SCHEMA_VERSION) {
                throw new ConductorScheduleStoreUnavailable('The store has another schema version.');
            }
            // Unlike Process, an empty cs_run means there is nothing to show: 503.
            if ((int) $runs->fetchColumn() < 1) {
                throw new ConductorScheduleStoreUnavailable('The store has no run.');
            }
        } catch (PDOException $e) {
            throw new ConductorScheduleStoreUnavailable('The store cannot be opened: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo = $pdo;
    }

    private static function constant(string $new, string $old): int
    {
        $value = defined('Pdo\Sqlite::' . $new) ? constant('Pdo\Sqlite::' . $new) : constant('PDO::' . $old);

        return is_int($value) ? $value : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        try {
            $statement = $this->connection()->prepare($sql);
            $statement->execute();
            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new ConductorScheduleStoreUnavailable('A store query failed: ' . $e->getMessage(), 0, $e);
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
