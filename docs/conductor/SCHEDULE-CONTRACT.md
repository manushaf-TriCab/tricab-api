# Conductor restock and schedule: shared contract

Status: contract, written 2026-10-28 for three builders (engine, API, web) working in parallel. Owner: Manusha.
Approved by Manusha 2026-10-28 with the proposals in section 10 accepted. Plan: `planning/conductor/PLATFORM-SCHEDULER-PLAN.md` (authoritative;
where it and the prototype disagree, the plan wins). Prototype: `planning/conductor/` (`restock_check.py`,
`consumption_rate.py`, `tricab-api/core/python/conductor_schedule/`).

Extends `docs/conductor/CONTRACT.md` (Conductor process). Everything there about layout, naming, store pattern, envelope,
permissions and tests applies unchanged; where this file is silent, copy that file, then `docs/cable-routes/CONTRACT.md`
(2 platform patterns, 3.3 atomic rebuild, 4.1 exit codes, 4.2 config precedence, 8 tests). Do not change Cable routes or
Conductor process behaviour.

## 0. Scope and decisions

A read-only demo in **Production > Conductor**: (1) **Restock**: which finished conductors need making, how many runs;
(2) **Schedule**: when and on which machine each run is made, from rod onward, finite capacity, shared 1.6 mm batches.
Nothing writes to Dynamics or 4js. The build reads files only (no Dynamics access).

| # | Decision (plan section 3) | Consequence in this contract |
|---|---|---|
| D1 | Demand = R-86 ORD (4js sales orders MTD); INV optional | `settings.demand` (3.2); both R-86 files are always read |
| D2 | Stock = Dynamics on-hand (correct); "Ordered in total" = incoming, "On order" = outgoing; warehouse QC excluded | 5.2 |
| D3 | Kanban refill; 8-week lead time is rod only | 4.4 |
| D4 | Stock points: rod, plain 1.6 (`NX-1W1.6`), tinned 1.6 (`DX-1W1.6`), finished conductor; no .3 spool or bunch inventory | `cs_wire_policy` is 1.6 mm only; spool stock on hand is used up first |
| D5 | Spools interchangeable by strand count within coating and diameter | pooled draw, issue `spool_substituted` |
| D6 | Yield per step, 100% for the demo | `settings.yields` |
| D7 | Weight to finished conductor, metres after; engine schedules in metres | store has m and kg columns; web formatting in 7.4 |
| D8 | Per-machine shift override, empty by default | `settings.shifts`, `cs_machine.calendar_source` |
| D9 | Copper, plain and tinned. Aluminium out. Mica taping (30_TAPE) out | mica restock rows are written, `scheduled = 0` |

Permission: reuse `CONDUCTOR.READ`. No new permission, no seed SQL.

## 1. Layout and names

| | API repo (`platform/tricab-api-conductor`, branch `feat/conductor-schedule`) |
|---|---|
| Engine | `core/python/conductor_schedule/` (stdlib only, Python 3.12; imports `cable_routes`, `conductor_process`), CLI `python -m conductor_schedule build\|status` |
| Schema, seed | `core/python/conductor_schedule/schema.sql` (section 5), `seed/settings.json` (3.1) |
| Engine tests | `core/python/tests/conductor_schedule/` |
| Source copy tool | `core/python/tools/make_schedule_source.py` (2.3) |
| Core | `core/src/Conductor/Schedule/...` (`TriCab\Core\Conductor\Schedule\` Vocabulary, Contract, Application\Query, Application\Service, Application\Exception). `Module.php` unchanged |
| Persistence | `src/Conductor/Schedule/Persistence/` (`SqliteConductorScheduleRepository`, `InMemoryConductorScheduleRepository`) |
| HTTP | `src/Http/Action/Conductor/Schedule/`, `src/Http/Auth/ConductorScheduleErrorMapper.php`, routes in `AppBuilder::registerConductorRoutes` after Process; DI in `ContainerFactory` as Process |
| Config | `CONDUCTOR_SCHEDULE_DB_PATH` (default `var/conductor/conductor-schedule.sqlite`), `CONDUCTOR_SCHEDULE_SOURCE_DIR` (engine only, no default), `CONDUCTOR_SCHEDULE_SETTINGS` (engine only, optional). `.env.example` lines as Process. `.gitignore` already has `/var/conductor/`. `pyproject.toml`: add package `conductor_schedule`, package-data `schema.sql`, `seed/*.json` |
| Web repo (`platform/tricab-web-conductor`, `feat/conductor-schedule`) | Client `src/api/admin/conductorSchedule.ts` + `.test.ts`; pages in `src/components/admin/conductor/schedule/` (7.x) |

Error codes (all under the conductor schedule endpoints):

| Code | Status | When |
|---|---|---|
| `CONDUCTOR_SCHEDULE_STORE_UNAVAILABLE` | 503 | store missing, unreadable, wrong `user_version`, or `cs_run` empty |
| `CONDUCTOR_PARAM_INVALID` | 422 | any bad query value (existing code, shared with Process) |
| `CONDUCTOR_OP_REQUIRED` / `CONDUCTOR_BATCH_REQUIRED` | 422 | `id` missing (added here, mirrors `CONDUCTOR_ITEM_REQUIRED`) |
| `CONDUCTOR_OP_NOT_FOUND` / `CONDUCTOR_BATCH_NOT_FOUND` | 404 | unknown id |

The 503 message never says why or where (as Process).

## 2. Source folder

`CONDUCTOR_SCHEDULE_SOURCE_DIR` is one flat folder. Missing file or required column: exit 3, message
`<file>: missing column X` / `missing file <file>`; all headers are checked before any slow read.

### 2.1 Files

Windows mirror paths (`D:\TriCab\...`). `snap` = `planning\dynamics\snapshot-2026-10-28\`.

| File in folder | Origin | Required columns | Used for |
|---|---|---|---|
| `production-order-headers-raw.csv` | `snap` | `ProductionOrderNumber, ItemNumber, ProductionOrderStatus, ScheduledDate, CsIsCuttingOrder, ProductionPoolId, CsReceivedQuantity, ReportedAsFinishedDate` | build tree (via `conductor_process`), kanban size |
| `production-bom-lines-raw.csv` | `snap` | `ProductionOrderNumber, LineNumber, ItemNumber, BOMLineQuantity, BOMLineQuantityDenominator, BOMLineUnitSymbol, CsPrdParallelLines` | build tree |
| `production-route-operations-raw.csv` | `snap` | `ProductionOrderNumber, OperationNumber, OperationId, CostingOperationResourceId` | build tree and machine history (see open question Q1) |
| `released-products.csv` | `snap` | `ItemNumber, CsDiameter, NetProductWeight, InventoryUnitSymbol, ProductionPoolId, ProductSearchName` | kg/m, issue classification |
| `manufactured-items.csv` | `snap` | `item_number, pool` | build tree |
| `purchase-order-lines-all.csv` | `snap` | `ItemNumber, LineDescription` | build tree (names only) |
| `production-order-headers.csv` | `snap` | `ProductionOrderNumber, ItemNumber, ProductionPoolId, ProductionOrderStatus, ScheduledQuantity, CsReceivedQuantity, ScheduledStartDate, ReportedAsFinishedDate` | issue-rate order classification, 1.6 mm batch history |
| `production-picking-list-lines.csv` | `snap` | `ProductionOrderNumber, ItemNumber, ConsumptionDate, IsPosted, ConsumptionBOMQuantity, ScrapBOMQuantity, BOMUnitSymbol` | weekly issue rate (port of `consumption_rate.py`) |
| `inventory.csv` | `planning\conductor\inventory-2026-10-26\full-inventory.csv` | `Item number, Warehouse, Physical inventory, Ordered in total, Inventory unit` (file has a UTF-8 BOM; read `utf-8-sig`) | stock |
| `inventory-meta.json` | `planning\conductor\inventory-2026-10-26\summary.json` (copied) | key `export_date` (`YYYY-MM-DD`) | inventory date. Absent: file mtime, issue `inventory_date_unknown` |
| `r86/R-86_ORD_MTD_<yymm>`, `r86/R-86_INV_MTD_<yymm>` (no extension) | **`D:\TriCab\platform\r86\`** (not `planning\r86`) | text format, 2.2 | demand |
| `production-route-jobs-raw.csv` | `planning\dynamics\` (2026-09-04 extract) | `ProductionOrderNumber, OperationNumber, OperationsResourceId, RouteJobType, IsJobCancelled` | machine history |
| `machine-operation-parameters-full.csv` | `planning\dynamics\` | `operation, resource, orders, setup_minutes_median, run_rate_per_hour_median` | rates and setups |
| `work-calendars.csv` | `planning\dynamics\` | `CalendarId, BaseWorkCalendarId, CalendarName` | machine calendars |
| `work-calendar-time-intervals.csv` | `planning\dynamics\` | `WorkCalendarId, WorkCalendarDate, StartTime, EndTime, EfficiencyPercentage` | calendar intervals |

All of these exist on this machine with the listed columns (checked 2026-10-28). The R-86 folder must hold at least one ORD
and one INV file, else exit 3.

### 2.2 R-86 files

Format as `R86ReportParser.php` (port to `r86.py`, same line rules, `VERSION = 'r86-v1'`). Checked against the two files in
`platform\r86\`: **both parse cleanly and the size lines sum to the printed `Total:` line** (ORD 35 size lines, INV 41).
Differences/notes against the prototype parser's assumptions:

- The period line ends with `->` and no end date (open period). Parser already allows it.
- The 10th column on a size line (`X`, e.g. 5687 on C1.5) is optional and **outside** the totals ("unknown style" metres).
  Keep it as `unknown_style_m` per bucket; it is not demand.
- Files have no extension and contain form feeds (`\f`) after the Total line: strip them.
- Both are plain ASCII. Sizes: `C<csa>` copper (`C.5` = 0.5 mm²), `A<...>` aluminium (ignored, D9). Aluminium rows are still
  needed to check the total.
- Any unrecognised line, a repeated size, or sums not matching the total: reject the file, exit 3 (as the prototype's
  `CONDUCTOR_IMPORT_INVALID`).
- Demand columns used: copper rows, `pacw` (plain) `NOmica_m`, `mica_m`; `tacw` (tinned) `NOmica_m`, `mica_m`. Weight columns
  are not used.

**Which file is "latest" (both files here are named `_2610`; the folder copy is newer than `planning\r86`):** per type
(ORD, INV), among files in `r86/` whose header `DocTyp` matches the type (the filename type is only a hint; mismatch with
the header is an error, exit 3), pick by, in order: (1) the `Printed:` date and time inside the file, latest wins;
(2) the period start, latest; (3) file mtime; (4) filename, descending. Record in `cs_run` the file name, `PeriodFrom`,
printed timestamp and weeks elapsed of each chosen file, and log the other candidates (name + printed timestamp) as issue
`r86_older_ignored` (info). `weeks elapsed = (printed date - period from) / 7`, or 1 when that is 0 days. The two chosen files
need not share a period; `r86_period_mismatch` (warning) when their `PeriodFrom` differs. On 2026-10-28 the platform files
print `27Oct'26` (ORD 20:01:57, INV 20:02:31) from `01Oct'26`: 26 days, 3.71 weeks.

### 2.3 Copy tool `core/python/tools/make_schedule_source.py`

```
python core/python/tools/make_schedule_source.py DEST
    [--dynamics DIR]    default <TriCab>/planning/dynamics          (env CS_SRC_DYNAMICS)
    [--snapshot DIR]    default <dynamics>/snapshot-2026-10-28      (env CS_SRC_SNAPSHOT)
    [--inventory DIR]   default <TriCab>/planning/conductor/inventory-2026-10-26   (env CS_SRC_INVENTORY)
    [--r86 DIR]         default <TriCab>/platform/r86               (env CS_SRC_R86)
    [--force]           overwrite existing files in DEST
```
`<TriCab>` is the folder that holds `platform/` (this repo's grandparent), e.g. `D:\TriCab` or `~/Desktop/TriCab`.
Copies every file in 2.1 into DEST (R-86 files into `DEST/r86/`, `full-inventory.csv` as `inventory.csv`, `summary.json` as
`inventory-meta.json`), flat, **no renames other than those two**. Precedence: flag, environment, default. Exits 2 on a bad
folder, 3 when a source file or required column is missing (same check as the engine, so the folder is proven before the
build), 0 otherwise. Prints each file with size and the chosen R-86 printed timestamps. Never writes outside DEST. No
network.

## 3. Settings

### 3.1 `core/python/conductor_schedule/seed/settings.json` (defaults, committed)

```json
{
  "refill_weeks": 3,
  "safety_weeks": 1,
  "rate_window": "13w",
  "demand": "r86-order",
  "r86_min_weeks": 2,
  "rod_warn_weeks": 8,
  "yields": { "rod_break": 1.0, "tinning": 1.0, "wire_draw": 1.0, "bunching": 1.0, "conductor": 1.0 },
  "batch_sizes": {},
  "min_levels": {},
  "shifts": {},
  "efficiency": {},
  "shutdown": [
    { "from": "2026-12-19", "to": "2027-01-02", "note": "Christmas shutdown (finding 11, unconfirmed)" }
  ],
  "_example_shifts": {
    "note": "NOT ACTIVE. Inferred from Dynamics job start and finish times for the drawing line; not confirmed by Vineeth. Copy into 'shifts' only when confirmed.",
    "RB1": { "days": [0, 1, 2, 3], "intervals": [["14:00", "22:00"], ["22:00", "06:00"]] },
    "TL1": { "days": [0, 1, 2, 3], "intervals": [["14:00", "22:00"], ["22:00", "06:00"]] },
    "WD3": { "days": [0, 1, 2, 3], "intervals": [["14:00", "22:00"], ["22:00", "06:00"]] },
    "WD4": { "days": [0, 1, 2, 3], "intervals": [["14:00", "22:00"], ["22:00", "06:00"]] },
    "WD5": { "days": [0, 1, 2, 3], "intervals": [["14:00", "22:00"], ["22:00", "06:00"]] }
  },
  "_efficiency_reference": {
    "note": "NOT ACTIVE. CableBuilder lists these; Manusha decides whether to apply them.",
    "RB1": 0.8, "TL1": 0.8, "WD3": 0.8, "WD4": 0.8, "WD5": 0.8, "ST1": 0.8,
    "BU1": 0.85, "BU2": 0.85, "BU4": 0.85, "BU5": 0.85, "BU6": 0.85, "BU7": 0.85
  }
}
```
`r86_min_weeks` and `rod_warn_weeks` are added by this contract (the plan fixes 2 and 8 in prose; see Q6). `_`-prefixed keys are
ignored by the loader (JSON has no comments). `_efficiency_reference` lists RB/TL/WD/ST1 at 80% and BU at 85% per the plan;
TP is not scheduled so it is omitted.

### 3.2 Keys

| Key | JSON shape | Validation (violation: exit 2, message names the key and value) |
|---|---|---|
| `refill_weeks`, `safety_weeks` | number | >= 0 |
| `rate_window` | `"13w"\|"26w"\|"52w"` | one of the three; window of the issue-rate fallback |
| `demand` | `"r86-order"\|"r86-invoice"\|"issues"` | one of the three |
| `r86_min_weeks` | number | > 0; warning threshold for weeks elapsed |
| `rod_warn_weeks` | number | > 0; rod cover below this raises `rod_cover_low` |
| `yields` | `{step: fraction}` | steps in `rod_break, tinning, wire_draw, bunching, conductor` (unknown step: exit 2); each value in (0, 1]; missing steps = 1.0 |
| `batch_sizes`, `min_levels` | `{item: metres}` | value >= 0 number; item non-empty string; applies to 1.6 mm stock items only (`NX-1W1.6`, `DX-1W1.6`), another item: exit 2 |
| `shifts` | `{machine: {days: [0..6], intervals: [["HH:MM","HH:MM"], ...]}}` | machine is one of the 12 scheduled D365 ids (`RB1 TL1 WD3 WD4 WD5 BU1 BU2 BU4 BU5 BU6 BU7 ST1`; unknown: exit 2); `days` non-empty, unique ints 0..6 (0 = Monday); `intervals` non-empty; times match `^([01]\d\|2[0-3]):[0-5]\d$`; start != end; two intervals of one machine must not overlap on any day (after the midnight split of 4.6) |
| `efficiency` | `{machine: fraction}` | machine known (as `shifts`); value in (0, 1] |
| `shutdown` | `[{from, to, note?}]` | ISO dates, `from <= to`; empty list allowed |

### 3.3 Resolution and precedence

Override file path: `--settings FILE`, else `CONDUCTOR_SCHEDULE_SETTINGS` (process environment, then `<API root>/.env`, as
Cable routes 4.2). Missing file or invalid JSON: exit 2. Effective settings = seed, then each top-level key present in the
override **replaces** the seed value (no deep merge), except `yields`, where missing steps keep 1.0. Unknown top-level key
without `_` prefix: exit 2. Other env vars: `CONDUCTOR_SCHEDULE_DB_PATH`, `CONDUCTOR_SCHEDULE_SOURCE_DIR`; flag beats process
env beats `.env`. The effective settings are written to `cs_setting` (one row per key, JSON text) and hashed
(`sha256` of canonical JSON, sorted keys, no spaces, first 16 hex) into `cs_run.settings_hash`; `cs_run.settings_source` is
`seed` or the override file's basename.

## 4. Engine

### 4.1 Modules (`core/python/conductor_schedule/`)

| File | Content |
|---|---|
| `__init__.py`, `__main__.py` | `SCHEMA_VERSION = 1`, `__version__`; `python -m` entry |
| `cli.py` | argparse, exit codes (4.2) |
| `config.py` | env names, db/source/settings resolution; constants (`MACHINES`, `STAGE_ORDER`, `POSTER_NAME`, `ROD_ITEM = "NX-1R8-NEX"`, `START_HOUR = 6`, `MIN_SHARE = 0.10`, `SPOOL_MAX_WIRES = 16`, `BATCH_MIN_ORDERS = 5`, `YIELD_STEPS`, `MATERIAL` map, regexes, windows 364 days, `CAPACITY_WEEKS = 26`, `MAX_SCHEDULE_DAYS = 3650`) |
| `settings.py` | load seed + override, validate, hash |
| `source.py` | check all files and headers (exit 3); loaders; `r86` discovery |
| `r86.py` | R-86 parser (port of `R86ReportParser.php`), latest-file pick |
| `rates.py` | issue-based weekly use (port of `consumption_rate.py`, in memory) |
| `stock.py` | inventory to stock rows (5.2) |
| `restock.py` | demand split, kanban, trigger, releases, jobs (4.4) |
| `history.py` | `History`, `Rate`, eligibility, rates, aliases (from prototype `inputs.py`), calendars loading |
| `passes.py` | job to `Pass` tree from the process build tree (4.5) |
| `calendar.py` | `MachineCalendar` incl. shift override, efficiency (4.6) |
| `batch.py`, `schedule.py` | scheduler (4.7), ported with tests |
| `issues.py` | `IssueLog` (item, kind) with severity |
| `run.py` | orchestration, weekly load/capacity, row assembly |
| `store.py` | atomic write (as `conductor_process/store.py`, this schema) |
| `schema.sql`, `seed/settings.json` | 5, 3.1 |

The process build tree is obtained **in memory**: `conductor_process.source.load(folder)` then
`conductor_process.build.build_tables(src, extra, name)`, indexed by `COLS` names (`cd_item`, `cd_operation`, `cd_input`,
`cd_alt_machine`, `cd_material`). The Conductor process store is not read.

### 4.2 CLI

```
python -m conductor_schedule build [--source DIR] [--db FILE] [--settings FILE] [--today YYYY-MM-DD] [--quiet]
python -m conductor_schedule status [--db FILE]
```
`--today` default: the local date of the machine; it fixes every "today" below (tests and reproducible runs set it). `build`
prints the `cs_run` row as `key: value` lines; `status` prints the stored row. Exit codes (Cable routes 4.1): 0 ok; 1 other
failure (old store untouched, temp file removed); 2 usage or configuration (no source, bad settings, no store for `status`);
3 required file, column, or R-86 format problem.

### 4.3 Stock and weights (5.2 of the plan)

- `kg_per_m` = `released-products.NetProductWeight` for the item (positive), else the `consumption-rate` fallback is not
  used.
- Inventory rows with warehouse `QC` are dropped. `base` = item without a trailing `-S`. Unit `m` (and rod, any unit) stays;
  `t` or `kg` is converted to metres by `(1000 if t else 1) / kg_per_m(base)`; no `kg_per_m` and the item has stock or
  incoming: issue `inventory_unconverted` and skipped. Rod `NX-1R8-NEX` stays in kg.
- Per base item: `physical = sum(Physical inventory)`, `incoming = sum(Ordered in total)`; `-S` rows add to the base item
  and note `incl. purchased <item>`. `On order` (outgoing) is ignored.
- Incoming never counts toward cover on **wire** (CONTRACT-TRACKING): the scheduler uses physical only; restock position on
  finished conductor and spools is `physical + incoming` (4.4).

### 4.4 Restock: demand to releases (port of `restock_check.py`, kanban model)

1. **Item set.** Over inventory items and issue-rate items (unit `m`, flow `downstream`): finished conductor
   `^(NX|NM|MNX|MNM|DX|DM|MDX|MDM)-1C(\d*\.?\d+)$` (csa = number); spools `^(NX|DX)-(\d+)W(\.\d+)$` with `n <= 16` and a
   downstream issue rate > 0; `VX`/`VDX` `-1C` items with downstream use (issue rate only). Others are not restock items.
2. **Issue rate** (`rates.py`): for each posted picking line (`IsPosted == Yes`) of a copper item (`copper_basis` as the
   prototype: prefix in NX, DX, DM, MNX; review prefixes NM, MNM, VX, VDX, MDX, MDM, DXI, TCWB, PCWS; description match;
   pool in 15_WIR, 20_BUN, 25_FCON; plus the rod): flow `rod_in` for the rod, `internal` when the consuming order makes copper
   (its pool in the stage pools or its item has a copper basis), else `downstream`. `qty = ConsumptionBOMQuantity +
   ScrapBOMQuantity` (returns are negative, so a plain sum nets). Windows relative to `today`: `(today - N days, today]` for
   91, 182, 364 days; weekly = sum / 13, 26, 52. `issue_weekly` = the `rate_window` weekly for `downstream` m rows;
   `issue_weekly_52w` always the 52-week one; `rod_weekly_kg` = rod `rod_in` rate.
3. **Weekly use** (`demand = r86-*`): per bucket `(material, mica, csa)` from the chosen R-86 file, `bucket_weekly = metres /
   weeks_elapsed`. Material and mica of an item by prefix: NX plain/no, NM plain/yes, MNX plain/yes, MNM plain/yes, DX
   tinned/no, DM tinned/yes, MDX tinned/yes, MDM tinned/yes. Items in a bucket share it by `issue_weekly_52w` share; if the
   members have no 52-week history the **plain code** (NX, NM, DX, DM, in that preference within the bucket) gets all of it
   and the others 0. Items R-86 cannot place (spools, VX/VDX, or no bucket) use `issue_weekly` (source `issues`). With
   `demand = issues`, every item uses `issue_weekly`.
   R-86 metres in buckets with no matching restock item are stored as unplaced (`cs_r86.unplaced_m`); warn
   `r86_short_period` when weeks elapsed < `r86_min_weeks`.
4. **Kanban size.** Over `production-order-headers.csv` orders of the item with `ReportedAsFinishedDate` within 364 days of
   `today`, quantity `CsReceivedQuantity` if > 0 else `ScheduledQuantity` if > 0: `kanban = median` (even count: mean of
   the middle two; prototype takes the upper middle: use the **mean of the middle two**, see Q8); `runs_52w` = count. No runs:
   `kanban = weekly_use`, `kanban_assumed = 1`, note `no runs in 52 weeks; kanban size unknown (1 week of use assumed)`
   (issue `kanban_assumed`).
5. **Trigger and release.** `trigger = weekly_use * (refill_weeks + safety_weeks)`; `position = physical + incoming`;
   `weeks_on_hand = physical / weekly_use` (NULL when weekly use = 0). `weekly_use <= 0`: status `no_use`, release 0.
   `position <= trigger`: `release_runs = floor((trigger - position) / kanban) + 1`, status `release`. Otherwise `ok`.
   `release_m = release_runs * kanban`; `release_kg = release_m * kg_per_m` (NULL without kg/m).
6. **Mica** (`mica = 1`, i.e. NM, MNX, MNM, DM, MDX, MDM): rows are written, `scheduled = 0`, note `not scheduled: taping
   not modelled` (D9). Issue `mica_not_scheduled`.
7. **Jobs.** For each scheduled-eligible release row (`scheduled` still 1), runs `i = 0..release_runs-1` each become a job of
   `kanban` metres. `due_date = today + floor(max(0, physical / weekly_use) * 7 + i * kanban / weekly_use * 7)` days. Jobs
   are numbered `job_id` 1..n ordered by `(due_date, item, run_no)`. A restock item with no build tree: `scheduled = 0`,
   job not created, issue `no_build_tree` (4.5).
8. The restock `status` set is `release`, `ok`, `no_use`. Sorting for the API (not stored): release first (by `release_kg`
   desc, NULL last, then item), then ok, then no_use.

### 4.5 Jobs to passes

For a job (item, metres) take the process tree: made edges of `cd_input` (`kind = 'made'`) expanded depth-first from the item,
one `Pass` per edge occurrence (as the prototype: one per requirement), a node on the current path is cut. Quantity of a child
= parent quantity x `qty_per_unit` (`parallel_ends` not multiplied, as Process). Rod and other `material` inputs are not
passes; the rod kg of the job = sum over rod edges of quantity x yield growth, where the rod is `cd_material.is_rod`.
Inputs whose unit is not `m` are converted with the input item's `kg_per_m` (issue `qty_converted`) else used as is (issue
`qty_unit`). **Yield** (D6): a node's quantity is multiplied by the product of `1 / yield(step_of(ancestor))` over all its
ancestors including the root; `step_of`: pool `20_BUN` bunching; `25_FCON` conductor; `15_WIR` rod_break for `NX-1W1.6`,
tinning for `DX-1W1.6`, else wire_draw. Non-1.0 yields raise one info issue `yield_applied` (`(all)`). Each pass:

| Field | Source |
|---|---|
| pool | `cd_item.pool` |
| eligible machines | `History.eligible(item, pool)`: machines (of the 12 scheduled) with >= 10% of the item's non-cancelled Process jobs, each with its most frequent operation; else the pool's with issue `pool_fallback` |
| operation, rate, setup | per chosen machine from `machine-operation-parameters-full.csv`; machines without rows borrow the most common costing resource (issue `rate_borrowed`), else the operation median |

A job is skipped and its restock row marked `scheduled = 0` when the item is not a `cd_item`, is excluded off-map, has
`traces_to_rod = 0` (issue `no_build_tree`, detail names which), or a pass has no eligible machine (`no_machine`) or rate
(`no_rate`). The job row then carries the status (5, `cs_job.status`).

### 4.6 Calendars, shift override, efficiency (D8)

- **Default** (`calendar_source = dynamics`): per machine, the base calendar's intervals as the weekly pattern of the most
  recent full Monday to Sunday week before `today` that has intervals (prototype `week_pattern`), repeated; issue
  `calendar_pattern` per base calendar. Dynamics interval efficiency (`EfficiencyPercentage / 100`) is kept.
- **Override** (`calendar_source = override`): a machine in `settings.shifts` uses its own pattern instead; one issue
  `shift_override` per machine. Each interval `[s, e]` on each listed weekday `d`: if `e > s` the interval `s..e` goes on `d`;
  if `e <= s` it crosses midnight: `s..24:00` goes on `d` and `00:00..e` on `(d + 1) mod 7` (end `00:00` is `s..24:00` with no
  next-day part). Override intervals have efficiency 1.0.
- **Efficiency** (both sources): a machine in `settings.efficiency` multiplies every interval's efficiency by the fraction
  (productive hours = wall hours x interval efficiency x machine efficiency). `cs_machine.efficiency` stores it (1.0 default).
  `hours_per_week` is productive hours with all of this applied, shutdown ignored.
- **Shutdown**: `settings.shutdown` ranges (inclusive) remove all working time for every machine.
- Scheduling starts `today` 06:00 plant time; timestamps are plant-local, rounded to the minute (`YYYY-MM-DDTHH:MM`).

### 4.7 Scheduler (port `batch.py`, `schedule.py`, `calendar.py`, passes part of `build.py`, with their tests)

Rules kept from the prototype and plan 5.5:

1. Jobs are processed in `job_id` order. Within a job, children first (post-order); a machine does one pass at a time (no
   back-filling), earliest finish wins (tie: stage order `RB1 TL1 WD3 WD4 WD5 BU1 BU2 BU4 BU5 BU6 BU7 ST1`), setup charged on
   every pass. Duration = `setup_min/60 + quantity / rate_per_hour` (rates are per metre; no kg conversion).
2. **CONTRACT-STOCK:** stock on hand is taken first, parents first, in job order (`stock` drawn down in place); children shrink
   in proportion to the parent's remainder; a fully covered pass drops with its subtree; a fully covered job is not scheduled
   (`job_from_stock`, status `from_stock`).
3. **CONTRACT-TRACKING:** only `NX-1W1.6` and `DX-1W1.6` are batched and held at a minimum. Batch size: `settings.batch_sizes`,
   else the median `CsReceivedQuantity` (> 0) of that item's 15_WIR orders with status Completed/ReportedFinished finished in
   the 52 weeks before `today` (needs >= `BATCH_MIN_ORDERS` = 5 orders, else lot-for-lot with `no_batch_size`). Minimum:
   `settings.min_levels`, else `weekly use (received / 52) x median refill days / 7`, refill days = median
   `ReportedAsFinishedDate - ScheduledStartDate` in days, floor 1 (release date is not in the extract, Q2). Before a draw,
   batches are made until stock plus open batches would stay at or above the minimum after the draw (`reason` `min_level` vs
   `shortage`). Spools and bunches are made per job, after their 1.6 mm share is ready. Yield grows all inputs below a step.
4. **CONTRACT-POOL:** spool stock on hand (`NX/DX-nW.d`, n <= 16) is drawn by strand count across the same coating and
   diameter (stock first, then open batches, then a new batch of the named spool); each substitution logs `spool_substituted`.
   A spool that is a job's root or a feeder into another wire is drawn as named.
5. **CONTRACT-BATCH:** a consumer is ready when its share of a batch is made (`Batch.available_at`), not when the whole batch
   ends; batch passes show 0 hours and carry `batch_id`; the batch books one setup and its whole run.
6. `late_days = max(0, last working date of job end - due_date)`; an end at exactly 00:00 counts as the previous day.
7. Not ported: `--spool-stock`, `--no-pool`, `--no-batch`, `--basis`, ratecheck, MRM reads.

### 4.8 Issue kinds (`cs_issue.kind`, closed list; `severity` in brackets)

| Kind | Sev | Meaning |
|---|---|---|
| `r86_short_period` | warning | R-86 weeks elapsed < `r86_min_weeks` |
| `r86_period_mismatch` | warning | ORD and INV `PeriodFrom` differ |
| `r86_older_ignored` | info | another R-86 file of the type was not the latest |
| `r86_unplaced` | info | R-86 metres in a bucket with no restock item |
| `inventory_date_unknown` | warning | no `inventory-meta.json` |
| `inventory_unconverted` | warning | t or kg stock without kg/m |
| `kanban_assumed` | info | no runs in 52 weeks |
| `mica_not_scheduled` | info | mica release not scheduled (D9) |
| `no_build_tree` | warning | no cd_item, off-map, or not traced to rod |
| `job_from_stock` | info | job fully covered by on-hand |
| `covered_by_stock` | info | a pass with zero remainder, not scheduled |
| `no_machine` | warning | no history for the item or its pool, job dropped |
| `no_rate` | warning | no rate for any eligible machine, job dropped |
| `pool_fallback` | info | machines from the pool, item has no job history |
| `rate_borrowed` | info | rate row borrowed from another resource or the operation median |
| `qty_converted` | info | non-metre input converted with kg/m |
| `qty_unit` | warning | non-metre input without kg/m used as is |
| `no_weight` | info | job item without `NetProductWeight`, no kg out |
| `calendar_pattern` | info | weekly pattern taken from the week of ... |
| `shift_override` | info | machine on a settings shift pattern |
| `efficiency_applied` | info | machine efficiency below 100% |
| `yield_applied` | info | yields other than 1.0 in use |
| `spool_substituted` | info | bunching drew another spool of the same coating and diameter |
| `no_batch_size` | info | 1.6 mm item made lot-for-lot |
| `no_feeder_batch` | warning | parent batch made without this wire |
| `rod_cover_low` | warning | rod weeks of cover < `rod_warn_weeks` |
| `shutdown_in_horizon` | info | the shutdown range falls inside the scheduled horizon |

Issues are keyed by `(item, kind)`, first detail wins, `count` = occurrences. Item `(all)` for global ones.

## 5. Store (`schema.sql`)

Conventions as Process: dates `YYYY-MM-DD`, schedule timestamps plant-local `YYYY-MM-DDTHH:MM`, `built_at` UTC `...Z`,
booleans 0/1, unrounded REAL, missing = NULL. One run only (no `run_id` columns); the store is rebuilt in full, atomically.
Machine ids are D365 ids (`RB1`, `TL1`, ...) with `poster_name` alongside.

```sql
PRAGMA user_version = 1;

CREATE TABLE cs_run (
  run_id               INTEGER PRIMARY KEY CHECK (run_id = 1),
  schema_version       INTEGER NOT NULL,
  built_at             TEXT    NOT NULL,   -- UTC
  engine_version       TEXT    NOT NULL,
  source_name          TEXT    NOT NULL,   -- basename of the source folder
  today                TEXT    NOT NULL,
  horizon_end          TEXT    NOT NULL,   -- date of the last op end (or today when no ops)
  data_as_of           TEXT    NOT NULL,   -- newest ScheduledDate in the headers, ignoring 1900-01-01
  demand               TEXT    NOT NULL CHECK (demand IN ('r86-order', 'r86-invoice', 'issues')),
  rate_window          TEXT    NOT NULL,
  settings_hash        TEXT    NOT NULL,
  settings_source      TEXT    NOT NULL,   -- 'seed' or override file basename
  inventory_file       TEXT    NOT NULL,
  inventory_exported   TEXT,               -- YYYY-MM-DD, NULL only when unknown
  r86_order_file       TEXT    NOT NULL,   -- chosen file (basename)
  r86_order_from       TEXT    NOT NULL,   -- period start
  r86_order_printed    TEXT    NOT NULL,   -- printed timestamp inside the file, YYYY-MM-DDTHH:MM:SS
  r86_order_weeks      REAL    NOT NULL,
  r86_invoice_file     TEXT    NOT NULL,
  r86_invoice_from     TEXT    NOT NULL,
  r86_invoice_printed  TEXT    NOT NULL,
  r86_invoice_weeks    REAL    NOT NULL,
  restock_items        INTEGER NOT NULL,
  release_items        INTEGER NOT NULL,   -- status = 'release'
  release_runs         INTEGER NOT NULL,
  release_m            REAL    NOT NULL,
  release_kg           REAL    NOT NULL,   -- sum where known
  jobs                 INTEGER NOT NULL,
  jobs_scheduled       INTEGER NOT NULL,   -- jobs with an op
  ops                  INTEGER NOT NULL,
  passes               INTEGER NOT NULL,
  batches              INTEGER NOT NULL,
  late_ops             INTEGER NOT NULL
);

CREATE TABLE cs_source (                   -- the files the build read
  name    TEXT PRIMARY KEY,
  role    TEXT NOT NULL,                   -- e.g. 'headers', 'inventory', 'r86-order', 'calendars'
  as_of   TEXT,                            -- file date when known (mtime date, or printed date for R-86)
  rows    INTEGER
);

CREATE TABLE cs_setting (                  -- the effective settings
  key    TEXT PRIMARY KEY,
  value  TEXT NOT NULL                     -- JSON
);

CREATE TABLE cs_r86 (                      -- chosen R-86 copper buckets, both documents
  doc          TEXT    NOT NULL CHECK (doc IN ('order', 'invoice')),
  material     TEXT    NOT NULL CHECK (material IN ('plain', 'tinned')),
  mica         INTEGER NOT NULL,
  csa_mm2      REAL    NOT NULL,
  metres       REAL    NOT NULL,
  weekly_m     REAL    NOT NULL,           -- metres / weeks elapsed
  placed_m     REAL    NOT NULL,           -- weekly metres given to restock items (0 for 'invoice' buckets unless demand uses invoices)
  unplaced_m   REAL    NOT NULL,           -- weekly_m - placed_m
  PRIMARY KEY (doc, material, mica, csa_mm2)
);

CREATE TABLE cs_demand (                   -- one row per restock item
  item             TEXT PRIMARY KEY,
  material         TEXT NOT NULL,          -- plain | tinned | unknown
  mica             INTEGER NOT NULL,
  csa_mm2          REAL,                   -- NULL for spools
  source           TEXT NOT NULL CHECK (source IN ('r86-order', 'r86-invoice', 'issues')),
  weekly_use_m     REAL NOT NULL,
  issue_weekly_m   REAL NOT NULL,          -- rate_window issue rate
  issue_weekly_52w_m REAL NOT NULL,
  unplaced         INTEGER NOT NULL        -- 1 when R-86 could not place it and the issue rate is used
);

CREATE TABLE cs_restock (
  item             TEXT PRIMARY KEY REFERENCES cs_demand (item),
  form             TEXT NOT NULL CHECK (form IN ('conductor', 'spool')),
  material         TEXT NOT NULL,
  mica             INTEGER NOT NULL,
  csa_mm2          REAL,
  kg_per_m         REAL,
  weekly_use_m     REAL NOT NULL,
  kanban_m         REAL NOT NULL,
  kanban_assumed   INTEGER NOT NULL,
  runs_52w         INTEGER NOT NULL,
  trigger_m        REAL NOT NULL,
  physical_m       REAL NOT NULL,
  incoming_m       REAL NOT NULL,
  position_m       REAL NOT NULL,
  weeks_on_hand    REAL,
  release_runs     INTEGER NOT NULL,
  release_m        REAL NOT NULL,
  release_kg       REAL,
  status           TEXT NOT NULL CHECK (status IN ('release', 'ok', 'no_use')),
  scheduled        INTEGER NOT NULL,       -- 1 when jobs were created and a build tree exists
  note             TEXT
);
CREATE INDEX ix_cs_restock_status ON cs_restock (status, item);

CREATE TABLE cs_job (
  job_id        INTEGER PRIMARY KEY,
  item          TEXT    NOT NULL REFERENCES cs_restock (item),
  run_no        INTEGER NOT NULL,          -- 1..release_runs of that item
  metres        REAL    NOT NULL,
  due_date      TEXT    NOT NULL,
  status        TEXT    NOT NULL CHECK (status IN ('scheduled', 'from_stock', 'no_machine', 'no_rate')),
  UNIQUE (item, run_no)
);

CREATE TABLE cs_machine (                  -- the 12 scheduled machines, always all, in stage order
  machine          TEXT PRIMARY KEY,       -- D365 id
  poster_name      TEXT NOT NULL,          -- RB1 -> RB2, TL1 -> TN1, else same
  name             TEXT NOT NULL,          -- Dynamics CalendarName
  calendar         TEXT NOT NULL,          -- base calendar id (informational for overrides)
  calendar_source  TEXT NOT NULL CHECK (calendar_source IN ('dynamics', 'override')),
  efficiency       REAL NOT NULL,
  hours_per_week   REAL NOT NULL,
  stage_order      INTEGER NOT NULL UNIQUE
);

CREATE TABLE cs_op (                       -- one per scheduled job; op_id = job_id
  op_id        INTEGER PRIMARY KEY REFERENCES cs_job (job_id),
  item         TEXT    NOT NULL,
  pool         TEXT    NOT NULL,
  metres       REAL    NOT NULL,
  required_by  TEXT    NOT NULL,           -- the job's due date
  start_at     TEXT    NOT NULL,
  end_at       TEXT    NOT NULL,
  late_days    INTEGER NOT NULL,
  rod_kg       REAL,
  out_kg       REAL,
  pass_count   INTEGER NOT NULL,
  stock_m      REAL    NOT NULL DEFAULT 0
);
CREATE INDEX ix_cs_op_start ON cs_op (start_at);

CREATE TABLE cs_pass (
  pass_id          INTEGER PRIMARY KEY,
  op_id            INTEGER NOT NULL REFERENCES cs_op (op_id),
  parent_pass_id   INTEGER,
  item             TEXT    NOT NULL,
  pool             TEXT    NOT NULL,
  depth            INTEGER NOT NULL,
  machine          TEXT    NOT NULL REFERENCES cs_machine (machine),
  eligible         TEXT    NOT NULL,       -- JSON list of machine ids
  operation        TEXT    NOT NULL,
  quantity         REAL    NOT NULL,       -- metres to make (after stock)
  unit             TEXT    NOT NULL,
  base_rate        REAL    NOT NULL,       -- units per hour
  operation_factor REAL    NOT NULL,
  product_factor   REAL    NOT NULL,
  setup_h          REAL    NOT NULL,
  run_h            REAL    NOT NULL,
  start_at         TEXT    NOT NULL,
  end_at           TEXT    NOT NULL,
  batch_id         INTEGER REFERENCES cs_batch (batch_id),
  stock_m          REAL    NOT NULL DEFAULT 0
);
CREATE INDEX ix_cs_pass_op ON cs_pass (op_id);
CREATE INDEX ix_cs_pass_machine ON cs_pass (machine, start_at);

CREATE TABLE cs_batch (                    -- 1.6 mm batches
  batch_id     INTEGER PRIMARY KEY,
  item         TEXT    NOT NULL,
  pool         TEXT    NOT NULL,
  machine      TEXT    NOT NULL REFERENCES cs_machine (machine),
  operation    TEXT    NOT NULL,
  metres       REAL    NOT NULL,
  batch_rule   TEXT    NOT NULL CHECK (batch_rule IN ('history', 'config', 'lot_for_lot')),
  allocated_m  REAL    NOT NULL,
  surplus_m    REAL    NOT NULL,
  base_rate    REAL    NOT NULL,
  setup_h      REAL    NOT NULL,
  run_h        REAL    NOT NULL,
  start_at     TEXT    NOT NULL,
  end_at       TEXT    NOT NULL,
  op_count     INTEGER NOT NULL,
  reason       TEXT    NOT NULL CHECK (reason IN ('shortage', 'min_level'))
);
CREATE INDEX ix_cs_batch_start ON cs_batch (start_at);

CREATE TABLE cs_draw (
  batch_id       INTEGER NOT NULL REFERENCES cs_batch (batch_id),
  consumer_kind  TEXT    NOT NULL CHECK (consumer_kind IN ('op', 'batch')),
  consumer_id    INTEGER NOT NULL,
  metres         REAL    NOT NULL,
  PRIMARY KEY (batch_id, consumer_kind, consumer_id)
);
CREATE INDEX ix_cs_draw_consumer ON cs_draw (consumer_kind, consumer_id);

CREATE TABLE cs_stock (                    -- stock points and every item stock was drawn from
  item         TEXT PRIMARY KEY,
  point        TEXT NOT NULL CHECK (point IN ('rod', 'wire_plain', 'wire_tinned', 'spool', 'conductor', 'other')),
  pool         TEXT NOT NULL,              -- '' for rod and restock-only items
  unit         TEXT NOT NULL CHECK (unit IN ('m', 'kg')),   -- rod is kg, everything else m
  on_hand_m    REAL,                       -- NULL for rod
  used_m       REAL,                       -- NULL for rod
  on_hand_kg   REAL,                       -- NULL when no kg/m (rod: the on-hand quantity itself)
  used_kg      REAL,
  incoming_m   REAL,                       -- NULL for rod
  incoming_kg  REAL,
  kg_per_m     REAL,
  weekly_use   REAL                        -- rod: kg/week (rod_in); others NULL
);

CREATE TABLE cs_wire_policy (              -- 1.6 mm only
  item            TEXT PRIMARY KEY,
  batch_m         REAL,
  batch_rule      TEXT NOT NULL,
  min_m           REAL NOT NULL,
  min_rule        TEXT NOT NULL,
  weekly_use_m    REAL,
  refill_days     REAL,
  orders          INTEGER NOT NULL,
  on_hand_m       REAL NOT NULL,
  below_min_at_start INTEGER NOT NULL
);

CREATE TABLE cs_capacity (
  machine      TEXT NOT NULL REFERENCES cs_machine (machine),
  week         TEXT NOT NULL,              -- Monday, YYYY-MM-DD
  available_h  REAL NOT NULL,
  PRIMARY KEY (machine, week)
);

CREATE TABLE cs_load (
  machine  TEXT NOT NULL REFERENCES cs_machine (machine),
  week     TEXT NOT NULL,
  hours    REAL NOT NULL,
  PRIMARY KEY (machine, week)
);

CREATE TABLE cs_issue (
  item      TEXT NOT NULL,
  kind      TEXT NOT NULL,                 -- closed list 4.8
  severity  TEXT NOT NULL CHECK (severity IN ('info', 'warning')),
  detail    TEXT NOT NULL,
  count     INTEGER NOT NULL,
  PRIMARY KEY (item, kind)
);
CREATE INDEX ix_cs_issue_kind ON cs_issue (kind);
```

Invariants (engine tests assert): `cs_run` has 1 row; `cs_machine` has the 12 machines in stage order; `cs_op.op_id` is in
`cs_job`; every `cs_pass.machine`/`cs_batch.machine` in `cs_machine`; `cs_job.status = 'scheduled'` iff a `cs_op` row exists;
`cs_restock.scheduled = 0` for mica and for items with `no_build_tree`; `release_runs` jobs per scheduled `release` item
(minus `from_stock`); `cs_wire_policy` items are in `{NX-1W1.6, DX-1W1.6}`; `cs_load` hours per machine and week do not
exceed `cs_capacity`; `PRAGMA integrity_check` ok, `foreign_key_check` empty. `cs_run` counters equal the table counts.

Weekly load and capacity (port of `weekly()`): weeks from the Monday of `today` to `max(horizon + 4 weeks, 26 weeks)`; a bar's
hours (batch passes excluded, batches included once) are split across weeks by the working time inside each week.

## 6. API

Read-only, `CONDUCTOR.READ`, envelope `{success, data}`, camelCase, base `/api/v1/conductor/schedule`. Store problems: 503
as section 1. Numbers are unrounded; null for NULL. Booleans in JSON as `true/false`.

| Endpoint | Query params (validation) | `data` |
|---|---|---|
| `GET /run` | none | `{ run, counts, r86, sources, settings }` |
| `GET /restock` | `status` release\|ok\|no_use; `material` plain\|tinned; `scheduled` true\|false | `{ rows, bySize, totals }` |
| `GET /jobs` | `status` scheduled\|from_stock\|no_machine\|no_rate; `late` true\|false; `page` >= 1 (1); `pageSize` 1..200 (50) | `{ jobs, page, pageSize, totalJobs, totalPages }` |
| `GET /ops` | `machine` (D365 id, case-insensitive); `late` true\|false; `item`; `page`, `pageSize` as jobs | `{ ops, page, pageSize, totalOps, totalPages }` (each op with `passes`) |
| `GET /op` | `id` required int | `{ op }` (passes, pass tree by `parentPassId`) |
| `GET /batches` | none | `{ batches }` |
| `GET /batch` | `id` required int | `{ batch }` with `draws`, `feeders` |
| `GET /machines/load` | `weeks` 4\|8\|26 (default 4) | `{ weeks, from, machines }` |
| `GET /stock` | none | `{ rod, rows }` |
| `GET /issues` | `kind`; `severity` info\|warning | `{ issues, byKind }` |

Invalid value: 422 `CONDUCTOR_PARAM_INVALID`; `id` absent/blank: 422 `*_REQUIRED`; `id` not an integer: 422 `PARAM_INVALID`.

### `GET run`
```json
{ "run": { "builtAt": "2026-10-28T09:12:44Z", "engineVersion": "1.0.0", "sourceName": "cs-source-2026-10-28",
           "today": "2026-10-28", "horizonEnd": "2026-11-10", "dataAsOf": "2026-10-28", "demand": "r86-order",
           "rateWindow": "13w", "settingsHash": "9f2c1a7be03d4a11", "settingsSource": "seed", "schemaVersion": 1,
           "inventoryFile": "inventory.csv", "inventoryExported": "2026-10-26" },
  "r86": { "order":   { "file": "R-86_ORD_MTD_2610", "from": "2026-10-01", "printed": "2026-10-27T20:01:57", "weeks": 3.714,
                        "unplacedWeeklyM": 1204.5 },
           "invoice": { "file": "R-86_INV_MTD_2610", "from": "2026-10-01", "printed": "2026-10-27T20:02:31", "weeks": 3.714,
                        "unplacedWeeklyM": 800.0 },
           "shortPeriod": false },
  "counts": { "restockItems": 60, "releaseItems": 11, "releaseRuns": 16, "releaseM": 2300000, "releaseKg": 25000,
              "jobs": 16, "jobsScheduled": 13, "ops": 13, "passes": 70, "batches": 5, "lateOps": 2,
              "issuesWarning": 4, "issuesInfo": 30 },
  "sources": [ { "name": "inventory.csv", "role": "inventory", "asOf": "2026-10-26", "rows": 5626 } ],
  "settings": { "refillWeeks": 3, "safetyWeeks": 1, "yields": { "rod_break": 1.0 }, "shifts": {}, "efficiency": {},
                "shutdown": [ { "from": "2026-12-19", "to": "2027-01-02", "note": "..." } ], "...": "all keys, camelCase top level, inner keys as stored" } }
```
`run` null and counts zero only if `cs_run` is empty (not expected: 503 instead). `settings` values are the stored JSON
(top-level keys camelCased, nested keys, machine ids and steps unchanged).

### `GET restock`
```json
{ "rows": [ { "item": "NX-1C16", "form": "conductor", "material": "plain", "mica": false, "csaMm2": 16, "kgPerM": 0.1424,
              "weeklyUseM": 23000, "demandSource": "r86-order", "unplaced": false, "issueWeeklyM": 21000,
              "kanbanM": 11240, "kanbanAssumed": false, "runs52w": 269, "triggerM": 92000, "physicalM": 98760,
              "incomingM": 0, "positionM": 98760, "weeksOnHand": 4.29,
              "releaseRuns": 0, "releaseM": 0, "releaseKg": 0, "status": "ok", "scheduled": true, "note": null } ],
  "bySize": [ { "material": "plain", "csaMm2": 16, "weeklyUseM": 23000, "issueWeeklyM": 21000,
                "r86OrderedWeeklyM": 17600, "r86InvoicedWeeklyM": 71000, "triggerM": 92000, "physicalM": 98760,
                "incomingM": 0, "releaseM": 0 } ],
  "totals": { "items": 60, "releaseItems": 11, "releaseRuns": 16, "releaseM": 2300000, "releaseKg": 25000,
              "okItems": 30, "noUseItems": 19 } }
```
Rows sorted per 4.4 step 8 (`status` filter applies before sorting). `bySize`: mica and no-mica together, per
`(material, csaMm2)` over spools excluded, sorted by material then csa; the R-86 values are `cs_r86` weekly sums over mica
for that size (`material` filter applies; `status` and `scheduled` filters do not). `totals` reflect the filters.

### `GET jobs`
```json
{ "jobs": [ { "jobId": 1, "item": "NX-1C16", "runNo": 1, "metres": 11240, "dueDate": "2026-11-20", "status": "scheduled",
              "startAt": "2026-10-28T06:00", "endAt": "2026-11-04T14:00", "lateDays": 0, "rodKg": 1600.5, "outKg": 1600.5,
              "passCount": 4, "stockM": 0 } ],
  "page": 1, "pageSize": 50, "totalJobs": 16, "totalPages": 1 }
```
Sorted by `jobId`. Unscheduled jobs have null `startAt/endAt/lateDays/rodKg/outKg` and `passCount` 0.

### `GET ops`, `GET op`
```json
{ "ops": [ { "opId": 1, "item": "NX-1C16", "pool": "25_FCON", "metres": 11240, "requiredBy": "2026-11-20",
             "startAt": "2026-10-28T06:00", "endAt": "2026-11-04T14:00", "lateDays": 0, "rodKg": 1600.5, "outKg": 1600.5,
             "impliedYield": 1.0, "stockMetres": 0, "passCount": 4,
             "passes": [ { "passId": 1, "parentPassId": null, "item": "NX-1C16", "pool": "25_FCON", "depth": 0,
                           "machine": "BU1", "posterName": "BU1", "eligible": ["BU1", "BU2"], "operation": "BUNCHING",
                           "quantity": 11240, "unit": "m", "setupHours": 0.75, "runHours": 12.4,
                           "startAt": "2026-11-03T06:00", "endAt": "2026-11-04T14:00", "batchId": null, "stockMetres": 0 } ] } ],
  "page": 1, "pageSize": 50, "totalOps": 13, "totalPages": 1 }
```
`impliedYield = outKg / rodKg` when both set, else null. `GET op` returns `{ "op": <same op object> }` with passes sorted by
`passId`. Ops sorted by `opId`; passes by `passId`.

### `GET batches`, `GET batch`
`batches`: `{ "batches": [Batch] }` sorted by `batchId`, no draws.
```json
{ "batch": { "batchId": 3, "item": "NX-1W1.6", "pool": "15_WIR", "machine": "RB1", "posterName": "RB2", "operation": "ROD_BREAK",
             "metres": 825000, "batchRule": "history", "allocatedMetres": 700000, "surplusMetres": 125000,
             "setupHours": 1.0, "runHours": 30.5, "startAt": "2026-10-28T06:30", "endAt": "2026-11-02T22:30",
             "opCount": 2, "reason": "shortage", "weightKg": 15000,
             "draws": [ { "consumerKind": "op", "consumerId": 1, "metres": 300000, "item": "NX-1C16" } ],
             "feeders": [ { "batchId": 2, "item": "NX-1W1.6", "metres": 0 } ] } }
```
`weightKg` = `metres x kg_per_m` or null. `draws.item` = item of the consumer op or batch. `feeders` = batches that feed this
one (rows of `cs_draw` where this batch is the consumer, `consumerKind = 'batch'`); empty list when none.

### `GET machines/load?weeks=4`
```json
{ "weeks": 4, "from": "2026-10-26",
  "machines": [ { "machine": "RB1", "posterName": "RB2", "name": "Rod Breaker 1 (Niehoff)", "calendarSource": "dynamics",
                  "efficiency": 1.0, "hoursPerWeek": 64.0,
                  "weeks": [ { "week": "2026-10-26", "availableHours": 56.2, "loadHours": 40.5, "utilisation": 0.72 } ],
                  "totalAvailableHours": 220.0, "totalLoadHours": 150.2, "utilisation": 0.68 } ] }
```
`from` = Monday of `run.today`'s week; the first `weeks` weeks from there. `utilisation = load / available` (null when
available is 0). All 12 machines in stage order.

### `GET stock`
```json
{ "rod": { "item": "NX-1R8-NEX", "onHandKg": 44709.91, "incomingKg": 2612823.4, "weeklyUseKg": 70000, "weeksCover": 0.64,
           "warnWeeks": 8, "low": true },
  "rows": [ { "item": "NX-1W1.6", "point": "wire_plain", "pool": "15_WIR", "unit": "m", "onHandM": 307590.73, "usedM": 0,
              "onHandKg": 1200, "usedKg": 0, "incomingM": 144835000, "kgPerM": 0.0178 } ] }
```
`rod` null when the rod has no inventory row. `weeksCover = onHandKg / weeklyUseKg` (null when use is 0). `rows` excludes
the rod, sorted by point (`wire_plain, wire_tinned, spool, conductor, other`), then item.

### `GET issues`
```json
{ "issues": [ { "item": "NX-1C16", "kind": "kanban_assumed", "severity": "info", "detail": "...", "count": 1 } ],
  "byKind": [ { "kind": "no_build_tree", "severity": "warning", "items": 2, "count": 3 } ] }
```
`issues` sorted by severity (warning first), kind, item; `byKind` by severity then kind.

### PHP layout (matching Process)

| File | Role |
|---|---|
| `core/src/Conductor/Schedule/Vocabulary.php` | `SCHEMA_VERSION = 1`; lists: statuses, materials, issue kinds, severities, `LOAD_WEEKS = [4, 8, 26]`, page defaults |
| `core/src/Conductor/Schedule/Contract/ConductorScheduleRepository.php` | typed table rows (camelCase keys, bool as bool), `@phpstan-type` per table; every method may throw `ConductorScheduleStoreUnavailable` |
| `core/src/Conductor/Schedule/Application/Service/ConductorScheduleQueryService.php` | all payload shaping and validation, so the Sqlite and in-memory repositories cannot drift |
| `core/src/Conductor/Schedule/Application/Query/` | `JobsFilter`, `OpsFilter`, `RestockFilter`, `IssuesFilter` |
| `core/src/Conductor/Schedule/Application/Exception/` | `ConductorScheduleInvalidRequest` (carries `errorCode`), `ConductorScheduleOpNotFound`, `ConductorScheduleBatchNotFound`, `ConductorScheduleStoreUnavailable` |
| `src/Conductor/Schedule/Persistence/` | `SqliteConductorScheduleRepository` (read-only PDO, checks `user_version = 1` and a `cs_run` row), `InMemoryConductorScheduleRepository` |
| `src/Http/Action/Conductor/Schedule/` | `GetRunAction`, `GetRestockAction`, `ListJobsAction`, `ListOpsAction`, `GetOpAction`, `ListBatchesAction`, `GetBatchAction`, `GetMachineLoadAction`, `GetStockAction`, `ListIssuesAction`, trait `RespondsToConductorSchedule` |
| `src/Http/Auth/ConductorScheduleErrorMapper.php` | exceptions to envelope errors (4xx from section 1; anything else logs `[conductor-schedule]` and 503) |
| `AppBuilder::registerConductorRoutes` | 10 `get` routes under `/api/v1/conductor/schedule`, `->add(($require)($gate))` with `Module::PERMISSION_READ` |

## 7. Web

### 7.1 Routes (inside `ConductorShell`, under `/admin/conductor`)

| Tab | Route | Page (file in `src/components/admin/conductor/schedule/`) |
|---|---|---|
| Process (existing) | `/admin/conductor` | unchanged |
| Machines (existing) | `/admin/conductor/machines` | unchanged |
| **Restock** | `/admin/conductor/restock` | `RestockPage.tsx` |
| **Schedule** | `/admin/conductor/schedule` | `SchedulePage.tsx`, `Gantt.tsx`, `OpPanel.tsx`, `BatchPanel.tsx`, `JobsList.tsx`; selected bar in the query string `?op=<id>` / `?batch=<id>`; view `?view=gantt\|jobs` |
| **Machine load** | `/admin/conductor/load` | `LoadPage.tsx`; `?weeks=4\|8\|26` |
| **Issues** | `/admin/conductor/issues` | `IssuesPage.tsx`; `?kind=` |

`ConductorShell.TABS` gains the four tabs after Machines (order: Process, Machines, Restock, Schedule, Machine load, Issues).
Routes added to `App.tsx` as lazy children of the `conductor` route. A fetch of `GET schedule/run` is made by the shell tabs'
pages through a second context `ConductorScheduleRunContext` (`scheduleContext.ts`) provided by a small layout route wrapping
the four new routes, so Process and Machines never call the schedule API. Shared files edited: `ConductorShell.tsx`, `App.tsx`,
`format.ts` (new helpers only). `STORE_UNAVAILABLE` text: "The conductor schedule has not been built yet."

### 7.2 Client `src/api/admin/conductorSchedule.ts`

Same shape as `conductorProcess.ts`: `BASE = '/api/v1/conductor/schedule'`, `apiRequest`, defensive parsers (`str`, `num`,
`bool`, `recs`), `requireSuccessData`, `mapConductorScheduleError` (store unavailable, param invalid, not found, else
generic), `AbortSignal` on every call. Functions: `getScheduleRun`, `getScheduleRestock(query)`, `listScheduleJobs(query)`,
`listScheduleOps(query)`, `getScheduleOp(id)`, `listScheduleBatches()`, `getScheduleBatch(id)`,
`getScheduleMachineLoad(weeks)`, `getScheduleStock()`, `getScheduleIssues(query)`. Types (all camelCase, mirror section 6):

```ts
export const SCHEDULE_ERROR_CODES = { STORE_UNAVAILABLE: 'CONDUCTOR_SCHEDULE_STORE_UNAVAILABLE', PARAM_INVALID: 'CONDUCTOR_PARAM_INVALID',
  OP_REQUIRED: 'CONDUCTOR_OP_REQUIRED', OP_NOT_FOUND: 'CONDUCTOR_OP_NOT_FOUND',
  BATCH_REQUIRED: 'CONDUCTOR_BATCH_REQUIRED', BATCH_NOT_FOUND: 'CONDUCTOR_BATCH_NOT_FOUND' } as const
export type RestockStatus = 'release' | 'ok' | 'no_use'
export type JobStatus = 'scheduled' | 'from_stock' | 'no_machine' | 'no_rate'
export type CalendarSource = 'dynamics' | 'override'
export type IssueSeverity = 'info' | 'warning'
export type ScheduleR86 = { file: string; from: string; printed: string; weeks: number; unplacedWeeklyM: number | null } // null for the file not used as demand
export type ScheduleRun = { run: { builtAt; engineVersion; sourceName; today; horizonEnd; dataAsOf; demand: 'r86-order'|'r86-invoice'|'issues';
  rateWindow; settingsHash; settingsSource; schemaVersion; inventoryFile; inventoryExported: string | null } | null;
  r86: { order: ScheduleR86; invoice: ScheduleR86; shortPeriod: boolean } | null;
  counts: { restockItems; releaseItems; releaseRuns; releaseM; releaseKg; jobs; jobsScheduled; ops; passes; batches; lateOps; issuesWarning; issuesInfo: number };
  sources: { name: string; role: string; asOf: string | null; rows: number | null }[]; settings: Record<string, unknown> }
export type RestockRow = { item; form: 'conductor'|'spool'; material; mica: boolean; csaMm2: number|null; kgPerM: number|null; weeklyUseM; demandSource;
  unplaced: boolean; issueWeeklyM; kanbanM; kanbanAssumed: boolean; runs52w; triggerM; physicalM; incomingM; positionM; weeksOnHand: number|null;
  releaseRuns; releaseM; releaseKg: number|null; status: RestockStatus; scheduled: boolean; note: string|null }
export type RestockBySize = { material; csaMm2; weeklyUseM; issueWeeklyM; r86OrderedWeeklyM; r86InvoicedWeeklyM; triggerM; physicalM; incomingM; releaseM }
export type ScheduleJob = { jobId; item; runNo; metres; dueDate; status: JobStatus; startAt: string|null; endAt: string|null; lateDays: number|null;
  rodKg: number|null; outKg: number|null; passCount; stockM }
export type SchedulePass = { passId; parentPassId: number|null; item; pool; depth; machine; posterName; eligible: string[]; operation; quantity; unit;
  setupHours; runHours; startAt; endAt; batchId: number|null; stockMetres }
export type ScheduleOp = { opId; item; pool; metres; requiredBy; startAt; endAt; lateDays; rodKg: number|null; outKg: number|null; impliedYield: number|null;
  stockMetres; passCount; passes: SchedulePass[] }
export type ScheduleBatch = { batchId; item; pool; machine; posterName; operation; metres; batchRule; allocatedMetres; surplusMetres; setupHours; runHours;
  startAt; endAt; opCount; reason: 'shortage'|'min_level'; weightKg: number|null; draws?: {consumerKind:'op'|'batch'; consumerId; metres; item}[];
  feeders?: {batchId; item; metres}[] }
export type MachineLoadWeek = { week: string; availableHours: number; loadHours: number; utilisation: number | null }
export type MachineLoad = { machine; posterName; name; calendarSource: CalendarSource; efficiency; hoursPerWeek; weeks: MachineLoadWeek[];
  totalAvailableHours; totalLoadHours; utilisation: number | null }
export type ScheduleStockRow = { item; point; pool; unit: 'm'|'kg'; onHandM: number|null; usedM: number|null; onHandKg: number|null; usedKg: number|null;
  incomingM: number|null; kgPerM: number|null }
export type ScheduleRod = { item; onHandKg; incomingKg; weeklyUseKg: number|null; weeksCover: number|null; warnWeeks; low: boolean }
export type ScheduleIssue = { item; kind; severity: IssueSeverity; detail; count }
```
Parsers coerce missing numbers to 0 and nullable ones to `null` as `conductorProcess.ts` does. Tests (`conductorSchedule.test.ts`):
each function builds the right URL (query only; ids only in the query string), parses a full and a minimal payload, maps each
error code to its message.

### 7.3 Pages

Style: Cable routes / Process components (`PageHeading`, `SectionHeading`, `TableShell`, `Th`, `Pager`, `ErrorPanel`,
`LoadingBlock`, `EmptyPanel`, `MachineChip`), `useConductorResource`, CSS variables `--admin-*`. Empty, loading and error
states follow the Process page.

- **Restock** (`/restock`)
  - Header: "R-86 {order.from} to {order.printed date}: {weeks, 1 dp} weeks elapsed · inventory exported {inventoryExported} ·
    data as of {dataAsOf}"; amber warning "Under {2} weeks of the month elapsed: the R-86 rate is noisy" when `r86.shortPeriod`;
    line "{unplacedWeeklyM} m/week of R-86 demand has no matching item"; "Demand: R-86 orders" label from `run.demand`.
  - Filters: status chips (All, To release, OK, No recent use), material chips. Table order = API order.
  - Columns: item (link to `/admin/conductor?item=<item>`), material, CSA (`16 mm²`; spools show "spool"), weekly use (m),
    kanban (m, "assumed" tag when `kanbanAssumed`), trigger, physical, incoming, weeks on hand (1 dp, "-" when null),
    release as three cells `runs`, `m`, `kg` (kg per 7.4), status badge (Release / OK / No recent use).
  - Mica rows (`mica` true): badge "not scheduled: taping not modelled". Rows with `scheduled` false and not mica show the
    `note` as a tooltip and a "no build tree" badge.
  - Toggle "By size": swaps the table for `bySize` (material, CSA, weekly use, issue rate, R-86 ordered and invoiced per week,
    trigger, physical, incoming, release m).
  - Footer: totals (items to release, runs, metres, tonnes).
- **Schedule** (`/schedule`)
  - Toggle Gantt / Jobs. Gantt (port of prototype `Gantt.tsx` into platform styling): rows = the 12 machines in stage order
    (label: D365 id and poster name when different, calendar-source badge), x axis = dates from `run.today` to `horizonEnd`
    (+1 day), zoom 1d/1w; bars = non-batch passes (colour by pool) and batches (hatched, labelled with item); shutdown range
    shaded; today line. Click a bar: `OpPanel` (job, item, metres, due date, late days, rod kg, out kg, passes table with
    machine, operation, quantity, setup + run hours, start, end, batch link) or `BatchPanel` (item, machine, metres, rule,
    allocated, surplus, hours, reason, draws, feeders, op links). Panels are side drawers; `Escape` closes.
  - Jobs list: columns job, item (link to Process), run, metres, due date, status badge, start, end, late days (red when > 0),
    passes. Pager over `listScheduleJobs`.
  - The Gantt loads all pages of `ops` (pageSize 200) plus `batches`.
- **Machine load** (`/load`): weeks selector 4/8/26. One block per machine in stage order: name, badge "Dynamics calendar" or
  "Shift override", efficiency when < 100%, hours/week; a bar chart of load hours against available hours per week (CSS bars,
  accessible table below), utilisation as %, over 100% never shown (the engine cannot exceed). Summary row per machine:
  total load, total available, utilisation.
- **Issues** (`/issues`): kind chips with counts (from `byKind`), warnings first; table of item, kind, detail, count; the rod cover
  warning (`GET stock` `rod.low`) shown at the top: "Rod on hand covers {weeksCover, 1 dp} weeks (lead time {warnWeeks})".
  A stock section (table of `stock.rows`: item, point, on hand, used, incoming in 7.4 units) sits under the issues, with the
  rod line first.

### 7.4 Unit formatting (D7)

| Quantity | Rule |
|---|---|
| Rod and 1.6 mm wire (`point` rod, wire_plain, wire_tinned) | kg; tonnes with 1 dp when >= 10,000 kg (`12.4 t`); below, 3 significant figures `1,230 kg` |
| Finished conductor, spools, bunches | metres, 3 significant figures with thousands separators (`formatQty`); `m` suffix; km never |
| Hours | 1 dp `h` |
| Utilisation | whole % |
| Restock release kg | `formatMass` as above (null: "not recorded") |
| Dates | `formatDataDate` (existing); timestamps `YYYY-MM-DD HH:mm` |

New helpers in `format.ts`: `formatMass(kg)`, `formatHours(h)`, `formatPercent(x)`, `formatDateTime(s)`, `scheduleHref`.

## 8. Fixtures and tests

### 8.1 Python

Fixture builder `core/python/tests/conductor_schedule/cs_support.py` (as `cd_support.py`) writes a synthetic source folder
(`Source` class) into `tmp_path`, with only the columns in 2.1: the Cable routes six files (rod to 1W1.6 on RB1 to 16W.3 on
WD4 to 32W.3 on BU6 to 1C16 on BU1; a tinned branch via TL1/WD5; a mica NM-1C16 item on TP2; an item off the map), headers
with `CsReceivedQuantity`/`ReportedAsFinishedDate`, a small `production-picking-list-lines.csv`, `inventory.csv` (with a QC
row and a `-S` t row), `inventory-meta.json`, two R-86 files built by a `r86_text(rows, printed, period)` helper (including
an aluminium row and an `X` column), route jobs, rates, one calendar. Fixed `today = 2026-10-28`. Files:

| Test file | Covers |
|---|---|
| `test_settings.py` | seed loads; override replaces keys; `CONDUCTOR_SCHEDULE_SETTINGS` and precedence; yields out of range, unknown step/machine, bad `HH:MM`, bad days, overlapping intervals, unknown top-level key (all exit 2) |
| `test_r86.py` | both real-format shapes parse; total mismatch rejected; `X` column; latest pick by printed time, then period, then mtime; mismatched DocTyp; weeks elapsed (0 days = 1) |
| `test_rates.py` | flows, returns net, window boundaries, scrap added |
| `test_stock.py` | QC dropped, `-S` t added to base in metres, rod in kg, incoming kept separate, unconverted issue |
| `test_restock.py` | R-86 split by issue share, no-history bucket goes to plain code, unplaced R-86, spools/VX on issue rate, kanban median (even count), no-run assumption, trigger, release runs formula, job due dates and spacing, mica written but `scheduled = 0`, `demand = issues` and `r86-invoice` |
| `test_passes.py` | passes from the process tree (one per edge, yield growth), eligibility 10%, pool fallback, `no_build_tree` for off-map and untraced |
| `test_calendar.py` | Dynamics pattern, shift override incl. an interval across midnight (Thu 22:00-06:00 puts 00:00-06:00 on Friday, Sunday wrap to Monday), efficiency, shutdown, `hours_per_week` |
| `test_schedule.py`, `test_batch.py` | the 59 prototype tests (`planning/conductor/tricab-api/core/python/tests/conductor_schedule`) ported to the new job source; `synth.py` replaced by `cs_support.py` |
| `test_store_invariants.py` | section 5 invariants on a built fixture store |
| `test_cli.py` | exit codes 0/1/2/3, atomic swap leaves the old store on failure, `status` |
| `test_make_source.py` | copy tool: copies, renames, exit 3 on a missing file |
| `test_real_data.py` | skips unless `CONDUCTOR_SCHEDULE_SOURCE_DIR` set; builds, asserts 12 machines, no off-map passes, release items > 0 |

### 8.2 PHP

`tests/Conductor/Schedule/FixtureStore.php` (runs `core/python/conductor_schedule/schema.sql`, as the Process one) and
`FixtureData.php` (a small consistent store: 3 restock items (release, ok, mica not scheduled) with demand rows, 4 jobs (one
unscheduled), 3 ops with passes, 2 batches with draws and a batch-to-batch feeder, 12 machines (one override), capacity/load
for 6 weeks, rod and 3 stock rows, 6 issues of both severities, r86 buckets, `cs_run` with the R-86 columns). Tests:

| Test | Covers |
|---|---|
| `core/tests/Conductor/Schedule/ConductorScheduleQueryServiceTest.php` | every payload shape (section 6 examples), filters, sort orders, `impliedYield`, `weeksCover`, utilisation null, by-size roll-up, validation of each param, not-found cases |
| `tests/Conductor/Schedule/ConductorSchedulePersistenceTest.php` | Sqlite vs InMemory return equal records; missing file, wrong `user_version`, empty `cs_run` raise store-unavailable |
| `tests/Http/ConductorScheduleEndpointsTest.php` | every endpoint 200 with the envelope, 401/403 without `CONDUCTOR.READ`, 422/404/503 codes and messages (503 text does not leak the path) |

`composer test`, `composer analyse` (level max, no baseline additions).

### 8.3 Web

`conductorSchedule.test.ts`, `RestockPage.test.tsx`, `SchedulePage.test.tsx` (Gantt rows/bars, panel open on click, jobs
view), `LoadPage.test.tsx`, `IssuesPage.test.tsx`, `ConductorShell.test.tsx` (new tabs), `format` tests for 7.4, fixtures in
`scheduleFixtures.ts`. `npm test`, `npm run typecheck`, `npm run lint`, `npm run build`.

## 9. Results (filled after the real build)

| Item | Value |
|---|---|
| Source folder / date | `var/conductor/source` from the 2026-10-28 snapshot, 2026-09-04 history and calendars, inventory export 2026-10-26. Built with `--today 2026-10-28` in about 6 s |
| R-86 files chosen (printed) | `R-86_ORD_MTD_2610` (2026-10-27 20:01), `R-86_INV_MTD_2610` (2026-10-27 20:02), both from 2026-10-01, 3.71 weeks |
| Restock items | 92 (87 conductor, 5 spool): 11 release, 59 ok, 22 no_use |
| Releases: items, runs, tonnes | 11 items, 16 runs, 178,426 m, 24.6 t. Of these, 4 mica items (NM-1C120/150/240/300, 8 runs) are not scheduled |
| Jobs, ops, passes, batches | 8 jobs, 8 ops, 27 passes, 2 batches (DX-1W1.6 40,000 m shortage on TL1; NX-1W1.6 825,000 m `min_level` top-up on RB1 with no draws) |
| Last op end | 2026-10-29T15:18 |
| Late ops | 0 |
| Load per machine, first 4 weeks | All in the week of 26 Oct: BU2 25.0 h, BU5 23.3, ST1 17.9, RB1 10.7, TL1 8.8, WD5 3.9, BU4 2.6, BU6 1.0. Capacity 120 h/week (BaseM-F3S: BU, ST1) and 64 h/week (BaseM-T2S: RB1, TL1, WD3–5). All 12 machines on Dynamics calendars |
| Prototype comparison (`restock_check.py`: 11 items, 16 runs, ~25 t on 2026-10-28) and differences explained | Same 11 release items and 16 runs. The prototype lists 120 items against 92: the 28 extra are spools over 16 wires or without downstream use, VX/VDX without downstream use, `-WH`/`-TSD` variants and `NX-1C500-AA`; none released. Weekly use differs on some rows because the R-86 files are newer (27 Oct, 3.71 weeks, against 25 Oct, 3.4 weeks). Kanban differs on some rows because of the mean-of-middle-two median (Q8) and the one-week kanban for items with no runs |
| Issues by kind | `kanban_assumed` 8, `covered_by_stock` 6, `mica_not_scheduled` 4, `pool_fallback` 3, `calendar_pattern` 2, `r86_unplaced` 2, `rod_cover_low` 1 (warning: 44.7 t on hand, 75.2 t/week, 0.6 weeks) |
| Differences from the prototype scheduler | The prototype scheduled the MRM explosion of every open sales line (44 ops, 110 passes, 11 batches, 43 late, last end 3 Nov). This build schedules only the kanban releases, so it is much smaller and nothing is late. Only 1.6 mm wire is batched (2 batches against 11). Machines come from each item's own history, so no `rate_borrowed`. The job's own finished item is not drawn from stock (restock already nets it); stock below the root is drawn as CONTRACT-STOCK |
| Decisions after the run (Manusha, 2026-10-28) | (1) Confirmed: a restock job does not draw its own finished item from stock, because restock already nets that stock; stock below the root is drawn as CONTRACT-STOCK. (2) The NX-1W1.6 history minimum gives an 825 km top-up batch that nobody draws, confirming the plan's "minimum looks high" note. Left to planners to set `min_levels`. (3) `run.r86.<doc>.unplacedWeeklyM` is null for the R-86 file not used as demand |
| Open issues | Real shifts and efficiency from Vineeth. Pages not yet checked in a browser against a running API |

## 10. Open questions and missing sources

Answered by Manusha 2026-10-28: the proposal column is accepted for every row. Q1 the 1.6% loss is acceptable; Q2 the
proxy is fine; Q8 mean of the middle two; Q9 keep and check the first real run; Q6 and Q12 accepted. Q5, Q10 and Q11
remain open business questions and stay settings or warnings.

| # | Question | Proposal in this contract |
|---|---|---|
| Q1 | `production-route-operations-raw.csv` exists twice with the same name: the 2026-10-28 snapshot (4 columns, 57,020 orders) and the 2026-09-04 extract in `planning\dynamics\` (47 columns, 55,220 orders). The history join needs one file in a flat folder. Against the snapshot, 333 of 20,287 Process-job orders (1.6%) in the 09-04 route jobs have no route or header row (they are missing from the snapshot headers too, so cannot be classified either way). | Use the snapshot file only; those 333 jobs are skipped. Confirm the 1.6% loss is acceptable, or supply a combined file |
| Q2 | The snapshot headers have no `ReleasedDate` (the prototype used it for release-to-finish days in the 1.6 mm minimum level). | Use `ScheduledStartDate` to `ReportedAsFinishedDate` as a proxy. The history minimum was already flagged as high (plan section 10); planners to confirm or set `min_levels` |
| Q3 | Which items are restock items beyond `-1C` conductor: spools with downstream use, VX/VDX. The prototype included every copper item with downstream issues (e.g. wire `NX-1W1.6`, other `-AA` suffix items). | 4.4 step 1 limits the set as the plan 5.3 says; expect the count to differ from the prototype's 11 items / 16 runs, to be explained in the results |
| Q4 | `inventory.csv` has no export date column (the export date is in the folder name / `summary.json`, 2026-10-26). | `inventory-meta.json` copied from `summary.json`; fallback mtime plus warning |
| Q5 | Vineeth's real shift patterns per machine, and whether CableBuilder efficiency (80% / 85%) applies. | Both off; settings only (D8) |
| Q6 | `r86_min_weeks` (2) and `rod_warn_weeks` (8) are settings added by this contract; the plan has them only as prose. | Accept, or hard-code |
| Q7 | The newer R-86 ORD of 27 Oct at `~/Desktop/TriCab/R-86_ORD_MTD_2710.numbers` is Numbers format. `platform\r86` ORD/INV are already printed 27 Oct 2026 (20:01 / 20:02) and parse and sum correctly, so it is not needed for this build. `planning\r86` (25 Oct) is older; the "latest" rule in 2.2 picks the platform copies. | None needed; confirm |
| Q8 | The prototype takes the upper middle element for an even-count median in `restock_check.py`, and the mean of the middle two in `conductor_process`. | Mean of the middle two everywhere (matches Process `run_size`); tiny differences from the prototype's kanban sizes possible |
| Q9 | `Ordered in total` of wire is inflated (e.g. `NX-1W1.6` 144,835,000 m); `On order` of finished conductor is large too. The plan says incoming is excluded from wire cover and `On order` is outgoing. | Followed. Restock position on finished conductor still adds `incoming`, so inflated incoming on a conductor item can hide a release; check the first real run |
| Q10 | The Christmas shutdown 2026-12-19 to 2027-01-02 is unconfirmed. | Setting |
| Q11 | Rod is treated as unlimited; only `rod_cover_low` is raised (0.6 weeks on 2026-10-28). | As plan |
| Q12 | `CONDUCTOR_OP_REQUIRED` / `CONDUCTOR_BATCH_REQUIRED` are extra to the plan's list. | Accept |

Missing sources: none. Every file in plan 5.1 exists in the mirrored folders with the required columns (checked by header
lines). Notes: `released-products.csv` in the snapshot carries `ProductionPoolId` and `ProductSearchName`, so
`consumption_rate.py`'s read of `reveng-research/.../ReleasedProductsV2.csv` is replaced by it; `planning\r86` is not used.
