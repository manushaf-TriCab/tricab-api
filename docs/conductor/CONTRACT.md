# Conductor process: shared contract

Status: contract, written 2026-10-28 for three builders (engine, API, web) working in parallel. Owner: Manusha.

## 0. What we are building

A new **Conductor** submodule under the admin **Production** nav group (beside Cable routes). Its first page lets anyone
see how copper conductor is made, on the conductor section of the factory map (poster stages 1 to 4) and **only those
machines**:

| Stage | Poster machines (D365 id in brackets where it differs) |
|---|---|
| 1 Rod breaking | RB2 (RB1) |
| 2 Drawing & tinning | WD3, WD4, TN1 (TL1), WD5 |
| 3 Bunching | BU1, BU2, BU6, BU7, BU5, BU4 |
| 4 Stranding & mica taping | TP3 (not in D365), TP1, TP2, ST1 |

Pick any conductor item (finished conductor, mica-taped conductor, bunch or wire) and the page shows its build tree
from 8 mm rod, each card with its machine, and the input quantities for a typed length. Each machine
has a page with the poster spec and what Dynamics actually runs on it. Read-only. No stock, kanban or scheduling yet.

Data: the Dynamics pull of **2026-10-28** (`planning/dynamics/snapshot-2026-10-28/`, see its README).

This module **reuses Cable routes**: read `docs/cable-routes/CONTRACT.md` first. Everything there about platform
patterns (section 2), atomic store rebuild (3.3), CLI exit codes (4.1), config precedence (4.2), envelope, settings,
DI, permission seeds, dev identity, tests, PHPStan level max, web routing/nav/permissions/testing applies here
unchanged. Where this file is silent, copy Cable routes. Do **not** change Cable routes behaviour; the only shared-code
edits allowed are listed in section 6.4.

## 1. Layout and names

| | API repo (`platform/tricab-api-conductor`, branch `feat/conductor-process`) |
|---|---|
| Engine | `core/python/conductor_process/` (stdlib only, Python 3.11; imports from `cable_routes`), CLI `python -m conductor_process build|status` |
| Schema | `core/python/conductor_process/schema.sql` (section 3; PHP tests run it) |
| Seed | `core/python/conductor_process/seed/conductor-map.csv` (section 3.3) |
| Engine tests | `core/python/tests/conductor_process/` |
| Core | `core/src/Conductor/Module.php` (`TriCab\Core\Conductor\Module`, `NAME = 'Conductor'`, `PERMISSION_READ = 'CONDUCTOR.READ'`), `core/src/Conductor/Process/...` (`TriCab\Core\Conductor\Process\` Contract, Application\Query, Application\Service, Application\Exception) |
| Core tests | `core/tests/Conductor/Process/` |
| Persistence | `src/Conductor/Process/Persistence/` (`SqliteConductorProcessRepository`, `InMemoryConductorProcessRepository`) |
| HTTP | `src/Http/Action/Conductor/Process/` (one action per endpoint + support trait), `src/Http/Auth/ConductorProcessErrorMapper.php`, routes in `AppBuilder::registerConductorRoutes` |
| Tests | `tests/Http/ConductorProcessEndpointsTest.php`, `tests/Conductor/Process/` (fixture store) |
| Seed SQL | `database/scripts/development/seed-conductor-permissions.sql` (one permission, `ADMINISTRATOR` + `EMPLOYEE`, as Cable routes) |
| Config | `CONDUCTOR_PROCESS_DB_PATH` (default `var/conductor/conductor-process.sqlite`), `CONDUCTOR_PROCESS_SOURCE_DIR` (engine only, no default); `.gitignore` `/var/conductor/`; `.env.example` lines as Cable routes |

| | Web repo (`platform/tricab-web-conductor`, branch `feat/conductor-process`) |
|---|---|
| Client | `src/api/admin/conductorProcess.ts` + `.test.ts` |
| Pages | `src/components/admin/conductor/` (shell, process page, machines page, machine page, map, permissions, tests) |

Error codes: `CONDUCTOR_ITEM_REQUIRED`, `CONDUCTOR_MACHINE_REQUIRED`, `CONDUCTOR_PARAM_INVALID`,
`CONDUCTOR_ITEM_NOT_FOUND`, `CONDUCTOR_MACHINE_NOT_FOUND`, `CONDUCTOR_STORE_UNAVAILABLE` (same statuses as the
Cable routes equivalents: 422, 422, 422, 404, 404, 503).

## 2. Inputs (engine)

Folder `CONDUCTOR_PROCESS_SOURCE_DIR` holds the **same six files with the same names and columns as Cable routes**
(`docs/cable-routes/CONTRACT.md` 4.3), so the engine loads them with `cable_routes.loader.load()`. Two extra columns are
read from `production-order-headers-raw.csv` directly (a second pass with `csv.DictReader`): `CsReceivedQuantity`,
`ReportedAsFinishedDate`. Missing extra columns → exit 3, same message style.

In the 2026-10-28 snapshot: headers (57,1xx rows), BOM lines 153,813, route operations 57,308, released products 43,432,
`manufactured-items.csv` derived from released products' `ProductionPoolId`, `purchase-order-lines-all.csv` copied from
the 2026-09-04 extract (used for material names only).

## 3. Store (SQLite, rebuilt in full; PHP reads only)

Conventions as Cable routes 3: dates `YYYY-MM-DD`, UTC timestamps, booleans 0/1, unrounded REAL, missing = NULL.

### 3.1 Schema (`schema.sql`, `PRAGMA user_version = 1`)

```sql
PRAGMA user_version = 1;

CREATE TABLE cd_run (
  run_id            INTEGER PRIMARY KEY CHECK (run_id = 1),
  schema_version    INTEGER NOT NULL,
  built_at          TEXT    NOT NULL,   -- UTC
  source_name       TEXT    NOT NULL,   -- basename only
  data_as_of        TEXT    NOT NULL,   -- as cr_run: newest ScheduledDate ignoring 1900-01-01
  engine_version    TEXT    NOT NULL,
  window_from       TEXT    NOT NULL,   -- data_as_of minus 364 days: the "last 52 weeks" window
  items             INTEGER NOT NULL,   -- rows in cd_item
  finished_items    INTEGER NOT NULL,   -- cd_item rows with stage in ('conductor', 'mica_taped')
  traced_to_rod     INTEGER NOT NULL,   -- cd_item rows with traces_to_rod = 1
  excluded_off_map  INTEGER NOT NULL    -- copper items left out because a route step is on a machine not on the map
);

-- The 15 map machines, always all 15 rows, in map order (seq).
CREATE TABLE cd_machine (
  machine            TEXT PRIMARY KEY,   -- poster name: RB2, TN1, TP3 ...
  d365_id            TEXT,               -- RB1, TL1, ...; NULL for TP3
  seq                INTEGER NOT NULL UNIQUE,
  stage_no           INTEGER NOT NULL CHECK (stage_no BETWEEN 1 AND 4),
  stage              TEXT    NOT NULL,   -- 'Rod breaking' | 'Drawing & tinning' | 'Bunching' | 'Stranding & mica taping'
  poster_status      TEXT,               -- from poster-machines.csv
  brand              TEXT,
  machine_type       TEXT,
  input_limit        TEXT,
  output_capability  TEXT,
  pay_off            TEXT,
  take_up            TEXT,
  speed_text         TEXT,
  note               TEXT,               -- the map badge text, from the seed (section 3.3), NULL when none
  items              INTEGER NOT NULL,   -- cd_item rows whose representative route uses this machine
  orders_total       INTEGER NOT NULL,   -- real orders (any status) of conductor-pool items with an operation on it
  orders_cancelled   INTEGER NOT NULL,
  orders_52w         INTEGER NOT NULL    -- non-cancelled real orders with ReportedAsFinishedDate in [window_from, data_as_of]
);

CREATE TABLE cd_machine_operation (    -- operations Dynamics routes on the machine (non-cancelled real orders)
  machine    TEXT    NOT NULL REFERENCES cd_machine (machine),
  operation  TEXT    NOT NULL,         -- ROD_BREAK, WIRE_DRAW, TINNING, BUNCHING, TAPING, ROPESTRAND ...
  orders     INTEGER NOT NULL,
  PRIMARY KEY (machine, operation)
);

CREATE TABLE cd_item (
  item             TEXT PRIMARY KEY,
  pool             TEXT NOT NULL,      -- 15_WIR | 20_BUN | 25_FCON | 30_TAPE
  stage            TEXT NOT NULL CHECK (stage IN ('wire', 'bunch', 'conductor', 'mica_taped')),
  prefix           TEXT NOT NULL,      -- NX, DX, DM, NM, ...
  material         TEXT NOT NULL CHECK (material IN ('plain', 'tinned', 'unknown')),
  mica             INTEGER NOT NULL,
  form             TEXT NOT NULL CHECK (form IN ('core', 'wires', 'other')),   -- section 4 rule 3
  ends             INTEGER,            -- the number before C or W (1 in NX-1C16, 32 in NX-32W.3)
  size             REAL,               -- CSA mm2 for 'core', wire diameter mm for 'wires'
  diameter_mm      REAL,
  kg_per_m         REAL,
  unit             TEXT NOT NULL,
  order_no         TEXT NOT NULL,      -- representative order (cable_routes rule)
  order_status     TEXT NOT NULL,
  order_date       TEXT NOT NULL,
  real_orders      INTEGER NOT NULL,
  live_orders      INTEGER NOT NULL,
  orders_52w       INTEGER NOT NULL,   -- non-cancelled real orders finished in the window
  run_size         REAL,               -- median CsReceivedQuantity (> 0) of those orders; NULL when none
  traces_to_rod    INTEGER NOT NULL,   -- the explosion reaches an item whose route has ROD_BREAK
  step_count       INTEGER NOT NULL    -- made items in its chain, itself included
);
CREATE INDEX ix_cd_item_stage ON cd_item (stage, item);

CREATE TABLE cd_operation (            -- representative order's route, OperationNumber order, seq 1..n
  item              TEXT    NOT NULL REFERENCES cd_item (item),
  seq               INTEGER NOT NULL,
  operation_number  INTEGER NOT NULL,
  operation         TEXT    NOT NULL,
  machine           TEXT    NOT NULL REFERENCES cd_machine (machine),   -- poster name
  PRIMARY KEY (item, seq)
);
CREATE INDEX ix_cd_operation_machine ON cd_operation (machine);

CREATE TABLE cd_input (                -- representative order's BOM minus self-consumption (cable_routes rule)
  item           TEXT    NOT NULL REFERENCES cd_item (item),
  line           INTEGER NOT NULL,
  input_item     TEXT    NOT NULL,
  qty_per_unit   REAL    NOT NULL,
  unit           TEXT    NOT NULL,
  parallel_ends  INTEGER NOT NULL,
  kind           TEXT    NOT NULL CHECK (kind IN ('made', 'material')),  -- made = input_item is in cd_item
  PRIMARY KEY (item, line)
);
CREATE INDEX ix_cd_input_input ON cd_input (input_item);

CREATE TABLE cd_alt_machine (          -- machines that ran each operation for the item (non-cancelled real orders), map machines only
  item       TEXT    NOT NULL REFERENCES cd_item (item),
  operation  TEXT    NOT NULL,
  machine    TEXT    NOT NULL REFERENCES cd_machine (machine),
  orders     INTEGER NOT NULL,
  PRIMARY KEY (item, operation, machine)
);

CREATE TABLE cd_material (             -- every cd_input row with kind = 'material'
  item   TEXT PRIMARY KEY,
  name   TEXT,                          -- first purchase-order LineDescription, cut to 48 chars
  unit   TEXT,
  is_rod INTEGER NOT NULL               -- consumed by an item whose route has ROD_BREAK
);

CREATE TABLE cd_need (                 -- explosion per item, for 1 unit of it (cable_routes.explode)
  root           TEXT    NOT NULL REFERENCES cd_item (item),
  item           TEXT    NOT NULL,
  unit           TEXT    NOT NULL,
  need_per_unit  REAL    NOT NULL,
  depth          INTEGER NOT NULL,
  kind           TEXT    NOT NULL CHECK (kind IN ('root', 'made', 'material')),
  PRIMARY KEY (root, item, unit)
);
CREATE INDEX ix_cd_need_item ON cd_need (item);
```

### 3.2 Invariants (engine tests assert them)

- `cd_machine` has exactly the 15 rows of section 0, `seq` 1..15 in the table's order (RB2, WD3, WD4, TN1, WD5, BU1,
  BU2, BU6, BU7, BU5, BU4, TP3, TP1, TP2, ST1).
- Every `cd_operation.machine` and `cd_alt_machine.machine` is in `cd_machine`. Every `cd_operation` seq run is 1..n.
- Every `cd_input` `kind = 'made'` row's `input_item` is in `cd_item`; every `material` row's is in `cd_material`.
- `cd_need` has exactly one `kind = 'root'` row per `cd_item` row (`item = root`, need 1, depth 0, the item's unit).
  Every `made` row's item is in `cd_item`. Quantities are per unit and never summed across units.

### 3.3 Seed `seed/conductor-map.csv`

`machine,d365_id,seq,stage_no,note` — the 15 rows in section 3.2 order. `d365_id`: RB1 for RB2, TL1 for TN1, blank for
TP3, else the same name. `note` = the map badge: RB2 `D365 calls it RB1`, TN1 `running · D365 TL1`, WD5 `running in D365`,
WD3 and BU7 `no orders in D365` (the 2026-10-28 pull has no conductor orders on either, and no cancelled orders at all, so the Cable routes "91% cancelled" note cannot be shown from it), TP3 `not in D365`; blank otherwise. Brand, type, limits, speed and poster status come
from `cable_routes/seed/poster-machines.csv` (read with `cable_routes.reference.read_seed`; the file has a duplicate
block, take the first row per machine). Stage names are fixed as in section 0.

## 4. Engine rules

1. Load with `cable_routes.loader.load(folder)` and use `cable_routes.explode.explode` unchanged. The representative
   order is chosen here (rule 4), **not** with `cable_routes.model.build_model`, because Cable routes picks the latest
   order even when it ran off the map (NX-1C500's latest order is on LU3; 25 earlier ones ran on ST1). Mirror
   `cable_routes/model.py` exactly for everything else: a **real order** is not a cutting order and has a BOM line
   consuming an item other than its own; inputs are the order's BOM minus self-consumption, `qty_per_unit =
   BOMLineQuantity / (BOMLineQuantityDenominator or 1)`, a repeated `LineNumber` moves to the next free number,
   `parallel_ends = CsPrdParallelLines or 1` (never multiplied in); ops sorted by `OperationNumber`, seq 1..n; operations
   with an empty resource are not machines. Pool = `manufactured-items.pool`, else the order's `ProductionPoolId`.
2. **Copper conductor item** = an item with at least one real order whose pool is one of `15_WIR, 20_BUN, 25_FCON, 30_TAPE` and whose prefix
   (text before the first `-`, upper case) is in `NX, DX, DM, MNX, NM, MNM, VX, VDX, MDX, MDM, DXI` (the copper-v2 rule:
   `planning/conductor/.../CopperRule.php`). Stage by pool: 15_WIR wire, 20_BUN bunch, 25_FCON conductor, 30_TAPE
   mica_taped. Material: prefix NX, NM, MNX, MNM → plain; DX, DM, MDX, MDM, DXI → tinned; VX, VDX → unknown. Mica = 1
   for NM, MNX, MNM, DM, MDX, MDM.
3. Parse the item number with `^[A-Z]+-(\d+)([CW])(\d*\.?\d+)` (anything after is a suffix): `C` → form `core`, size =
   CSA mm²; `W` → form `wires`, size = wire diameter mm (`.3` → 0.3, `1.6` → 1.6). `ends` = the first number. No match →
   form `other`, ends and size NULL. Rod (`NX-1R8-NEX`) is a material, never a `cd_item`.
4. **Map only.** Resource → poster name: RB1 → RB2, TL1 → TN1, else the same; a resource is *on the map* when it is
   the D365 id of one of the 15 map machines. For each copper conductor item, the **representative order** is the best
   of its real orders whose every operation (non-empty resource) is on the map, ranked as Cable routes ranks them:
   non-cancelled first, then latest `ScheduledDate`, then highest order number. An item with no such order is
   **excluded** and counted in `cd_run.excluded_off_map` (on 2026-10-28 this should be only a few odd LU3-only items such
   as `NX-1C500-AA`; NX-1C500 and NX-1C630 stay, via ST1). An input that is an excluded item is a `material` row for its
   parent (it still appears, just not as a traced step).
5. Graph for the explosion: `item -> [(input_item, qty_per_unit, unit)]` over cd_items only; anything else is a leaf.
   Run `cable_routes.explode.explode(root, graph, unit_of, root_unit)` for every cd_item. `kind`: root / made (in
   cd_item) / material. `traces_to_rod` = some made node (or the root) has a `ROD_BREAK` operation. `step_count` =
   made nodes + 1.
6. Alt machines: per cd_item, over its non-cancelled real orders, count orders per (OperationId, resource) for on-map
   resources (as poster names); off-map resources are not counted.
7. Machine stats: over real orders of **all copper conductor items before exclusion** (rule 2), per route operation
   resource mapped to a poster name: `orders_total` distinct orders, `orders_cancelled`, `orders_52w` (rule 9), and
   `cd_machine_operation` counts over non-cancelled orders. `items` = cd_items whose representative route uses it. A
   map machine with no data keeps zeros (WD3, BU7, TP3 may be zero or near zero; that is the point of showing them).
8. Materials: name as Cable routes (first `LineDescription`, 48 chars); `is_rod` = consumed by an item whose route has
   ROD_BREAK.
9. Window: `window_from = data_as_of − 364 days`. `orders_52w` counts non-cancelled real orders with
   `ReportedAsFinishedDate[:10]` in [window_from, data_as_of]. `run_size` = median of `CsReceivedQuantity` (> 0) over those
   orders (median of an even count = mean of the middle two).

Engine tests (pytest): a small synthetic source folder (rod → 1W1.6 on RB1 → 16W.3 on WD4 → 32W.3 bunch on BU6 →
1C16 on BU1; a tinned branch through TL1/WD5; a 30_TAPE item on TP2; an item on LU3 that must be excluded; a cancelled
BU7 order; an item whose latest order is on LU3 but an earlier one on ST1, which must stay with the ST1 route) asserting
every invariant and the numbers; plus a real-data test that skips unless
`CONDUCTOR_PROCESS_SOURCE_DIR` is set, asserting 15 machines, NX-1C16 traces to rod, and no off-map machine leaks in.

## 5. HTTP (read-only, `CONDUCTOR.READ`, envelope `{success, data}`, camelCase)

All under `/api/v1/conductor/process`. Store missing or unreadable → 503 `CONDUCTOR_STORE_UNAVAILABLE`.

### `GET /run`
```json
{ "run": { "builtAt", "dataAsOf", "windowFrom", "sourceName", "schemaVersion", "engineVersion" },
  "counts": { "items", "finishedItems", "tracedToRod", "excludedOffMap" },
  "stages": [ { "stage": "wire", "items": 12 }, ... ] }
```
`run` is null (and counts zero) only if `cd_run` is empty.

### `GET /machines`
`{ "machines": [MachineRow] }`, all 15 in `seq` order.
`MachineRow = { machine, d365Id, seq, stageNo, stage, posterStatus, brand, machineType, inputLimit, outputCapability,
payOff, takeUp, speedText, note, items, ordersTotal, ordersCancelled, orders52w, cancelledShare }` where
`cancelledShare = ordersCancelled / ordersTotal` (null when ordersTotal is 0).

### `GET /machine?machine=BU6`
Case-insensitive; accepts the poster name or the D365 id (RB1 → RB2). Missing → 422 `CONDUCTOR_MACHINE_REQUIRED`;
unknown → 404 `CONDUCTOR_MACHINE_NOT_FOUND`.
```json
{ "machine": MachineRow,
  "operations": [ { "operation": "BUNCHING", "orders": 1313 } ],
  "items": [ { "item", "stage", "material", "size", "form", "role": "representative"|"alternative", "orders": 12 } ] }
```
`items`: cd_items whose representative route uses it (`role = representative`, `orders` = the item's `live_orders`)
plus items that only have it in `cd_alt_machine` (`role = alternative`, `orders` = that alt count). Sorted by stage
(wire, bunch, conductor, mica_taped), then item. Max 500.

### `GET /items?q=&stage=&material=&page=1&pageSize=50`
`stage` ∈ wire|bunch|conductor|mica_taped|finished (finished = conductor + mica_taped); `material` ∈ plain|tinned|unknown;
`q` = case-insensitive substring of the item number. `pageSize` 1..200. Invalid value → 422 `CONDUCTOR_PARAM_INVALID`.
Sorted by stage order, then `size` (nulls last), then item.
```json
{ "items": [ItemRow], "page": 1, "pageSize": 50, "totalItems": 312, "totalPages": 7 }
```
`ItemRow = { item, pool, stage, prefix, material, mica, form, ends, size, kgPerM, unit, orders52w, runSize, tracesToRod }`.

### `GET /item?item=NX-1C16&length=1000`
`item` required (422 `CONDUCTOR_ITEM_REQUIRED`), case-insensitive exact match, unknown → 404
`CONDUCTOR_ITEM_NOT_FOUND`. `length` > 0 and ≤ 10,000,000, default 1000 (422 otherwise); it is in the item's own unit.
```json
{ "item": ItemRow + { diameterMm, orderNo, orderStatus, orderDate, realOrders, liveOrders, stepCount },
  "length": 1000,
  "steps": [Step],
  "materials": [ { "item", "name", "unit", "isRod", "quantity" } ],
  "usedBy": [ { "item", "stage", "qtyPerUnit", "unit" } ] }
```
`steps`: one per made node of the explosion (the root included), ordered **deepest first** (depth desc, then item), so
step 1 is nearest the rod and the last step is the item itself. `stepNo` is 1..n in that order.
```
Step = { stepNo, item, stage, material, form, ends, size, unit, kgPerM, depth,
         quantity,            // need_per_unit (in the step item's unit) × length
         kg,                  // quantity × kgPerM when unit is 'm' and kgPerM is set, else null
         operations: [ { seq, operation, machine, d365Id, stageNo,
                         alternatives: [ { machine, orders } ] } ],   // alt machines for that operation, excluding the step's own machine
         inputs: [ { item, kind, qtyPerUnit, unit, parallelEnds, quantity } ] }   // quantity = qtyPerUnit × step quantity
```
`materials`: the explosion's material rows × length, rod first then by item. `usedBy`: cd_items that have this item as a
`cd_input` (direct parents only), by item.

## 6. Web

### 6.1 Nav and routes
- `ADMIN_NAV` Production group: children become `[cable-routes, conductor]`; `{ id: 'conductor', label: 'Conductor',
  icon: 'Cable' }`. Register `Cable` from `lucide-react` in `iconMap.ts`. Add `'conductor'` to `AdminModuleId`,
  `adminRoutes.ts` (`/admin/conductor`, prefix matching like cable-routes), `ADMIN_NAV_VISIBILITY_RULES`
  (`canOpenConductor`), `ModulePlaceholder` copy, and tests next to the Cable routes ones.
- `src/components/admin/conductor/conductorPermissions.ts`: `CONDUCTOR_PERMISSIONS = { READ: 'CONDUCTOR.READ' }`,
  `canOpenConductor(hasPermission)`.
- Routes under `/admin/conductor` with `ConductorShell` (tabs like `CableRoutesShell`): index = **Process**
  (`ProcessPage`), `machines` = **Machines** (`MachinesPage`), `machines/:machine` = `MachinePage`. The selected item lives
  in the query string: `/admin/conductor?item=NX-1C16&length=1000`.

### 6.2 Process page
- Header line: "Data as of {dataAsOf} · {items} conductor items · {excludedOffMap} left out (a step runs on a machine not
  on this map)". 
- Picker: search box + stage chips (Finished conductor [default] / Bunches / Wires / All) + plain/tinned filter; a short
  result list (item, size label, material, orders in 52 weeks, run size). Selecting sets `?item=`.
- Selected item: summary card (item, stage, material, size label `16 mm²` / `32 × 0.3 mm`, kg/m, orders in 52 weeks,
  usual run size, representative order + date), a length input (default 1000, the item's unit), then the steps as a
  vertical list rod → item: step number pill, item made + stage, machine chip(s) (poster name; D365 id in brackets when
  different; alternatives as small chips with order counts), inputs with quantities for the length (m or kg, 3
  significant figures, thousands separators), and kg for the step. Above the steps: "Rod for {length} {unit}: {kg} kg"
  when there is a rod material. `usedBy` as links under the summary. Not-traced items say so plainly.
- Empty, loading and error states as Cable routes (`STORE_UNAVAILABLE` → "The conductor store has not been built yet").

### 6.3 Machines pages
- Machines: the 15 rows grouped by stage, columns machine, brand/type, capability, Dynamics name, items, orders (52w),
  cancelled share, note badge.
- Machine page: poster spec block, the note badge, operations Dynamics routes on it, and its items (representative /
  alternative) linking to `/admin/conductor?item=...`. A machine with no Dynamics data says "Dynamics has no routings on
  this machine".

### 6.4 The build tree (revised 2026-10-28 by the owner)
The selected conductor is shown as the **Cable routes build tree** (`../cableRoutes/BuildTree.tsx`), not a lit factory
map: the poster image only defines which 15 machines are in scope. `conductorTree.ts` maps the item response onto
the tree's `ItemDetail` (one card per step, an edge per made input, steps numbered per (operation, machine) rod first,
machine chips as the D365 name with the poster name). Shared edits allowed: `BuildTree` takes optional `rootLabel`
(default `Finished cable`; Conductor passes `Conductor`) and `machineHref` (Conductor links its own machine pages),
and `MachineChip` takes an optional `href`. Cable routes behaviour is unchanged. No `ConductorMap`.

## 7. Done means
- API: `composer test`, `composer analyse` (level max, no baseline additions), `python3 -m pytest core/python/tests/conductor_process -p no:cacheprovider` all green.
- Web: `npm test`, `npm run typecheck`, `npm run lint`, `npm run build` green.
- Real build: `CONDUCTOR_PROCESS_SOURCE_DIR=~/Desktop/TriCab/planning/dynamics/snapshot-2026-10-28 python -m conductor_process build` succeeds.
- No commits by builders; the integrator commits.
