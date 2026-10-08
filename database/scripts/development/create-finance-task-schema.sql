/*
================================================================================
  Tri-Task's tables in [finance] (Phase 2, task T2.2). Written 7 Oct 2026.
  Additive to create-finance-schema.sql; run after it.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  Ten tables, shaped on Tri-Task's own (inventory/tri-task.md section 3, the
  Alembic revisions 0001 and 0002 at 625c900), with the table and column names
  kept (decision D16):

    [finance].[task_entities]           the entities tasks are filed under
    [finance].[task_projects]           projects, soft-deleted into the Bin
    [finance].[task_statement_areas]    financial statement areas
    [finance].[task_templates]          task templates
    [finance].[task_tasks]              the task register
    [finance].[task_dependencies]       task_id cannot complete until
                                        depends_on_task_id has
    [finance].[task_history]            status changes, written with the change
    [finance].[task_member_profiles]    a person's job title (the only thing
                                        Tri-Task stores about a person)
    [finance].[task_report_recipients]  who gets the daily summary
    [finance].[task_mail_links]         task <-> mail ledger row (question Q5)

  What changed from the source, and why:

    - Conventions (decision D8): timestamps are DATETIME2(6) in UTC with a
      SYSUTCDATETIME() default (the source's were the same, naive UTC); text is
      NVARCHAR; booleans are BIT. `due_date` and `target_date` stay
      NVARCHAR(10) yyyy-MM-dd strings, as the source compares them lexically.
    - ids are CHAR(32), lower-case hex generated in PHP, as the other finance
      tables (the source used NVARCHAR(36) UUID text). Nothing is migrated
      (question Q3), so no id has to round trip.
    - Every person column is BIGINT NULL holding identity.Users.UserId
      (decision D7), where the source held the portal's user id. There is no
      foreign key outside [finance], so a finance-only restore stays possible.
      The history keeps the name at the time in [changed_by_name].
    - [legacy_id] is dropped (question Q3: Tri-Task starts empty).
    - [task_outbound_messages] is replaced by [task_mail_links] (question Q5):
      subject, recipients and status are read from [finance].[mail_deliveries],
      and the body of a sent mail is kept nowhere. The link has no foreign key
      to the task, so a permanently deleted task keeps its history, and none to
      the ledger row (the ledger is the shared table's, F1.3).
    - Statuses, categories and kinds are check constraints (decision D16). The
      stored task status is in_progress or completed; `overdue` is derived and
      refused by the check.
    - Uniqueness the source enforced in code only is now an index (question
      Q4): a project name among live projects, a statement area name, an entity
      name. The database collation is case-insensitive, which matches the
      source's lower(). A binned project does not block a new one.
    - Foreign keys inside [finance] only, with no cascades: a task's
      dependencies and history are removed by the permanent delete, in code,
      in one transaction.

  No ORDER BY on an NVARCHAR(MAX) column anywhere: every list sorts on a
  bounded column (inventory section 3, notes for Azure SQL).

  Guarded and idempotent: each table is created only if missing, in one
  transaction, and a second run changes nothing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF SCHEMA_ID(N'finance') IS NULL
    EXEC (N'CREATE SCHEMA [finance];');
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_entities]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_entities] (
            [id]           CHAR(32)       NOT NULL,
            [name]         NVARCHAR(200)  NOT NULL,
            [code]         NVARCHAR(40)   NULL,
            [description]  NVARCHAR(MAX)  NULL,
            [color]        NVARCHAR(20)   NULL,
            [is_active]    BIT            NOT NULL
                CONSTRAINT [DF_finance_task_entities_is_active] DEFAULT (1),
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_entities_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_entities_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_entities] PRIMARY KEY CLUSTERED ([id])
        );

        CREATE UNIQUE INDEX [UX_finance_task_entities_name]
            ON [finance].[task_entities] ([name]);
    END;

    IF OBJECT_ID(N'[finance].[task_projects]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_projects] (
            [id]           CHAR(32)       NOT NULL,
            [name]         NVARCHAR(200)  NOT NULL,
            [description]  NVARCHAR(MAX)  NULL,
            [color]        NVARCHAR(20)   NULL,
            [status]       NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_task_projects_status] DEFAULT (N'active'),
            [target_date]  NVARCHAR(10)   NULL,
            [deleted_at]   DATETIME2(6)   NULL,
            [deleted_by]   BIGINT         NULL,
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_projects_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_projects_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_projects] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_task_projects_status]
                CHECK ([status] IN (N'planning', N'active', N'completed', N'on_hold'))
        );

        CREATE UNIQUE INDEX [UX_finance_task_projects_name_live]
            ON [finance].[task_projects] ([name])
            WHERE [deleted_at] IS NULL;

        CREATE INDEX [IX_finance_task_projects_deleted_at]
            ON [finance].[task_projects] ([deleted_at]);
    END;

    IF OBJECT_ID(N'[finance].[task_statement_areas]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_statement_areas] (
            [id]           CHAR(32)       NOT NULL,
            [name]         NVARCHAR(300)  NOT NULL,
            [category]     NVARCHAR(30)   NOT NULL
                CONSTRAINT [DF_finance_task_statement_areas_category] DEFAULT (N'other'),
            [description]  NVARCHAR(MAX)  NULL,
            [sort_order]   INT            NOT NULL
                CONSTRAINT [DF_finance_task_statement_areas_sort_order] DEFAULT (0),
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_statement_areas_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_statement_areas_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_statement_areas] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_task_statement_areas_category]
                CHECK ([category] IN (N'balance_sheet', N'income_statement', N'cash_flow', N'equity', N'notes', N'other'))
        );

        CREATE UNIQUE INDEX [UX_finance_task_statement_areas_name]
            ON [finance].[task_statement_areas] ([name]);

        CREATE INDEX [IX_finance_task_statement_areas_sort]
            ON [finance].[task_statement_areas] ([sort_order], [name]);
    END;

    IF OBJECT_ID(N'[finance].[task_templates]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_templates] (
            [id]                      CHAR(32)       NOT NULL,
            [name]                    NVARCHAR(300)  NOT NULL,
            [description]             NVARCHAR(MAX)  NULL,
            [requirements]            NVARCHAR(MAX)  NULL,
            [category]                NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_task_templates_category] DEFAULT (N'routine'),
            [finance_statement_area]  NVARCHAR(300)  NULL,
            [preparer_user_id]        BIGINT         NULL,
            [reviewer_user_id]        BIGINT         NULL,
            [default_progress]        INT            NOT NULL
                CONSTRAINT [DF_finance_task_templates_default_progress] DEFAULT (0),
            [due_date]                NVARCHAR(10)   NULL,
            [due_date_requested]      BIT            NOT NULL
                CONSTRAINT [DF_finance_task_templates_due_date_requested] DEFAULT (0),
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_templates_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_templates_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_templates] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_task_templates_category] CHECK ([category] IN (N'routine', N'ad_hoc')),
            CONSTRAINT [CK_finance_task_templates_progress] CHECK ([default_progress] BETWEEN 0 AND 100)
        );

        CREATE INDEX [IX_finance_task_templates_name]
            ON [finance].[task_templates] ([name]);
    END;

    IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_tasks] (
            [id]                      CHAR(32)       NOT NULL,
            [name]                    NVARCHAR(300)  NOT NULL,
            [description]             NVARCHAR(MAX)  NULL,
            [requirements]            NVARCHAR(MAX)  NULL,
            [notes]                   NVARCHAR(MAX)  NULL,
            [category]                NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_task_tasks_category] DEFAULT (N'routine'),
            [finance_statement_area]  NVARCHAR(300)  NULL,
            [entity_id]               CHAR(32)       NULL,
            [project_id]              CHAR(32)       NULL,
            [preparer_user_id]        BIGINT         NULL,
            [reviewer_user_id]        BIGINT         NULL,
            [status]                  NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_task_tasks_status] DEFAULT (N'in_progress'),
            [progress]                INT            NOT NULL
                CONSTRAINT [DF_finance_task_tasks_progress] DEFAULT (0),
            [due_date]                NVARCHAR(10)   NULL,
            [due_date_requested]      BIT            NOT NULL
                CONSTRAINT [DF_finance_task_tasks_due_date_requested] DEFAULT (0),
            [urgent]                  BIT            NOT NULL
                CONSTRAINT [DF_finance_task_tasks_urgent] DEFAULT (0),
            [completed_at]            DATETIME2(6)   NULL,
            [created_by_user_id]      BIGINT         NULL,
            [deleted_at]              DATETIME2(6)   NULL,
            [deleted_by]              BIGINT         NULL,
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_tasks_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_tasks_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_tasks] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_task_tasks_entity] FOREIGN KEY ([entity_id])
                REFERENCES [finance].[task_entities] ([id]),
            CONSTRAINT [FK_finance_task_tasks_project] FOREIGN KEY ([project_id])
                REFERENCES [finance].[task_projects] ([id]),
            CONSTRAINT [CK_finance_task_tasks_category] CHECK ([category] IN (N'routine', N'ad_hoc')),
            CONSTRAINT [CK_finance_task_tasks_status] CHECK ([status] IN (N'in_progress', N'completed')),
            CONSTRAINT [CK_finance_task_tasks_progress] CHECK ([progress] BETWEEN 0 AND 100)
        );

        CREATE INDEX [IX_finance_task_tasks_preparer] ON [finance].[task_tasks] ([preparer_user_id]);
        CREATE INDEX [IX_finance_task_tasks_reviewer] ON [finance].[task_tasks] ([reviewer_user_id]);
        CREATE INDEX [IX_finance_task_tasks_deleted_at] ON [finance].[task_tasks] ([deleted_at]);
        CREATE INDEX [IX_finance_task_tasks_status_due] ON [finance].[task_tasks] ([status], [due_date]);
        CREATE INDEX [IX_finance_task_tasks_entity] ON [finance].[task_tasks] ([entity_id]);
        CREATE INDEX [IX_finance_task_tasks_project] ON [finance].[task_tasks] ([project_id]);
        CREATE INDEX [IX_finance_task_tasks_updated] ON [finance].[task_tasks] ([updated_date], [id]);
    END;

    IF OBJECT_ID(N'[finance].[task_dependencies]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_dependencies] (
            [id]                  CHAR(32)      NOT NULL,
            [task_id]             CHAR(32)      NOT NULL,
            [depends_on_task_id]  CHAR(32)      NOT NULL,
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_dependencies_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_dependencies] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_task_dependencies_task] FOREIGN KEY ([task_id])
                REFERENCES [finance].[task_tasks] ([id]),
            CONSTRAINT [FK_finance_task_dependencies_depends_on] FOREIGN KEY ([depends_on_task_id])
                REFERENCES [finance].[task_tasks] ([id]),
            CONSTRAINT [UQ_finance_task_dependencies_pair] UNIQUE ([task_id], [depends_on_task_id]),
            CONSTRAINT [CK_finance_task_dependencies_not_self] CHECK ([task_id] <> [depends_on_task_id])
        );

        CREATE INDEX [IX_finance_task_dependencies_depends_on]
            ON [finance].[task_dependencies] ([depends_on_task_id]);
    END;

    IF OBJECT_ID(N'[finance].[task_history]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_history] (
            [id]                  CHAR(32)       NOT NULL,
            [task_id]             CHAR(32)       NOT NULL,
            [from_status]         NVARCHAR(40)   NULL,
            [to_status]           NVARCHAR(40)   NULL,
            [changed_by_user_id]  BIGINT         NULL,
            [changed_by_name]     NVARCHAR(200)  NULL,
            [changed_at]          DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_task_history_changed_at] DEFAULT (SYSUTCDATETIME()),
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_history_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_history_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_history] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_task_history_task] FOREIGN KEY ([task_id])
                REFERENCES [finance].[task_tasks] ([id])
        );

        CREATE INDEX [IX_finance_task_history_task]
            ON [finance].[task_history] ([task_id], [changed_at]);
        CREATE INDEX [IX_finance_task_history_changed_at]
            ON [finance].[task_history] ([changed_at], [id]);
    END;

    IF OBJECT_ID(N'[finance].[task_member_profiles]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_member_profiles] (
            [id]        CHAR(32)       NOT NULL,
            [user_id]   BIGINT         NOT NULL,
            [job_title] NVARCHAR(200)  NULL,
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_member_profiles_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_member_profiles_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_member_profiles] PRIMARY KEY CLUSTERED ([id])
        );

        CREATE UNIQUE INDEX [UX_finance_task_member_profiles_user]
            ON [finance].[task_member_profiles] ([user_id]);
    END;

    IF OBJECT_ID(N'[finance].[task_report_recipients]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_report_recipients] (
            [id]       CHAR(32)       NOT NULL,
            [email]    NVARCHAR(320)  NOT NULL,
            [name]     NVARCHAR(200)  NULL,
            [user_id]  BIGINT         NULL,
            [created_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_report_recipients_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_task_report_recipients_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_report_recipients] PRIMARY KEY CLUSTERED ([id])
        );

        CREATE UNIQUE INDEX [UX_finance_task_report_recipients_email]
            ON [finance].[task_report_recipients] ([email]);
    END;

    IF OBJECT_ID(N'[finance].[task_mail_links]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_mail_links] (
            [id]           CHAR(32)      NOT NULL,
            [kind]         NVARCHAR(20)  NOT NULL,
            [task_id]      CHAR(32)      NULL,
            [delivery_id]  CHAR(32)      NOT NULL,
            [created_at]   DATETIME2(6)  NOT NULL
                CONSTRAINT [DF_finance_task_mail_links_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_mail_links] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_task_mail_links_kind]
                CHECK ([kind] IN (N'task_email', N'reminder', N'daily_summary'))
        );

        CREATE INDEX [IX_finance_task_mail_links_task]
            ON [finance].[task_mail_links] ([task_id], [created_at]);
    END;

    COMMIT TRANSACTION;

    SELECT
        (SELECT COUNT(*) FROM sys.tables  WHERE [schema_id] = SCHEMA_ID(N'finance')) AS [FinanceTables],
        (SELECT COUNT(*) FROM sys.indexes i
           JOIN sys.tables t ON t.[object_id] = i.[object_id]
          WHERE t.[schema_id] = SCHEMA_ID(N'finance') AND i.[index_id] > 0) AS [FinanceIndexes];
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
