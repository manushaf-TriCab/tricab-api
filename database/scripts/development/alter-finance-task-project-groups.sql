/*
================================================================================
  Tri-Task: project groups on the Projects page (Nick Han, 19 Oct 2026: "can we
  enable 'Add Group' for projects as well? so we can create the structure
  ourselves"). Additive to create-finance-task-schema.sql; run after
  alter-finance-task-board.sql.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's or Jeff's, with the owner's approval. Run it BEFORE the API that
  reads [task_projects].[group_id]. Run against the local Docker database only
  until then.

  What it does, each step only if not already done, so a second run changes
  nothing and changes no existing row:
    - [finance].[task_project_groups]: named, coloured, ordered groups that
      projects sit in, as [task_groups] are for a project's tasks.
    - [task_projects].[group_id] pointing at one (NULL: ungrouped), with an
      index. No foreign key cascade: deleting a group clears its projects'
      group_id in code first (live and binned), and a project is never deleted
      with its group.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_projects]', N'U') IS NULL
        THROW 54274, N'Run create-finance-task-schema.sql first: [finance].[task_projects] is missing.', 1;

    IF OBJECT_ID(N'[finance].[task_project_groups]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_project_groups] (
            [id]           CHAR(32)       NOT NULL,
            [name]         NVARCHAR(200)  NOT NULL,
            [color]        NVARCHAR(20)   NULL,
            [sort_order]   INT            NOT NULL
                CONSTRAINT [DF_finance_task_project_groups_sort_order] DEFAULT (0),
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_project_groups_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_project_groups_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_project_groups] PRIMARY KEY CLUSTERED ([id])
        );
        CREATE INDEX [IX_finance_task_project_groups_order]
            ON [finance].[task_project_groups] ([sort_order], [id]);
    END;

    IF COL_LENGTH(N'finance.task_projects', N'group_id') IS NULL
        ALTER TABLE [finance].[task_projects] ADD [group_id] CHAR(32) NULL;

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

-- The index on the column added above, in its own batch: SQL Server compiles a batch before it runs, so a column
-- added in the batch above cannot be named in it.
BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [name] = N'IX_finance_task_projects_group' AND [object_id] = OBJECT_ID(N'[finance].[task_projects]'))
        CREATE INDEX [IX_finance_task_projects_group] ON [finance].[task_projects] ([group_id]);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    DECLARE @ErrorMessage2 NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity2 INT = ERROR_SEVERITY();
    DECLARE @ErrorState2 INT = ERROR_STATE();

    RAISERROR(@ErrorMessage2, @ErrorSeverity2, @ErrorState2);
END CATCH;
GO
