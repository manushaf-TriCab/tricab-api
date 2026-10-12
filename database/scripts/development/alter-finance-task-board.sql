/*
================================================================================
  Tri-Task: the review step, priority, task groups and project templates
  (TRI-TASK-UX-PLAN.md items 5 and 9, 13 Oct 2026). Additive to
  create-finance-task-schema.sql; run after it.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  What it does, each step only if not already done, so a second run changes
  nothing and changes no existing row:
    - [task_tasks].[status] allows five values. The two stored today keep their
      values and change only their labels in the app:
          not_started        Not started        (new)
          in_progress        Working on it      (was "In Progress")
          stuck              Stuck              (new)
          ready_for_review   Ready for review   (new)
          completed          Done               (was "Completed")
      `overdue` is still worked out from the due date and refused.
    - [task_tasks].[priority]: critical, high, medium or low; NULL for none.
    - [finance].[task_groups]: the groups a project's tasks are arranged in
      (Nick's board: "Odoo Studying", "Odoo Implementation", ...), and
      [task_tasks].[group_id] pointing at one. Deleting a group clears its
      tasks' group_id in code first (no cascades, as the rest of [finance]).
    - [finance].[task_project_templates]: a reusable project. Its groups and
      tasks are one JSON document in [structure] (ISJSON is checked): a
      template is read and saved whole, never queried inside.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL
        THROW 54271, N'Run create-finance-task-schema.sql first: [finance].[task_tasks] is missing.', 1;

    -- Statuses: replace the check only while it still lacks the new values.
    IF EXISTS (
        SELECT 1 FROM sys.check_constraints
         WHERE [name] = N'CK_finance_task_tasks_status'
           AND [parent_object_id] = OBJECT_ID(N'[finance].[task_tasks]')
           AND [definition] NOT LIKE N'%ready_for_review%')
    BEGIN
        ALTER TABLE [finance].[task_tasks] DROP CONSTRAINT [CK_finance_task_tasks_status];
        ALTER TABLE [finance].[task_tasks] ADD CONSTRAINT [CK_finance_task_tasks_status]
            CHECK ([status] IN (N'not_started', N'in_progress', N'stuck', N'ready_for_review', N'completed'));
    END;

    IF COL_LENGTH(N'finance.task_tasks', N'priority') IS NULL
        ALTER TABLE [finance].[task_tasks] ADD [priority] NVARCHAR(10) NULL;

    IF OBJECT_ID(N'[finance].[task_groups]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_groups] (
            [id]           CHAR(32)       NOT NULL,
            [project_id]   CHAR(32)       NOT NULL,
            [name]         NVARCHAR(200)  NOT NULL,
            [color]        NVARCHAR(20)   NULL,
            [sort_order]   INT            NOT NULL
                CONSTRAINT [DF_finance_task_groups_sort_order] DEFAULT (0),
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_groups_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_groups_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_groups] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_task_groups_project] FOREIGN KEY ([project_id])
                REFERENCES [finance].[task_projects] ([id])
        );
        CREATE INDEX [IX_finance_task_groups_project]
            ON [finance].[task_groups] ([project_id], [sort_order], [id]);
    END;

    IF COL_LENGTH(N'finance.task_tasks', N'group_id') IS NULL
        ALTER TABLE [finance].[task_tasks] ADD [group_id] CHAR(32) NULL;

    IF OBJECT_ID(N'[finance].[task_project_templates]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_project_templates] (
            [id]                  CHAR(32)       NOT NULL,
            [name]                NVARCHAR(200)  NOT NULL,
            [description]         NVARCHAR(MAX)  NULL,
            [color]               NVARCHAR(20)   NULL,
            [structure]           NVARCHAR(MAX)  NOT NULL,
            [created_by_user_id]  BIGINT         NULL,
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_project_templates_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_project_templates_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_project_templates] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_task_project_templates_structure] CHECK (ISJSON([structure]) = 1)
        );
        CREATE UNIQUE INDEX [UX_finance_task_project_templates_name]
            ON [finance].[task_project_templates] ([name]);
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

-- Constraints on the columns added above, in their own batch: SQL Server compiles a batch before it runs, so a
-- column added in the batch above cannot be named in it.
BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[CK_finance_task_tasks_priority]', N'C') IS NULL
        ALTER TABLE [finance].[task_tasks] ADD CONSTRAINT [CK_finance_task_tasks_priority]
            CHECK ([priority] IS NULL OR [priority] IN (N'critical', N'high', N'medium', N'low'));

    IF OBJECT_ID(N'[finance].[FK_finance_task_tasks_group]', N'F') IS NULL
        ALTER TABLE [finance].[task_tasks] ADD CONSTRAINT [FK_finance_task_tasks_group]
            FOREIGN KEY ([group_id]) REFERENCES [finance].[task_groups] ([id]);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [name] = N'IX_finance_task_tasks_group' AND [object_id] = OBJECT_ID(N'[finance].[task_tasks]'))
        CREATE INDEX [IX_finance_task_tasks_group] ON [finance].[task_tasks] ([group_id]);

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
