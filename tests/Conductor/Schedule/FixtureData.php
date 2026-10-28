<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Conductor\Schedule;

use TriCab\Api\Conductor\Schedule\Persistence\InMemoryConductorScheduleRepository;
use TriCab\Core\Conductor\Schedule\Contract\ConductorScheduleRepository;

/**
 * A small conductor schedule store as typed rows. Three restock items: NX-1C16 (release, 4 runs), DX-1C2.5 (ok, demand
 * the issue rate) and the mica NM-1C16 (ok, not scheduled). Four jobs of NX-1C16 (the fourth has no rate and no op),
 * three ops with five passes, two 1.6 mm batches (the second is fed by the first), the 12 machines (WD3 on an override
 * calendar), six weeks of capacity and load from Monday 2026-10-26, the rod and three stock rows, six issues and the
 * R-86 buckets of both documents. The same rows feed the in-memory repository (service tests) and the SQLite fixture
 * store (repository and HTTP tests), so both are held to one answer. Rows are deliberately NOT in presentation order
 * where the service must sort them.
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
final class FixtureData
{
    /** Monday of the week of {@see run()}'s today (Wednesday 2026-10-28). */
    public const FIRST_WEEK = '2026-10-26';

    public static function inMemory(bool $withRun = true): InMemoryConductorScheduleRepository
    {
        return new InMemoryConductorScheduleRepository(
            $withRun ? self::run() : null,
            self::sources(),
            self::settings(),
            self::r86(),
            self::demand(),
            self::restock(),
            self::jobs(),
            self::machines(),
            self::ops(),
            self::passes(),
            self::batches(),
            self::draws(),
            self::stock(),
            self::capacity(),
            self::load(),
            self::issues(),
        );
    }

    /** @return RunRecord */
    public static function run(): array
    {
        return [
            'schemaVersion' => 1, 'builtAt' => '2026-10-28T09:12:44Z', 'engineVersion' => '1.0.0', 'sourceName' => 'cs-source-2026-10-28',
            'today' => '2026-10-28', 'horizonEnd' => '2026-11-22', 'dataAsOf' => '2026-10-28', 'demand' => 'r86-order', 'rateWindow' => '13w',
            'settingsHash' => '9f2c1a7be03d4a11', 'settingsSource' => 'seed', 'inventoryFile' => 'inventory.csv', 'inventoryExported' => '2026-10-26',
            'r86OrderFile' => 'R-86_ORD_MTD_2610', 'r86OrderFrom' => '2026-10-01', 'r86OrderPrinted' => '2026-10-27T20:01:57', 'r86OrderWeeks' => 3.714,
            'r86InvoiceFile' => 'R-86_INV_MTD_2610', 'r86InvoiceFrom' => '2026-10-01', 'r86InvoicePrinted' => '2026-10-27T20:02:31', 'r86InvoiceWeeks' => 3.714,
            'restockItems' => 3, 'releaseItems' => 1, 'releaseRuns' => 4, 'releaseM' => 44960.0, 'releaseKg' => 6402.304,
            'jobs' => 4, 'jobsScheduled' => 3, 'ops' => 3, 'passes' => 5, 'batches' => 2, 'lateOps' => 1,
        ];
    }

    /** @return list<SourceRecord> */
    public static function sources(): array
    {
        return [
            ['name' => 'inventory.csv', 'role' => 'inventory', 'asOf' => '2026-10-26', 'rows' => 5626],
            ['name' => 'production-order-headers.csv', 'role' => 'headers', 'asOf' => '2026-10-28', 'rows' => 9000],
            ['name' => 'R-86_ORD_MTD_2610', 'role' => 'r86-order', 'asOf' => '2026-10-27', 'rows' => null],
        ];
    }

    /** @return list<SettingRecord> */
    public static function settings(): array
    {
        $json = [
            'refill_weeks' => '3',
            'safety_weeks' => '1',
            'rate_window' => '"13w"',
            'demand' => '"r86-order"',
            'r86_min_weeks' => '2',
            'rod_warn_weeks' => '8',
            'yields' => '{"rod_break": 1.0, "tinning": 1.0}',
            'batch_sizes' => '{}',
            'shifts' => '{}',
            'shutdown' => '[{"from": "2026-12-19", "to": "2027-01-02", "note": "Christmas"}]',
        ];
        $out = [];
        foreach ($json as $key => $value) {
            $out[] = ['key' => $key, 'value' => json_decode($value, false, 512, JSON_THROW_ON_ERROR)];
        }

        return $out;
    }

    /** @return list<R86Record> */
    public static function r86(): array
    {
        return [
            ['doc' => 'order', 'material' => 'plain', 'mica' => 0, 'csaMm2' => 16.0, 'metres' => 74280.0, 'weeklyM' => 20000.0, 'placedM' => 20000.0, 'unplacedM' => 0.0],
            ['doc' => 'order', 'material' => 'plain', 'mica' => 1, 'csaMm2' => 16.0, 'metres' => 11142.0, 'weeklyM' => 3000.0, 'placedM' => 3000.0, 'unplacedM' => 0.0],
            ['doc' => 'order', 'material' => 'tinned', 'mica' => 0, 'csaMm2' => 2.5, 'metres' => 17827.2, 'weeklyM' => 4800.0, 'placedM' => 0.0, 'unplacedM' => 4800.0],
            ['doc' => 'order', 'material' => 'plain', 'mica' => 0, 'csaMm2' => 25.0, 'metres' => 4473.5, 'weeklyM' => 1204.5, 'placedM' => 0.0, 'unplacedM' => 1204.5],
            ['doc' => 'invoice', 'material' => 'plain', 'mica' => 0, 'csaMm2' => 16.0, 'metres' => 263694.0, 'weeklyM' => 71000.0, 'placedM' => 0.0, 'unplacedM' => 71000.0],
            ['doc' => 'invoice', 'material' => 'plain', 'mica' => 1, 'csaMm2' => 16.0, 'metres' => 1857.0, 'weeklyM' => 500.0, 'placedM' => 0.0, 'unplacedM' => 500.0],
        ];
    }

    /** @return list<DemandRecord> */
    public static function demand(): array
    {
        return [
            ['item' => 'NM-1C16', 'material' => 'plain', 'mica' => true, 'csaMm2' => 16.0, 'source' => 'r86-order', 'weeklyUseM' => 2000.0, 'issueWeeklyM' => 1900.0, 'issueWeekly52wM' => 1800.0, 'unplaced' => false],
            ['item' => 'NX-1C16', 'material' => 'plain', 'mica' => false, 'csaMm2' => 16.0, 'source' => 'r86-order', 'weeklyUseM' => 23000.0, 'issueWeeklyM' => 21000.0, 'issueWeekly52wM' => 20000.0, 'unplaced' => false],
            ['item' => 'DX-1C2.5', 'material' => 'tinned', 'mica' => false, 'csaMm2' => 2.5, 'source' => 'issues', 'weeklyUseM' => 5000.0, 'issueWeeklyM' => 5000.0, 'issueWeekly52wM' => 4700.0, 'unplaced' => true],
        ];
    }

    /** @return list<RestockRecord> */
    public static function restock(): array
    {
        return [
            [
                'item' => 'NM-1C16', 'form' => 'conductor', 'material' => 'plain', 'mica' => true, 'csaMm2' => 16.0, 'kgPerM' => 0.15,
                'weeklyUseM' => 2000.0, 'kanbanM' => 5000.0, 'kanbanAssumed' => false, 'runs52w' => 4, 'triggerM' => 8000.0,
                'physicalM' => 12000.0, 'incomingM' => 0.0, 'positionM' => 12000.0, 'weeksOnHand' => 6.0, 'releaseRuns' => 0,
                'releaseM' => 0.0, 'releaseKg' => 0.0, 'status' => 'ok', 'scheduled' => false, 'note' => 'not scheduled: taping not modelled',
            ],
            [
                'item' => 'DX-1C2.5', 'form' => 'conductor', 'material' => 'tinned', 'mica' => false, 'csaMm2' => 2.5, 'kgPerM' => 0.0225,
                'weeklyUseM' => 5000.0, 'kanbanM' => 20000.0, 'kanbanAssumed' => false, 'runs52w' => 6, 'triggerM' => 20000.0,
                'physicalM' => 60000.0, 'incomingM' => 0.0, 'positionM' => 60000.0, 'weeksOnHand' => 12.0, 'releaseRuns' => 0,
                'releaseM' => 0.0, 'releaseKg' => 0.0, 'status' => 'ok', 'scheduled' => true, 'note' => null,
            ],
            [
                'item' => 'NX-1C16', 'form' => 'conductor', 'material' => 'plain', 'mica' => false, 'csaMm2' => 16.0, 'kgPerM' => 0.1424,
                'weeklyUseM' => 23000.0, 'kanbanM' => 11240.0, 'kanbanAssumed' => false, 'runs52w' => 12, 'triggerM' => 92000.0,
                'physicalM' => 50000.0, 'incomingM' => 0.0, 'positionM' => 50000.0, 'weeksOnHand' => 2.1739, 'releaseRuns' => 4,
                'releaseM' => 44960.0, 'releaseKg' => 6402.304, 'status' => 'release', 'scheduled' => true, 'note' => null,
            ],
        ];
    }

    /** @return list<JobRecord> */
    public static function jobs(): array
    {
        return [
            ['jobId' => 4, 'item' => 'NX-1C16', 'runNo' => 4, 'metres' => 11240.0, 'dueDate' => '2026-11-22', 'status' => 'no_rate'],
            ['jobId' => 1, 'item' => 'NX-1C16', 'runNo' => 1, 'metres' => 11240.0, 'dueDate' => '2026-11-12', 'status' => 'scheduled'],
            ['jobId' => 2, 'item' => 'NX-1C16', 'runNo' => 2, 'metres' => 11240.0, 'dueDate' => '2026-11-15', 'status' => 'scheduled'],
            ['jobId' => 3, 'item' => 'NX-1C16', 'runNo' => 3, 'metres' => 11240.0, 'dueDate' => '2026-11-19', 'status' => 'scheduled'],
        ];
    }

    /** @return list<MachineRecord> the 12 machines, in stage order */
    public static function machines(): array
    {
        $rows = [
            // [machine, poster, name, calendar, source]
            ['RB1', 'RB2', 'Rod Breaker 1 (Niehoff)', 'CAL-RB', 'dynamics'],
            ['TL1', 'TN1', 'Tinning Line 1', 'CAL-DRAW', 'dynamics'],
            ['WD3', 'WD3', 'Wire Drawer 3', 'CAL-DRAW', 'override'],
            ['WD4', 'WD4', 'Wire Drawer 4', 'CAL-DRAW', 'dynamics'],
            ['WD5', 'WD5', 'Wire Drawer 5', 'CAL-DRAW', 'dynamics'],
            ['BU1', 'BU1', 'Bunching 1', 'CAL-BU', 'dynamics'],
            ['BU2', 'BU2', 'Bunching 2', 'CAL-BU', 'dynamics'],
            ['BU4', 'BU4', 'Bunching 4', 'CAL-BU', 'dynamics'],
            ['BU5', 'BU5', 'Bunching 5', 'CAL-BU', 'dynamics'],
            ['BU6', 'BU6', 'Bunching 6', 'CAL-BU', 'dynamics'],
            ['BU7', 'BU7', 'Bunching 7', 'CAL-BU', 'dynamics'],
            ['ST1', 'ST1', 'Stranding 1', 'CAL-ST', 'dynamics'],
        ];
        $out = [];
        foreach ($rows as $i => [$machine, $poster, $name, $calendar, $source]) {
            $out[] = [
                'machine' => $machine, 'posterName' => $poster, 'name' => $name, 'calendar' => $calendar, 'calendarSource' => $source,
                'efficiency' => $machine === 'BU1' ? 0.85 : 1.0, 'hoursPerWeek' => $machine === 'RB1' ? 64.0 : 56.0, 'stageOrder' => $i + 1,
            ];
        }

        return $out;
    }

    /** @return list<OpRecord> */
    public static function ops(): array
    {
        return [
            [
                'opId' => 3, 'item' => 'NX-1C16', 'pool' => '25_FCON', 'metres' => 11240.0, 'requiredBy' => '2026-11-19',
                'startAt' => '2026-11-14T22:00', 'endAt' => '2026-11-22T06:00', 'lateDays' => 3, 'rodKg' => null, 'outKg' => null,
                'passCount' => 1, 'stockM' => 500.0,
            ],
            [
                'opId' => 1, 'item' => 'NX-1C16', 'pool' => '25_FCON', 'metres' => 11240.0, 'requiredBy' => '2026-11-12',
                'startAt' => '2026-10-28T06:00', 'endAt' => '2026-11-04T14:00', 'lateDays' => 0, 'rodKg' => 1600.5, 'outKg' => 1600.5,
                'passCount' => 2, 'stockM' => 0.0,
            ],
            [
                'opId' => 2, 'item' => 'NX-1C16', 'pool' => '25_FCON', 'metres' => 11240.0, 'requiredBy' => '2026-11-15',
                'startAt' => '2026-11-04T14:00', 'endAt' => '2026-11-14T22:00', 'lateDays' => 0, 'rodKg' => 1700.0, 'outKg' => 1615.0,
                'passCount' => 2, 'stockM' => 0.0,
            ],
        ];
    }

    /** @return list<PassRecord> */
    public static function passes(): array
    {
        $pass = static fn (int $id, int $op, ?int $parent, string $item, string $pool, int $depth, string $machine, string $operation, float $quantity, string $start, string $end, ?int $batch, float $stock = 0.0): array => [
            'passId' => $id, 'opId' => $op, 'parentPassId' => $parent, 'item' => $item, 'pool' => $pool, 'depth' => $depth,
            'machine' => $machine, 'eligible' => $depth === 0 ? ['BU1', 'BU2'] : [$machine], 'operation' => $operation,
            'quantity' => $quantity, 'unit' => 'm', 'baseRate' => 900.0, 'operationFactor' => 1.0, 'productFactor' => 1.0,
            'setupH' => 0.75, 'runH' => 12.4, 'startAt' => $start, 'endAt' => $end, 'batchId' => $batch, 'stockM' => $stock,
        ];

        return [
            $pass(5, 3, null, 'NX-1C16', '25_FCON', 0, 'BU1', 'BUNCHING', 10740.0, '2026-11-20T06:00', '2026-11-22T06:00', null, 500.0),
            $pass(2, 1, 1, 'NX-1W1.6', '15_WIR', 1, 'RB1', 'ROD_BREAK', 300000.0, '2026-10-28T06:30', '2026-11-02T22:30', 1),
            $pass(1, 1, null, 'NX-1C16', '25_FCON', 0, 'BU1', 'BUNCHING', 11240.0, '2026-11-03T06:00', '2026-11-04T14:00', null),
            $pass(4, 2, 3, 'NX-1W1.6', '15_WIR', 1, 'RB1', 'ROD_BREAK', 300000.0, '2026-10-28T06:30', '2026-11-02T22:30', 1),
            $pass(3, 2, null, 'NX-1C16', '25_FCON', 0, 'BU2', 'BUNCHING', 11240.0, '2026-11-13T06:00', '2026-11-14T22:00', null),
        ];
    }

    /** @return list<BatchRecord> */
    public static function batches(): array
    {
        return [
            [
                'batchId' => 2, 'item' => 'DX-1W1.6', 'pool' => '15_WIR', 'machine' => 'TL1', 'operation' => 'TINNING', 'metres' => 150000.0,
                'batchRule' => 'lot_for_lot', 'allocatedM' => 100000.0, 'surplusM' => 50000.0, 'baseRate' => 5000.0, 'setupH' => 0.5,
                'runH' => 30.0, 'startAt' => '2026-11-03T06:00', 'endAt' => '2026-11-04T12:00', 'opCount' => 1, 'reason' => 'min_level',
            ],
            [
                'batchId' => 1, 'item' => 'NX-1W1.6', 'pool' => '15_WIR', 'machine' => 'RB1', 'operation' => 'ROD_BREAK', 'metres' => 825000.0,
                'batchRule' => 'history', 'allocatedM' => 750000.0, 'surplusM' => 75000.0, 'baseRate' => 27000.0, 'setupH' => 1.0,
                'runH' => 30.5, 'startAt' => '2026-10-28T06:30', 'endAt' => '2026-11-02T22:30', 'opCount' => 2, 'reason' => 'shortage',
            ],
        ];
    }

    /** @return list<DrawRecord> */
    public static function draws(): array
    {
        return [
            ['batchId' => 1, 'consumerKind' => 'op', 'consumerId' => 2, 'metres' => 300000.0],
            ['batchId' => 1, 'consumerKind' => 'batch', 'consumerId' => 2, 'metres' => 150000.0],
            ['batchId' => 1, 'consumerKind' => 'op', 'consumerId' => 1, 'metres' => 300000.0],
            ['batchId' => 2, 'consumerKind' => 'op', 'consumerId' => 3, 'metres' => 100000.0],
        ];
    }

    /** @return list<StockRecord> */
    public static function stock(): array
    {
        return [
            [
                'item' => 'NX-1C16', 'point' => 'conductor', 'pool' => '', 'unit' => 'm', 'onHandM' => 50000.0, 'usedM' => 500.0,
                'onHandKg' => 7120.0, 'usedKg' => 71.2, 'incomingM' => 0.0, 'incomingKg' => 0.0, 'kgPerM' => 0.1424, 'weeklyUse' => null,
            ],
            [
                'item' => 'DX-1W1.6', 'point' => 'wire_tinned', 'pool' => '15_WIR', 'unit' => 'm', 'onHandM' => 0.0, 'usedM' => 0.0,
                'onHandKg' => null, 'usedKg' => null, 'incomingM' => null, 'incomingKg' => null, 'kgPerM' => 0.0182, 'weeklyUse' => null,
            ],
            [
                'item' => 'NX-1R8-NEX', 'point' => 'rod', 'pool' => '', 'unit' => 'kg', 'onHandM' => null, 'usedM' => null,
                'onHandKg' => 44709.91, 'usedKg' => 0.0, 'incomingM' => null, 'incomingKg' => 2612823.4, 'kgPerM' => null, 'weeklyUse' => 70000.0,
            ],
            [
                'item' => 'NX-1W1.6', 'point' => 'wire_plain', 'pool' => '15_WIR', 'unit' => 'm', 'onHandM' => 307590.73, 'usedM' => 0.0,
                'onHandKg' => 5474.9, 'usedKg' => 0.0, 'incomingM' => 144835000.0, 'incomingKg' => 2578063.0, 'kgPerM' => 0.0178, 'weeklyUse' => null,
            ],
        ];
    }

    /** @return list<CapacityRecord> */
    public static function capacity(): array
    {
        $out = [];
        foreach (self::machines() as $m) {
            foreach (self::weeks() as $i => $week) {
                $hours = $m['machine'] === 'RB1' && $i === 2 ? 0.0 : ($m['machine'] === 'BU1' ? 47.6 : $m['hoursPerWeek']);
                $out[] = ['machine' => $m['machine'], 'week' => $week, 'availableH' => $hours];
            }
        }

        return $out;
    }

    /** @return list<LoadRecord> machines without a row have no load */
    public static function load(): array
    {
        return [
            ['machine' => 'BU1', 'week' => '2026-10-26', 'hours' => 23.8],
            ['machine' => 'BU1', 'week' => '2026-11-02', 'hours' => 47.6],
            ['machine' => 'BU1', 'week' => '2026-11-09', 'hours' => 11.9],
            ['machine' => 'RB1', 'week' => '2026-10-26', 'hours' => 40.0],
            ['machine' => 'RB1', 'week' => '2026-11-02', 'hours' => 16.0],
            ['machine' => 'RB1', 'week' => '2026-11-30', 'hours' => 8.0],
        ];
    }

    /** @return list<IssueRecord> */
    public static function issues(): array
    {
        return [
            ['item' => 'NX-1C16', 'kind' => 'rate_borrowed', 'severity' => 'info', 'detail' => 'rate borrowed from BU2', 'count' => 3],
            ['item' => '(all)', 'kind' => 'rod_cover_low', 'severity' => 'warning', 'detail' => 'rod cover 0.6 weeks', 'count' => 1],
            ['item' => 'NM-1C16', 'kind' => 'mica_not_scheduled', 'severity' => 'info', 'detail' => 'taping not modelled', 'count' => 1],
            ['item' => '(all)', 'kind' => 'r86_short_period', 'severity' => 'warning', 'detail' => 'R-86 covers 1.9 weeks', 'count' => 1],
            ['item' => 'DX-1C2.5', 'kind' => 'rate_borrowed', 'severity' => 'info', 'detail' => 'rate borrowed from the operation median', 'count' => 2],
            ['item' => 'NX-1C16', 'kind' => 'no_rate', 'severity' => 'warning', 'detail' => 'no rate on any eligible machine', 'count' => 1],
        ];
    }

    /** @return list<string> the six Mondays from {@see FIRST_WEEK} */
    public static function weeks(): array
    {
        return ['2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30'];
    }
}
