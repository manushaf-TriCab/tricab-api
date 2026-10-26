/*
================================================================================
  Tri-AP2 mail sync in batches (27 Oct 2026).

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. A fresh build of
  create-finance-ap2-schema.sql already has the objects below.

  Adds to [finance].[ap2_sync_runs]:
    [processed]   messages handled so far (created, skipped or failed)
    [failed]      messages that failed (blob, classifier, reader)
    [updated_at]  the last time the run made progress; a Running row with no
                  progress for 10 minutes (or NULL, i.e. written before this
                  column) is stale and no longer blocks a new sync
  and lets [status] be N'Cancelled' (the Stop button).

  Also closes the Running rows left by requests that died (the 30-second PHP
  fatal): they are marked Failed. Nothing else is changed.

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

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

-- Separate batch: the new columns must exist before they are named.
UPDATE [finance].[ap2_sync_runs]
   SET [status] = N'Failed',
       [error] = COALESCE([error], N'Abandoned: the request died before it finished'),
       [finished_at] = COALESCE([finished_at], SYSUTCDATETIME())
 WHERE [status] = N'Running' AND [updated_at] IS NULL;
GO

SELECT COUNT(*) AS [StillRunning] FROM [finance].[ap2_sync_runs] WHERE [status] = N'Running';
GO
