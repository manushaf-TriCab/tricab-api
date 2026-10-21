/*
================================================================================
  Tri-Task: a task's history logs every change, not only its status (Nick Han,
  21 Oct 2026: "rename it to Task History and then log all the changes done to
  the task"). Additive to create-finance-task-schema.sql; run after
  alter-finance-task-order.sql.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's or Jeff's, with the owner's approval. Run it BEFORE the API that
  reads [task_history].[field]: that API names the columns in every history
  query and writes them with every status change, so without them no task can
  be saved with a new status and no history loads. Run against the local
  Docker database only until then.

  What it does, only if not already done, so a second run changes nothing and
  changes no existing row:
    - [task_history].[field] NVARCHAR(40) NULL: the field a row records.
      NULL is a status change (every row today), whose statuses stay in
      [from_status] and [to_status].
    - [task_history].[from_value] and [to_value] NVARCHAR(400) NULL: the
      field's value before and after, as text (a person, entity, project or
      group by name). NULL for long text, which is logged without its words.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_history]', N'U') IS NULL
        THROW 54276, N'Run create-finance-task-schema.sql first: [finance].[task_history] is missing.', 1;

    IF COL_LENGTH(N'finance.task_history', N'field') IS NULL
        ALTER TABLE [finance].[task_history] ADD [field] NVARCHAR(40) NULL;

    IF COL_LENGTH(N'finance.task_history', N'from_value') IS NULL
        ALTER TABLE [finance].[task_history] ADD [from_value] NVARCHAR(400) NULL;

    IF COL_LENGTH(N'finance.task_history', N'to_value') IS NULL
        ALTER TABLE [finance].[task_history] ADD [to_value] NVARCHAR(400) NULL;

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
