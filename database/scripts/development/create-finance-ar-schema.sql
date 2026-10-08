/*
================================================================================
  Tri-AR's tables in [finance] (Phase 5, task T5.2). Written 8 Oct 2026.
  Additive to create-finance-schema.sql and create-finance-attachments-schema.sql;
  run after both.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  Eight tables, shaped on Tri-AR's own SQLite tables (inventory/tri-ar.md
  section 3, server/store.py at 6b54894), with the column names kept so the
  migration maps column to column (decision D40):

    [finance].[ar_email_records]       one row per mailbox message
    [finance].[ar_email_attachments]   kept attachments and the extracted payment
                                       header; the file itself is in
                                       [finance].[attachments] (decision D41)
    [finance].[ar_remittance_lines]    the invoices a payment settles
    [finance].[ar_outbound_messages]   replies sent from the composer: a link to
                                       [finance].[mail_deliveries] that keeps the
                                       body (decision D42)
    [finance].[ar_credit_note_requests] the tokenised credit note workflow; only
                                       a HASH of the token is stored (question Q6)
    [finance].[ar_scan_runs]           scan history, watermarks and the claim
    [finance].[ar_option_lists]        the Category and Method lists (Settings)
    [finance].[ar_deleted_messages]    tombstones for permanently deleted mail

  What changed from the source, and why:

    - Conventions (decision D8): ids are CHAR(32) lower-case hex generated in PHP;
      timestamps are DATETIME2(6) in UTC with a SYSUTCDATETIME() default; text is
      NVARCHAR; money is DECIMAL(18,2); flags are BIT. [due_date] is a DATE (the
      source kept text, compared as its first ten characters).
    - Overdue is NOT a stored status (question Q8): [follow_up_status] is
      needs_review, in_progress or completed, and the check refuses overdue.
      Overdue is derived on read.
    - [status] allows a fourth value, skipped, which the register's "skip" action
      writes and the inventory did not list.
    - [subject], [customer_name] and [sender_name] are NVARCHAR(400) so they can
      be sorted (question Q9). [body] and the other long text are NVARCHAR(MAX)
      and are never sorted on. There is an index on [deleted_at], which every list
      filters on and the source never indexed.
    - [source_message_id] holds a Graph IMMUTABLE message id (question Q1). It is
      nullable, because a migrated record whose message has gone has none, and
      [message_unreachable] says so. It is unique only where present (a filtered
      unique index), which is also the backstop for two scans ingesting the same
      message (decision D45).
    - [assigned_person] becomes [assigned_user_id] BIGINT NULL plus the name at
      the time, [assigned_name] (decision D43). A migrated free-text assignee
      lives in the name alone. Every person column holds identity.Users.UserId
      (decision D7) with no foreign key outside [finance].
    - [ar_email_attachments] points at [finance].[attachments] by id and keeps no
      file name, stored name, type or size: the file store holds them. Two files
      with one name in one message are two files (the source overwrote one).
    - [ar_remittance_lines] gains [line_no], because the source ordered the lines
      by SQLite's rowid and SQL Server has none.
    - [ar_outbound_messages] keeps [body] (decision D42) and takes the subject,
      recipients, status, error and sent time from the mail ledger. It is written
      after the send, so [delivery_id] is always known.
    - [ar_credit_note_requests] has [token_hash] CHAR(64), the SHA-256 of the
      token in hex, and no token column: the link is shown once, when the request
      is made (question Q6).
    - [ar_scan_runs] holds a claim (decision D45): a filtered unique index allows
      one run with status running per mailbox, and [claim_expires_at] lets a later
      task fail a run that died holding it.
    - Foreign keys inside [finance] only, with no cascades: a permanent delete
      removes the children first, in code, in one transaction.

  No ORDER BY on an NVARCHAR(MAX) column anywhere: every list sorts on a bounded
  column.

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

    IF OBJECT_ID(N'[finance].[ar_email_records]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ar_email_records] (
            [id]                       CHAR(32)       NOT NULL,
            [email_date]               DATETIME2(6)   NOT NULL,
            [customer_name]            NVARCHAR(400)  NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_customer_name] DEFAULT (N''),
            [sender_email]             NVARCHAR(320)  NOT NULL,
            [sender_name]              NVARCHAR(400)  NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_sender_name] DEFAULT (N''),
            [subject]                  NVARCHAR(400)  NOT NULL,
            [body]                     NVARCHAR(MAX)  NULL,
            [ai_summary]               NVARCHAR(MAX)  NULL,
            [follow_up_category]       NVARCHAR(100)  NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_follow_up_category] DEFAULT (N'other'),
            [assigned_user_id]         BIGINT         NULL,
            [assigned_name]            NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_assigned_name] DEFAULT (N''),
            [proposed_response_type]   NVARCHAR(100)  NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_proposed_response_type] DEFAULT (N'email'),
            [proposed_response]        NVARCHAR(MAX)  NULL,
            [follow_up_status]         NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_follow_up_status] DEFAULT (N'in_progress'),
            [due_date]                 DATE           NULL,
            [final_outcome]            NVARCHAR(MAX)  NULL,
            [source_message_id]        NVARCHAR(200)  NULL,
            [message_unreachable]      BIT            NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_message_unreachable] DEFAULT (0),
            [mailbox]                  NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_mailbox] DEFAULT (N'ar@tricab.com'),
            [classification_confidence] DECIMAL(9,8)  NULL,
            [status]                   NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_status] DEFAULT (N'new'),
            [assigned_sales_rep_email] NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_assigned_sales_rep_email] DEFAULT (N''),
            [credit_note_request_id]   CHAR(32)       NULL,
            [reminder_sent_at]         DATETIME2(6)   NULL,
            [deleted_at]               DATETIME2(6)   NULL,
            [deleted_by_user_id]       BIGINT         NULL,
            [deleted_by]               NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_deleted_by] DEFAULT (N''),
            [created_date]             DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date]             DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_email_records_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ar_email_records] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_ar_email_records_follow_up_status]
                CHECK ([follow_up_status] IN (N'needs_review', N'in_progress', N'completed')),
            CONSTRAINT [CK_finance_ar_email_records_status]
                CHECK ([status] IN (N'new', N'reviewed', N'sent', N'skipped')),
            CONSTRAINT [CK_finance_ar_email_records_confidence]
                CHECK ([classification_confidence] IS NULL OR [classification_confidence] BETWEEN 0 AND 1)
        );

        CREATE UNIQUE INDEX [UX_finance_ar_email_records_source_message_id]
            ON [finance].[ar_email_records] ([source_message_id])
            WHERE [source_message_id] IS NOT NULL;

        CREATE INDEX [IX_finance_ar_email_records_email_date]
            ON [finance].[ar_email_records] ([email_date], [id]);

        CREATE INDEX [IX_finance_ar_email_records_deleted_at]
            ON [finance].[ar_email_records] ([deleted_at]);

        CREATE INDEX [IX_finance_ar_email_records_category]
            ON [finance].[ar_email_records] ([follow_up_category]);

        CREATE INDEX [IX_finance_ar_email_records_follow_up_status]
            ON [finance].[ar_email_records] ([follow_up_status]);

        CREATE INDEX [IX_finance_ar_email_records_due_date]
            ON [finance].[ar_email_records] ([due_date])
            WHERE [due_date] IS NOT NULL;
    END;

    IF OBJECT_ID(N'[finance].[ar_email_attachments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ar_email_attachments] (
            [id]                    CHAR(32)       NOT NULL,
            [email_record_id]       CHAR(32)       NOT NULL,
            [attachment_id]         CHAR(32)       NOT NULL,
            [is_remittance]         BIT            NOT NULL
                CONSTRAINT [DF_finance_ar_email_attachments_is_remittance] DEFAULT (0),
            [payer_name]            NVARCHAR(400)  NULL,
            [payment_date]          NVARCHAR(40)   NULL,
            [payment_reference]     NVARCHAR(200)  NULL,
            [currency]              NVARCHAR(10)   NOT NULL
                CONSTRAINT [DF_finance_ar_email_attachments_currency] DEFAULT (N'AUD'),
            [total_amount]          DECIMAL(18,2)  NULL,
            [extraction_confidence] DECIMAL(9,8)   NULL,
            [reconciles]            BIT            NULL,
            [extraction_status]     NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_ar_email_attachments_extraction_status] DEFAULT (N'pending'),
            [extraction_error]      NVARCHAR(1000) NULL,
            [notes]                 NVARCHAR(MAX)  NULL,
            [extracted_at]          DATETIME2(6)   NULL,
            [created_date]          DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_email_attachments_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date]          DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_email_attachments_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ar_email_attachments] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ar_email_attachments_record]
                FOREIGN KEY ([email_record_id]) REFERENCES [finance].[ar_email_records] ([id]),
            CONSTRAINT [FK_finance_ar_email_attachments_file]
                FOREIGN KEY ([attachment_id]) REFERENCES [finance].[attachments] ([id]),
            CONSTRAINT [CK_finance_ar_email_attachments_extraction_status]
                CHECK ([extraction_status] IN (N'pending', N'extracted', N'failed', N'skipped')),
            CONSTRAINT [CK_finance_ar_email_attachments_confidence]
                CHECK ([extraction_confidence] IS NULL OR [extraction_confidence] BETWEEN 0 AND 1)
        );

        CREATE UNIQUE INDEX [UX_finance_ar_email_attachments_file]
            ON [finance].[ar_email_attachments] ([attachment_id]);

        CREATE INDEX [IX_finance_ar_email_attachments_record]
            ON [finance].[ar_email_attachments] ([email_record_id]);

        CREATE INDEX [IX_finance_ar_email_attachments_status]
            ON [finance].[ar_email_attachments] ([extraction_status]);
    END;

    IF OBJECT_ID(N'[finance].[ar_remittance_lines]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ar_remittance_lines] (
            [id]                  CHAR(32)       NOT NULL,
            [email_attachment_id] CHAR(32)       NOT NULL,
            [email_record_id]     CHAR(32)       NOT NULL,
            [line_no]             INT            NOT NULL
                CONSTRAINT [DF_finance_ar_remittance_lines_line_no] DEFAULT (0),
            [invoice_number]      NVARCHAR(100)  NULL,
            [invoice_date]        NVARCHAR(40)   NULL,
            [gross_amount]        DECIMAL(18,2)  NULL,
            [discount_amount]     DECIMAL(18,2)  NULL,
            [paid_amount]         DECIMAL(18,2)  NULL,
            [created_date]        DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_remittance_lines_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ar_remittance_lines] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ar_remittance_lines_attachment]
                FOREIGN KEY ([email_attachment_id]) REFERENCES [finance].[ar_email_attachments] ([id])
        );

        CREATE INDEX [IX_finance_ar_remittance_lines_attachment]
            ON [finance].[ar_remittance_lines] ([email_attachment_id], [line_no]);

        CREATE INDEX [IX_finance_ar_remittance_lines_record]
            ON [finance].[ar_remittance_lines] ([email_record_id]);

        CREATE INDEX [IX_finance_ar_remittance_lines_invoice]
            ON [finance].[ar_remittance_lines] ([invoice_number]);
    END;

    IF OBJECT_ID(N'[finance].[ar_outbound_messages]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ar_outbound_messages] (
            [id]              CHAR(32)       NOT NULL,
            [email_record_id] CHAR(32)       NOT NULL,
            [delivery_id]     CHAR(32)       NOT NULL,
            [sender_user_id]  BIGINT         NULL,
            [sender_user]     NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_ar_outbound_messages_sender_user] DEFAULT (N''),
            [threaded]        BIT            NOT NULL
                CONSTRAINT [DF_finance_ar_outbound_messages_threaded] DEFAULT (0),
            [body]            NVARCHAR(MAX)  NOT NULL,
            [created_date]    DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_outbound_messages_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ar_outbound_messages] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ar_outbound_messages_record]
                FOREIGN KEY ([email_record_id]) REFERENCES [finance].[ar_email_records] ([id])
        );

        CREATE INDEX [IX_finance_ar_outbound_messages_record]
            ON [finance].[ar_outbound_messages] ([email_record_id], [created_date]);
    END;

    IF OBJECT_ID(N'[finance].[ar_credit_note_requests]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ar_credit_note_requests] (
            [id]               CHAR(32)       NOT NULL,
            [email_record_id]  CHAR(32)       NULL,
            [customer_name]    NVARCHAR(400)  NOT NULL,
            [customer_email]   NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_ar_credit_note_requests_customer_email] DEFAULT (N''),
            [sales_rep_name]   NVARCHAR(400)  NOT NULL
                CONSTRAINT [DF_finance_ar_credit_note_requests_sales_rep_name] DEFAULT (N''),
            [sales_rep_email]  NVARCHAR(320)  NOT NULL,
            [invoice_number]   NVARCHAR(100)  NOT NULL
                CONSTRAINT [DF_finance_ar_credit_note_requests_invoice_number] DEFAULT (N''),
            [credit_amount]    DECIMAL(18,2)  NOT NULL
                CONSTRAINT [DF_finance_ar_credit_note_requests_credit_amount] DEFAULT (0),
            [reason]           NVARCHAR(MAX)  NULL,
            [original_request] NVARCHAR(MAX)  NULL,
            [token_hash]       CHAR(64)       NOT NULL,
            [token_expires_at] DATETIME2(6)   NOT NULL,
            [status]           NVARCHAR(30)   NOT NULL
                CONSTRAINT [DF_finance_ar_credit_note_requests_status] DEFAULT (N'form_sent'),
            [form_sent_date]   DATETIME2(6)   NULL,
            [submitted_date]   DATETIME2(6)   NULL,
            [reviewed_at]      DATETIME2(6)   NULL,
            [created_date]     DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_credit_note_requests_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date]     DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_credit_note_requests_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ar_credit_note_requests] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_ar_credit_note_requests_status]
                CHECK ([status] IN (N'form_sent', N'awaiting_sales_response', N'submitted', N'under_review', N'approved', N'rejected', N'completed'))
        );

        CREATE UNIQUE INDEX [UX_finance_ar_credit_note_requests_token_hash]
            ON [finance].[ar_credit_note_requests] ([token_hash]);

        CREATE INDEX [IX_finance_ar_credit_note_requests_status]
            ON [finance].[ar_credit_note_requests] ([status]);

        CREATE INDEX [IX_finance_ar_credit_note_requests_created]
            ON [finance].[ar_credit_note_requests] ([created_date], [id]);
    END;

    IF OBJECT_ID(N'[finance].[ar_scan_runs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ar_scan_runs] (
            [id]                  CHAR(32)       NOT NULL,
            [started_at]          DATETIME2(6)   NOT NULL,
            [finished_at]         DATETIME2(6)   NULL,
            [mailbox]             NVARCHAR(320)  NOT NULL,
            [status]              NVARCHAR(20)   NOT NULL,
            [watermark]           DATETIME2(6)   NULL,
            [total_fetched]       INT            NOT NULL
                CONSTRAINT [DF_finance_ar_scan_runs_total_fetched] DEFAULT (0),
            [new_records]         INT            NOT NULL
                CONSTRAINT [DF_finance_ar_scan_runs_new_records] DEFAULT (0),
            [skipped_records]     INT            NOT NULL
                CONSTRAINT [DF_finance_ar_scan_runs_skipped_records] DEFAULT (0),
            [failed_enrichments]  INT            NOT NULL
                CONSTRAINT [DF_finance_ar_scan_runs_failed_enrichments] DEFAULT (0),
            [errors]              NVARCHAR(MAX)  NULL,
            [from_date]           DATE           NULL,
            [to_date]             DATE           NULL,
            [requested_by_user_id] BIGINT        NULL,
            [requested_by]        NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_ar_scan_runs_requested_by] DEFAULT (N''),
            [total_messages]      INT            NOT NULL
                CONSTRAINT [DF_finance_ar_scan_runs_total_messages] DEFAULT (0),
            [processed_messages]  INT            NOT NULL
                CONSTRAINT [DF_finance_ar_scan_runs_processed_messages] DEFAULT (0),
            [claim_expires_at]    DATETIME2(6)   NULL,
            [created_date]        DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_scan_runs_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ar_scan_runs] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_ar_scan_runs_status]
                CHECK ([status] IN (N'running', N'completed', N'failed', N'stopped'))
        );

        CREATE UNIQUE INDEX [UX_finance_ar_scan_runs_one_running]
            ON [finance].[ar_scan_runs] ([mailbox])
            WHERE [status] = N'running';

        CREATE INDEX [IX_finance_ar_scan_runs_mailbox_started]
            ON [finance].[ar_scan_runs] ([mailbox], [started_at]);
    END;

    IF OBJECT_ID(N'[finance].[ar_option_lists]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ar_option_lists] (
            [list_name]    NVARCHAR(20)   NOT NULL,
            [value]        NVARCHAR(100)  NOT NULL,
            [label]        NVARCHAR(200)  NOT NULL,
            [description]  NVARCHAR(2000) NOT NULL
                CONSTRAINT [DF_finance_ar_option_lists_description] DEFAULT (N''),
            [color]        NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_ar_option_lists_color] DEFAULT (N'slate'),
            [sort_order]   INT            NOT NULL
                CONSTRAINT [DF_finance_ar_option_lists_sort_order] DEFAULT (0),
            [active]       BIT            NOT NULL
                CONSTRAINT [DF_finance_ar_option_lists_active] DEFAULT (1),
            [is_system]    BIT            NOT NULL
                CONSTRAINT [DF_finance_ar_option_lists_is_system] DEFAULT (0),
            [created_date] DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_option_lists_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date] DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ar_option_lists_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ar_option_lists] PRIMARY KEY CLUSTERED ([list_name], [value]),
            CONSTRAINT [CK_finance_ar_option_lists_list_name]
                CHECK ([list_name] IN (N'category', N'method'))
        );
    END;

    IF OBJECT_ID(N'[finance].[ar_deleted_messages]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ar_deleted_messages] (
            [source_message_id] NVARCHAR(200) NOT NULL,
            [deleted_date]      DATETIME2(6)  NOT NULL
                CONSTRAINT [DF_finance_ar_deleted_messages_deleted_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ar_deleted_messages] PRIMARY KEY CLUSTERED ([source_message_id])
        );
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
