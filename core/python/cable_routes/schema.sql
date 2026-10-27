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
