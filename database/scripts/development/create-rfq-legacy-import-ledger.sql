/*
================================================================================
  RFQ legacy refresh ledger (RFQ plan 7.2-7.4): what the platform last took
  from the old app's MySQL store, row by row, so a later refresh can tell a
  change made in tc-web from one made on the platform.

    - rfq_legacy_import_rows: one row per imported source row. `key_sha256`
      is the SHA-256 of the row's primary key (the correction-memory key is
      too long to index whole); `row_sha256` the row's content as last
      imported (bin/sync-rfq-legacy-store.php's canonical form). A row with
      no ledger entry was made on the platform and is never touched.
    - rfq_legacy_import_runs: one row per adopt or refresh, with its counts
      and conflicts as JSON.

  Platform tables, not source ones, so they use DATETIME2, not text times.
  Guarded and idempotent: each table is created only if missing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[rfq].[rfq_draft_quotes]', N'U') IS NULL
        THROW 50001, N'The [rfq] tables are missing. Run create-rfq-schema.sql first.', 1;

    IF OBJECT_ID(N'[rfq].[rfq_legacy_import_runs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_legacy_import_runs] (
            [id]                  CHAR(32)       NOT NULL,
            [mode]                NVARCHAR(16)   NOT NULL,
            [started_at]          DATETIME2(3)   NOT NULL,
            [finished_at]         DATETIME2(3)   NULL,
            [source_snapshot_at]  DATETIME2(3)   NULL,
            [status]              NVARCHAR(16)   NOT NULL,
            [counts]              NVARCHAR(MAX)  NULL,
            [conflicts]           NVARCHAR(MAX)  NULL,
            CONSTRAINT [PK_rfq_legacy_import_runs] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_rfq_legacy_import_runs_mode] CHECK ([mode] IN (N'adopt', N'refresh')),
            CONSTRAINT [CK_rfq_legacy_import_runs_status] CHECK ([status] IN (N'committed', N'failed'))
        );
        CREATE NONCLUSTERED INDEX [rfq_legacy_import_runs_started_at] ON [rfq].[rfq_legacy_import_runs]([started_at]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_legacy_import_rows]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_legacy_import_rows] (
            [table_name]   NVARCHAR(64)   NOT NULL,
            [key_sha256]   CHAR(64)       NOT NULL,
            [row_sha256]   CHAR(64)       NOT NULL,
            [run_id]       CHAR(32)       NOT NULL,
            CONSTRAINT [PK_rfq_legacy_import_rows] PRIMARY KEY CLUSTERED ([table_name], [key_sha256]),
            CONSTRAINT [FK_rfq_legacy_import_rows_run] FOREIGN KEY ([run_id]) REFERENCES [rfq].[rfq_legacy_import_runs]([id])
        );
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
