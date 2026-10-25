/*
================================================================================
  Tri-Task: Unread, Flag and Pin on a task, each one per person like a mailbox
  (Nick Han, 23 Oct 2026: "can mark as unread when first created until clicked,
  can flag, can pin to the top"). Additive to create-finance-task-schema.sql;
  run after alter-finance-task-history-fields.sql.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's or Jeff's, with the owner's approval. Run it BEFORE the API that
  reads these tables: that API names them in every task list, so without them
  no task loads. Run it right before the deploy: see [task_unread_cutoff].
  Run against the local Docker database only until then.

  What it does, only if not already done, so a second run changes nothing and
  changes no existing row:
    - [finance].[task_user_state]: one row per (task, person) once that person
      reads, unreads, flags or pins the task. No row means: not flagged, not
      pinned, and read or unread by the rule below.
        read_at        when the person last opened the task
        marked_unread  1 after "Mark as unread" (an old task can be unread too)
        flagged_at     NULL when not flagged
        pinned_at      NULL when not pinned; pinned tasks keep this order
    - [finance].[task_unread_cutoff]: one row, the moment this script first ran.
      A task created AFTER it is unread for everyone who can see it except its
      creator, until they open it. A task created BEFORE it counts as read, so
      nobody wakes up to every existing task unread. The row is written once,
      never moved by a second run.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL
        THROW 54276, N'Run create-finance-task-schema.sql first: [finance].[task_tasks] is missing.', 1;

    IF OBJECT_ID(N'[finance].[task_user_state]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_user_state] (
            [task_id]       CHAR(32)      NOT NULL,
            [user_id]       BIGINT        NOT NULL,
            [read_at]       DATETIME2(6)  NULL,
            [marked_unread] BIT           NOT NULL
                CONSTRAINT [DF_finance_task_user_state_marked_unread] DEFAULT (0),
            [flagged_at]    DATETIME2(6)  NULL,
            [pinned_at]     DATETIME2(6)  NULL,
            CONSTRAINT [PK_finance_task_user_state] PRIMARY KEY CLUSTERED ([task_id], [user_id]),
            CONSTRAINT [FK_finance_task_user_state_task] FOREIGN KEY ([task_id])
                REFERENCES [finance].[task_tasks] ([id])
        );

        CREATE INDEX [IX_finance_task_user_state_user] ON [finance].[task_user_state] ([user_id]);
    END;

    IF OBJECT_ID(N'[finance].[task_unread_cutoff]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_unread_cutoff] (
            [id]        TINYINT       NOT NULL,
            [cutoff_at] DATETIME2(6)  NOT NULL,
            CONSTRAINT [PK_finance_task_unread_cutoff] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_task_unread_cutoff_one] CHECK ([id] = 1)
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM [finance].[task_unread_cutoff] WHERE [id] = 1)
        INSERT INTO [finance].[task_unread_cutoff] ([id], [cutoff_at]) VALUES (1, SYSUTCDATETIME());

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
