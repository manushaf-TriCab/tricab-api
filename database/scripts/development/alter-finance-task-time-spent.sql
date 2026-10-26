/*
================================================================================
  Tri-Task: Estimated Time on a task (Nick Han, 26 Oct 2026: a column where
  people enter how long a task would take, as free text). The column is named
  time_spent: the label became Estimated Time after this script was applied.
  Additive to create-finance-task-schema.sql; run after
  alter-finance-task-order.sql.

  STATUS: APPLIED to tricab-mel 26 Oct 2026. Run it BEFORE the API that reads
  [task_tasks].[time_spent] on any other database: that API names the column
  in every task query, so without it no task loads.

  What it does, only if not already done, so a second run changes nothing and
  changes no existing row:
    - [task_tasks].[time_spent] NVARCHAR(200) NULL: what the person wrote,
      "2 hours", "half a day". NULL (every task today) for none.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL
        THROW 54276, N'Run create-finance-task-schema.sql first: [finance].[task_tasks] is missing.', 1;

    IF COL_LENGTH(N'finance.task_tasks', N'time_spent') IS NULL
        ALTER TABLE [finance].[task_tasks] ADD [time_spent] NVARCHAR(200) NULL;

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
