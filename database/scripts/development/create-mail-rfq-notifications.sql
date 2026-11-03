/*
================================================================================
  RFQ mail intake: new-email-RFQ bell notices. Additive to create-mail-schema.sql.

  STATUS: NOT APPLIED to any shared database. Apply to tricab-mel BEFORE setting
  RFQ_MAIL_NOTIFY_ENABLED=true. With the flag off nothing reads or writes this
  table, so the API can be deployed first. Applying it is Manusha's or Jeff's.

  One row per person per draft: the reader writes a row for every active user
  holding RFQ.DRAFT.CREATE when it makes a NEW draft from an email. The unique
  index (user_id, draft_public_id) means a retry cannot notify twice.

  Idempotent: safe to run any number of times. It changes no existing data.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'[mail].[mailboxes]', N'U') IS NULL
BEGIN
    RAISERROR(N'The [mail] schema is missing. Run create-mail-schema.sql first.', 16, 1);
    SET NOEXEC ON;
END;
GO

IF OBJECT_ID(N'[mail].[rfq_notifications]', N'U') IS NULL
BEGIN
    CREATE TABLE [mail].[rfq_notifications] (
        [id]              CHAR(32)       NOT NULL,
        [user_id]         BIGINT         NOT NULL,
        [draft_public_id] NVARCHAR(64)   NOT NULL,
        [title]           NVARCHAR(300)  NOT NULL,
        [body]            NVARCHAR(1000) NOT NULL,
        [link]            NVARCHAR(500)  NOT NULL,
        [created_at]      DATETIME2(6)   NOT NULL
            CONSTRAINT [DF_mail_rfq_notifications_created_at] DEFAULT (SYSUTCDATETIME()),
        [is_read]         BIT            NOT NULL
            CONSTRAINT [DF_mail_rfq_notifications_is_read] DEFAULT (0),
        CONSTRAINT [PK_mail_rfq_notifications] PRIMARY KEY CLUSTERED ([id])
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [name] = N'UX_mail_rfq_notifications_user_draft' AND [object_id] = OBJECT_ID(N'[mail].[rfq_notifications]'))
    CREATE UNIQUE INDEX [UX_mail_rfq_notifications_user_draft]
        ON [mail].[rfq_notifications] ([user_id], [draft_public_id]);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [name] = N'IX_mail_rfq_notifications_user_created' AND [object_id] = OBJECT_ID(N'[mail].[rfq_notifications]'))
    CREATE INDEX [IX_mail_rfq_notifications_user_created]
        ON [mail].[rfq_notifications] ([user_id], [created_at] DESC);
GO

SET NOEXEC OFF;
GO
