/*
================================================================================
  Finance Odoo reference lists: the platform's own copy of four Odoo lists
  (Phase 1, task F1.12). Written 7 Oct 2026. Additive to create-finance-schema.sql;
  run after it.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  Two tables, shaped as the finance portal's platform_odoo_records and
  platform_odoo_sync_runs (inventory portal.md section 3):

    [finance].[odoo_records]    one row per (list, Odoo id): a read-only copy of a
                                company, vendor, cost centre or account. A record
                                that disappears from Odoo keeps its row with
                                [deleted_at] set, so an invoice that already points
                                at it still resolves.
    [finance].[odoo_sync_runs]  one row per sync run, scheduled or asked for.

  The payload column. [data_json] holds the record as the apps read it and is
  NVARCHAR(MAX). A query that ORDERs BY while selecting it took 25 seconds for
  1,864 vendors on the AWS RDS instance (0.05 s unsorted, measured 6 Oct 2026),
  so nothing in this schema or its readers sorts a row that carries it: the
  ordering columns are [name] and [odoo_id], with an index on ([list_key], [name],
  [odoo_id]) that includes the other small columns the list reads, and the
  payload is fetched in a second query by key. The list key is not CHECKed
  against the four lists: the lists are defined in code, as the portal's were, so
  a fifth is not an ALTER.

  "Two runs never overlap" is this schema's, not PHP's: a filtered unique index
  allows at most one row in status 'running'. A second run cannot start while one
  is, and a run found stuck for 15 minutes is marked 'abandoned' by the service
  before a new one starts.

  Conventions as create-finance-schema.sql: DATETIME2(6) in UTC, NVARCHAR text,
  no foreign key outside [finance]. [actor_user_id] is identity.Users.UserId, with
  no foreign key (a finance-only restore stays possible, F1.3); null for a
  scheduled run.

  Guarded and idempotent: each table is created only if missing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF SCHEMA_ID(N'finance') IS NULL
    EXEC (N'CREATE SCHEMA [finance];');
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[odoo_records]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[odoo_records] (
            [list_key]    NVARCHAR(40)   NOT NULL,
            [odoo_id]     INT            NOT NULL,
            [name]        NVARCHAR(400)  NOT NULL
                CONSTRAINT [DF_finance_odoo_records_name] DEFAULT (N''),
            [code]        NVARCHAR(100)  NULL,
            [company_id]  INT            NULL,
            [is_active]   BIT            NOT NULL
                CONSTRAINT [DF_finance_odoo_records_is_active] DEFAULT (1),
            [data_json]   NVARCHAR(MAX)  NOT NULL
                CONSTRAINT [DF_finance_odoo_records_data_json] DEFAULT (N'{}'),
            [write_date]  DATETIME2(6)   NULL,
            [deleted_at]  DATETIME2(6)   NULL,
            [synced_at]   DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_odoo_records_synced_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_odoo_records] PRIMARY KEY CLUSTERED ([list_key], [odoo_id])
        );

        -- Covers the list query (the small columns, in name order), so reading a list is
        -- an ordered index seek with no Sort operator at all.
        CREATE INDEX [IX_finance_odoo_records_list_name]
            ON [finance].[odoo_records] ([list_key], [name], [odoo_id])
            INCLUDE ([is_active], [write_date], [deleted_at], [synced_at]);

        CREATE INDEX [IX_finance_odoo_records_list_write]
            ON [finance].[odoo_records] ([list_key], [write_date]);

        CREATE INDEX [IX_finance_odoo_records_list_synced]
            ON [finance].[odoo_records] ([list_key], [synced_at]);
    END;

    IF OBJECT_ID(N'[finance].[odoo_sync_runs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[odoo_sync_runs] (
            [id]             CHAR(32)       NOT NULL,
            [mode]           NVARCHAR(20)   NOT NULL,
            [triggered_by]   NVARCHAR(20)   NOT NULL,
            [actor_user_id]  BIGINT         NULL,
            [status]         NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_odoo_sync_runs_status] DEFAULT (N'running'),
            [counts_json]    NVARCHAR(MAX)  NOT NULL
                CONSTRAINT [DF_finance_odoo_sync_runs_counts] DEFAULT (N'{}'),
            [error_message]  NVARCHAR(1000) NULL,
            [started_at]     DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_odoo_sync_runs_started_at] DEFAULT (SYSUTCDATETIME()),
            [finished_at]    DATETIME2(6)   NULL,
            CONSTRAINT [PK_finance_odoo_sync_runs] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_odoo_sync_runs_mode]
                CHECK ([mode] IN (N'full', N'incremental')),
            CONSTRAINT [CK_finance_odoo_sync_runs_triggered_by]
                CHECK ([triggered_by] IN (N'schedule', N'admin')),
            CONSTRAINT [CK_finance_odoo_sync_runs_status]
                CHECK ([status] IN (N'running', N'succeeded', N'failed', N'abandoned'))
        );

        CREATE INDEX [IX_finance_odoo_sync_runs_started_at]
            ON [finance].[odoo_sync_runs] ([started_at]);

        -- At most one row may be 'running': every row in the filter has the same status, so
        -- the unique index admits one. This is what keeps two runs from overlapping.
        CREATE UNIQUE INDEX [UX_finance_odoo_sync_runs_one_running]
            ON [finance].[odoo_sync_runs] ([status])
            WHERE [status] = N'running';
    END;

    COMMIT TRANSACTION;

    SELECT
        (SELECT COUNT(*) FROM sys.tables  WHERE [schema_id] = SCHEMA_ID(N'finance')) AS [FinanceTables],
        (SELECT COUNT(*) FROM sys.indexes i
           JOIN sys.tables t ON t.[object_id] = i.[object_id]
          WHERE t.[schema_id] = SCHEMA_ID(N'finance') AND i.[index_id] > 0) AS [FinanceIndexes];
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();

    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
GO
