/*
================================================================================
  Tri-Task: a task's place on its project board (Nick Han, 20 Oct 2026: "can we
  move the task around by dragging"). Additive to create-finance-task-schema.sql;
  run after alter-finance-task-project-groups.sql.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's or Jeff's, with the owner's approval. Run it BEFORE the API that
  reads [task_tasks].[sort_order]: that API names the column in every task
  query, so without it no task loads. Run against the local Docker database
  only until then.

  What it does, only if not already done, so a second run changes nothing and
  changes no existing row:
    - [task_tasks].[sort_order] INT NULL: the task's position in its group,
      0 at the top. NULL (every task today) until someone drags; the board
      shows those after the ordered ones, oldest first.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL
        THROW 54275, N'Run create-finance-task-schema.sql first: [finance].[task_tasks] is missing.', 1;

    IF COL_LENGTH(N'finance.task_tasks', N'sort_order') IS NULL
        ALTER TABLE [finance].[task_tasks] ADD [sort_order] INT NULL;

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
