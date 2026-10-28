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
