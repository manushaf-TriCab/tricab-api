/*
================================================================================
  Tri-Task: projects lose the "Planning" status (Nick Han, 13 Oct 2026). A
  project is Active from the start; Active, Completed and On Hold remain.
  Run after create-finance-task-schema.sql.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's or Jeff's.

  What it does, each step only if not already done, so a second run changes
  nothing:
    - every project still at `planning` becomes `active` (the app already
      shows them as Active);
    - [task_projects].[status] allows active, completed and on_hold only.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_projects]', N'U') IS NULL
        THROW 54272, N'Run create-finance-task-schema.sql first: [finance].[task_projects] is missing.', 1;

    IF EXISTS (
        SELECT 1 FROM sys.check_constraints
         WHERE [name] = N'CK_finance_task_projects_status'
           AND [parent_object_id] = OBJECT_ID(N'[finance].[task_projects]')
           AND [definition] LIKE N'%planning%')
    BEGIN
        UPDATE [finance].[task_projects]
           SET [status] = N'active', [updated_date] = SYSUTCDATETIME()
         WHERE [status] = N'planning';
        ALTER TABLE [finance].[task_projects] DROP CONSTRAINT [CK_finance_task_projects_status];
        ALTER TABLE [finance].[task_projects] ADD CONSTRAINT [CK_finance_task_projects_status]
            CHECK ([status] IN (N'active', N'completed', N'on_hold'));
    END;

    COMMIT TRANSACTION;
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
