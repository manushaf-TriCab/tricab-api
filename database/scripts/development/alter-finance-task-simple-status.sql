/*
================================================================================
  Tri-Task: tasks are Active, On Hold or Done (Nick Han, 13 Oct 2026). Run after
  alter-finance-task-board.sql.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's or Jeff's. Run it BEFORE the API that writes `active` and `on_hold`
  is deployed: the check below lets both that API and the one before it write.

  What it does, each step only if not already done, so a second run changes
  nothing:
    - [task_tasks].[status] allows active, on_hold and completed, and still the
      board's four of the morning (not_started, in_progress, stuck,
      ready_for_review) so the API deployed before this one keeps working;
    - every task at one of those four becomes `active`. Its history rows keep
      the status they recorded; the app labels them.
  Overdue is still worked out from the due date, for Active tasks only.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL
        THROW 54273, N'Run create-finance-task-schema.sql first: [finance].[task_tasks] is missing.', 1;

    IF EXISTS (
        SELECT 1 FROM sys.check_constraints
         WHERE [name] = N'CK_finance_task_tasks_status'
           AND [parent_object_id] = OBJECT_ID(N'[finance].[task_tasks]')
           AND [definition] NOT LIKE N'%on_hold%')
    BEGIN
        ALTER TABLE [finance].[task_tasks] DROP CONSTRAINT [CK_finance_task_tasks_status];
        ALTER TABLE [finance].[task_tasks] ADD CONSTRAINT [CK_finance_task_tasks_status]
            CHECK ([status] IN (N'active', N'on_hold', N'completed',
                                N'not_started', N'in_progress', N'stuck', N'ready_for_review'));
    END;

    UPDATE [finance].[task_tasks]
       SET [status] = N'active', [updated_date] = SYSUTCDATETIME()
     WHERE [status] IN (N'not_started', N'in_progress', N'stuck', N'ready_for_review');

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
