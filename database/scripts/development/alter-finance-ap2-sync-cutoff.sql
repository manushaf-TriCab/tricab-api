/*
================================================================================
  Tri-AP2 mail sync with a cutoff date (27 Oct 2026).

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. A fresh build of
  create-finance-ap2-schema.sql already has the objects below.

  Product decision: no syncing of old email or of a chosen period. One cutoff
  date (the setting 'mail_cutoff_date' in [finance].[ap2_settings], a key/value
  table, so no column for it); an hourly job and the Sync button read only new
  mail since the last successful run.

  Adds to [finance].[ap2_sync_runs]:
    [high_water_at]    the latest received time fully processed (UTC); the next
                       sync starts one hour before it
    [window_start_at]  the window being read (a catch-up reads successive windows
    [window_end_at]    of at most 31 days / 500 messages) ...
    [target_at]        ... and the time the run is heading for (when it started)

  Independent of alter-finance-ap2-sync-batches.sql: it adds the batch columns too
  when that script was not applied (same guards), so it runs in either order.
  Existing rows and mail are untouched; nothing is deleted.

  Idempotent. Run with sqlcmd -I (QUOTED_IDENTIFIER).
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[ap2_sync_runs]', N'U') IS NULL
    BEGIN
        THROW 54321, N'The Tri-AP2 tables do not exist. Run create-finance-ap2-schema.sql first.', 1;
    END;

    -- From alter-finance-ap2-sync-batches.sql (guarded, so either order works).
    IF COL_LENGTH(N'[finance].[ap2_sync_runs]', N'processed') IS NULL
        ALTER TABLE [finance].[ap2_sync_runs] ADD [processed] INT NOT NULL
            CONSTRAINT [DF_finance_ap2_sync_runs_processed] DEFAULT (0);
    IF COL_LENGTH(N'[finance].[ap2_sync_runs]', N'failed') IS NULL
        ALTER TABLE [finance].[ap2_sync_runs] ADD [failed] INT NOT NULL
            CONSTRAINT [DF_finance_ap2_sync_runs_failed] DEFAULT (0);
    IF COL_LENGTH(N'[finance].[ap2_sync_runs]', N'updated_at') IS NULL
        ALTER TABLE [finance].[ap2_sync_runs] ADD [updated_at] DATETIME2(6) NULL;

    IF NOT EXISTS (
        SELECT 1 FROM sys.check_constraints
         WHERE [name] = N'CK_finance_ap2_sync_runs_status'
           AND [parent_object_id] = OBJECT_ID(N'[finance].[ap2_sync_runs]')
           AND [definition] LIKE N'%Cancelled%')
    BEGIN
        IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [name] = N'CK_finance_ap2_sync_runs_status' AND [parent_object_id] = OBJECT_ID(N'[finance].[ap2_sync_runs]'))
            ALTER TABLE [finance].[ap2_sync_runs] DROP CONSTRAINT [CK_finance_ap2_sync_runs_status];
        ALTER TABLE [finance].[ap2_sync_runs] ADD CONSTRAINT [CK_finance_ap2_sync_runs_status]
            CHECK ([status] IN (N'Running', N'Succeeded', N'Failed', N'Cancelled'));
    END;

    -- New for the cutoff model.
    IF COL_LENGTH(N'[finance].[ap2_sync_runs]', N'high_water_at') IS NULL
        ALTER TABLE [finance].[ap2_sync_runs] ADD [high_water_at] DATETIME2(6) NULL;
    IF COL_LENGTH(N'[finance].[ap2_sync_runs]', N'window_start_at') IS NULL
        ALTER TABLE [finance].[ap2_sync_runs] ADD [window_start_at] DATETIME2(6) NULL;
    IF COL_LENGTH(N'[finance].[ap2_sync_runs]', N'window_end_at') IS NULL
        ALTER TABLE [finance].[ap2_sync_runs] ADD [window_end_at] DATETIME2(6) NULL;
    IF COL_LENGTH(N'[finance].[ap2_sync_runs]', N'target_at') IS NULL
        ALTER TABLE [finance].[ap2_sync_runs] ADD [target_at] DATETIME2(6) NULL;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

-- Separate batch: the new columns must exist before they are named. Closes Running rows left by requests that died.
UPDATE [finance].[ap2_sync_runs]
   SET [status] = N'Failed',
       [error] = COALESCE([error], N'Abandoned: the request died before it finished'),
       [finished_at] = COALESCE([finished_at], SYSUTCDATETIME())
 WHERE [status] = N'Running' AND [updated_at] IS NULL;
GO

SELECT COUNT(*) AS [StillRunning] FROM [finance].[ap2_sync_runs] WHERE [status] = N'Running';
GO
