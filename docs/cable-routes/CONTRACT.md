# Cable routes: shared contract

Status: contract, written 2026-10-27 for three builders (engine, API, web) to work in parallel. Source of truth for
intent is `planning/cable-routes/PLAN.md` (read-only, outside this repo). This file settles what the plan left open
and records the platform's real patterns, so nobody has to guess. Where this file and the plan disagree, this file
wins; every such place is listed under "Decisions where the plan is silent" (D-numbers below are this file's own, not
the plan's D1 to D5).

Owner: Manusha. Plan decisions D1 to D5 (data freshness, which route, who can see it, store, scope) all take the plan's
defaults. One override from the owner: the module is built **directly in the platform worktrees**, not in
`planning/cable-routes/`, so plan section 3's "Here" column is replaced by the paths below and plan section 11's
"don't touch platform/" no longer applies to these two worktrees. Everything else in section 11 still stands
(Dynamics is read-only, `planning/dynamics/` and `planning/workflow/` are never edited, copy seed files, do not move
them).

## 1. Layout (final paths)

API repo `tricab-api` (worktree `platform/tricab-api-cable-routes`, branch `feat/cable-routes`):

| Path | Owns |
|---|---|
| `core/python/cable_routes/` | Engine package (stdlib only). CLI `python -m cable_routes build` |
| `core/python/cable_routes/schema.sql` | The one definition of the store schema (section 3). The engine runs it; PHP tests run it too |
| `core/python/cable_routes/seed/` | Copies of the four poster CSVs plus `machine-limits.csv` (section 3.4) |
| `core/python/tests/cable_routes/` | pytest suite (existing pattern: `tests/finance_ai/`, `tests/rfq_parser/`) |
| `core/src/CableRoutes/` (`TriCab\Core\CableRoutes`) | `Module`, repository contract, read DTOs, query service, exceptions |
| `core/tests/CableRoutes/` | Core unit tests (service rules, parameter validation) with an in-memory repository double |
| `src/CableRoutes/Persistence/` (`TriCab\Api\CableRoutes\Persistence`) | `SqliteCableRoutesRepository` (read-only PDO), `InMemoryCableRoutesRepository` |
| `src/Http/Action/CableRoutes/` (`TriCab\Api\Http\Action\CableRoutes`) | One action class per endpoint plus a small support trait |
| `src/Http/Auth/CableRoutesErrorMapper.php` | Exception to error-envelope mapping (same home and shape as `LogNoteErrorMapper`) |
| `tests/Http/CableRoutesEndpointsTest.php`, `tests/CableRoutes/` | HTTP tests (pattern of `tests/Http/TelDirectoryEndpointsTest.php`) and repository tests with a fixture store |
| `database/scripts/development/seed-cable-routes-permissions.sql` | Permission seed |

Web repo `tricab-web` (worktree `platform/tricab-web-cable-routes`, branch `feat/cable-routes`). The platform keeps
admin API clients in `src/api/admin/` and admin pages in `src/components/admin/<module>/`, not in `src/pages/` (that
folder is for public pages), so the plan's `src/api/cableRoutes/` and `src/pages/cableRoutes/` become:

| Path | Owns |
|---|---|
| `src/api/admin/cableRoutes.ts` and `cableRoutes.test.ts` | Typed client, one function per endpoint (pattern of `src/api/admin/telDirectory.ts`, `hrEmployees.ts`) |
| `src/components/admin/cableRoutes/` | Permission constants, shell, the seven pages, the poster map, tests beside the code |

Names: module "Cable routes", URL slug `cable-routes`, Python `cable_routes`, PHP `CableRoutes`, permission
`CABLE_ROUTES.READ`, env `CABLE_ROUTES_DB_PATH` and `CABLE_ROUTES_SOURCE_DIR`, error codes `CABLE_ROUTES_*`.

## 2. How the platform does this (what the contract is built on)

These were read from the worktrees on 2026-10-27; builders should treat them as the pattern to copy, not re-derive.

API:
- **Routes** are registered in `src/Bootstrap/AppBuilder.php`, one private static `register<Module>Routes(App $app,
  Container $container)` per module, called from the list around line 434 to 450 (`registerTelDirectoryRoutes`,
  `registerHrEmployeeRoutes`, `registerCableFitRoutes`). Each creates `$require = new RequirePermission($container)` and
  chains `->add(($require)(Module::PERMISSION_X))` on every route.
- **Permission check**: `src/Http/Middleware/RequirePermissionMiddleware.php` (built through
  `src/Http/Auth/RequirePermission.php`). No verified identity gives 401 `UNAUTHENTICATED`; a missing permission gives
  403 `INSUFFICIENT_PERMISSION`. Those two codes are the platform's, not `CABLE_ROUTES_*`.
- **Envelope**: `src/Http/Response/JsonResponder.php`. Success is `{"success":true,"data":{...}}` (`->success($array)`);
  failure is `{"success":false,"error":{"code":"...","message":"..."}}` (`->error($code,$message,$status)`).
  The web client (`src/api/apiClient.ts`, `ApiError`) and every existing admin client (`requireSuccessData` in
  `telDirectory.ts`) expect exactly this. Existing modules map exceptions to it in `src/Http/Auth/*ErrorMapper.php`.
- **Core module constants**: `core/src/<Module>/Module.php` with `NAME`, `PERMISSION_*` and `PERMISSIONS`
  (see `core/src/TelDirectory/Module.php`).
- **DI**: `src/Bootstrap/ContainerFactory.php`, one flat definitions array; Tel Directory's block is at about line
  3070 to 3085 (`\DI\autowire(...)` for services and actions, a `static fn` for infrastructure that needs `$settings`).
- **Settings**: `src/Config/Settings.php`, constructor property with a default (about line 355 to 372 for the
  `nprMail*` and `cablefitResearch*` ones) and a `self::string($env, 'KEY', default)` line in `fromEnvironment()`
  (about line 1680 to 1695). Relative paths are resolved against the API root (`dirname(__DIR__, 3)` from a class in
  `src/<Module>/<Sub>/`, see `FileCableFitResearchWake::fromSettings`). `.env.example` documents each key.
- **Query params**: `src/Http/Request/Admin/QueryParams.php` (`normalize`, `string`, `stringOrNull`, `int`). Paging in the
  platform is `page` and `pageSize` in, and `items`, `page`, `pageSize`, `totalItems`, `totalPages` out (HR employees).
  Validation failures are 422.
- **Authenticated user in an action**: `$request->getAttribute(AuthenticatedUserAttribute::KEY)`; this module does not
  need it beyond the middleware.
- **Python**: `core/python/` holds `tricab_cable_rules`, `product_finder`, `rfq_parser`, `finance_ai`, tests in
  `core/python/tests/<package>/`, config in `core/python/pyproject.toml` (explicit `packages` list and `package-data`;
  pytest `pythonpath = ["."]`, `testpaths = ["tests"]`). CI runs `composer python-test` (`python3 -m pytest core/python`)
  on Python 3.12, so the engine must be stdlib only and 3.12 compatible. `requirements.txt` does not change.
  `scripts/build-app-service-release.sh` stages `core/python` as `app/python` and rejects test caches, so run pytest with
  `-p no:cacheprovider` when staging.
- **Permission seeds**: `database/scripts/development/seed-<module>-permissions.sql`, idempotent T-SQL; the closest
  match is `seed-tel-directory-permissions.sql` (one permission, granted to `ADMINISTRATOR` and `EMPLOYEE`).
- **Dev identity**: with `USER_REPOSITORY=memory` the dev user's permissions come from
  `src/Identity/Persistence/DevelopmentUserManagementCatalog.php` (`managementPermissions()`), so a new permission has to
  be added there or the local API answers 403.
- **SQLite precedent**: `src/Finance/Ar/Migration/SqliteArMigrationSource.php` opens a SQLite file read-only through PDO
  and handles the PHP 8.5 move of the constants to `Pdo\Sqlite` with a `constant()` helper. Reuse that approach.
- **Tests**: `tests/TestAppFactory.php` (`create(array $env)`, `request`, `json`), `InMemoryApiUserRepository::replacePermissions(1,
  [...], ['EMPLOYEE'])` to set the caller's permissions; PHPUnit 11 (`composer test`) and PHPStan level max (`composer
  analyse`; new code lands clean, do not add to the baseline files).
- **Git ignore**: `.gitignore` lists `/var/<thing>/` per feature (`/var/rfq-mail/`, `/var/cablefit-research/`...). `var/`
  does not exist in a fresh checkout; PHP and Python create their own subfolder.

Web:
- **Admin routes** live in `src/App.tsx`: lazy imports near lines 217 to 220 (`HrEmployeesPage`, `TelDirectoryPage`) and
  `<Route path="..." element={<Suspense fallback={<AdminSectionFallback />}>...}` blocks nested under
  `<Route path="/admin" ...>` (the `tel-directory` route is at about line 841; the `tsd` shell with nested children at
  about line 1055). Shell pattern: `src/components/admin/tsd/TsdAdminShell.tsx` (an `AdminAccessGate` around `<Outlet />`).
- **Nav** is data: `ADMIN_NAV` in `src/components/admin/data/mockDashboard.ts` (groups are items with `children`; a
  group with its own id has no visibility rule and shows when any child is permitted, see `customer-group`, `hr`, `tsd`).
  Ids are the union `AdminModuleId` in `src/components/admin/types/admin.ts`. Paths and active-module matching are in
  `src/components/admin/navigation/adminRoutes.ts` (`ADMIN_ROUTE_MODULES`, `ADMIN_ROUTABLE_MODULES`,
  `ADMIN_MODULE_ROUTES`, `moduleIdFromPathname`). Visibility rules are `ADMIN_NAV_VISIBILITY_RULES` in
  `src/components/admin/permissions/adminNavVisibility.ts`. Icons are lucide names registered in
  `src/components/admin/iconMap.ts` (`Factory` and `GitBranch` are already registered).
- **Permission gating in the UI**: UI constants only, per module (`src/components/admin/hr/hrPermissions.ts`:
  `HR_EMPLOYEE_PERMISSIONS` and `canOpenHrEmployees(hasPermission)`); `useAuth().hasPermission`; the API is the authority.
- **API client**: `apiRequest<T>(path, init)` from `src/api/apiClient.ts`; it returns the parsed body and throws
  `ApiError(message, code, status)` for non-2xx. Each client unwraps `{success,data}` itself.
- **Tests**: vitest with jsdom (`vite.config.ts` `test` block, setup `src/test/setup.ts`, `globals: true`), testing-library;
  `npm test` is `vitest run`, also `npm run typecheck` (`tsc -b`), `npm run lint` (oxlint), `npm run build`.

## 3. Store: SQLite, rebuilt in full on every build

The engine writes it; PHP only reads it. It holds no operational data (plan D4 default). Dates are `YYYY-MM-DD`,
timestamps are UTC `YYYY-MM-DDTHH:MM:SSZ`. Booleans are `INTEGER` 0 or 1. Quantities are unrounded `REAL`
(never round in storage; the browser formats). A missing optional value is `NULL`, never an empty string, except
`colour`, which is `''` when absent.

### 3.1 Schema (`core/python/cable_routes/schema.sql`, schema version 1)

```sql
PRAGMA user_version = 1;

-- One row. Everything the "data as of" banner and the run endpoint need.
CREATE TABLE cr_run (
  run_id                          INTEGER PRIMARY KEY CHECK (run_id = 1),
  schema_version                  INTEGER NOT NULL,
  built_at                        TEXT    NOT NULL,   -- UTC
  source_name                     TEXT    NOT NULL,   -- basename of the source folder, never a full path
  data_as_of                      TEXT    NOT NULL,   -- newest ScheduledDate in the headers file, ignoring 1900-01-01
  engine_version                  TEXT    NOT NULL,
  finished_total                  INTEGER NOT NULL,   -- pool 50_FMAN or 60_CAT
  finished_traced                 INTEGER NOT NULL,   -- trace_status <> 'untraceable'
  made_items                      INTEGER NOT NULL,   -- rows in cr_item
  trace_to_rod                    INTEGER NOT NULL,
  trace_to_purchased_conductor    INTEGER NOT NULL,
  trace_stops_early               INTEGER NOT NULL,
  untraceable_no_orders           INTEGER NOT NULL,
  untraceable_cutting_only        INTEGER NOT NULL,
  parsed_fit                      INTEGER NOT NULL,   -- finished items whose number fits the pattern (section 5.3)
  parsed_other                    INTEGER NOT NULL,
  cycle_edges_cut                 INTEGER NOT NULL,   -- backward BOM edges ignored during explosion
  check_rows                      INTEGER NOT NULL
);

-- Every made item (has a real order and is in a cable pool or the `?` placeholder pool) with its representative order. Finished items that are
-- traceable are here too; untraceable finished items are not (they have no real order).
CREATE TABLE cr_item (
  item          TEXT PRIMARY KEY,
  pool          TEXT NOT NULL,      -- manufactured-items.pool, else the order's ProductionPoolId, else ''
  diameter_mm   REAL,               -- released-products.CsDiameter, NULL when 0 or absent
  kg_per_m      REAL,               -- released-products.NetProductWeight, NULL when 0 or absent
  unit          TEXT NOT NULL,      -- released-products.InventoryUnitSymbol, '' when absent
  order_no      TEXT NOT NULL,      -- representative order
  order_status  TEXT NOT NULL,
  order_date    TEXT NOT NULL,      -- its ScheduledDate, date part
  real_orders   INTEGER NOT NULL,   -- all real orders for the item, any status
  live_orders   INTEGER NOT NULL    -- real orders that are not Cancelled
);
CREATE INDEX ix_cr_item_pool ON cr_item (pool);

-- The representative order's route, in OperationNumber order. seq is 1-based and gapless.
CREATE TABLE cr_operation (
  item              TEXT    NOT NULL REFERENCES cr_item (item),
  seq               INTEGER NOT NULL,
  operation_number  INTEGER NOT NULL,
  operation         TEXT    NOT NULL,   -- OperationId, e.g. INSULATION
  machine           TEXT    NOT NULL,   -- CostingOperationResourceId, the D365 id, e.g. EX6, RB1
  PRIMARY KEY (item, seq)
);
CREATE INDEX ix_cr_operation_machine ON cr_operation (machine);

-- The representative order's BOM, minus self-consumption.
CREATE TABLE cr_input (
  item            TEXT    NOT NULL REFERENCES cr_item (item),
  line            INTEGER NOT NULL,   -- BOM LineNumber
  input_item      TEXT    NOT NULL,
  qty_per_unit    REAL    NOT NULL,   -- BOMLineQuantity / (BOMLineQuantityDenominator or 1)
  unit            TEXT    NOT NULL,   -- BOMLineUnitSymbol, never converted
  parallel_ends   INTEGER NOT NULL,   -- CsPrdParallelLines (0 or blank -> 1); information only
  kind            TEXT    NOT NULL CHECK (kind IN ('made', 'material')),
  PRIMARY KEY (item, line)
);
CREATE INDEX ix_cr_input_input ON cr_input (input_item);

-- Machines that have run each operation for the item across all live (non-cancelled) real orders.
-- Includes the representative machine; the API removes the step's own machine when it serves this.
CREATE TABLE cr_alt_machine (
  item       TEXT    NOT NULL REFERENCES cr_item (item),
  operation  TEXT    NOT NULL,
  machine    TEXT    NOT NULL,
  orders     INTEGER NOT NULL,
  PRIMARY KEY (item, operation, machine)
);

-- Every item that appears as an input with kind = 'material' (not made).
CREATE TABLE cr_material (
  item         TEXT PRIMARY KEY,
  name         TEXT,                  -- first purchase-order LineDescription, cut to 48 chars; NULL when none
  unit         TEXT,
  diameter_mm  REAL,
  conductor    INTEGER NOT NULL       -- 1 when the item code prefix is a conductor prefix (section 5.5)
);

-- All 4,497 finished items.
CREATE TABLE cr_finished (
  item          TEXT PRIMARY KEY,
  pool          TEXT NOT NULL,        -- 50_FMAN or 60_CAT
  code          TEXT NOT NULL,        -- section 5.3
  construction  TEXT NOT NULL,        -- '5C16', or 'other'
  colour        TEXT NOT NULL,        -- 'BK', or ''
  parse_ok      INTEGER NOT NULL,
  diameter_mm   REAL,
  kg_per_m      REAL,
  unit          TEXT NOT NULL,
  trace_status  TEXT NOT NULL CHECK (trace_status IN ('to_rod', 'to_purchased_conductor', 'stops_early', 'untraceable')),
  reason        TEXT,                 -- section 5.5: no_orders | cutting_only | purchased_conductor | no_rod_in_chain; NULL for to_rod
  reason_items  TEXT,                 -- up to 5 terminal item codes, comma separated; NULL when not applicable
  step_count    INTEGER NOT NULL DEFAULT 0,   -- distinct (operation, machine) pairs in the tree
  max_depth     INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX ix_cr_finished_code ON cr_finished (code, construction);
CREATE INDEX ix_cr_finished_status ON cr_finished (trace_status, code);

-- The explosion: need for 1 unit of the finished item, per (finished, item, unit). The page multiplies by the length.
CREATE TABLE cr_need (
  finished      TEXT    NOT NULL REFERENCES cr_finished (item),
  item          TEXT    NOT NULL,
  unit          TEXT    NOT NULL,
  need_per_unit REAL    NOT NULL,
  depth         INTEGER NOT NULL,     -- longest path from the finished item (finished = 0)
  kind          TEXT    NOT NULL CHECK (kind IN ('finished', 'made', 'material')),
  is_rod        INTEGER NOT NULL DEFAULT 0,  -- material consumed by an item whose route has ROD_BREAK
  PRIMARY KEY (finished, item, unit)
);
CREATE INDEX ix_cr_need_item ON cr_need (item);

-- Poster reference data (seed CSVs).
CREATE TABLE cr_machine (
  machine            TEXT PRIMARY KEY,   -- the D365 id when there is one, else the poster name
  poster_name        TEXT,               -- the poster's name (RB2 for D365 RB1), NULL when not on the poster
  d365_id            TEXT,               -- NULL when the machine is not in D365 routes
  in_poster          INTEGER NOT NULL,
  d365_status        TEXT NOT NULL CHECK (d365_status IN ('routed', 'alias', 'no_routings', 'retired', 'not_on_poster')),
  hidden             INTEGER NOT NULL,   -- 1 for retired machines (EX7, EX8): kept for checks, never served in lists or the map
  site               TEXT,
  stage_no           INTEGER,
  stage              TEXT,
  poster_status      TEXT,               -- existing | new_or_upgraded | planned | not_in_use
  brand              TEXT,
  machine_type       TEXT,
  input_limit        TEXT,
  output_capability  TEXT,
  pay_off            TEXT,
  take_up            TEXT,
  speed_text         TEXT,
  speed_m_per_min    REAL,
  other_notes        TEXT,
  legibility         TEXT,
  made_items         INTEGER NOT NULL,   -- cr_item rows whose representative route uses it
  finished_items     INTEGER NOT NULL,   -- finished items whose tree uses it
  codes              INTEGER NOT NULL    -- distinct finished-item codes among those
);
CREATE UNIQUE INDEX ux_cr_machine_poster ON cr_machine (poster_name) WHERE poster_name IS NOT NULL;

CREATE TABLE cr_machine_limit (          -- poster limits, section 3.4
  machine  TEXT PRIMARY KEY,
  min_mm   REAL,
  max_mm   REAL,
  note     TEXT
);

CREATE TABLE cr_machine_finished (       -- which finished items route through which machine
  machine   TEXT NOT NULL,
  finished  TEXT NOT NULL REFERENCES cr_finished (item),
  steps     INTEGER NOT NULL,            -- how many made items in the tree run on it
  PRIMARY KEY (machine, finished)
);
CREATE INDEX ix_cr_machine_finished_item ON cr_machine_finished (finished);

CREATE TABLE cr_flow_edge (              -- poster-flow-edges.csv; stored, not served in v1
  seq INTEGER PRIMARY KEY, from_stage TEXT, from_machines TEXT, to_stage TEXT, to_machines TEXT, flow TEXT, line_colour TEXT
);

CREATE TABLE cr_family (                 -- poster-product-families.csv, one row per CSV row
  seq INTEGER PRIMARY KEY, family TEXT NOT NULL UNIQUE, line_colour TEXT, codes_text TEXT, poster_route TEXT,
  expected_ops TEXT, notes TEXT
);
CREATE TABLE cr_family_code (
  family TEXT NOT NULL REFERENCES cr_family (family),
  code   TEXT NOT NULL,
  PRIMARY KEY (family, code)
);

CREATE TABLE cr_range (                  -- poster-product-ranges.csv
  seq INTEGER PRIMARY KEY,
  range_name TEXT NOT NULL, code TEXT NOT NULL, description TEXT, voltage TEXT, insulation_jacket TEXT,
  conductor TEXT, max_temp_c REAL, legibility TEXT,
  UNIQUE (range_name, code)
);

-- One row per code seen in finished items or the poster ranges.
CREATE TABLE cr_code (
  code            TEXT PRIMARY KEY,
  description     TEXT,                -- description of the code's first range row (CSV order), NULL if none
  in_poster       INTEGER NOT NULL,    -- 1 when the code is in cr_range
  finished_items  INTEGER NOT NULL,
  traced_items    INTEGER NOT NULL     -- trace_status <> 'untraceable'
);

CREATE TABLE cr_check (
  check_id   INTEGER PRIMARY KEY,
  kind       TEXT NOT NULL CHECK (kind IN ('family_route_mismatch', 'machine_not_on_poster', 'poster_machine_unused',
                                           'routed_to_retired_machine', 'outside_poster_limit', 'stops_early', 'untraceable')),
  item       TEXT,        -- a made item or finished item, NULL for code-level and machine-level rows
  code       TEXT,
  machine    TEXT,
  operation  TEXT,
  detail     TEXT NOT NULL,   -- one human sentence, no jargon beyond item and operation codes
  affected   INTEGER,         -- items behind a grouped row
  total      INTEGER          -- the denominator for that count, where there is one
);
CREATE INDEX ix_cr_check_kind ON cr_check (kind, code);
CREATE INDEX ix_cr_check_item ON cr_check (item);
```

### 3.2 Sizes and invariants (the engine's tests assert these)

- `cr_finished` has one row per finished item (4,497 on the 2026-09-04 extract). `cr_item` has 6,373 rows on that extract
  (plan section 4: "6,373 made items sit in all the chains together").
- Every `cr_input.item` and every `cr_need.finished` exists. Every `cr_input.kind = 'material'` row's `input_item` is in
  `cr_material`. Every `cr_operation.seq` run per item is 1..n.
- `cr_need` has exactly one row with `kind = 'finished'` per traced finished item: `item = finished`, `need_per_unit = 1`,
  `depth = 0`, `unit` = the finished item's unit.
- A made item's `need_per_unit` is the sum over all parents of (parent need x qty per unit on the edge), per unit. Never
  summed across units. `ParallelLines` is never multiplied in (it is already in `BOMLineQuantity`).
- `cr_check` rows exist only for the seven kinds above.

### 3.3 Atomic rebuild

The engine builds into `<db>.tmp-<pid>` in the same folder, runs `PRAGMA integrity_check` and `PRAGMA foreign_key_check`,
closes it, then `os.replace(tmp, db)`. On any failure the temp file is deleted and the old store is left alone.
`journal_mode = DELETE` (no WAL files). PHP opens the file read-only, so a swap between two requests is seen whole.

### 3.4 `seed/machine-limits.csv`

Fifth seed file, written by the engine builder from plan section 5.6 (`machine,min_mm,max_mm,note`). Poster names equal
D365 ids for all of these, so no alias is needed. Diameters are the diameter of the item entering the machine.

| Machine | min_mm | max_mm |
|---|---|---|
| EX5 | | 20 |
| EX1 | | 12 |
| EX3 | | 73 |
| EX4 | | 83 |
| EX6 | | 10 |
| LU5 | | 10 |
| LU9 | | 28 |
| LU8 | | 14 |
| LU3 | | 70 |
| BR4 | 4 | 30 |
| BR1 | 20 | 80 |
| BR2 | 4 | 45 |
| BR3 | 4 | 20 |
| BR5 | 4 | 30 |
| BR6 | 20 | 85 |

## 4. Engine

### 4.1 Command line

```
python -m cable_routes build  [--source DIR] [--db PATH] [--quiet]
python -m cable_routes status [--db PATH]
```

Run from `core/python` (pytest's `pythonpath` is `.`), or as `PYTHONPATH=core/python python3 -m cable_routes ...` from the
API root. `build` prints one summary line per stage and the final counts; `status` prints the `cr_run` row. Exit codes:
0 success, 2 bad usage or configuration (no source dir, folder missing), 3 a required file or column is missing (the
message names the file and column), 1 anything else. Nothing is written on a non-zero exit.

### 4.2 Configuration and environment

| Variable | Default | Read by | Meaning |
|---|---|---|---|
| `CABLE_ROUTES_DB_PATH` | `var/cable-routes/cable-routes.sqlite` | PHP (`Settings::cableRoutesDbPath`) and the engine | The store. A relative path is resolved against the API root (the `tricab-api` folder: the parent of `core/`). An absolute path is used as is. `~` is expanded by the engine. The engine creates the folder |
| `CABLE_ROUTES_SOURCE_DIR` | none | Engine only | Folder holding the six D365 extract CSVs. No default: `build` fails with exit 2 and a clear message if neither this nor `--source` is given |

Precedence in the engine: command-line flag, then process environment, then `<API root>/.env` (only these two keys,
read with a few lines of stdlib parsing, no new dependency), then the default. This matches PHP, where the process
environment wins over `.env`.

Why there is no default for the source: the plan's default (`planning/dynamics`) is outside this repository and every
developer's checkout is somewhere else, so any default would be a hard-coded home path. In development, set it once in
the untracked `.env` of the API checkout, for example `CABLE_ROUTES_SOURCE_DIR=~/Desktop/TriCab/planning/dynamics` (the
engine expands `~`). `.env.example` carries a commented placeholder only (`# CABLE_ROUTES_SOURCE_DIR=/path/to/d365-extract`).

The store must not be committed: add `/var/cable-routes/` to `.gitignore` (the default path is inside it, and so are any
temp or journal files). Verified on 2026-10-27 that nothing ignores it today (`git check-ignore` returns 1).

The PHP side never reads `CABLE_ROUTES_SOURCE_DIR`. PHP tests do not run Python: they create a fixture store in a temp
file by running `core/python/cable_routes/schema.sql` and inserting rows (section 8.2), so schema drift fails a PHP test.

Production note (plan D1/D4, a follow-up): the store is not part of the release zip (`var/` is not staged), so on the App
Service every endpoint answers 503 `CABLE_ROUTES_STORE_UNAVAILABLE` until a store is built or copied onto the host and
`CABLE_ROUTES_DB_PATH` points at it. Wiring that is out of scope for v1; the dev flow is `build` on a laptop.

### 4.3 Inputs

Folder `CABLE_ROUTES_SOURCE_DIR`, read with `csv.DictReader`, `encoding="utf-8-sig"`. Fail loudly (exit 3) when a file or
a column below is absent. Only these columns are used:

| File | Columns |
|---|---|
| `production-order-headers-raw.csv` | `ProductionOrderNumber`, `ItemNumber`, `ProductionOrderStatus`, `ScheduledDate`, `CsIsCuttingOrder`, `ProductionPoolId` |
| `production-bom-lines-raw.csv` | `ProductionOrderNumber`, `LineNumber`, `ItemNumber`, `BOMLineQuantity`, `BOMLineQuantityDenominator`, `BOMLineUnitSymbol`, `CsPrdParallelLines` |
| `production-route-operations-raw.csv` | `ProductionOrderNumber`, `OperationNumber`, `OperationId`, `CostingOperationResourceId` |
| `released-products.csv` | `ItemNumber`, `CsDiameter`, `NetProductWeight`, `InventoryUnitSymbol` |
| `manufactured-items.csv` | `item_number`, `pool` |
| `purchase-order-lines-all.csv` | `ItemNumber`, `LineDescription` |

Seeds (packaged in `seed/`, copied from `planning/workflow/`, never moved): `poster-machines.csv`,
`poster-product-families.csv`, `poster-product-ranges.csv`, `poster-flow-edges.csv`, plus `machine-limits.csv`. Add
`cable_routes` to `[tool.setuptools] packages` and `cable_routes = ["schema.sql", "seed/*.csv"]` to `package-data`
in `core/python/pyproject.toml`.

### 4.4 Rules (plan section 4 and 5, made exact)

1. **Finished item**: `manufactured-items.pool` in `{50_FMAN, 60_CAT}`.
2. **Real order** of an item: `CsIsCuttingOrder != "Yes"` and at least one BOM line on the order has an `ItemNumber` other
   than the order's own item.
3. **Representative order** (plan D2 default): among the item's real orders, the max of `(status != "Cancelled",
   ScheduledDate, ProductionOrderNumber)`. The last key only makes ties deterministic. Dates of `1900-01-01` are a D365
   "no date" value; they sort oldest naturally and are never reported as "data as of".
4. **Made item**: has a real order and its pool is a cable pool, `{15_WIR, 20_BUN, 25_FCON, 30_TAPE, 35_WIP, 40_SFG, 50_FMAN,
   55_PNP, 60_CAT}`, or the placeholder pool `?` (90 items with a real order that are missing from `manufactured-items`
   and whose order carries the literal pool `?`; they are in-house cores and conductors such as `DIX-1C1.5`; owner decision
   2026-10-27, matching the prototype). Everything else consumed (including `10_CBC` compounds, `RS`/`RI`/`RC` items, and cable-pool items
   with no real order) is a `material` and is never expanded.
5. **Inputs** of a made item: the representative order's BOM lines minus lines whose item is the order's own item.
   Quantity per unit is `BOMLineQuantity / (BOMLineQuantityDenominator or 1)`; a denominator of 0, blank or unparsable counts
   as 1. Units are kept as given (mostly `m` for made, `kg` for materials) and never converted. `parallel_ends` is
   `CsPrdParallelLines` as an integer, 1 when 0 or blank.
6. **Operations**: the representative order's route operations sorted by integer `OperationNumber`; `seq` is 1..n.
7. **Alternative machines**: for every real, non-cancelled order of the item, count `(OperationId, CostingOperationResourceId)`.
8. **Material names**: first non-empty `LineDescription` per item in file order, stripped, cut to 48 characters, then
   `rstrip(" ,.")`. In-house compounds have none: `name` stays `NULL`. Do not guess what a prefix means.
9. **Explosion**, per traced finished item F: build the graph of made-item edges from `cr_input` (`kind = 'made'` and
   `'material'` both count as edges for need). Cut every backward edge found by a depth-first walk that keeps the
   current path as its visited set (a cut edge is any edge whose target is already on the path); count them once into
   `cycle_edges_cut` (distinct edges across the whole build). Then process the remaining acyclic graph in topological
   order from F: `need[F] = 1` (in the finished item's unit). For each edge parent to child with quantity `q` in unit `u`,
   `need[child, u] += N x q`, where `N` is the parent's need **in the parent's own output unit** (`cr_item.unit`; the
   sum of its `need` rows in that unit). Needs in any other unit stay as separate rows and are never converted or added to
   it. Diamonds (NX-1C16 feeding four cores) accumulate across all paths. `depth` is the longest path from F; a material's depth is
   its longest path (consumer depth + 1).
10. **`is_rod`**: a `material` row is rod when at least one made item that consumes it has an operation `ROD_BREAK` in its route.
11. **Machines**: start from `poster-machines.csv`; add every D365 resource seen in `cr_operation` and `cr_alt_machine`.
    The alias map is a constant `{"RB2": "RB1", "TN1": "TL1"}` (poster name to D365 id). `machine` is the D365 id when one exists,
    otherwise the poster name. `d365_status`, in this precedence: `retired` (poster status `not_in_use`; also `hidden = 1`), else
    `alias` (seen under a different id than the poster name), else `routed` (seen under its own name), else `no_routings`
    (on the poster, never seen), else `not_on_poster` (seen, no poster machine). Counts `made_items`, `finished_items` and
    `codes` come from `cr_operation` and `cr_machine_finished`.
12. **Data as of**: the newest `ScheduledDate` (date part) in the headers file, ignoring `1900-01-01`. On the 2026-09-04
    extract that is 2026-09-04.

### 4.5 Trace status (plan 5.5, made exact)

For each finished item, in this order:

1. No real order: `untraceable`; `reason = cutting_only` when the item has orders and every one is a cutting order or
   self-consuming, otherwise `no_orders`.
2. The tree (F plus everything reachable through made inputs) contains any item whose route includes `ROD_BREAK`: `to_rod`.
3. Else the tree has a `material` input whose item prefix (text before the first `-`, upper case) is a conductor prefix:
   `to_purchased_conductor`, `reason = purchased_conductor`, `reason_items` = up to 5 such item codes. Conductor prefixes
   are a single constant: `NX, DX, DM, MNX, N1, N2, AL` (the confirmed families of `planning/conductor` `CopperRule`
   `copper-v2`, plus the plan's purchased `N1`/`N2` and the poster's "AL (purchased)" aluminium row). `cr_material.conductor`
   uses the same constant.
4. Else `stops_early`, `reason = no_rod_in_chain`, `reason_items` = up to 5 terminal materials.

Expected on the 2026-09-04 extract: 2,862 traced, of which 2,689 `to_rod`; the other 173 split between
`to_purchased_conductor` and `stops_early`. Confirmed on the real extract once the `?` pool counts as made (rule 4; without it the figure is 2,672). The plan's wording "trace all the way back to ROD_BREAK" could also mean every conductor branch; the owner chose "the tree contains a ROD_BREAK operation".

### 4.6 Checks (plan 5.6, made exact)

| Kind | Rows | Rule |
|---|---|---|
| `family_route_mismatch` | one per (code, operation), `item` NULL, `affected`, `total` | For each code in exactly one family with non-empty `expected_ops`, and in no family with empty `expected_ops` (this skips `XL KL CC FR EZ`, which are also single-core SDI and mixed, per workflow README): take the traced finished items of that code; their actual operation set is the union of route operations over the tree, restricted to `{LAYUP, BEDDING, BRAIDING, ARMOURING, TAPING, SHEATHING}`. Expected set = the family's `expected_ops` with `NYLON` replaced by `SHEATHING` (D365 books nylon jackets as sheathing). For each expected op present in fewer than 50% of the code's items: a row "ARMOURING expected for R2; none in Dynamics" (`affected` = items lacking it, `total` = items). For each of `{BEDDING, BRAIDING, ARMOURING}` not expected but present in 50% or more: a row "BEDDING in 100% of HT items; the poster family does not list it". Must catch FC (no braid or bedding), HT (has bedding) and R2/RA/IA (no ARMOURING) |
| `machine_not_on_poster` | one per machine, `machine` set | `d365_status = not_on_poster` (CPD1, RW4, PRINT and the like) |
| `poster_machine_unused` | one per machine | `d365_status = no_routings` (TP3, BR4, BR5, BR6 are expected here) |
| `routed_to_retired_machine` | one per made item, `item`, `machine`, `operation` | A made item's representative route uses a `retired` machine. `detail` names the order, its status and date so a reader can tell a real order from an old one (EX8 appears on 8 items) |
| `outside_poster_limit` | one per (made item, machine) | For a made item with an operation on a machine in `cr_machine_limit`: the diameter of the largest made input is below `min_mm` or above `max_mm`. `detail` reads "input is 27.9 mm; poster limit, 2023: 4 to 20 mm". Never worded as an error |
| `stops_early` | one per (code, reason) | Finished items with that status, `affected` = count, `total` = finished items of the code |
| `untraceable` | one per (code, reason) | Same, for `no_orders` and `cutting_only` |

An item page shows the checks that touch it: rows whose `item` is any item in its tree (`cr_need` with `kind` in
`finished`, `made`), plus code-level rows (`item IS NULL`, kinds `family_route_mismatch`, `stops_early`, `untraceable`) whose
`code` equals the finished item's code, plus `machine`-level rows are not shown on item pages.

### 4.7 Item number parsing

`code` = the first 2 characters of the item number, or 3 when it starts with `XDT`, `KDT` or `CDT`. After the first `/`, the rest
must match `^(\d+C\d+(?:\.\d+)?)([A-Z]*)$` (case-insensitive, compared upper case): group 1 is `construction`, group 2 is
`colour`, `parse_ok = 1`. Anything else (no `/`, a second `/`, no match): `construction = "other"`, `colour = ""`,
`parse_ok = 0`. The full item number stays the key. Required cases: `KA9X-PASN/5C16BK` gives KA, `5C16`, `BK`;
`...1C300BK` gives `1C300`, `BK`; `...3C2.5` gives `3C2.5`, no colour; a non-matching number gives `other`.

### 4.8 Engine module layout (suggested, not binding)

`__init__.py`, `__main__.py`, `cli.py`, `config.py` (env and `.env`), `loader.py` (CSV and column checks), `parse.py`
(item numbers), `model.py` (representative orders, inputs, operations), `explode.py`, `trace.py`, `checks.py`, `store.py`
(schema, write, atomic swap), `schema.sql`, `seed/`. Tests in `core/python/tests/cable_routes/` with a `conftest.py` that
writes synthetic CSV folders into `tmp_path` (only the columns in 4.3) and a real-data module that skips unless
`CABLE_ROUTES_SOURCE_DIR` is set.

## 5. API

All routes: `GET`, under `/api/v1/cable-routes`, each guarded by `CABLE_ROUTES.READ`. Item numbers contain `/`, so
they only ever travel in the query string, URL-encoded (`item=KA9X-PASN%2F5C16BK`).

### 5.1 Envelope and errors (a deliberate departure from conductor)

Conductor returned bare JSON on success. The platform does not: `JsonResponder::success()` always wraps in
`{"success":true,"data":{...}}`, and the shipped web client (`requireSuccessData`) rejects a body without it. This module
**uses the platform envelope**: success `{"success":true,"data":<payload>}`, failure `{"success":false,"error":{"code","message"}}`.
Every payload below is the content of `data`. Field names are camelCase.

| Status | Code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | No verified identity (middleware) |
| 403 | `INSUFFICIENT_PERMISSION` | Signed in without `CABLE_ROUTES.READ` (middleware) |
| 422 | `CABLE_ROUTES_ITEM_REQUIRED` | `item` missing or blank on `GET item` |
| 422 | `CABLE_ROUTES_MACHINE_REQUIRED` | `id` missing or blank on `GET machine` |
| 422 | `CABLE_ROUTES_KIND_INVALID` | `kind` on `GET checks` is not one of the seven kinds |
| 422 | `CABLE_ROUTES_PARAM_INVALID` | Any other bad parameter (`page` < 1, `pageSize` out of range, unknown `traceStatus`) |
| 404 | `CABLE_ROUTES_ITEM_NOT_FOUND` | `item` is not in `cr_finished` (untraceable items are found; they answer with an empty tree) |
| 404 | `CABLE_ROUTES_MACHINE_NOT_FOUND` | `id` matches no machine, or the machine is hidden (retired) |
| 503 | `CABLE_ROUTES_STORE_UNAVAILABLE` | The file is missing, unreadable, has no `cr_run` row, or `user_version` is not 1. The message never contains a path |

Errors are mapped in `CableRoutesErrorMapper` from core exceptions (`CableRoutesStoreUnavailable`, `CableRoutesItemNotFound`,
`CableRoutesMachineNotFound`, `CableRoutesInvalidRequest(code, message)`); an unexpected `Throwable` is logged with
`error_log('[cable-routes] ...')` and answered as 503 `CABLE_ROUTES_STORE_UNAVAILABLE`, as Tel Directory does.

Parameter parsing uses `QueryParams`. Text filters are trimmed; an empty value means "not given". `q` and `code` are matched
case-insensitively; `q` is a substring match on the item number with `%`, `_` and `\` treated literally.
Paging: `page` (default 1), `pageSize` (default 50, 1 to 200); response carries `page`, `pageSize`, `totalItems`, `totalPages`.

### 5.2 Endpoints

**`GET /api/v1/cable-routes/run`**

```json
{"success":true,"data":{
  "run":{"builtAt":"2026-10-27T03:12:44Z","dataAsOf":"2026-09-04","sourceName":"dynamics","schemaVersion":1,"engineVersion":"1.0.0"},
  "counts":{"finishedItems":4497,"tracedItems":2862,"madeItems":6373,"parsedFit":4400,"parsedOther":97,"cycleEdgesCut":0,"checkRows":0},
  "traceStatus":[
    {"status":"to_rod","count":2689},{"status":"to_purchased_conductor","count":0},{"status":"stops_early","count":0},{"status":"untraceable","count":1635}],
  "untraceableReasons":[{"reason":"no_orders","count":0},{"reason":"cutting_only","count":0}]}}
```
(The numbers in the example other than 4,497, 2,862, 2,689, 6,373 and 1,635 are placeholders.) `traceStatus` always lists all
four statuses in that order; `untraceableReasons` both reasons.

**`GET /api/v1/cable-routes/codes`** (no paging, about a hundred rows)

```json
{"success":true,"data":{
  "codes":[{"code":"KA","description":"110 °C power cable with steel wire armour","inPoster":true,
            "ranges":[{"range":"Industrial","description":"...","voltage":"0.6/1kV","insulationJacket":"X-HF-110/PVC/SWA/PVC","conductor":"CU","maxTempC":110}],
            "families":["Armoured cables"],"finishedItems":120,"tracedItems":97}],
  "parse":{"fit":4400,"other":97}}}
```
Sorted by code. `description` is the first range row's description (CSV order), null when the code is not on the poster.
`ranges` lists every range row for the code (empty when none); the Product ranges page is built from this. `families` lists the
names from `cr_family_code`. Codes with `inPoster: true` and zero finished items are included.

**`GET /api/v1/cable-routes/items?code=&construction=&q=&traceStatus=&page=&pageSize=`**

```json
{"success":true,"data":{
  "items":[{"item":"KA9X-PASN/5C16BK","code":"KA","construction":"5C16","colour":"BK","diameterMm":27.85,"kgPerM":1.678,
            "unit":"m","pool":"50_FMAN","traceStatus":"to_rod","reason":null}],
  "facets":{"constructions":[{"construction":"5C16","count":6}],
            "traceStatus":[{"traceStatus":"to_rod","count":6}]},
  "page":1,"pageSize":50,"totalItems":6,"totalPages":1}}
```
Ordered by `item` ascending. `facets` are counted over the `code` and `q` filters but **not** the `construction` or
`traceStatus` filter, so the pickers can show their options. `construction=other` selects items that do not fit the pattern.
`traceStatus`, when given, must be one of the four values (422 otherwise). No filter at all is allowed (everything, paged).

**`GET /api/v1/cable-routes/item?item=KA9X-PASN/5C16BK`** (the whole tree; one request feeds the Cable page and the map)

```json
{"success":true,"data":{
  "item":{"item":"KA9X-PASN/5C16BK","code":"KA","construction":"5C16","colour":"BK","pool":"50_FMAN","diameterMm":27.85,
          "kgPerM":1.678,"unit":"m","traceStatus":"to_rod","reason":null,"reasonItems":[],"stepCount":10,"maxDepth":8},
  "dataAsOf":"2026-09-04",
  "nodes":[
    {"item":"KA9X-PASN/5C16BK","kind":"finished","pool":"50_FMAN","depth":0,"diameterMm":27.85,"kgPerM":1.678,"unit":"m",
     "order":{"orderNo":"151107M","status":"Completed","date":"2023-12-18","realOrders":1,"liveOrders":1},
     "steps":[{"seq":1,"operation":"SHEATHING","machine":{"machine":"EX4","posterName":"EX4","stageNo":5,"onPoster":true,"retired":false}}],
     "altMachines":[],
     "need":[{"unit":"m","perUnit":1}],
     "materialInputs":[{"item":"RS0002","name":null,"qtyPerUnit":0.21541,"unit":"kg"},
                       {"item":"MB_BLACK","name":"Colour Masterbatch - BLACK","qtyPerUnit":0.0057,"unit":"kg"}]},
    {"item":"NX-1C16","kind":"made","pool":"25_FCON","depth":5,"diameterMm":5.18,"kgPerM":0.1361,"unit":"m",
     "order":{"orderNo":"W0049406","status":"Released","date":"2026-09-04","realOrders":391,"liveOrders":391},
     "steps":[{"seq":1,"operation":"BUNCHING","machine":{"machine":"BU1","posterName":"BU1","stageNo":3,"onPoster":true,"retired":false}}],
     "altMachines":[],
     "need":[{"unit":"m","perUnit":4.0396}],
     "materialInputs":[]},
    {"item":"NX-1W1.6","kind":"made","pool":"15_WIR","depth":8,"diameterMm":1.6,"kgPerM":0.01804,"unit":"m",
     "order":{"orderNo":"W0049517","status":"Released","date":"2026-09-04","realOrders":473,"liveOrders":473},
     "steps":[{"seq":1,"operation":"ROD_BREAK","machine":{"machine":"RB1","posterName":"RB2","stageNo":1,"onPoster":true,"retired":false}}],
     "altMachines":[],"need":[{"unit":"m","perUnit":30.4753}],
     "materialInputs":[{"item":"NX-1R8-NEX","name":"Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe","qtyPerUnit":0.01804,"unit":"kg"}]}
  ],
  "edges":[{"parent":"KA9X-PASN/5C16BK","input":"WAKA9X-PASN/5C16","qtyPerUnit":1.0,"unit":"m","parallelEnds":1},
           {"parent":"NX-1C16","input":"NX-32W.3","qtyPerUnit":7.02611,"unit":"m","parallelEnds":7}],
  "steps":[{"stepNo":1,"operation":"ROD_BREAK","machine":{"machine":"RB1","posterName":"RB2","stageNo":1,"onPoster":true,"retired":false},"items":["NX-1W1.6"],"maxDepth":8},
           {"stepNo":10,"operation":"SHEATHING","machine":{"machine":"EX4","posterName":"EX4","stageNo":5,"onPoster":true,"retired":false},"items":["KA9X-PASN/5C16BK"],"maxDepth":0}],
  "materials":[{"item":"NX-1R8-NEX","name":"Rod PCu 8mm Rod PCu 8mm 8mm plain annealed coppe","unit":"kg","perUnit":0.5498,"isRod":true,"conductor":true,"depth":9},
               {"item":"RS0002","name":null,"unit":"kg","perUnit":0.30474,"isRod":false,"conductor":false,"depth":3}],
  "checks":[]}}
```
The example is abbreviated: it shows 3 of the 15 nodes, 2 of the edges, 2 of the 10 steps and 2 of the materials, and
every number that is not printed in plan sections 4 and 8 (the chain, `0.1361`, the per-line quantities, 1,000 m cores
at 1.0099 m each) is illustrative. The rod `perUnit` of `0.5498` (4.0396 x 0.1361) covers the 16 mm² conductor paths only; the real value also includes the
earth-core path and comes from `cr_need`. `NX-1W1.6`'s `30.4753` is `4.0396 x 7.02611 x 4.00232 x 0.26829`, likewise without the earth path.

Field rules:
- `item` carries the `cr_finished` row. `reasonItems` is `reason_items` split into a list. `stepCount` and `maxDepth` come from `cr_finished`.
- `nodes`: the finished item and every made item in the tree, **one node per item** even when it feeds several parents,
  sorted by `depth` ascending then `item`. `kind` is `finished` or `made`. `steps` is that item's own route (`cr_operation`),
  `altMachines` is `cr_alt_machine` for the item's operations **excluding the step's own machine**, ordered by `orders` descending
  then `machine`, each `{operation, machine:<machine object>, orders}`. `need` is one entry per unit (`cr_need`). `materialInputs` are
  that item's own `kind = 'material'` inputs (`cr_input` joined to `cr_material`), ordered by `line`; `qtyPerUnit` is per one unit
  of **this** node's output. `order` is the provenance (representative order).
- `machine` objects are `{machine, posterName, stageNo, onPoster, retired}`; `stageNo` and `posterName` are null when
  `onPoster` is false (the UI draws those grey). Retired machines (EX7, EX8) can appear here, with `retired: true`; the UI
  shows a grey chip and leaves them off the map.
- `edges`: `cr_input` rows with `kind = 'made'` between two nodes of this tree, from `input` into `parent`. Backward edges
  that the engine cut are not served. Ordered by `parent`, then `input`.
- `steps`: the distinct `(operation, machine)` pairs over all nodes, numbered `stepNo` 1..n in production order: by `maxDepth`
  (the deepest node running that step) descending, then by the pair's smallest `seq`, then `operation`, then `machine`.
  `items` lists the nodes running it. For the fixture this gives **10 steps** (plan section 8).
- `materials`: `cr_need` rows with `kind = 'material'`, one per (item, unit), joined to `cr_material`; ordered with
  `isRod` rows first, then `unit` (`kg` before the rest), then `perUnit` descending, then `item`. `perUnit` is per one unit
  of finished item; the browser multiplies by the length box and does nothing else.
- `checks`: rows touching the item (section 4.6), shaped as in `GET checks` rows.
- An untraceable finished item answers 200 with `nodes` containing only itself (no `steps`, `order` null), empty `edges`,
  `steps`, `materials`, and `traceStatus: "untraceable"` with its `reason`.

**`GET /api/v1/cable-routes/machines`** (not paged; hidden machines excluded)

```json
{"success":true,"data":{"machines":[
  {"machine":"RB1","posterName":"RB2","d365Id":"RB1","onPoster":true,"d365Status":"alias","site":"Port Melbourne",
   "stageNo":1,"stage":"Rod Breaking","posterStatus":"existing","brand":"NIEHOFF","machineType":"Rod breaker",
   "inputLimit":"8 mm soft Cu rod","speedText":"29 m/s","speedMPerMin":1740,
   "limit":null,"madeItems":480,"finishedItems":2689,"codes":90}]}}
```
Ordered by `stageNo` (nulls last), then `machine`. `limit` is `{minMm,maxMm,note}` from `cr_machine_limit` or null.

**`GET /api/v1/cable-routes/machine?id=EX6&code=&page=&pageSize=`**

`id` is matched case-insensitively against `machine`, then against `poster_name` (so the map can link with either name).
Returns the machine as above plus the poster card (`outputCapability`, `payOff`, `takeUp`, `otherNotes`, `legibility`), then:

```json
{"success":true,"data":{
  "machine":{"machine":"EX6","posterName":"EX6", "...":"..."},
  "codes":[{"code":"KA","finishedItems":97}],
  "items":{"items":[{"item":"KA9X-PASN/5C16BK","code":"KA","construction":"5C16","colour":"BK","diameterMm":27.85,"steps":5}],
           "page":1,"pageSize":50,"totalItems":97,"totalPages":2},
  "topInputs":[{"item":"NX-1C16","name":null,"kind":"made","unit":"m","madeItems":412}],
  "checks":[]}}
```
`codes` are the distinct codes of the finished items routed through the machine (`cr_machine_finished`), ordered by count
descending then code. `items` is paged and honours `code`. `topInputs` is the 15 inputs (`cr_input.input_item`) most often
consumed by made items whose route runs on the machine, by distinct `madeItems` descending then `item`. `checks` are the
rows with `machine = id`. A hidden machine (EX7, EX8) is 404.

**`GET /api/v1/cable-routes/checks?kind=&code=&page=&pageSize=`**

```json
{"success":true,"data":{
  "counts":[{"kind":"family_route_mismatch","label":"Route differs from the poster family","count":14},
            {"kind":"outside_poster_limit","label":"Outside poster limit, 2023","count":0}],
  "kind":"family_route_mismatch",
  "rows":[{"checkId":1,"kind":"family_route_mismatch","item":null,"code":"R2","machine":null,"operation":"ARMOURING",
           "detail":"ARMOURING expected for R2; none in Dynamics","affected":18,"total":18}],
  "page":1,"pageSize":100,"totalItems":14,"totalPages":1}}
```
`counts` always lists all seven kinds in this order and with these labels: `family_route_mismatch` "Route differs from the poster
family", `machine_not_on_poster` "Machine in Dynamics but not on the poster", `poster_machine_unused` "Poster machine with no
Dynamics routings", `routed_to_retired_machine` "Routed to a machine that is not used", `outside_poster_limit` "Outside poster
limit, 2023", `stops_early` "Chain stops before copper rod or purchased conductor", `untraceable` "No production orders to trace".
`kind` is omitted from the request to get every kind (then `kind` in the response is null). `pageSize` here defaults to 100 and
may go to 500. Rows are ordered by kind (in the order above), then `code`, `machine`, `item`, `checkId`.

## 6. Web

### 6.1 Routes (all under `/admin`, all inside the existing `<Route path="/admin">` block of `src/App.tsx`)

Query strings carry item numbers and machine ids (item numbers contain `/`).

| Route | Page (file in `src/components/admin/cableRoutes/`) | Calls |
|---|---|---|
| `/admin/cable-routes` | `FindCablePage.tsx`, the landing page: Code, Construction, Item pickers and free-text search; each code shows its poster description | `GET codes` once; `GET items?code=&construction=&q=` as the pickers change |
| `/admin/cable-routes/cable?item=` | `CablePage.tsx`: length box (default 1,000 m), build tree, steps in order, materials for this length, in-place checks, link to the map | `GET item` |
| `/admin/cable-routes/map?item=` | `FactoryMapPage.tsx` + `map/PosterMap.tsx` + `map/posterLayout.ts` (port of `redraw.py` `LAYOUT` and `machine_diagram()`; EX7 and EX8 left out) | `GET machines` always; `GET item` when `item` is present |
| `/admin/cable-routes/machines` | `MachinesPage.tsx` | `GET machines` |
| `/admin/cable-routes/machine?id=` | `MachinePage.tsx` | `GET machine` |
| `/admin/cable-routes/checks?kind=` | `ChecksPage.tsx`: the seven kinds as filterable tables | `GET checks` |
| `/admin/cable-routes/ranges` | `RangesPage.tsx`: the eight poster range tables, each code linking to Find a cable | `GET codes` (its `ranges`) |

`CableRoutesShell.tsx` is the element of the parent `cable-routes` route (pattern: `TsdAdminShell`). It wraps `<Outlet />` in
`AdminAccessGate` using `canOpenCableRoutes(hasPermission)`, shows the tab bar (Find a cable, Factory map, Machines, Checks,
Product ranges) and, on every page, the line "Dynamics data as of {date}" with the sentence "Routes come from the most recent
production order for each item." (from `GET run`, fetched once by the shell and shared). The tab bar, not the sidebar,
carries the sub-pages, so the nav needs one entry only. `/admin/cable-routes` is the index route. The shell gates on the
single permission; the API is the authority.

All state worth sharing lives in the URL (`item`, `id`, `kind`, `code`, `construction`, `q`, `page`), and the length lives in
`?metres=` on the Cable page (default 1000) so a view can be shared. The tree lays out left to right: column = `maxDepth - depth`
(rod on the left, finished cable on the right).

### 6.2 Navigation entry

A new group "Production" (it does not exist yet) with one child:

```ts
{
  id: 'production',            // group: own id, no visibility rule, shows when a child is permitted
  label: 'Production',
  icon: 'Factory',
  children: [{ id: 'cable-routes', label: 'Cable routes', icon: 'GitBranch' }],
},
```

Placed in `ADMIN_NAV` (`mockDashboard.ts`) directly after the `tsd` group. Add `'production'` and `'cable-routes'` to
`AdminModuleId`. In `adminRoutes.ts`: `'/admin/cable-routes': 'cable-routes'` in `ADMIN_ROUTE_MODULES`, `'cable-routes'` in
`ADMIN_ROUTABLE_MODULES`, `'cable-routes': '/admin/cable-routes'` in `ADMIN_MODULE_ROUTES`, and
`if (pathname.startsWith('/admin/cable-routes')) return 'cable-routes'` in `moduleIdFromPathname` (so every sub-page keeps the nav
entry active). In `adminNavVisibility.ts`: `'cable-routes': canOpenCableRoutes`. If `tsc` reports that `ModulePlaceholder`'s
exhaustive `MODULE_COPY` record needs the two new ids, add entries there. Existing tests to extend: `navigation/adminRoutes.test.ts`,
`navigation/filterAdminNav.test.ts`, `Sidebar.businessModules.test.tsx`.

### 6.3 Permission in the UI

`src/components/admin/cableRoutes/cableRoutesPermissions.ts`, same shape as `hrPermissions.ts`:

```ts
export const CABLE_ROUTES_PERMISSIONS = { READ: 'CABLE_ROUTES.READ' } as const
export function canOpenCableRoutes(hasPermission: (permission: string) => boolean): boolean {
  return hasPermission(CABLE_ROUTES_PERMISSIONS.READ)
}
```
UI constants only. It is not added to `ADMIN_BUSINESS_PERMISSION_CODES` (HR is not there either).

### 6.4 API client (`src/api/admin/cableRoutes.ts`)

Functions `getCableRoutesRun`, `listCableRoutesCodes`, `listCableRoutesItems(query)`, `getCableRoutesItem(item)`,
`listCableRoutesMachines`, `getCableRoutesMachine(id, query)`, `listCableRoutesChecks(query)`, each taking an optional
`AbortSignal`, built on `apiRequest<unknown>` with `URLSearchParams` for query strings, unwrapping `{success,data}`
with a local `requireSuccessData` (as `telDirectory.ts`), parsing defensively, and throwing `ApiError` unchanged. Export
`mapCableRoutesError(err)`: 403 gives "You do not have permission to view Cable routes."; code
`CABLE_ROUTES_STORE_UNAVAILABLE` gives "The cable routes data has not been built yet. Ask an administrator to run the build.";
`CABLE_ROUTES_ITEM_NOT_FOUND` gives "That cable is not in the cable routes data." Also export the types for every payload in
section 5 (the examples are the shapes), and `CABLE_ROUTES_ERROR_CODES`.

Rules from the plan that the UI must keep: no arithmetic other than "per-unit x length" (round for display only); quantities
are per unit and never converted between `m` and `kg`; the rod is `isRod` and shown first; label limit rows "poster limit, 2023";
write comparisons in words; "7 ends" shows only when `parallelEnds > 1`; the map treats `posterName` as the drawing key and
`machine` as the Dynamics name (`RB1` lights the poster's `RB2`).

## 7. Permission `CABLE_ROUTES.READ`

- **Constant**: `core/src/CableRoutes/Module.php` with `NAME = 'CableRoutes'`, `PERMISSION_READ = 'CABLE_ROUTES.READ'`,
  `PERMISSIONS = [self::PERMISSION_READ]`.
- **Seed**: `database/scripts/development/seed-cable-routes-permissions.sql`, copied from `seed-tel-directory-permissions.sql`:
  idempotent insert into `[identity].[Permissions]` (code `CABLE_ROUTES.READ`, name "Read Cable routes", description "See how
  each finished cable is made: its machine steps, materials and the factory map (read-only).", module code `cable_routes`),
  reactivate if inactive, then map it in `[identity].[RolePermissions]` to **`ADMINISTRATOR` and `EMPLOYEE`**. `EMPLOYEE` is the
  platform's internal-staff role ("internal staff, legacy is_staff parity" in the Tel Directory seed), and the narrower
  staff roles (HR, finance, NPR and so on) are additions on top of it, so granting `EMPLOYEE` reaches every internal staff
  member. The plan's D3 holds: no `GUEST`, no `CUSTOMER_*`. Add the guard that `seed-customer-contact-report-permissions.sql`
  uses: after the grants, `THROW` if any `GUEST` or `CUSTOMER_%` role holds the permission. Output the same summary row.
  It is a development seed ("review before production"); production apply is a separate manual step, as for the others.
- **API check**: every route has `->add(($require)(Module::PERMISSION_READ))` (section 9). No extra check in the actions.
- **Local development**: add `'CABLE_ROUTES.READ' => ['name' => 'Read Cable routes', 'module' => 'CABLE_ROUTES']` to
  `DevelopmentUserManagementCatalog::managementPermissions()`, so the in-memory dev user (and the in-memory ADMINISTRATOR
  role) holds it. Add the code to `expectedBusinessPermissionCodes()` in `tests/Identity/AdminBusinessModulePermissionsCatalogTest.php`.

## 8. Tests and acceptance

### 8.1 Python (pytest, `core/python/tests/cable_routes/`)

The worked example below exactly, including the copper check to plus or minus 0.0005; cutting-order exclusion; a zero
denominator counts as 1; a cycle in a synthetic BOM terminates; a shared sub-item's need is the sum over its parents; the
parser cases (`5C16BK`, `1C300BK`, `3C2.5`, a non-matching item); trace-status reasons; each check kind fires on a synthetic
case; the atomic swap leaves the old store when the build fails; missing file and missing column exit 3 with a message that
names them. A real-data test (skipped unless `CABLE_ROUTES_SOURCE_DIR` is set) asserts 4,497 finished items, 2,862 traced and
2,689 `to_rod`, and that FC, HT and R2 appear in `family_route_mismatch`.

### 8.2 PHP (phpunit and phpstan max)

- A helper `tests/CableRoutes/FixtureStore.php` creates a temp SQLite file by executing
  `core/python/cable_routes/schema.sql` and inserting the fixture below, and returns the path; tests pass it as
  `TestAppFactory::create(['CABLE_ROUTES_DB_PATH' => $path])`. The same helper can build a store that has no `cr_run` row, a
  wrong `user_version`, and a path that does not exist.
- Every endpoint against the fixture store, including the `item` response for `KA9X-PASN/5C16BK` (15 nodes, 10 steps, the
  rod first in `materials`), `items` facets, `machine` for `EX6` and for `RB1` found by `RB2`, `machine?id=EX8` giving 404, and
  `checks` counts for all seven kinds.
- 401 `UNAUTHENTICATED` without auth (`DEV_AUTH_ENABLED=false`); 403 `INSUFFICIENT_PERMISSION` with only `ACCOUNT.LOGIN`; 422
  `CABLE_ROUTES_ITEM_REQUIRED` on a missing `item`; 404 `CABLE_ROUTES_ITEM_NOT_FOUND` on an unknown item; 503
  `CABLE_ROUTES_STORE_UNAVAILABLE` when the store is missing; 422 on a bad `kind`, `page`, `pageSize`, `traceStatus`.
- The fixture's values come from plan section 4 and the prototype's `cable-routes.json`: the 15 made items of the worked
  example (the nine-line chain, the four 1C16 cores `RE`, `WH`, `BL`, `BK`, the earth core `LM16X-XXXN/1C6EA`, `NX-1C6`,
  `NX-7W.3`), with the pools, diameters, kg/m, orders and machines of the prototype. Anything the plan does not print
  (for example `NX-1C6`'s own inputs) is synthetic and marked `-- fixture only` in the helper.

The fixture's facts, for all three builders (diameter mm, kg/m, order, status, date, real orders, machine, operation):

| Item | Pool | Ø | kg/m | Order | Status | Date | Orders | Op | Machine |
|---|---|---|---|---|---|---|---|---|---|
| KA9X-PASN/5C16BK | 50_FMAN | 27.85 | 1.678 | 151107M | Completed | 2023-12-18 | 1 | SHEATHING | EX4 |
| WAKA9X-PASN/5C16 | 40_SFG | 24.62 | 1.41547 | W0037316 | Completed | 2026-02-27 | 2 | ARMOURING | LU3 |
| BEKA9X-PASN/5C16OR | 40_SFG | 20.38 | 0.77711 | W0037315 | Completed | 2026-02-25 | 2 | BEDDING | EX3 |
| LUKA9X-PASN/5C16 | 40_SFG | 18.34 | 0.68552 | W0037314 | Completed | 2026-02-18 | 2 | LAYUP | LU9 |
| LM1X-XXXN/1C16RE | 35_WIP | 6.72 | 0.15193 | W0037194 | Scheduled | 2026-09-04 | 11 | INSULATION | EX6 |
| LM1X-XXXN/1C16WH | 35_WIP | 6.72 | 0.15194 | W0048787 | Scheduled | 2026-09-04 | 15 | INSULATION | EX6 |
| LM1X-XXXN/1C16BL | 35_WIP | 6.72 | 0.15191 | W0046227 | StartedUp | 2026-09-04 | 41 | INSULATION | EX6 |
| LM1X-XXXN/1C16BK | 35_WIP | 6.72 | 0.15193 | W0046228 | StartedUp | 2026-09-04 | 39 | INSULATION | EX6 |
| LM16X-XXXN/1C6EA | 35_WIP | 4.61 | 0.06322 | W0043404 | Released | 2026-09-04 | 26 | INSULATION | EX6 |
| NX-1C16 | 25_FCON | 5.18 | 0.1361 | W0049406 | Released | 2026-09-04 | 391 | BUNCHING | BU1 |
| NX-1C6 | 25_FCON | 3.17 | 0.05124 | W0048517 | Released | 2026-09-04 | 38 | BUNCHING | BU2 |
| NX-32W.3 | 20_BUN | 1.96 | 0.01937 | W0050075 | ReportedFinished | 2026-09-04 | 393 | BUNCHING | BU6 |
| NX-8W.3 | 15_WIR | 0.98 | 0.00484 | W0047291 | Released | 2026-09-04 | 929 | WIRE_DRAW | WD4 |
| NX-7W.3 | 15_WIR | 0.92 | 0.00424 | W0038782 | Released | 2026-09-04 | 322 | WIRE_DRAW | WD4 |
| NX-1W1.6 | 15_WIR | 1.6 | 0.01804 | W0049517 | Released | 2026-09-04 | 473 | ROD_BREAK | RB1 |

### 8.3 Web (vitest, beside the code)

The pickers cascade (Code, then Construction, then Item; Construction options come from `facets`); the length box rescales
quantities and nothing else changes; the tree renders the fixture (15 cards, one per item, the four cores feeding the
lay-up card, "7 ends" on the NX-32W.3 connector); the map lights the right machines in step order, dimmed elsewhere
(`RB1` lights the poster's `RB2`; EX7 and EX8 are not drawn); the shell shows "Dynamics data as of"; a 403 and a 503 render
the friendly messages; the nav entry appears only with `CABLE_ROUTES.READ`. API client tests mock `fetch`/`apiRequest`
like `telDirectory.test.ts`.

### 8.4 Acceptance fixture and numbers (plan sections 4 and 8, verbatim)

`KA9X-PASN/5C16BK` (KA 5-core 16 mm², black sheath). Read from the finished cable downwards:

```
KA9X-PASN/5C16BK      50_FMAN  SHEATHING   EX4   ← WAKA9X-PASN/5C16 1 m/m; RS0002 0.21541 kg/m; MB_BLACK 0.0057 kg/m
WAKA9X-PASN/5C16      40_SFG   ARMOURING   LU3   ← BEKA9X-PASN/5C16OR 1; GSWA_1.60MM 0.61207 kg; TAPE_FPP130-40MM 0.01314 kg
BEKA9X-PASN/5C16OR    40_SFG   BEDDING     EX3   ← LUKA9X-PASN/5C16 1; RS0002 0.08933 kg; MB_ORANGE 0.00225 kg
LUKA9X-PASN/5C16      40_SFG   LAYUP       LU9   ← LM1X-XXXN/1C16{RE,WH,BL,BK} 1.0099 m each; LM16X-XXXN/1C6EA 1.0139 m; fillers; TAPE_PP50-40MM
LM1X-XXXN/1C16RE      35_WIP   INSULATION  EX6   ← NX-1C16 1 m; RI0001, RI0002, RC0001, RC0002, MB_RED, TAPE_NW50_20MM
NX-1C16               25_FCON  BUNCHING    BU1   ← NX-32W.3 7.02611 m (7 ends)
NX-32W.3              20_BUN   BUNCHING    BU6   ← NX-8W.3 4.00232 m
NX-8W.3               15_WIR   WIRE_DRAW   WD4   ← NX-1W1.6 0.26829 m
NX-1W1.6              15_WIR   ROD_BREAK   RB1   ← NX-1R8-NEX 0.01804 kg   (8 mm rod, purchased)
```

The cores are not printed: the route goes EX6 → LU9, which is the poster's "cores without print (multi-core power)"
line. **Copper check:** 7.02611 × 4.00232 × 0.26829 × 0.01804 = **0.1361 kg of rod per metre of NX-1C16**, which
matches `NetProductWeight` 0.13610 for NX-1C16. That makes it an exact test that the quantity explosion is right.

Data facts the real-data run must reproduce:

- **Finished cable** = an item in pool `50_FMAN` or `60_CAT`. 4,497 of them.
- **Traceable** = it has at least one *real* production order (`CsIsCuttingOrder` ≠ `Yes` and some BOM line consumes an item
  other than the order's own item). **2,862 are traceable.** The other 1,635 have no orders, or only cutting orders.
  **2,689** of the 2,862 trace all the way back to `ROD_BREAK`; most of the other 173 should end at purchased conductor
  (`N1-*`, aluminium), and the engine says which reason applies to each one.
- **6,373 made items** sit in all the chains together. The prototype's JSON is 2 MB.
- The plan cites 965 backward BOM edges, but that is a count over all orders by pool stage. The graph of representative orders that the engine walks has none, so `cycle_edges_cut` is 0 on this extract. Every walk still tracks a visited set, and the tests cover cycles.
- `CsPrdParallelLines` is already included in `BOMLineQuantity`; do not multiply by it again. Show "7 ends" for information only.

In the browser, before calling it done (plan section 8): open KA9X-PASN/5C16BK at 1,000 m. It should show 10 steps, EX6 →
LU9 → EX3 → LU3 → EX4, and roughly 136 kg of rod per km for each 16 mm² conductor. Open an R2 item and see the
armouring check. Open a braided XU item and see BR2 on the map.

## 9. Integration points (the exact existing files each builder touches)

Everything not listed here is a new file under the paths in section 1. Edits to existing files are small and additive;
keep them in the order below so parallel builders do not collide (one owner per file).

**Engine builder**
- `core/python/pyproject.toml`: add `"cable_routes"` to `packages`; add `cable_routes = ["schema.sql", "seed/*.csv"]` to `package-data`.
- `.gitignore`: add `/var/cable-routes/`.
- `.env.example`: a commented block documenting `CABLE_ROUTES_DB_PATH` and `CABLE_ROUTES_SOURCE_DIR`.
- `composer.json` (optional convenience): script `"cable-routes-build": "cd core/python && python3 -m cable_routes build"` with a
  description under `scripts-descriptions`.
- `core/python/README.md`: a short "cable_routes" section (what it is, the two env vars, `build`).
- Seed copies in `core/python/cable_routes/seed/` come from `planning/workflow/` (read, copy, never move).

**API builder** (all in `tricab-api`)
- `src/Bootstrap/AppBuilder.php`: add `private static function registerCableRoutesRoutes(App $app, Container $container): void`
  next to `registerTelDirectoryRoutes` (about line 1724), and call `self::registerCableRoutesRoutes($app, $container);` in the
  list after `self::registerCableFitRoutes($app, $container);` (about line 447). Seven `$app->get('/api/v1/cable-routes/<run|codes|items|item|machines|machine|checks>', ...Action::class)->add(($require)(\TriCab\Core\CableRoutes\Module::PERMISSION_READ))`.
- `src/Bootstrap/ContainerFactory.php`: a block beside the Tel Directory definitions (about line 3070 to 3085):
  `CableRoutesRepository::class => static fn (): SqliteCableRoutesRepository => SqliteCableRoutesRepository::fromSettings($settings)`
  (lazy: it opens nothing until a request), `CableRoutesQueryService::class => \DI\autowire()`, `CableRoutesErrorMapper::class =>
  \DI\autowire()`, and `\DI\autowire()` for the seven actions.
- `src/Config/Settings.php`: constructor property `public string $cableRoutesDbPath = 'var/cable-routes/cable-routes.sqlite'` and the
  `cableRoutesDbPath: trim(self::string($env, 'CABLE_ROUTES_DB_PATH', 'var/cable-routes/cable-routes.sqlite')) ?: 'var/cable-routes/cable-routes.sqlite'`
  line in `fromEnvironment()`.
- `src/Identity/Persistence/DevelopmentUserManagementCatalog.php`: the `CABLE_ROUTES.READ` entry (section 7).
- `tests/Identity/AdminBusinessModulePermissionsCatalogTest.php`: add the code to `expectedBusinessPermissionCodes()`.
- `database/scripts/development/seed-cable-routes-permissions.sql`: new (section 7).
- `.env.example`: with the engine builder's block (one owner: the engine builder writes it; the API builder reviews).
- `phpstan-baseline.neon`, `phpstan-core-baseline.neon`: **do not touch**; new code is clean at level max.

**Web builder** (all in `tricab-web`)
- `src/App.tsx`: three lazy imports near lines 217 to 220 (`CableRoutesShell`, and the seven pages), and one nested route block
  after the `tel-directory` route (about line 841 to 848): `<Route path="cable-routes" element={<Suspense ...><CableRoutesShell /></Suspense>}>`
  with `index`, `cable`, `map`, `machines`, `machine`, `checks`, `ranges` children, each in `Suspense` with `AdminSectionFallback`.
- `src/components/admin/types/admin.ts`: add `'production'` and `'cable-routes'` to `AdminModuleId`.
- `src/components/admin/data/mockDashboard.ts`: the Production group in `ADMIN_NAV` after the `tsd` group.
- `src/components/admin/navigation/adminRoutes.ts`: the three maps and the `moduleIdFromPathname` prefix line (section 6.2).
- `src/components/admin/permissions/adminNavVisibility.ts`: import `canOpenCableRoutes` and add `'cable-routes': canOpenCableRoutes`.
- `src/components/admin/ModulePlaceholder.tsx`: only if `tsc` demands the two new ids.
- Tests extended: `src/components/admin/navigation/adminRoutes.test.ts`, `navigation/filterAdminNav.test.ts`, `Sidebar.businessModules.test.tsx`.
- `package.json`: no new dependency (SVG map and tree are hand-built, as the conductor Gantt was).

## 10. Decisions where the plan is silent

1. **Envelope**: platform `{success,data}` / `{success:false,error}` rather than conductor's bare JSON (section 5.1). Reason: it is the only
   response shape the platform's `JsonResponder` produces and the web client's `requireSuccessData` requires; bare JSON would need a
   second responder and a second client convention.
2. **401 and 403 codes** are the platform's `UNAUTHENTICATED` and `INSUFFICIENT_PERMISSION`, not `CABLE_ROUTES_*`, because the shared
   middleware produces them.
3. **File locations** follow the platform (`src/api/admin/`, `src/components/admin/cableRoutes/`), not the plan's `src/pages/`.
4. **Source dir has no default**; the DB default is `var/cable-routes/cable-routes.sqlite` inside the API root, gitignored (section 4.2).
   The engine also reads `<API root>/.env` for the two keys so dev needs no shell exports.
5. **`GET codes` carries the range rows**, so the Product ranges page needs no extra endpoint; **`GET items` carries `facets`**, so the
   pickers need no extra endpoint.
6. **`GET item` is one payload** (nodes, edges, steps, materials, checks) feeding both the Cable page and the map, with the machine
   objects carrying the poster name so the browser holds no alias table.
7. **Steps** are the distinct `(operation, machine)` pairs ordered by the deepest node running them; this reproduces the prototype's 10 steps for
   the fixture.
8. **Machine key** is the D365 id (poster name for poster-only machines); `machine?id=` also accepts the poster name.
9. **Retired machines (EX7, EX8)** are kept in `cr_machine` with `hidden = 1`, never listed or drawn, 404 on `machine?id=`, but still named in
   `routed_to_retired_machine` rows and in item-page step chips (grey).
10. **`to_rod` means "the tree contains a ROD_BREAK operation"**; flagged for the owner in 4.5 in case "every conductor branch" was meant.
11. **Conductor prefixes** `NX, DX, DM, MNX, N1, N2, AL` as one constant; `AL` is the poster's "AL (purchased)" row.
12. **Family-route check** is per code at a 50% threshold, skips codes shared with an empty-expectation family (XL, KL, CC, FR, EZ), and maps
    `NYLON` to `SHEATHING`.
13. **`outside_poster_limit`** compares the largest made input of the item running on the machine, both for the upper and lower limit; rows are per
    (made item, machine), not per finished item, so nothing is duplicated across the thousands of finished items that share a sub-item.
14. **Cycle handling**: depth-first with the current path as the visited set; cut edges are counted in `cr_run.cycle_edges_cut`, not shown as checks (they would be noise if
    there were many; on the 2026-09-04 extract there are none) and not served in `edges`.
15. **Data as of** ignores the `1900-01-01` placeholder. `cr_run.source_name` is the folder's basename so no home path is stored or served.
16. **Schema extras** beyond the plan's sketch: `cr_code`, `cr_family_code`, `cr_flow_edge`, `cr_machine_limit`, `cr_machine_finished`, `cr_check` columns
    `operation`, `affected`, `total`, `cr_finished.reason_items`, `cr_need.is_rod`, `cr_material.conductor`.
17. **Not in v1**: serving `cr_flow_edge`, a store in SQL Server (plan D4), a scheduled refresh (plan D1), shipping the store in the release zip, and a
    module on/off switch in `PlatformSettings`.
18. **Grouping by code** for `stops_early` and `untraceable` (per code and reason, with `affected` and `total`); the page links each row to
    `items?code=&traceStatus=`.
19. **Paging defaults**: 50 (max 200) for items, 100 (max 500) for checks, same field names as HR employees.
20. **PHP tests build their store from `schema.sql`** rather than committing a binary, so there is one definition of the schema and PHP breaks if it drifts.
