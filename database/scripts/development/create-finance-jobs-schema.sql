/*
================================================================================
  Finance jobs: the claim table behind the job runner (Phase 1, task F1.8).
  Written 7 Oct 2026. Additive to create-finance-schema.sql; run after it.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  One table:

    [finance].[job_runs]   one row per (job, slot): the right to run that slot
                           of that job, and how the run ended.

  Why it exists. A due job must not run twice when two runners overlap (a slot
  swap runs two instances for a while). The guarantee is this table's primary
  key: a runner takes a slot by inserting its row, and only one insert can win.
  [claim_token] says which runner owns a row, so a runner that died holding a
  claim can be taken over after a while without the dead one later overwriting
  the new owner's result. [slot] is the schedule's own string (a day and a time
  in a stated time zone, or an interval number), never parsed here.

  The runner also writes each outcome to [finance].[audit_events] (F1.3); this
  table is what proves "once", that one is what answers "what happened".

  Conventions as create-finance-schema.sql: DATETIME2(6) in UTC, NVARCHAR text,
  no foreign key outside [finance], a JSON column that is never sorted on.

  Guarded and idempotent: the table is created only if missing.
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

    IF OBJECT_ID(N'[finance].[job_runs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[job_runs] (
            [job_name]       NVARCHAR(100)  NOT NULL,
            [slot]           NVARCHAR(80)   NOT NULL,
            [claim_token]    CHAR(32)       NOT NULL,
            [status]         NVARCHAR(16)   NOT NULL,
            [claimed_at]     DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_job_runs_claimed_at] DEFAULT (SYSUTCDATETIME()),
            [finished_at]    DATETIME2(6)   NULL,
            [error_message]  NVARCHAR(500)  NULL,
            [summary_json]   NVARCHAR(MAX)  NULL,
            CONSTRAINT [PK_finance_job_runs] PRIMARY KEY CLUSTERED ([job_name], [slot]),
            CONSTRAINT [CK_finance_job_runs_status]
                CHECK ([status] IN (N'running', N'succeeded', N'failed'))
        );

        CREATE INDEX [IX_finance_job_runs_claimed_at]
            ON [finance].[job_runs] ([claimed_at]);
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
