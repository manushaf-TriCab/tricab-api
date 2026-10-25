/*
================================================================================
  Tri-Task: several preparers and several reviewers on one task (Nick Han,
  23 Oct 2026: "can we also have multiple Preparers and Reviewers please?
  Sometimes, multiple people will do one job together."). Additive to
  create-finance-task-schema.sql; run after alter-finance-task-history-fields.sql.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's or Jeff's, with the owner's approval. RUN IT BEFORE THE API THAT
  READS [finance].[task_assignees]: that API names the table in every task
  query, so without it no task loads. Run against the local Docker database
  only until then.

  What it does, each step only if not already done, so a second run changes
  nothing and adds no duplicate:
    - [finance].[task_assignees]: one row per person per role on a task
      (role 'preparer' or 'reviewer'), with [sort_order] so the first person
      stays first. Primary key (task_id, role, user_id). Foreign key to
      [task_tasks]; no cascade (a permanent delete removes the rows first, in
      code). [user_id] is a platform user id, as [preparer_user_id] is: no
      foreign key out of the [finance] schema.
    - [finance].[task_template_assignees]: the same for [task_templates].
    - Backfill: every task and template that names a preparer or reviewer in
      the old single columns gets a row for it (sort_order 0), unless it has
      one already.

  The old columns [task_tasks].[preparer_user_id] / [reviewer_user_id] (and the
  same on [task_templates]) are KEPT and still written: the API stores the first
  preparer and first reviewer in them as well, so a reader of the old columns
  (an older API instance during a deploy swap, a report) still sees one person.
  They can be dropped in a later release, once nothing reads them. Nothing here
  alters or deletes an existing row of the old tables.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL
        THROW 54276, N'Run create-finance-task-schema.sql first: [finance].[task_tasks] is missing.', 1;

    IF OBJECT_ID(N'[finance].[task_assignees]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_assignees] (
            [task_id]      CHAR(32)       NOT NULL,
            [role]         NVARCHAR(10)   NOT NULL,
            [user_id]      BIGINT         NOT NULL,
            [sort_order]   INT            NOT NULL
                CONSTRAINT [DF_finance_task_assignees_sort_order] DEFAULT (0),
            [created_date] DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_task_assignees_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_assignees] PRIMARY KEY CLUSTERED ([task_id], [role], [user_id]),
            CONSTRAINT [FK_finance_task_assignees_task] FOREIGN KEY ([task_id])
                REFERENCES [finance].[task_tasks] ([id]),
            CONSTRAINT [CK_finance_task_assignees_role] CHECK ([role] IN (N'preparer', N'reviewer'))
        );

        CREATE INDEX [IX_finance_task_assignees_user] ON [finance].[task_assignees] ([user_id], [task_id]);
    END;

    IF OBJECT_ID(N'[finance].[task_template_assignees]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_template_assignees] (
            [template_id]  CHAR(32)       NOT NULL,
            [role]         NVARCHAR(10)   NOT NULL,
            [user_id]      BIGINT         NOT NULL,
            [sort_order]   INT            NOT NULL
                CONSTRAINT [DF_finance_task_template_assignees_sort_order] DEFAULT (0),
            [created_date] DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_task_template_assignees_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_template_assignees] PRIMARY KEY CLUSTERED ([template_id], [role], [user_id]),
            CONSTRAINT [FK_finance_task_template_assignees_template] FOREIGN KEY ([template_id])
                REFERENCES [finance].[task_templates] ([id]),
            CONSTRAINT [CK_finance_task_template_assignees_role] CHECK ([role] IN (N'preparer', N'reviewer'))
        );
    END;

    -- Backfill from the old single columns; a person already linked is not linked twice.
    INSERT INTO [finance].[task_assignees] ([task_id], [role], [user_id], [sort_order])
    SELECT t.[id], N'preparer', t.[preparer_user_id], 0
    FROM [finance].[task_tasks] AS t
    WHERE t.[preparer_user_id] IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM [finance].[task_assignees] AS a WHERE a.[task_id] = t.[id] AND a.[role] = N'preparer');

    INSERT INTO [finance].[task_assignees] ([task_id], [role], [user_id], [sort_order])
    SELECT t.[id], N'reviewer', t.[reviewer_user_id], 0
    FROM [finance].[task_tasks] AS t
    WHERE t.[reviewer_user_id] IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM [finance].[task_assignees] AS a WHERE a.[task_id] = t.[id] AND a.[role] = N'reviewer');

    INSERT INTO [finance].[task_template_assignees] ([template_id], [role], [user_id], [sort_order])
    SELECT t.[id], N'preparer', t.[preparer_user_id], 0
    FROM [finance].[task_templates] AS t
    WHERE t.[preparer_user_id] IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM [finance].[task_template_assignees] AS a WHERE a.[template_id] = t.[id] AND a.[role] = N'preparer');

    INSERT INTO [finance].[task_template_assignees] ([template_id], [role], [user_id], [sort_order])
    SELECT t.[id], N'reviewer', t.[reviewer_user_id], 0
    FROM [finance].[task_templates] AS t
    WHERE t.[reviewer_user_id] IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM [finance].[task_template_assignees] AS a WHERE a.[template_id] = t.[id] AND a.[role] = N'reviewer');

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
