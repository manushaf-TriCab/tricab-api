/*
================================================================================
  Tri-Task: calendar invites by due date (Nick Han, 23 Oct 2026: "can select one
  task or tasks to send calendar invites by due date"). Additive to
  create-finance-task-schema.sql; run after alter-finance-task-user-state.sql.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's or Jeff's, with the owner's approval. RUN IT ON tricab-mel BEFORE
  THE API IS DEPLOYED: every task list reads [task_calendar_invites], so without
  the table no task loads. Run it right before the deploy. Run against the local
  Docker database only until then.

  What it does, only if not already done, so a second run changes nothing and
  changes no existing row:
    - [finance].[task_calendar_invites]: one row per task that has had an invite,
      holding the LATEST one (a re-send updates the row, it does not add one).
        uid             stable per task: the same event is updated in people's
                        calendars, never duplicated
        sequence        iCalendar SEQUENCE: 0 for the first invite, +1 per re-send
        due_date_sent   the due date (yyyy-MM-dd) the last invite carried; when the
                        task's due date differs the invite is out of date
        sent_by         identity.Users.UserId of who sent it
        organizer_email the mailbox it was sent from (the iCalendar ORGANIZER)
        recipients      JSON list of the attendee addresses
        sent_at         when (UTC)
      The row goes when its task is deleted permanently (ON DELETE CASCADE).
    - [finance].[task_mail_links]: the kind check also allows 'calendar_invite'
      (the constraint is dropped and re-added only when it lacks that kind), so
      an invite shows in a task's mail history like a reminder. No row changes.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL
        THROW 54277, N'Run create-finance-task-schema.sql first: [finance].[task_tasks] is missing.', 1;

    IF OBJECT_ID(N'[finance].[task_calendar_invites]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[task_calendar_invites] (
            [task_id]         CHAR(32)       NOT NULL,
            [uid]             NVARCHAR(200)  NOT NULL,
            [sequence]        INT            NOT NULL
                CONSTRAINT [DF_finance_task_calendar_invites_sequence] DEFAULT (0),
            [due_date_sent]   NVARCHAR(10)   NOT NULL,
            [sent_by]         BIGINT         NULL,
            [organizer_email] NVARCHAR(320)  NOT NULL,
            [recipients]      NVARCHAR(MAX)  NOT NULL,
            [sent_at]         DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_task_calendar_invites_sent_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_task_calendar_invites] PRIMARY KEY CLUSTERED ([task_id]),
            CONSTRAINT [FK_finance_task_calendar_invites_task] FOREIGN KEY ([task_id])
                REFERENCES [finance].[task_tasks] ([id]) ON DELETE CASCADE
        );
    END;

    IF EXISTS (
        SELECT 1 FROM sys.check_constraints
        WHERE [name] = N'CK_finance_task_mail_links_kind'
          AND [parent_object_id] = OBJECT_ID(N'[finance].[task_mail_links]')
          AND [definition] NOT LIKE N'%calendar_invite%'
    )
    BEGIN
        ALTER TABLE [finance].[task_mail_links] DROP CONSTRAINT [CK_finance_task_mail_links_kind];
        ALTER TABLE [finance].[task_mail_links] ADD CONSTRAINT [CK_finance_task_mail_links_kind]
            CHECK ([kind] IN (N'task_email', N'reminder', N'daily_summary', N'calendar_invite'));
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
