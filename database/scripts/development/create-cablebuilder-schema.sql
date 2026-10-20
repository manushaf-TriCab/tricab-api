/*
================================================================================
  [cablebuilder] schema - CableBuilder designs, loaded from the Blob archive.

  Source: the `cablebuilder` blob container kept by the nightly sync
  (cablebuilder-sync/README.md). The container is the system of record: one
  blob per distinct design body, never rewritten. These tables are a queryable
  copy of it and can be rebuilt from it at any time, so nothing here is the only
  copy of anything.

  Lives in `tc` (decided 2026-10-20) so the product tables can be populated from
  CableBuilder in SQL: the catalogue loads already map FINCAT designs to
  product.ProductVariants and styles to product.Products, from a CSV snapshot.
  Sized for that: about 0.45 GB for the 40,331 designs of the August pull, and
  roughly flat over time, because only the current body of a design keeps its
  attributes_json (see below).

  Shape:

    design_versions   one row per distinct body of a design (code, alternative,
                      version). A changed design adds a row; is_current marks
                      the latest body. About 40 promoted columns, chosen by
                      profiling the August 2026 pull (40,331 designs): the
                      identity, D365 mapping, units, lengths, weights and status
                      fields planning reads. Everything else that CableBuilder
                      returned (header and plantHeader, about 470 fields, 231
                      always empty) is kept in attributes_json, non-empty values
                      only, so promoting another column later is an ALTER and a
                      backfill from that JSON, not a re-pull. When a newer body
                      arrives, the superseded row's attributes_json is set to
                      NULL: history keeps its typed columns and blob_path, and
                      the full document stays in Blob.
    layers            the construction layers of a design version
    operations        one operation per layer: machine, speed, run and set-up
                      time, ERP scrap
    resources         resource rows of an operation, carrying the D365 route
                      mapping (resource, group, cost categories, minutes)
    bom_lines         BOM lines of a layer: quantity per series (1,000 m), unit,
                      ERP consumption/reservation/warehouse. A line in metres
                      names a sub-design; a line in kilograms a material
    lookup_table_versions / lookup_rows
                      the 186 lookup tables, one JSON array per grid row
    sync_runs         one row per nightly run, from the run records in Blob

  Units are as CableBuilder stores them and are named in the column: quantities
  are per bom_lines.qty_series (1,000 in 99.7% of lines), run time is hours per
  1,000 m, set-up time is hours, erp_* times are minutes, speed is m/min.
  Material scrap (bom_lines.scrap_pct) is NOT included in qty, matching the
  source; consumers apply it.

  layers, operations, resources and bom_lines have typed columns only. Their
  remaining fields (about 450 MB as JSON) are construction detail that neither
  planning nor the product tables read; they stay in the Blob documents.

  Values arrive as text. The loader puts a value in a typed column only when it
  parses; for a design, the original stays in attributes_json.

  Conventions as the other create-*-schema.sql files: BIGINT IDENTITY keys,
  DATETIME2(7) UTC, ISJSON checks on JSON columns, every object guarded so the
  file is idempotent. No dependency on other schemas.
================================================================================
*/

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'cablebuilder')
BEGIN
    EXEC(N'CREATE SCHEMA [cablebuilder]');
END
GO

-- ---------------------------------------------------------------- design_versions

IF OBJECT_ID(N'[cablebuilder].[design_versions]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablebuilder].[design_versions] (
        [design_version_id]       BIGINT IDENTITY(1,1) NOT NULL,
        -- identity: CableBuilder's (design, designAlternative, version) plus the body hash
        [design_code]             NVARCHAR(64) NOT NULL,
        [design_alternative]      INT NOT NULL,
        [design_version]          INT NOT NULL,
        [body_sha256]             CHAR(64) NOT NULL,
        [document_kind]           VARCHAR(6) NOT NULL,   -- full | header (getDesign failed)
        [blob_path]               NVARCHAR(400) NOT NULL,
        [first_seen_at]           DATETIME2(7) NOT NULL,
        [last_checked_at]         DATETIME2(7) NOT NULL,
        [is_current]              BIT NOT NULL
            CONSTRAINT [DF_cablebuilder_design_versions_is_current] DEFAULT (1),
        [superseded_at]           DATETIME2(7) NULL,
        -- what the cable is
        [standard]                NVARCHAR(64) NULL,
        [specification]           NVARCHAR(64) NULL,
        [item_type]               NVARCHAR(32) NULL,
        [item_nature]             NVARCHAR(32) NULL,
        [material_type]           NVARCHAR(100) NULL,
        [voltage]                 NVARCHAR(32) NULL,
        [colour]                  NVARCHAR(64) NULL,
        [description]             NVARCHAR(400) NULL,
        [outer_diameter_mm]       DECIMAL(18,6) NULL,    -- wanted by the product tables (variant specs)
        [weight_kg_per_km]        DECIMAL(18,6) NULL,
        [cu_weight_kg_per_km]     DECIMAL(18,6) NULL,
        [al_weight_kg_per_km]     DECIMAL(18,6) NULL,
        -- D365 mapping (plantHeader erp_*); erp_item_id is the D365 item number
        [erp_item_id]             NVARCHAR(64) NULL,
        [erp_item_group]          NVARCHAR(16) NULL,     -- SFG | FINCAT | FINMAN | raw-material groups
        [erp_item_template]       NVARCHAR(32) NULL,     -- determines the D365 pool
        [erp_category_name]       NVARCHAR(64) NULL,
        [erp_bom_id]              NVARCHAR(64) NULL,     -- <item>-A<alt>V<ver>
        [erp_route_id]            NVARCHAR(64) NULL,
        [erp_bom_id_bc]           NVARCHAR(64) NULL,     -- second site (BC) variant
        [erp_route_id_bc]         NVARCHAR(64) NULL,
        [erp_site_bc]             NVARCHAR(8) NULL,
        [erp_unit]                NVARCHAR(8) NULL,      -- m | ea | kg
        [erp_from_unit]           NVARCHAR(8) NULL,      -- km
        [erp_to_unit]             NVARCHAR(8) NULL,      -- kg
        [erp_from_unit_factor]    DECIMAL(18,6) NULL,    -- kg per km
        -- order lengths, metres
        [calc_min_length_m]       DECIMAL(18,3) NULL,
        [calc_max_length_m]       DECIMAL(18,3) NULL,
        [std_order_length_m]      DECIMAL(18,3) NULL,
        -- CableBuilder status. Note: in the August pull every design was Draft,
        -- 99.5% Unconfirmed and 47% out of date, so none of these is yet a
        -- release signal.
        [status]                  NVARCHAR(32) NULL,
        [approval_stage]          NVARCHAR(16) NULL,
        [approved]                BIT NULL,
        [out_of_date]             BIT NULL,
        [site_validation]         NVARCHAR(16) NULL,
        [cb_last_update]          DATE NULL,
        [cb_last_update_by]       NVARCHAR(64) NULL,
        [validation_errors]       NVARCHAR(MAX) NULL,
        -- every non-empty header and plantHeader field:
        -- {"header": {...}, "plantHeader": {...}}; NULL once superseded
        [attributes_json]         NVARCHAR(MAX) NULL,
        [loaded_at]               DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cablebuilder_design_versions_loaded_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_cablebuilder_design_versions] PRIMARY KEY CLUSTERED ([design_version_id]),
        CONSTRAINT [UQ_cablebuilder_design_versions_body]
            UNIQUE ([design_code], [design_alternative], [design_version], [body_sha256]),
        CONSTRAINT [CK_cablebuilder_design_versions_kind]
            CHECK ([document_kind] IN ('full', 'header')),
        CONSTRAINT [CK_cablebuilder_design_versions_attributes_json]
            CHECK ([attributes_json] IS NULL OR ISJSON([attributes_json]) = 1)
    );
END
GO

-- At most one current body per (code, alternative, version).
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_cablebuilder_design_versions_current'
               AND object_id = OBJECT_ID(N'[cablebuilder].[design_versions]'))
BEGIN
    CREATE UNIQUE INDEX [UX_cablebuilder_design_versions_current]
        ON [cablebuilder].[design_versions] ([design_code], [design_alternative], [design_version])
        WHERE [is_current] = 1;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablebuilder_design_versions_erp_item'
               AND object_id = OBJECT_ID(N'[cablebuilder].[design_versions]'))
BEGIN
    CREATE INDEX [IX_cablebuilder_design_versions_erp_item]
        ON [cablebuilder].[design_versions] ([erp_item_id])
        INCLUDE ([design_code], [design_alternative], [design_version])
        WHERE [is_current] = 1;
END
GO

-- ---------------------------------------------------------------- layers

IF OBJECT_ID(N'[cablebuilder].[layers]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablebuilder].[layers] (
        [design_version_id]       BIGINT NOT NULL,
        [layer_seq]               INT NOT NULL,          -- order in the document
        [layer_no]                INT NULL,              -- CableBuilder's layerno
        [process]                 NVARCHAR(32) NULL,
        [process_detail]          NVARCHAR(64) NULL,     -- the second <process> element
        [machine_allocated]       NVARCHAR(32) NULL,
        [nom_diam_mm]             DECIMAL(18,6) NULL,
        [nom_weight_kg_per_km]    DECIMAL(18,6) NULL,
        [fixed_scrap_factor]      DECIMAL(18,6) NULL,    -- lay take-up, e.g. 1.0027
        [lay_length_mm]           DECIMAL(18,6) NULL,    -- NULL where CableBuilder holds Java's
                                                         -- Long.MAX_VALUE, its "no lay" placeholder
        CONSTRAINT [PK_cablebuilder_layers] PRIMARY KEY CLUSTERED ([design_version_id], [layer_seq]),
        CONSTRAINT [FK_cablebuilder_layers_design_version]
            FOREIGN KEY ([design_version_id])
            REFERENCES [cablebuilder].[design_versions]([design_version_id]) ON DELETE CASCADE
    );
END
GO

-- ---------------------------------------------------------------- operations

IF OBJECT_ID(N'[cablebuilder].[operations]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablebuilder].[operations] (
        [design_version_id]       BIGINT NOT NULL,
        [layer_seq]               INT NOT NULL,
        [op_index]                INT NOT NULL,
        [operation_seq]           INT NULL,              -- 5, 10, 15 ... route sequence
        [task]                    NVARCHAR(32) NULL,     -- SHEATHING, LAYUP, ...
        [task_description]        NVARCHAR(64) NULL,
        [machine]                 NVARCHAR(16) NULL,
        [machine_new]             NVARCHAR(16) NULL,
        [workcentre]              NVARCHAR(32) NULL,     -- 28% populated; prefer the Machine Table
        [speed_m_per_min]         DECIMAL(18,6) NULL,
        [run_time_h_per_km]       DECIMAL(18,6) NULL,    -- = 1000 / (60 * speed)
        [setup_time_h]            DECIMAL(18,6) NULL,
        [efficiency_pct]          DECIMAL(9,3) NULL,     -- feeds cost, not run time
        [run_occupation]          DECIMAL(9,3) NULL,
        [setup_occupation]        DECIMAL(9,3) NULL,
        [erp_operation_next]      INT NULL,
        [erp_excess_scrap_m]      DECIMAL(18,3) NULL,
        [erp_start_scrap_m]       DECIMAL(18,3) NULL,
        [erp_mid_scrap_m]         DECIMAL(18,3) NULL,
        [erp_end_scrap_m]         DECIMAL(18,3) NULL,
        CONSTRAINT [PK_cablebuilder_operations]
            PRIMARY KEY CLUSTERED ([design_version_id], [layer_seq], [op_index]),
        CONSTRAINT [FK_cablebuilder_operations_layer]
            FOREIGN KEY ([design_version_id], [layer_seq])
            REFERENCES [cablebuilder].[layers]([design_version_id], [layer_seq]) ON DELETE CASCADE
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablebuilder_operations_machine'
               AND object_id = OBJECT_ID(N'[cablebuilder].[operations]'))
BEGIN
    CREATE INDEX [IX_cablebuilder_operations_machine]
        ON [cablebuilder].[operations] ([machine], [task]);
END
GO

-- ---------------------------------------------------------------- resources

IF OBJECT_ID(N'[cablebuilder].[resources]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablebuilder].[resources] (
        [design_version_id]       BIGINT NOT NULL,
        [layer_seq]               INT NOT NULL,
        [op_index]                INT NOT NULL,
        [res_index]               INT NOT NULL,
        [resource]                NVARCHAR(32) NULL,
        [machine]                 NVARCHAR(16) NULL,
        [speed_m_per_min]         DECIMAL(18,6) NULL,
        [run_time_h_per_km]       DECIMAL(18,6) NULL,
        [setup_time_h]            DECIMAL(18,6) NULL,
        [setup_time_h_per_km]     DECIMAL(18,6) NULL,    -- = setup_time / (lot_size / 1000)
        [lot_size_m]              DECIMAL(18,3) NULL,
        [excess_qty_m]            DECIMAL(18,3) NULL,
        [start_scrap_m]           DECIMAL(18,3) NULL,
        [mid_scrap_m]             DECIMAL(18,3) NULL,
        [end_scrap_m]             DECIMAL(18,3) NULL,
        [drum_change_length_m]    DECIMAL(18,3) NULL,
        [extruder_output_lookup]  NVARCHAR(64) NULL,     -- e.g. "Metric Extruder Output HFFR"
        [extruder_output_kg_per_h] DECIMAL(18,3) NULL,
        -- D365 route mapping
        [erp_resource]            NVARCHAR(16) NULL,
        [erp_resource_group]      NVARCHAR(16) NULL,     -- EXT_LG, LAYUP, BRAID, ...
        [erp_route_group]         NVARCHAR(16) NULL,
        [erp_route_id]            NVARCHAR(64) NULL,
        [erp_route_name]          NVARCHAR(100) NULL,
        [erp_process_category_id] NVARCHAR(16) NULL,     -- e.g. 540EX5RUN
        [erp_setup_category_id]   NVARCHAR(16) NULL,     -- e.g. 540EX5SET
        [erp_run_time_min]        DECIMAL(18,6) NULL,
        [erp_setup_time_min]      DECIMAL(18,6) NULL,
        CONSTRAINT [PK_cablebuilder_resources]
            PRIMARY KEY CLUSTERED ([design_version_id], [layer_seq], [op_index], [res_index]),
        CONSTRAINT [FK_cablebuilder_resources_operation]
            FOREIGN KEY ([design_version_id], [layer_seq], [op_index])
            REFERENCES [cablebuilder].[operations]([design_version_id], [layer_seq], [op_index])
            ON DELETE CASCADE
    );
END
GO

-- ---------------------------------------------------------------- bom_lines

IF OBJECT_ID(N'[cablebuilder].[bom_lines]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablebuilder].[bom_lines] (
        [design_version_id]       BIGINT NOT NULL,
        [layer_seq]               INT NOT NULL,
        [bom_index]               INT NOT NULL,
        [item_code]               NVARCHAR(64) NULL,     -- a design code (unit m) or a material (kg);
                                                         -- NULL on 1 line of 115,120 in the August pull
        [item_alternative]        INT NULL,
        [description]             NVARCHAR(400) NULL,
        [qty]                     DECIMAL(28,10) NULL,   -- bomqty_erp, per qty_series of the parent
        [qty_series]              DECIMAL(18,4) NULL,    -- bomqtyserie_erp, normally 1000
        [unit]                    NVARCHAR(8) NULL,      -- bomunit_erp: m | kg | ea
        [net_weight]              DECIMAL(28,10) NULL,
        [scrap_pct]               DECIMAL(18,6) NULL,    -- bomscrapbqpu, NOT included in qty
        [erp_consumption]         NVARCHAR(32) NULL,     -- FlushingPrincip | Always | Allocation
        [erp_reservation]         NVARCHAR(32) NULL,
        [erp_position]            NVARCHAR(16) NULL,     -- a position code such as 0.510, not a number
        [erp_warehouse]           NVARCHAR(16) NULL,
        [erp_warehouse_bc]        NVARCHAR(16) NULL,
        [erp_bom_type]            NVARCHAR(16) NULL,
        CONSTRAINT [PK_cablebuilder_bom_lines]
            PRIMARY KEY CLUSTERED ([design_version_id], [layer_seq], [bom_index]),
        CONSTRAINT [FK_cablebuilder_bom_lines_layer]
            FOREIGN KEY ([design_version_id], [layer_seq])
            REFERENCES [cablebuilder].[layers]([design_version_id], [layer_seq]) ON DELETE CASCADE
    );
END
GO

-- Where-used: which designs consume an item.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablebuilder_bom_lines_item'
               AND object_id = OBJECT_ID(N'[cablebuilder].[bom_lines]'))
BEGIN
    CREATE INDEX [IX_cablebuilder_bom_lines_item]
        ON [cablebuilder].[bom_lines] ([item_code], [item_alternative])
        INCLUDE ([qty], [qty_series], [unit]);
END
GO

-- ---------------------------------------------------------------- lookup tables

IF OBJECT_ID(N'[cablebuilder].[lookup_table_versions]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablebuilder].[lookup_table_versions] (
        [lookup_table_version_id] BIGINT IDENTITY(1,1) NOT NULL,
        [tablecode]               INT NOT NULL,          -- pin this: descriptions repeat
        [description]             NVARCHAR(200) NULL,
        [standard]                NVARCHAR(64) NULL,
        [plant]                   NVARCHAR(16) NULL,
        [body_sha256]             CHAR(64) NOT NULL,
        [blob_path]               NVARCHAR(400) NOT NULL,
        [row_count]               INT NOT NULL,
        [col_count]               INT NOT NULL,
        [first_seen_at]           DATETIME2(7) NOT NULL,
        [last_checked_at]         DATETIME2(7) NOT NULL,
        [is_current]              BIT NOT NULL
            CONSTRAINT [DF_cablebuilder_lookup_table_versions_is_current] DEFAULT (1),
        [loaded_at]               DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cablebuilder_lookup_table_versions_loaded_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_cablebuilder_lookup_table_versions] PRIMARY KEY CLUSTERED ([lookup_table_version_id]),
        CONSTRAINT [UQ_cablebuilder_lookup_table_versions_body] UNIQUE ([tablecode], [body_sha256])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_cablebuilder_lookup_table_versions_current'
               AND object_id = OBJECT_ID(N'[cablebuilder].[lookup_table_versions]'))
BEGIN
    CREATE UNIQUE INDEX [UX_cablebuilder_lookup_table_versions_current]
        ON [cablebuilder].[lookup_table_versions] ([tablecode])
        WHERE [is_current] = 1;
END
GO

-- One grid row per row, cells as a JSON array of strings. Header rows are kept
-- (row 0 labels, and in some tables row 1 units): promoting them is the
-- consumer's job, as in the CSV extract.
IF OBJECT_ID(N'[cablebuilder].[lookup_rows]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablebuilder].[lookup_rows] (
        [lookup_table_version_id] BIGINT NOT NULL,
        [row_no]                  INT NOT NULL,
        [cells_json]              NVARCHAR(MAX) NOT NULL,
        CONSTRAINT [PK_cablebuilder_lookup_rows] PRIMARY KEY CLUSTERED ([lookup_table_version_id], [row_no]),
        CONSTRAINT [FK_cablebuilder_lookup_rows_table]
            FOREIGN KEY ([lookup_table_version_id])
            REFERENCES [cablebuilder].[lookup_table_versions]([lookup_table_version_id]) ON DELETE CASCADE,
        CONSTRAINT [CK_cablebuilder_lookup_rows_cells_json] CHECK (ISJSON([cells_json]) = 1)
    );
END
GO

-- ---------------------------------------------------------------- sync_runs

IF OBJECT_ID(N'[cablebuilder].[sync_runs]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablebuilder].[sync_runs] (
        [sync_run_id]             BIGINT IDENTITY(1,1) NOT NULL,
        [command]                 NVARCHAR(32) NOT NULL,
        [started_at]              DATETIME2(7) NOT NULL,
        [seconds]                 DECIMAL(18,1) NULL,
        [run_record_blob]         NVARCHAR(400) NOT NULL,
        [designs_new]             INT NULL,
        [designs_changed]         INT NULL,
        [designs_failed]          INT NULL,
        [summary_json]            NVARCHAR(MAX) NOT NULL,
        CONSTRAINT [PK_cablebuilder_sync_runs] PRIMARY KEY CLUSTERED ([sync_run_id]),
        CONSTRAINT [UQ_cablebuilder_sync_runs_blob] UNIQUE ([run_record_blob]),
        CONSTRAINT [CK_cablebuilder_sync_runs_summary_json] CHECK (ISJSON([summary_json]) = 1)
    );
END
GO

-- ---------------------------------------------------------------- views

-- The latest version of each design alternative, current body only.
CREATE OR ALTER VIEW [cablebuilder].[current_designs] AS
SELECT dv.*
FROM [cablebuilder].[design_versions] AS dv
WHERE dv.[is_current] = 1
  AND dv.[design_version] = (
        SELECT MAX(x.[design_version])
        FROM [cablebuilder].[design_versions] AS x
        WHERE x.[design_code] = dv.[design_code]
          AND x.[design_alternative] = dv.[design_alternative]
          AND x.[is_current] = 1);
GO

-- BOM lines of current designs, with the per-metre quantity worked out.
--   item_is_design  the item has a current design record. About 219 purchased
--                   materials (compounds, copper rod, masterbatch) have one too,
--                   with no BOM, so this alone does not mean "has components".
--   item_has_bom    the item's current design has BOM lines: the explosion
--                   recurses on this, and a line without it is a leaf.
CREATE OR ALTER VIEW [cablebuilder].[current_bom_lines] AS
SELECT d.[design_code], d.[design_alternative], d.[design_version],
       b.[layer_seq], b.[bom_index], b.[item_code], b.[item_alternative], b.[unit],
       b.[qty], b.[qty_series],
       CASE WHEN b.[qty_series] > 0 THEN b.[qty] / b.[qty_series] END AS [qty_per_parent_unit],
       b.[scrap_pct], b.[erp_consumption], b.[erp_warehouse],
       CASE WHEN sub.[design_version_id] IS NULL THEN 0 ELSE 1 END AS [item_is_design],
       CASE WHEN EXISTS (SELECT 1 FROM [cablebuilder].[bom_lines] AS sb
                         WHERE sb.[design_version_id] = sub.[design_version_id])
            THEN 1 ELSE 0 END AS [item_has_bom],
       b.[design_version_id]
FROM [cablebuilder].[current_designs] AS d
JOIN [cablebuilder].[bom_lines] AS b ON b.[design_version_id] = d.[design_version_id]
OUTER APPLY (
    SELECT TOP (1) s.[design_version_id]
    FROM [cablebuilder].[current_designs] AS s
    WHERE s.[design_code] = b.[item_code]
      AND s.[design_alternative] = ISNULL(b.[item_alternative], 0)
) AS sub;
GO

-- Routing of current designs: one row per operation with its first resource's
-- D365 mapping.
CREATE OR ALTER VIEW [cablebuilder].[current_operations] AS
SELECT d.[design_code], d.[design_alternative], d.[design_version],
       o.[layer_seq], o.[op_index], o.[operation_seq], o.[task], o.[machine],
       o.[speed_m_per_min], o.[run_time_h_per_km], o.[setup_time_h],
       r.[lot_size_m], r.[setup_time_h_per_km],
       r.[excess_qty_m], r.[start_scrap_m], r.[drum_change_length_m],
       r.[erp_resource], r.[erp_resource_group],
       r.[erp_process_category_id], r.[erp_setup_category_id],
       r.[erp_run_time_min], r.[erp_setup_time_min],
       o.[design_version_id]
FROM [cablebuilder].[current_designs] AS d
JOIN [cablebuilder].[operations] AS o ON o.[design_version_id] = d.[design_version_id]
LEFT JOIN [cablebuilder].[resources] AS r
       ON r.[design_version_id] = o.[design_version_id]
      AND r.[layer_seq] = o.[layer_seq]
      AND r.[op_index] = o.[op_index]
      AND r.[res_index] = 0;
GO

-- The Machine Table (tablecode 94794140, plant HQ) as columns. Rows 0 and 1 of
-- the grid are the label and unit rows, so data starts at row_no 2.
CREATE OR ALTER VIEW [cablebuilder].[machines] AS
SELECT JSON_VALUE(r.[cells_json], '$[0]')  AS [machine],
       JSON_VALUE(r.[cells_json], '$[1]')  AS [description],
       JSON_VALUE(r.[cells_json], '$[2]')  AS [workcentre],
       JSON_VALUE(r.[cells_json], '$[3]')  AS [task],
       JSON_VALUE(r.[cells_json], '$[4]')  AS [plant],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[5]'))  AS [setup_scrap],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[6]'))  AS [machine_rate_per_h],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[7]'))  AS [labour_rate_per_h],
       TRY_CONVERT(DECIMAL(9,3),  JSON_VALUE(r.[cells_json], '$[8]'))  AS [efficiency_pct],
       TRY_CONVERT(DECIMAL(9,3),  JSON_VALUE(r.[cells_json], '$[9]'))  AS [run_occupation],
       TRY_CONVERT(DECIMAL(9,3),  JSON_VALUE(r.[cells_json], '$[10]')) AS [setup_occupation],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[11]')) AS [setup_time_h],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[12]')) AS [max_rpm],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[13]')) AS [max_speed_m_per_min],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[14]')) AS [extruder_output_pvc_kg_per_h],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[15]')) AS [extruder_output_hffr_kg_per_h],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[16]')) AS [extruder_output_xlpe_kg_per_h],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[17]')) AS [extruder_output_pa12_kg_per_h],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[18]')) AS [extruder_output_tpu_kg_per_h],
       TRY_CONVERT(DECIMAL(18,4), JSON_VALUE(r.[cells_json], '$[19]')) AS [extruder_output_mv_kg_per_h]
FROM [cablebuilder].[lookup_table_versions] AS t
JOIN [cablebuilder].[lookup_rows] AS r ON r.[lookup_table_version_id] = t.[lookup_table_version_id]
WHERE t.[tablecode] = 94794140
  AND t.[is_current] = 1
  AND r.[row_no] >= 2;
GO

-- ---------------------------------------------------------------- BOM explosion

/*
  Full multi-level BOM of one design, from current designs:

      SELECT * FROM [cablebuilder].[explode_bom](N'KL39X-CAXN/3C2.5BK', 0, 1000);

  Returns every line under the design with the quantity needed for
  @quantity of the top item (in its own unit, normally metres), scrap not
  applied. is_leaf = 1 marks what is consumed rather than made: a purchased
  material (with or without a design record), or a sub-design the archive does
  not hold (item_is_design = 0 with unit m: unresolved). Raw materials per
  @quantity of the top item:

      SELECT item_code, unit, SUM(quantity) FROM [cablebuilder].[explode_bom](...)
      WHERE is_leaf = 1 GROUP BY item_code, unit;

  Depth is capped at 20; the deepest tree in the August pull is 10.
*/
CREATE OR ALTER FUNCTION [cablebuilder].[explode_bom]
(
    @design_code        NVARCHAR(64),
    @design_alternative INT,
    @quantity           DECIMAL(28,10)
)
RETURNS TABLE
AS
RETURN
WITH [tree] AS (
    SELECT 1 AS [level],
           b.[design_code] AS [parent_code],
           b.[item_code], b.[item_alternative], b.[unit], b.[item_is_design], b.[item_has_bom],
           CAST(@quantity * b.[qty_per_parent_unit] AS DECIMAL(28,10)) AS [quantity],
           CAST(b.[design_code] + N' > ' + b.[item_code] AS NVARCHAR(2000)) AS [path]
    FROM [cablebuilder].[current_bom_lines] AS b
    WHERE b.[design_code] = @design_code
      AND b.[design_alternative] = @design_alternative
    UNION ALL
    SELECT t.[level] + 1,
           b.[design_code],
           b.[item_code], b.[item_alternative], b.[unit], b.[item_is_design], b.[item_has_bom],
           CAST(t.[quantity] * b.[qty_per_parent_unit] AS DECIMAL(28,10)),
           CAST(t.[path] + N' > ' + b.[item_code] AS NVARCHAR(2000))
    FROM [tree] AS t
    JOIN [cablebuilder].[current_bom_lines] AS b
      ON b.[design_code] = t.[item_code]
     AND b.[design_alternative] = ISNULL(t.[item_alternative], 0)
    WHERE t.[item_has_bom] = 1
      AND t.[level] < 20
)
SELECT [level], [parent_code], [item_code], [item_alternative], [unit],
       [item_is_design], 1 - [item_has_bom] AS [is_leaf], [quantity], [path]
FROM [tree];
GO
