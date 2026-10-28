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
