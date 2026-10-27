<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableRoutes;

use PDO;
use RuntimeException;

/**
 * Builds a throwaway cable routes store for tests: it runs the engine's own schema.sql and inserts the worked example
 * of docs/cable-routes/CONTRACT.md (KA9X-PASN/5C16BK, 15 made items, 10 steps). A change to the schema that breaks
 * these inserts fails a PHP test. Rows the plan does not print are marked "fixture only".
 */
final class FixtureStore
{
    public const ITEM = 'KA9X-PASN/5C16BK';

    /** @var list<string> */
    private static array $created = [];

    public static function schemaPath(): string
    {
        return dirname(__DIR__, 2) . '/core/python/cable_routes/schema.sql';
    }

    /** A complete store. */
    public static function create(): string
    {
        $path = self::tempPath();
        $pdo = self::open($path);
        self::fill($pdo);

        return $path;
    }

    /** A store with the schema but no cr_run row. */
    public static function createWithoutRun(): string
    {
        $path = self::tempPath();
        $pdo = self::open($path);
        self::fill($pdo);
        $pdo->exec('DELETE FROM cr_run');

        return $path;
    }

    /** A store written by some other schema version. */
    public static function createWithVersion(int $version): string
    {
        $path = self::tempPath();
        $pdo = self::open($path);
        self::fill($pdo);
        $pdo->exec('PRAGMA user_version = ' . $version);

        return $path;
    }

    /** A path in the temp folder where nothing exists. */
    public static function missingPath(): string
    {
        return sys_get_temp_dir() . '/cable-routes-missing-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        self::$created = [];
    }

    private static function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/cable-routes-fixture-' . bin2hex(random_bytes(6)) . '.sqlite';
        self::$created[] = $path;

        return $path;
    }

    private static function open(string $path): PDO
    {
        $schema = file_get_contents(self::schemaPath());
        if ($schema === false) {
            throw new RuntimeException('core/python/cable_routes/schema.sql is missing.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($schema);

        return $pdo;
    }

    /** @param array<string, string|int|float|null> $row */
    private static function insert(PDO $pdo, string $table, array $row): void
    {
        $columns = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $pdo->prepare('INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . $marks . ')')->execute(array_values($row));
    }

    private static function fill(PDO $pdo): void
    {
        $pdo->beginTransaction();
        self::finished($pdo);
        self::madeItems($pdo);
        self::needs($pdo);
        self::machines($pdo);
        self::reference($pdo);
        self::checks($pdo);
        self::insert($pdo, 'cr_run', [
            'run_id' => 1, 'schema_version' => 1, 'built_at' => '2026-10-27T03:12:44Z', 'source_name' => 'dynamics',
            'data_as_of' => '2026-09-04', 'engine_version' => '1.0.0', 'finished_total' => 5, 'finished_traced' => 3,
            'made_items' => 15, 'trace_to_rod' => 1, 'trace_to_purchased_conductor' => 1, 'trace_stops_early' => 1,
            'untraceable_no_orders' => 1, 'untraceable_cutting_only' => 1, 'parsed_fit' => 4, 'parsed_other' => 1,
            'cycle_edges_cut' => 1, 'check_rows' => 7,
        ]);
        $pdo->commit();
    }

    private static function finished(PDO $pdo): void
    {
        $rows = [
            // [item, pool, code, construction, colour, parse_ok, diameter, kg/m, status, reason, reason_items, steps, depth]
            [self::ITEM, '50_FMAN', 'KA', '5C16', 'BK', 1, 27.85, 1.678, 'to_rod', null, null, 10, 8],
            ['KA9X-PASN/3C2.5', '50_FMAN', 'KA', '3C2.5', '', 1, 14.2, 0.55, 'to_purchased_conductor', 'purchased_conductor', 'N1-1W2,N2-3W1', 4, 3], // fixture only
            ['KA9X-PASN/5C16RD', '50_FMAN', 'KA', '5C16', 'RD', 1, 27.85, 1.678, 'stops_early', 'no_rod_in_chain', 'ZZ-MAT', 2, 1], // fixture only
            ['KA-STRANGE', '60_CAT', 'KA', 'other', '', 0, null, null, 'untraceable', 'no_orders', null, 0, 0], // fixture only
            ['R2X-AAAA/3C1.5', '50_FMAN', 'R2', '3C1.5', '', 1, 11.0, 0.3, 'untraceable', 'cutting_only', null, 0, 0], // fixture only
        ];
        foreach ($rows as $r) {
            self::insert($pdo, 'cr_finished', [
                'item' => $r[0], 'pool' => $r[1], 'code' => $r[2], 'construction' => $r[3], 'colour' => $r[4], 'parse_ok' => $r[5],
                'diameter_mm' => $r[6], 'kg_per_m' => $r[7], 'unit' => 'm', 'trace_status' => $r[8], 'reason' => $r[9],
                'reason_items' => $r[10], 'step_count' => $r[11], 'max_depth' => $r[12],
            ]);
        }
    }

    private static function madeItems(PDO $pdo): void
    {
        // item, pool, diameter, kg/m, order, status, date, real orders, operation, machine
        $items = [
            [self::ITEM, '50_FMAN', 27.85, 1.678, '151107M', 'Completed', '2023-12-18', 1, 'SHEATHING', 'EX4'],
            ['WAKA9X-PASN/5C16', '40_SFG', 24.62, 1.41547, 'W0037316', 'Completed', '2026-02-27', 2, 'ARMOURING', 'LU3'],
            ['BEKA9X-PASN/5C16OR', '40_SFG', 20.38, 0.77711, 'W0037315', 'Completed', '2026-02-25', 2, 'BEDDING', 'EX3'],
            ['LUKA9X-PASN/5C16', '40_SFG', 18.34, 0.68552, 'W0037314', 'Completed', '2026-02-18', 2, 'LAYUP', 'LU9'],
            ['LM1X-XXXN/1C16RE', '35_WIP', 6.72, 0.15193, 'W0037194', 'Scheduled', '2026-09-04', 11, 'INSULATION', 'EX6'],
            ['LM1X-XXXN/1C16WH', '35_WIP', 6.72, 0.15194, 'W0048787', 'Scheduled', '2026-09-04', 15, 'INSULATION', 'EX6'],
            ['LM1X-XXXN/1C16BL', '35_WIP', 6.72, 0.15191, 'W0046227', 'StartedUp', '2026-09-04', 41, 'INSULATION', 'EX6'],
            ['LM1X-XXXN/1C16BK', '35_WIP', 6.72, 0.15193, 'W0046228', 'StartedUp', '2026-09-04', 39, 'INSULATION', 'EX6'],
            ['LM16X-XXXN/1C6EA', '35_WIP', 4.61, 0.06322, 'W0043404', 'Released', '2026-09-04', 26, 'INSULATION', 'EX6'],
            ['NX-1C16', '25_FCON', 5.18, 0.1361, 'W0049406', 'Released', '2026-09-04', 391, 'BUNCHING', 'BU1'],
            ['NX-1C6', '25_FCON', 3.17, 0.05124, 'W0048517', 'Released', '2026-09-04', 38, 'BUNCHING', 'BU2'],
            ['NX-32W.3', '20_BUN', 1.96, 0.01937, 'W0050075', 'ReportedFinished', '2026-09-04', 393, 'BUNCHING', 'BU6'],
            ['NX-8W.3', '15_WIR', 0.98, 0.00484, 'W0047291', 'Released', '2026-09-04', 929, 'WIRE_DRAW', 'WD4'],
            ['NX-7W.3', '15_WIR', 0.92, 0.00424, 'W0038782', 'Released', '2026-09-04', 322, 'WIRE_DRAW', 'WD4'],
            ['NX-1W1.6', '15_WIR', 1.6, 0.01804, 'W0049517', 'Released', '2026-09-04', 473, 'ROD_BREAK', 'RB1'],
        ];
        foreach ($items as $i) {
            self::insert($pdo, 'cr_item', [
                'item' => $i[0], 'pool' => $i[1], 'diameter_mm' => $i[2], 'kg_per_m' => $i[3], 'unit' => 'm', 'order_no' => $i[4],
                'order_status' => $i[5], 'order_date' => $i[6], 'real_orders' => $i[7], 'live_orders' => $i[7],
            ]);
            self::insert($pdo, 'cr_operation', [
                'item' => $i[0], 'seq' => 1, 'operation_number' => 10, 'operation' => $i[8], 'machine' => $i[9],
            ]);
        }
        // The same operation also ran on BU3 for 11 of NX-1C16's orders; BU1 is the step's own machine and must not be listed.
        self::insert($pdo, 'cr_alt_machine', ['item' => 'NX-1C16', 'operation' => 'BUNCHING', 'machine' => 'BU1', 'orders' => 380]);
        self::insert($pdo, 'cr_alt_machine', ['item' => 'NX-1C16', 'operation' => 'BUNCHING', 'machine' => 'BU3', 'orders' => 11]);

        $materials = [
            ['NX-1R8-NEX', 'Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe', 'kg', 8.0, 1],
            ['RS0002', null, 'kg', null, 0],
            ['MB_BLACK', 'Colour Masterbatch - BLACK', 'kg', null, 0],
            ['MB_ORANGE', 'Colour Masterbatch - ORANGE', 'kg', null, 0],
            ['GSWA_1.60MM', null, 'kg', 1.6, 0],
            ['TAPE_FPP130-40MM', null, 'kg', null, 0],
            ['FILLER_PP', null, 'm', null, 0], // fixture only: a material counted in metres, to test that kg sorts first
        ];
        foreach ($materials as $m) {
            self::insert($pdo, 'cr_material', ['item' => $m[0], 'name' => $m[1], 'unit' => $m[2], 'diameter_mm' => $m[3], 'conductor' => $m[4]]);
        }

        // item, line, input, qty per unit, unit, parallel ends, kind
        $inputs = [
            [self::ITEM, 1, 'WAKA9X-PASN/5C16', 1.0, 'm', 1, 'made'],
            [self::ITEM, 2, 'RS0002', 0.21541, 'kg', 1, 'material'],
            [self::ITEM, 3, 'MB_BLACK', 0.0057, 'kg', 1, 'material'],
            ['WAKA9X-PASN/5C16', 1, 'BEKA9X-PASN/5C16OR', 1.0, 'm', 1, 'made'],
            ['WAKA9X-PASN/5C16', 2, 'GSWA_1.60MM', 0.61207, 'kg', 1, 'material'],
            ['WAKA9X-PASN/5C16', 3, 'TAPE_FPP130-40MM', 0.01314, 'kg', 1, 'material'],
            ['BEKA9X-PASN/5C16OR', 1, 'LUKA9X-PASN/5C16', 1.0, 'm', 1, 'made'],
            ['BEKA9X-PASN/5C16OR', 2, 'RS0002', 0.08933, 'kg', 1, 'material'],
            ['BEKA9X-PASN/5C16OR', 3, 'MB_ORANGE', 0.00225, 'kg', 1, 'material'],
            ['LUKA9X-PASN/5C16', 1, 'LM1X-XXXN/1C16RE', 1.0099, 'm', 1, 'made'],
            ['LUKA9X-PASN/5C16', 2, 'LM1X-XXXN/1C16WH', 1.0099, 'm', 1, 'made'],
            ['LUKA9X-PASN/5C16', 3, 'LM1X-XXXN/1C16BL', 1.0099, 'm', 1, 'made'],
            ['LUKA9X-PASN/5C16', 4, 'LM1X-XXXN/1C16BK', 1.0099, 'm', 1, 'made'],
            ['LUKA9X-PASN/5C16', 5, 'LM16X-XXXN/1C6EA', 1.0139, 'm', 1, 'made'],
            ['LUKA9X-PASN/5C16', 6, 'FILLER_PP', 5.0, 'm', 1, 'material'],
            ['LM1X-XXXN/1C16RE', 1, 'NX-1C16', 1.0, 'm', 1, 'made'],
            ['LM1X-XXXN/1C16WH', 1, 'NX-1C16', 1.0, 'm', 1, 'made'],
            ['LM1X-XXXN/1C16BL', 1, 'NX-1C16', 1.0, 'm', 1, 'made'],
            ['LM1X-XXXN/1C16BK', 1, 'NX-1C16', 1.0, 'm', 1, 'made'],
            ['LM16X-XXXN/1C6EA', 1, 'NX-1C6', 1.0, 'm', 1, 'made'],
            ['NX-1C16', 1, 'NX-32W.3', 7.02611, 'm', 7, 'made'],
            ['NX-1C6', 1, 'NX-7W.3', 7.0, 'm', 7, 'made'], // fixture only
            ['NX-32W.3', 1, 'NX-8W.3', 4.00232, 'm', 1, 'made'],
            ['NX-8W.3', 1, 'NX-1W1.6', 0.26829, 'm', 1, 'made'],
            ['NX-7W.3', 1, 'NX-1W1.6', 0.2, 'm', 1, 'made'], // fixture only
            ['NX-1W1.6', 1, 'NX-1R8-NEX', 0.01804, 'kg', 1, 'material'],
            // fixture only: a backward edge of the kind the engine cuts; the API must not serve it.
            ['NX-1W1.6', 2, 'NX-1C16', 0.001, 'm', 1, 'made'],
        ];
        foreach ($inputs as $r) {
            self::insert($pdo, 'cr_input', [
                'item' => $r[0], 'line' => $r[1], 'input_item' => $r[2], 'qty_per_unit' => $r[3], 'unit' => $r[4],
                'parallel_ends' => $r[5], 'kind' => $r[6],
            ]);
        }
    }

    private static function needs(PDO $pdo): void
    {
        // finished, item, unit, need per unit, depth, kind, is_rod
        $rows = [
            [self::ITEM, self::ITEM, 'm', 1.0, 0, 'finished', 0],
            [self::ITEM, 'WAKA9X-PASN/5C16', 'm', 1.0, 1, 'made', 0],
            [self::ITEM, 'BEKA9X-PASN/5C16OR', 'm', 1.0, 2, 'made', 0],
            [self::ITEM, 'LUKA9X-PASN/5C16', 'm', 1.0, 3, 'made', 0],
            [self::ITEM, 'LM1X-XXXN/1C16RE', 'm', 1.0099, 4, 'made', 0],
            [self::ITEM, 'LM1X-XXXN/1C16WH', 'm', 1.0099, 4, 'made', 0],
            [self::ITEM, 'LM1X-XXXN/1C16BL', 'm', 1.0099, 4, 'made', 0],
            [self::ITEM, 'LM1X-XXXN/1C16BK', 'm', 1.0099, 4, 'made', 0],
            [self::ITEM, 'LM16X-XXXN/1C6EA', 'm', 1.0139, 4, 'made', 0],
            [self::ITEM, 'NX-1C16', 'm', 4.0396, 5, 'made', 0],
            [self::ITEM, 'NX-1C6', 'm', 1.0139, 5, 'made', 0],
            [self::ITEM, 'NX-32W.3', 'm', 28.3827, 6, 'made', 0],
            [self::ITEM, 'NX-7W.3', 'm', 7.0973, 6, 'made', 0],
            [self::ITEM, 'NX-8W.3', 'm', 113.5, 7, 'made', 0],
            [self::ITEM, 'NX-1W1.6', 'm', 31.9, 8, 'made', 0],
            [self::ITEM, 'NX-1R8-NEX', 'kg', 0.5755, 9, 'material', 1],
            [self::ITEM, 'RS0002', 'kg', 0.30474, 1, 'material', 0],
            [self::ITEM, 'MB_BLACK', 'kg', 0.0057, 1, 'material', 0],
            [self::ITEM, 'GSWA_1.60MM', 'kg', 0.61207, 2, 'material', 0],
            [self::ITEM, 'FILLER_PP', 'm', 5.0, 4, 'material', 0],
            ['KA9X-PASN/3C2.5', 'KA9X-PASN/3C2.5', 'm', 1.0, 0, 'finished', 0],
            ['KA9X-PASN/5C16RD', 'KA9X-PASN/5C16RD', 'm', 1.0, 0, 'finished', 0],
        ];
        foreach ($rows as $r) {
            self::insert($pdo, 'cr_need', [
                'finished' => $r[0], 'item' => $r[1], 'unit' => $r[2], 'need_per_unit' => $r[3], 'depth' => $r[4], 'kind' => $r[5], 'is_rod' => $r[6],
            ]);
        }
    }

    private static function machines(PDO $pdo): void
    {
        $base = [
            'in_poster' => 1, 'd365_status' => 'routed', 'hidden' => 0, 'poster_status' => 'existing',
            'made_items' => 0, 'finished_items' => 0, 'codes' => 0,
        ];
        $machines = [
            ['machine' => 'RB1', 'poster_name' => 'RB2', 'd365_id' => 'RB1', 'd365_status' => 'alias', 'site' => 'Port Melbourne', 'stage_no' => 1,
                'stage' => 'Rod Breaking', 'brand' => 'NIEHOFF', 'machine_type' => 'Rod breaker', 'input_limit' => '8 mm soft Cu rod',
                'speed_text' => '29 m/s', 'speed_m_per_min' => 1740.0, 'made_items' => 1, 'finished_items' => 1, 'codes' => 1],
            ['machine' => 'WD4', 'poster_name' => 'WD4', 'd365_id' => 'WD4', 'stage_no' => 2, 'stage' => 'Wire drawing', 'made_items' => 2, 'finished_items' => 1, 'codes' => 1],
            ['machine' => 'BU1', 'poster_name' => 'BU1', 'd365_id' => 'BU1', 'stage_no' => 3, 'stage' => 'Bunching', 'made_items' => 1, 'finished_items' => 1, 'codes' => 1],
            ['machine' => 'BU2', 'poster_name' => 'BU2', 'd365_id' => 'BU2', 'stage_no' => 3, 'stage' => 'Bunching', 'made_items' => 1, 'finished_items' => 1, 'codes' => 1],
            ['machine' => 'BU6', 'poster_name' => 'BU6', 'd365_id' => 'BU6', 'stage_no' => 3, 'stage' => 'Bunching', 'made_items' => 1, 'finished_items' => 1, 'codes' => 1],
            ['machine' => 'EX6', 'poster_name' => 'EX6', 'd365_id' => 'EX6', 'stage_no' => 4, 'stage' => 'Insulation', 'brand' => 'Troester',
                'machine_type' => 'Extruder', 'output_capability' => 'Cores to 10 mm', 'pay_off' => 'Drum', 'take_up' => 'Drum', 'other_notes' => 'Cores are not printed.',
                'legibility' => 'none', 'made_items' => 5, 'finished_items' => 2, 'codes' => 1],
            ['machine' => 'LU9', 'poster_name' => 'LU9', 'd365_id' => 'LU9', 'stage_no' => 4, 'stage' => 'Lay-up', 'made_items' => 1, 'finished_items' => 1, 'codes' => 1],
            ['machine' => 'EX3', 'poster_name' => 'EX3', 'd365_id' => 'EX3', 'stage_no' => 5, 'stage' => 'Bedding', 'made_items' => 1, 'finished_items' => 1, 'codes' => 1],
            ['machine' => 'LU3', 'poster_name' => 'LU3', 'd365_id' => 'LU3', 'stage_no' => 5, 'stage' => 'Armouring', 'made_items' => 1, 'finished_items' => 1, 'codes' => 1],
            ['machine' => 'EX4', 'poster_name' => 'EX4', 'd365_id' => 'EX4', 'stage_no' => 5, 'stage' => 'Sheathing', 'made_items' => 1, 'finished_items' => 1, 'codes' => 1],
            // Retired: kept for checks, never listed.
            ['machine' => 'EX8', 'poster_name' => 'EX8', 'd365_id' => 'EX8', 'd365_status' => 'retired', 'hidden' => 1, 'poster_status' => 'not_in_use',
                'stage_no' => 5, 'stage' => 'Sheathing'],
            // In Dynamics but not on the poster: no poster name, no stage.
            ['machine' => 'CPD1', 'poster_name' => null, 'd365_id' => 'CPD1', 'in_poster' => 0, 'd365_status' => 'not_on_poster', 'poster_status' => null],
            // On the poster, never routed.
            ['machine' => 'BR2', 'poster_name' => 'BR2', 'd365_id' => null, 'd365_status' => 'no_routings', 'stage_no' => 6, 'stage' => 'Braiding'],
        ];
        foreach ($machines as $m) {
            self::insert($pdo, 'cr_machine', $m + $base);
        }
        self::insert($pdo, 'cr_machine_limit', ['machine' => 'EX6', 'min_mm' => null, 'max_mm' => 10.0, 'note' => 'poster limit, 2023']);
        self::insert($pdo, 'cr_machine_limit', ['machine' => 'BR2', 'min_mm' => 4.0, 'max_mm' => 45.0, 'note' => null]);

        self::insert($pdo, 'cr_machine_finished', ['machine' => 'EX6', 'finished' => self::ITEM, 'steps' => 5]);
        self::insert($pdo, 'cr_machine_finished', ['machine' => 'EX6', 'finished' => 'KA9X-PASN/5C16RD', 'steps' => 3]);
        foreach (['RB1', 'WD4', 'BU1', 'BU2', 'BU6', 'LU9', 'EX3', 'LU3', 'EX4', 'EX8'] as $machine) {
            self::insert($pdo, 'cr_machine_finished', ['machine' => $machine, 'finished' => self::ITEM, 'steps' => 1]);
        }
    }

    private static function reference(PDO $pdo): void
    {
        self::insert($pdo, 'cr_family', ['seq' => 1, 'family' => 'Armoured cables', 'line_colour' => '#cc0000', 'codes_text' => 'KA R2', 'poster_route' => 'LAYUP > BEDDING > ARMOURING > SHEATHING', 'expected_ops' => 'LAYUP BEDDING ARMOURING SHEATHING', 'notes' => null]);
        self::insert($pdo, 'cr_family', ['seq' => 2, 'family' => 'Flexible', 'line_colour' => '#0000cc', 'codes_text' => 'KA', 'poster_route' => null, 'expected_ops' => null, 'notes' => null]);
        self::insert($pdo, 'cr_family_code', ['family' => 'Armoured cables', 'code' => 'KA']);
        self::insert($pdo, 'cr_family_code', ['family' => 'Armoured cables', 'code' => 'R2']);
        self::insert($pdo, 'cr_family_code', ['family' => 'Flexible', 'code' => 'KA']);

        self::insert($pdo, 'cr_range', ['seq' => 1, 'range_name' => 'Industrial', 'code' => 'KA', 'description' => '110 °C power cable with steel wire armour', 'voltage' => '0.6/1kV', 'insulation_jacket' => 'X-HF-110/PVC/SWA/PVC', 'conductor' => 'CU', 'max_temp_c' => 110.0, 'legibility' => null]);
        self::insert($pdo, 'cr_range', ['seq' => 2, 'range_name' => 'Marine', 'code' => 'KA', 'description' => 'Second KA row', 'voltage' => null, 'insulation_jacket' => null, 'conductor' => null, 'max_temp_c' => null, 'legibility' => null]);
        self::insert($pdo, 'cr_range', ['seq' => 3, 'range_name' => 'Industrial', 'code' => 'ZZ', 'description' => 'Poster only', 'voltage' => '1kV', 'insulation_jacket' => 'PVC', 'conductor' => 'AL', 'max_temp_c' => 70.0, 'legibility' => null]);

        self::insert($pdo, 'cr_code', ['code' => 'KA', 'description' => '110 °C power cable with steel wire armour', 'in_poster' => 1, 'finished_items' => 4, 'traced_items' => 3]);
        self::insert($pdo, 'cr_code', ['code' => 'R2', 'description' => null, 'in_poster' => 0, 'finished_items' => 1, 'traced_items' => 0]);
        self::insert($pdo, 'cr_code', ['code' => 'ZZ', 'description' => 'Poster only', 'in_poster' => 1, 'finished_items' => 0, 'traced_items' => 0]);
    }

    private static function checks(PDO $pdo): void
    {
        // id, kind, item, code, machine, operation, detail, affected, total
        $rows = [
            [1, 'family_route_mismatch', null, 'R2', null, 'ARMOURING', 'ARMOURING expected for R2; none in Dynamics', 18, 18],
            [2, 'machine_not_on_poster', null, null, 'CPD1', null, 'CPD1 is routed in Dynamics but is not on the poster.', null, null],
            [3, 'poster_machine_unused', null, null, 'BR2', null, 'BR2 is on the poster; no Dynamics routings use it.', null, null],
            [4, 'routed_to_retired_machine', 'NX-8W.3', null, 'EX8', 'WIRE_DRAW', 'Order W0047291 (Released, 2026-09-04) runs on EX8, a machine that is not used.', null, null],
            [5, 'outside_poster_limit', 'LM1X-XXXN/1C16RE', null, 'EX6', 'INSULATION', 'input is 12 mm; poster limit, 2023: up to 10 mm', null, null],
            [6, 'stops_early', null, 'KA', null, null, '1 of 3 KA cables stop before copper rod or purchased conductor (no_rod_in_chain).', 1, 3],
            [7, 'untraceable', null, 'R2', null, null, '1 of 1 R2 cables have only cutting orders (cutting_only).', 1, 1],
        ];
        foreach ($rows as $r) {
            self::insert($pdo, 'cr_check', [
                'check_id' => $r[0], 'kind' => $r[1], 'item' => $r[2], 'code' => $r[3], 'machine' => $r[4], 'operation' => $r[5],
                'detail' => $r[6], 'affected' => $r[7], 'total' => $r[8],
            ]);
        }
    }
}
