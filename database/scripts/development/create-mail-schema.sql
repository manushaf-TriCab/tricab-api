/*
================================================================================
  The mail review tables in [mail] (inbox integration plan I2, item 2.1).
  Written 18 Oct 2026.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 0.2). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  What it holds (decision 0.1, approved 18 Oct): a message's metadata, Graph's
  bodyPreview and the classifier's result. Bodies and attachment bytes are NOT
  stored: they are fetched from Graph by immutable id when someone opens the
  message, so retention stays with Exchange.

    [mail].[mailboxes]        a mailbox we read (melbourne@ first)
    [mail].[threads]          one row per conversation in a mailbox
    [mail].[messages]         one row per message; internetMessageId unique per mailbox
    [mail].[attachments]      names, types and sizes only; never the bytes
    [mail].[assessments]      the classifier's results, kept as history; the latest wins
    [mail].[label_decisions]  people marking a label right or wrong, kept as history
    [mail].[activity]         the Activity tab: reader, classifier and review events
    [mail].[message_links]    thread -> the RFQ draft made from it

  Threads. A thread is Graph's conversationId within one mailbox: the key is
  (mailbox_id, conversation_id), because the id is only unique inside a mailbox
  and the reader already has it on every message, so nothing has to be guessed
  from subjects. The thread is a real row, not a GROUP BY, for two reasons: the
  review state ("open", "drafted", "ignored") and the links belong to the
  conversation and not to any one message, and the list, the queue counts and
  the sort all read one indexed table instead of aggregating messages and
  assessments on every request. The summary columns on [threads] (latest
  sender, preview, counts, label, decision) are a projection the application
  rewrites in the same transaction as every change; nothing else writes them.
  A message with no conversationId (Graph omits it rarely) gets a thread of its
  own, keyed on its internetMessageId.

  Conventions follow the finance schema: ids are CHAR(32) lower-case hex made in
  PHP; timestamps are DATETIME2(6) UTC with a SYSUTCDATETIME() default; people
  are BIGINT holding identity.Users.UserId with the name and address kept as
  they were when the person acted, and no foreign key outside [mail], so a
  mail-only restore stays possible. Recipients are JSON text (checked with
  ISJSON), as the other modules keep small lists nobody queries; attachments are
  a child table because each needs an id the browser can ask for.

  No ORDER BY on an NVARCHAR(MAX) column anywhere.

  Guarded and idempotent: each table is created only if missing, in one
  transaction, and a second run changes nothing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF SCHEMA_ID(N'mail') IS NULL
    EXEC (N'CREATE SCHEMA [mail];');
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[mail].[mailboxes]', N'U') IS NULL
    BEGIN
        CREATE TABLE [mail].[mailboxes] (
            [id]           CHAR(32)      NOT NULL,
            [address]      NVARCHAR(320) NOT NULL,
            [display_name] NVARCHAR(200) NOT NULL,
            [connection]   NVARCHAR(12)  NOT NULL
                CONSTRAINT [DF_mail_mailboxes_connection] DEFAULT (N'connected'),
            [last_sync_at] DATETIME2(6)  NULL,
            [created_at]   DATETIME2(6)  NOT NULL
                CONSTRAINT [DF_mail_mailboxes_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_mail_mailboxes] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_mail_mailboxes_connection]
                CHECK ([connection] IN (N'connected', N'paused', N'error'))
        );

        CREATE UNIQUE INDEX [UX_mail_mailboxes_address]
            ON [mail].[mailboxes] ([address]);
    END;

    IF OBJECT_ID(N'[mail].[threads]', N'U') IS NULL
    BEGIN
        CREATE TABLE [mail].[threads] (
            [id]                    CHAR(32)       NOT NULL,
            [mailbox_id]            CHAR(32)       NOT NULL,
            [conversation_id]       NVARCHAR(450)  NOT NULL,
            [subject]               NVARCHAR(500)  NOT NULL,
            [review_state]          NVARCHAR(10)   NOT NULL
                CONSTRAINT [DF_mail_threads_review_state] DEFAULT (N'open'),
            -- The projection below is rewritten with every change to the thread's messages,
            -- assessments or decisions (see the header). A thread with no assessment yet has
            -- NULL label columns and is kept out of every list until it is classified.
            [latest_at]             DATETIME2(6)   NOT NULL,
            [message_count]         INT            NOT NULL,
            [unread_count]          INT            NOT NULL,
            [has_attachments]       BIT            NOT NULL
                CONSTRAINT [DF_mail_threads_has_attachments] DEFAULT (0),
            [from_name]             NVARCHAR(200)  NOT NULL,
            [from_address]          NVARCHAR(320)  NOT NULL,
            [preview]               NVARCHAR(1000) NOT NULL,
            [assessment_id]         CHAR(32)       NULL,
            [label]                 NVARCHAR(16)   NULL,
            [confidence]            DECIMAL(5,4)   NULL,
            [skipped]               BIT            NOT NULL
                CONSTRAINT [DF_mail_threads_skipped] DEFAULT (0),
            [injection_suspected]   BIT            NOT NULL
                CONSTRAINT [DF_mail_threads_injection] DEFAULT (0),
            [decision_id]           CHAR(32)       NULL,
            [effective_label]       NVARCHAR(16)   NULL,
            [created_at]            DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_mail_threads_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at]            DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_mail_threads_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_mail_threads] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_mail_threads_mailbox]
                FOREIGN KEY ([mailbox_id]) REFERENCES [mail].[mailboxes] ([id]),
            CONSTRAINT [CK_mail_threads_review_state]
                CHECK ([review_state] IN (N'open', N'drafted', N'ignored')),
            CONSTRAINT [CK_mail_threads_label]
                CHECK ([label] IS NULL OR [label] IN (N'rfq', N'rfq_followup', N'not_rfq', N'uncertain')),
            CONSTRAINT [CK_mail_threads_effective_label]
                CHECK ([effective_label] IS NULL OR [effective_label] IN (N'rfq', N'rfq_followup', N'not_rfq', N'uncertain'))
        );

        CREATE UNIQUE INDEX [UX_mail_threads_conversation]
            ON [mail].[threads] ([mailbox_id], [conversation_id]);

        CREATE INDEX [IX_mail_threads_latest]
            ON [mail].[threads] ([latest_at] DESC, [id])
            WHERE [label] IS NOT NULL;

        CREATE INDEX [IX_mail_threads_effective_label]
            ON [mail].[threads] ([effective_label], [latest_at] DESC)
            WHERE [label] IS NOT NULL;
    END;

    IF OBJECT_ID(N'[mail].[messages]', N'U') IS NULL
    BEGIN
        CREATE TABLE [mail].[messages] (
            [id]                  CHAR(32)       NOT NULL,
            [mailbox_id]          CHAR(32)       NOT NULL,
            [thread_id]           CHAR(32)       NOT NULL,
            [graph_message_id]    NVARCHAR(450)  NOT NULL,
            [internet_message_id] NVARCHAR(450)  NOT NULL,
            [subject]             NVARCHAR(500)  NOT NULL,
            [from_name]           NVARCHAR(200)  NOT NULL,
            [from_address]        NVARCHAR(320)  NOT NULL,
            [to_json]             NVARCHAR(MAX)  NOT NULL,
            [cc_json]             NVARCHAR(MAX)  NOT NULL,
            [received_at]         DATETIME2(6)   NOT NULL,
            [body_preview]        NVARCHAR(1000) NOT NULL,
            [is_read]             BIT            NOT NULL
                CONSTRAINT [DF_mail_messages_is_read] DEFAULT (0),
            [created_at]          DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_mail_messages_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_mail_messages] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_mail_messages_mailbox]
                FOREIGN KEY ([mailbox_id]) REFERENCES [mail].[mailboxes] ([id]),
            CONSTRAINT [FK_mail_messages_thread]
                FOREIGN KEY ([thread_id]) REFERENCES [mail].[threads] ([id]),
            CONSTRAINT [CK_mail_messages_to_json] CHECK (ISJSON([to_json]) = 1),
            CONSTRAINT [CK_mail_messages_cc_json] CHECK (ISJSON([cc_json]) = 1)
        );

        -- The dedupe key (RFQ plan 3.3): Graph can deliver a message again.
        CREATE UNIQUE INDEX [UX_mail_messages_internet_id]
            ON [mail].[messages] ([mailbox_id], [internet_message_id]);

        CREATE UNIQUE INDEX [UX_mail_messages_graph_id]
            ON [mail].[messages] ([mailbox_id], [graph_message_id]);

        CREATE INDEX [IX_mail_messages_thread]
            ON [mail].[messages] ([thread_id], [received_at]);
    END;

    IF OBJECT_ID(N'[mail].[attachments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [mail].[attachments] (
            [id]                   CHAR(32)       NOT NULL,
            [message_id]           CHAR(32)       NOT NULL,
            [graph_attachment_id]  NVARCHAR(450)  NOT NULL,
            [file_name]            NVARCHAR(500)  NOT NULL,
            [content_type]         NVARCHAR(200)  NOT NULL,
            [size_bytes]           BIGINT         NOT NULL,
            [is_inline]            BIT            NOT NULL
                CONSTRAINT [DF_mail_attachments_is_inline] DEFAULT (0),
            CONSTRAINT [PK_mail_attachments] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_mail_attachments_message]
                FOREIGN KEY ([message_id]) REFERENCES [mail].[messages] ([id]),
            CONSTRAINT [CK_mail_attachments_size] CHECK ([size_bytes] >= 0)
        );

        CREATE UNIQUE INDEX [UX_mail_attachments_graph_id]
            ON [mail].[attachments] ([message_id], [graph_attachment_id]);
    END;

    IF OBJECT_ID(N'[mail].[assessments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [mail].[assessments] (
            [id]                  CHAR(32)       NOT NULL,
            [message_id]          CHAR(32)       NOT NULL,
            [label]               NVARCHAR(16)   NOT NULL,
            [confidence]          DECIMAL(5,4)   NOT NULL,
            [reason]              NVARCHAR(1000) NOT NULL,
            [model]               NVARCHAR(100)  NOT NULL,
            [latency_ms]          INT            NULL,
            [version]             NVARCHAR(100)  NOT NULL,
            [skipped]             BIT            NOT NULL
                CONSTRAINT [DF_mail_assessments_skipped] DEFAULT (0),
            [skip_reason]         NVARCHAR(200)  NULL,
            [injection_suspected] BIT            NOT NULL
                CONSTRAINT [DF_mail_assessments_injection] DEFAULT (0),
            [assessed_at]         DATETIME2(6)   NOT NULL,
            CONSTRAINT [PK_mail_assessments] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_mail_assessments_message]
                FOREIGN KEY ([message_id]) REFERENCES [mail].[messages] ([id]),
            CONSTRAINT [CK_mail_assessments_label]
                CHECK ([label] IN (N'rfq', N'rfq_followup', N'not_rfq', N'uncertain')),
            CONSTRAINT [CK_mail_assessments_confidence]
                CHECK ([confidence] >= 0 AND [confidence] <= 1),
            -- A pre-filter skip never ran the model, and says why.
            CONSTRAINT [CK_mail_assessments_skip]
                CHECK ([skipped] = 0 OR [skip_reason] IS NOT NULL)
        );

        CREATE INDEX [IX_mail_assessments_message]
            ON [mail].[assessments] ([message_id], [assessed_at]);
    END;

    IF OBJECT_ID(N'[mail].[label_decisions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [mail].[label_decisions] (
            [id]                  CHAR(32)       NOT NULL,
            [thread_id]           CHAR(32)       NOT NULL,
            [assessment_id]       CHAR(32)       NOT NULL,
            [kind]                NVARCHAR(10)   NOT NULL,
            [label]               NVARCHAR(16)   NOT NULL,
            [decided_by_user_id]  BIGINT         NOT NULL,
            [decided_by_name]     NVARCHAR(200)  NOT NULL,
            [decided_by_email]    NVARCHAR(320)  NOT NULL,
            [note]                NVARCHAR(1000) NULL,
            [decided_at]          DATETIME2(6)   NOT NULL,
            CONSTRAINT [PK_mail_label_decisions] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_mail_label_decisions_thread]
                FOREIGN KEY ([thread_id]) REFERENCES [mail].[threads] ([id]),
            CONSTRAINT [FK_mail_label_decisions_assessment]
                FOREIGN KEY ([assessment_id]) REFERENCES [mail].[assessments] ([id]),
            CONSTRAINT [CK_mail_label_decisions_kind]
                CHECK ([kind] IN (N'confirm', N'correct')),
            CONSTRAINT [CK_mail_label_decisions_label]
                CHECK ([label] IN (N'rfq', N'rfq_followup', N'not_rfq', N'uncertain'))
        );

        CREATE INDEX [IX_mail_label_decisions_thread]
            ON [mail].[label_decisions] ([thread_id], [decided_at]);
    END;

    IF OBJECT_ID(N'[mail].[activity]', N'U') IS NULL
    BEGIN
        CREATE TABLE [mail].[activity] (
            [id]             CHAR(32)       NOT NULL,
            [thread_id]      CHAR(32)       NOT NULL,
            [message_id]     CHAR(32)       NULL,
            [kind]           NVARCHAR(20)   NOT NULL,
            -- NULL actor: the reader or the classifier ("system").
            [actor_user_id]  BIGINT         NULL,
            [actor_name]     NVARCHAR(200)  NULL,
            [actor_email]    NVARCHAR(320)  NULL,
            [description]    NVARCHAR(1000) NOT NULL,
            [created_at]     DATETIME2(6)   NOT NULL,
            CONSTRAINT [PK_mail_activity] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_mail_activity_thread]
                FOREIGN KEY ([thread_id]) REFERENCES [mail].[threads] ([id]),
            CONSTRAINT [CK_mail_activity_kind]
                CHECK ([kind] IN (N'received', N'classified', N'skipped', N'label_confirmed', N'label_corrected', N'draft_created', N'ignored'))
        );

        CREATE INDEX [IX_mail_activity_thread]
            ON [mail].[activity] ([thread_id], [created_at]);
    END;

    IF OBJECT_ID(N'[mail].[message_links]', N'U') IS NULL
    BEGIN
        CREATE TABLE [mail].[message_links] (
            [id]                 CHAR(32)       NOT NULL,
            [thread_id]          CHAR(32)       NOT NULL,
            [message_id]         CHAR(32)       NULL,
            [kind]               NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_mail_message_links_kind] DEFAULT (N'rfq_draft'),
            -- The RFQ draft's public id (a dashed uuid). No foreign key into [rfq].
            [rfq_public_id]      NVARCHAR(36)   NOT NULL,
            [reference]          NVARCHAR(100)  NOT NULL,
            [title]              NVARCHAR(500)  NOT NULL,
            [created_by_user_id] BIGINT         NULL,
            [created_at]         DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_mail_message_links_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_mail_message_links] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_mail_message_links_thread]
                FOREIGN KEY ([thread_id]) REFERENCES [mail].[threads] ([id]),
            CONSTRAINT [CK_mail_message_links_kind] CHECK ([kind] IN (N'rfq_draft'))
        );

        CREATE UNIQUE INDEX [UX_mail_message_links_draft]
            ON [mail].[message_links] ([thread_id], [rfq_public_id]);
    END;

    COMMIT TRANSACTION;

    SELECT
        (SELECT COUNT(*) FROM sys.tables WHERE [schema_id] = SCHEMA_ID(N'mail')) AS [MailTables],
        (SELECT COUNT(*) FROM sys.indexes i
           JOIN sys.tables t ON t.[object_id] = i.[object_id]
          WHERE t.[schema_id] = SCHEMA_ID(N'mail') AND i.[index_id] > 0) AS [MailIndexes];
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
