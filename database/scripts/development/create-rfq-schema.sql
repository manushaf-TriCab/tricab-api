/*
================================================================================
  RFQ store: [rfq] schema on Azure SQL, shaped to the live RFQ store so its data
  moves across unchanged (plan 2.2).

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Source of truth: the live MySQL store, tc-mysql-live / tc (the old app's
  RFQ_STORE_BACKEND=mysql since 11 Sep), read from information_schema on
  28 Sep 2026. Owner decision, 28 Sep: follow that schema, table for table,
  with the same table, column, index and foreign-key names.

    - 15 tables, 28 secondary indexes, 4 foreign keys, as in MySQL.
    - Types: varchar(n) -> NVARCHAR(n); text/longtext -> NVARCHAR(MAX);
      char(n) -> CHAR(n) (hex ids and the SHA-256, ASCII only); int, double
      and tinyint -> INT, FLOAT, TINYINT. NVARCHAR(n) counts UTF-16 units; the
      live data has no characters that take two, and every value is under its
      column's size (longest `correction_memory.normalized_text` 117 of 400).
    - Timestamps stay text, as MySQL stores them: ISO-8601 UTC with an offset.
      Exception: 39 `rfq_draft_quotes.due_at` values from 8-17 Jul read
      `YYYY-MM-DD HH:MM:SS` with no zone. They are carried as they are.
    - No ISJSON checks: MySQL has none, and 7 `row_data` and 11
      `system_row_data` values hold Python's NaN, which is not valid JSON.
    - `rfq_correction_memory`'s key is NVARCHAR(400) + NVARCHAR(50), 900 bytes:
      exactly SQL Server's clustered-key limit.

  Replaces the seven empty tables a 5 Aug run of the old app's own SQL Server
  script left here. Run drop-rfq-legacy-tables.sql first; this script refuses
  while any of them remains.

  Guarded and idempotent: each table is created only if missing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF SCHEMA_ID(N'rfq') IS NULL
    EXEC (N'CREATE SCHEMA [rfq] AUTHORIZATION [dbo];');
GO

BEGIN TRY
    BEGIN TRANSACTION;

    -- The 5 Aug tables reuse five of these names with an older shape, so an
    -- existence check alone would skip them silently. They are told apart by
    -- names only one shape has: correction_memory and mail_audit are old;
    -- rfq_sync_attempts is new, and this script creates all fifteen or none.
    IF OBJECT_ID(N'[rfq].[correction_memory]', N'U') IS NOT NULL
       OR OBJECT_ID(N'[rfq].[mail_audit]', N'U') IS NOT NULL
       OR (OBJECT_ID(N'[rfq].[rfq_draft_quotes]', N'U') IS NOT NULL
           AND OBJECT_ID(N'[rfq].[rfq_sync_attempts]', N'U') IS NULL)
        THROW 50001, N'The 5 Aug RFQ tables are still here. Run drop-rfq-legacy-tables.sql first.', 1;

    IF OBJECT_ID(N'[rfq].[rfq_roles]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_roles] (
            [role]         NVARCHAR(64)   NOT NULL,
            [description]  NVARCHAR(MAX)  NULL,
            [draft_scope]  NVARCHAR(16)   NOT NULL
                CONSTRAINT [DF_rfq_roles_draft_scope] DEFAULT (N'own'),
            [permissions]  NVARCHAR(MAX)  NOT NULL,
            [created_at]   NVARCHAR(40)   NOT NULL,
            [updated_at]   NVARCHAR(40)   NOT NULL,
            CONSTRAINT [PK_rfq_roles] PRIMARY KEY CLUSTERED ([role])
        );
    END;

    IF OBJECT_ID(N'[rfq].[rfq_user_roles]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_user_roles] (
            [email]       NVARCHAR(255)  NOT NULL,
            [role]        NVARCHAR(64)   NOT NULL,
            [branch]      NVARCHAR(32)   NULL,
            [note]        NVARCHAR(MAX)  NULL,
            [updated_at]  NVARCHAR(40)   NOT NULL,
            CONSTRAINT [PK_rfq_user_roles] PRIMARY KEY CLUSTERED ([email])
        );
    END;

    IF OBJECT_ID(N'[rfq].[rfq_identities]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_identities] (
            [email]       NVARCHAR(255)  NOT NULL,
            [login]       NVARCHAR(255)  NULL,
            [branch]      NVARCHAR(32)   NULL,
            [office]      NVARCHAR(64)   NULL,
            [job_title]   NVARCHAR(255)  NULL,
            [updated_at]  NVARCHAR(40)   NOT NULL,
            CONSTRAINT [PK_rfq_identities] PRIMARY KEY CLUSTERED ([email])
        );
    END;

    IF OBJECT_ID(N'[rfq].[rfq_draft_quotes]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_draft_quotes] (
            [id]                CHAR(32)       NOT NULL,
            [created_at]        NVARCHAR(40)   NOT NULL,
            [updated_at]        NVARCHAR(40)   NULL,
            [source]            NVARCHAR(32)   NOT NULL,
            [label]             NVARCHAR(MAX)  NULL,
            [status]            NVARCHAR(32)   NOT NULL
                CONSTRAINT [DF_rfq_draft_quotes_status] DEFAULT (N'new'),
            [quote_details]     NVARCHAR(MAX)  NOT NULL,
            [email_meta]        NVARCHAR(MAX)  NULL,
            [push_error]        NVARCHAR(MAX)  NULL,
            [refno]             NVARCHAR(64)   NULL,
            [refver]            NVARCHAR(32)   NULL,
            [synced_at]         NVARCHAR(40)   NULL,
            [sync_payload]      NVARCHAR(MAX)  NULL,
            [created_by_email]  NVARCHAR(255)  NULL,
            [branch]            NVARCHAR(32)   NULL,
            [assignee_email]    NVARCHAR(255)  NULL,
            [priority]          NVARCHAR(16)   NOT NULL
                CONSTRAINT [DF_rfq_draft_quotes_priority] DEFAULT (N'normal'),
            [due_at]            NVARCHAR(40)   NULL,
            [archived_at]       NVARCHAR(40)   NULL,
            [archived_by]       NVARCHAR(255)  NULL,
            [archive_reason]    NVARCHAR(MAX)  NULL,
            [revision]          INT            NOT NULL
                CONSTRAINT [DF_rfq_draft_quotes_revision] DEFAULT (1),
            [sync_started_at]   NVARCHAR(40)   NULL,
            [sync_revision]     INT            NULL,
            CONSTRAINT [PK_rfq_draft_quotes] PRIMARY KEY CLUSTERED ([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_draft_quotes_archived_at] ON [rfq].[rfq_draft_quotes]([archived_at]);
        CREATE NONCLUSTERED INDEX [rfq_draft_quotes_assignee] ON [rfq].[rfq_draft_quotes]([assignee_email]);
        CREATE NONCLUSTERED INDEX [rfq_draft_quotes_branch] ON [rfq].[rfq_draft_quotes]([branch]);
        CREATE NONCLUSTERED INDEX [rfq_draft_quotes_created_at] ON [rfq].[rfq_draft_quotes]([created_at]);
        CREATE NONCLUSTERED INDEX [rfq_draft_quotes_due_at] ON [rfq].[rfq_draft_quotes]([due_at]);
        CREATE NONCLUSTERED INDEX [rfq_draft_quotes_status] ON [rfq].[rfq_draft_quotes]([status]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_draft_rows]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_draft_rows] (
            [id]                     CHAR(32)       NOT NULL,
            [draft_id]               CHAR(32)       NOT NULL,
            [row_order]              INT            NOT NULL,
            [row_data]               NVARCHAR(MAX)  NOT NULL,
            [system_row_data]        NVARCHAR(MAX)  NULL,
            [system_snapshot_valid]  TINYINT        NOT NULL
                CONSTRAINT [DF_rfq_draft_rows_system_snapshot_valid] DEFAULT (1),
            [line_uid]               CHAR(32)       NULL,
            CONSTRAINT [PK_rfq_draft_rows] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [rfq_draft_rows_draft_fk] FOREIGN KEY ([draft_id]) REFERENCES [rfq].[rfq_draft_quotes]([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_draft_rows_draft_id] ON [rfq].[rfq_draft_rows]([draft_id]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_draft_activity]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_draft_activity] (
            [id]            CHAR(32)       NOT NULL,
            [draft_id]      CHAR(32)       NOT NULL,
            [created_at]    NVARCHAR(40)   NOT NULL,
            [actor_email]   NVARCHAR(255)  NULL,
            [actor_role]    NVARCHAR(64)   NULL,
            [actor_branch]  NVARCHAR(32)   NULL,
            [event_type]    NVARCHAR(64)   NOT NULL,
            [message]       NVARCHAR(MAX)  NULL,
            [detail]        NVARCHAR(MAX)  NULL,
            [revision]      INT            NULL,
            CONSTRAINT [PK_rfq_draft_activity] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [rfq_draft_activity_draft_fk] FOREIGN KEY ([draft_id]) REFERENCES [rfq].[rfq_draft_quotes]([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_draft_activity_created_at] ON [rfq].[rfq_draft_activity]([created_at]);
        CREATE NONCLUSTERED INDEX [rfq_draft_activity_draft_time] ON [rfq].[rfq_draft_activity]([draft_id], [created_at]);
        CREATE NONCLUSTERED INDEX [rfq_draft_activity_type_time] ON [rfq].[rfq_draft_activity]([event_type], [created_at]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_draft_row_edits]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_draft_row_edits] (
            [id]           CHAR(32)       NOT NULL,
            [draft_id]     CHAR(32)       NOT NULL,
            [line_uid]     CHAR(32)       NULL,
            [row_order]    INT            NOT NULL,
            [revision]     INT            NULL,
            [created_at]   NVARCHAR(40)   NOT NULL,
            [actor_email]  NVARCHAR(255)  NULL,
            [actor_role]   NVARCHAR(64)   NULL,
            [view_mode]    NVARCHAR(16)   NULL,
            [field]        NVARCHAR(32)   NOT NULL,
            [old_value]    NVARCHAR(MAX)  NULL,
            [new_value]    NVARCHAR(MAX)  NULL,
            [from_system]  TINYINT        NOT NULL
                CONSTRAINT [DF_rfq_draft_row_edits_from_system] DEFAULT (0),
            CONSTRAINT [PK_rfq_draft_row_edits] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [rfq_draft_row_edits_draft_fk] FOREIGN KEY ([draft_id]) REFERENCES [rfq].[rfq_draft_quotes]([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_draft_row_edits_actor] ON [rfq].[rfq_draft_row_edits]([actor_email]);
        CREATE NONCLUSTERED INDEX [rfq_draft_row_edits_draft_time] ON [rfq].[rfq_draft_row_edits]([draft_id], [created_at]);
        CREATE NONCLUSTERED INDEX [rfq_draft_row_edits_field_time] ON [rfq].[rfq_draft_row_edits]([field], [created_at]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_notification_outbox]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_notification_outbox] (
            [id]               CHAR(32)       NOT NULL,
            [created_at]       NVARCHAR(40)   NOT NULL,
            [kind]             NVARCHAR(32)   NOT NULL,
            [draft_id]         CHAR(32)       NULL,
            [recipient_email]  NVARCHAR(255)  NOT NULL,
            [payload]          NVARCHAR(MAX)  NULL,
            [status]           NVARCHAR(16)   NOT NULL
                CONSTRAINT [DF_rfq_notification_outbox_status] DEFAULT (N'pending'),
            [attempts]         INT            NOT NULL
                CONSTRAINT [DF_rfq_notification_outbox_attempts] DEFAULT (0),
            [last_error]       NVARCHAR(MAX)  NULL,
            [sent_at]          NVARCHAR(40)   NULL,
            CONSTRAINT [PK_rfq_notification_outbox] PRIMARY KEY CLUSTERED ([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_notification_outbox_pending] ON [rfq].[rfq_notification_outbox]([status], [created_at]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_reader_jobs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_reader_jobs] (
            [id]              CHAR(32)       NOT NULL,
            [created_at]      NVARCHAR(40)   NOT NULL,
            [updated_at]      NVARCHAR(40)   NULL,
            [draft_id]        CHAR(32)       NULL,
            [uploader_email]  NVARCHAR(255)  NULL,
            [file_name]       NVARCHAR(512)  NOT NULL,
            [content_type]    NVARCHAR(128)  NULL,
            [spool_path]      NVARCHAR(1024) NOT NULL,
            [status]          NVARCHAR(16)   NOT NULL
                CONSTRAINT [DF_rfq_reader_jobs_status] DEFAULT (N'queued'),
            [attempts]        INT            NOT NULL
                CONSTRAINT [DF_rfq_reader_jobs_attempts] DEFAULT (0),
            [base_revision]   INT            NULL,
            [note]            NVARCHAR(MAX)  NULL,
            [proposal]        NVARCHAR(MAX)  NULL,
            CONSTRAINT [PK_rfq_reader_jobs] PRIMARY KEY CLUSTERED ([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_reader_jobs_draft] ON [rfq].[rfq_reader_jobs]([draft_id], [created_at]);
        CREATE NONCLUSTERED INDEX [rfq_reader_jobs_pending] ON [rfq].[rfq_reader_jobs]([status], [created_at]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_sync_attempts]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_sync_attempts] (
            [id]              CHAR(32)       NOT NULL,
            [draft_id]        CHAR(32)       NOT NULL,
            [created_at]      NVARCHAR(40)   NOT NULL,
            [updated_at]      NVARCHAR(40)   NULL,
            [state]           NVARCHAR(16)   NOT NULL
                CONSTRAINT [DF_rfq_sync_attempts_state] DEFAULT (N'queued'),
            [revision]        INT            NULL,
            [actor_email]     NVARCHAR(255)  NULL,
            [actor_role]      NVARCHAR(64)   NULL,
            [actor_branch]    NVARCHAR(64)   NULL,
            [origin]          NVARCHAR(32)   NULL,
            [payload]         NVARCHAR(MAX)  NOT NULL,
            [payload_sha256]  CHAR(64)       NOT NULL,
            [attempt_no]      INT            NOT NULL
                CONSTRAINT [DF_rfq_sync_attempts_attempt_no] DEFAULT (0),
            [header_state]    NVARCHAR(16)   NOT NULL
                CONSTRAINT [DF_rfq_sync_attempts_header_state] DEFAULT (N'pending'),
            [header_sent_at]  NVARCHAR(40)   NULL,
            [refno]           NVARCHAR(32)   NULL,
            [refver]          NVARCHAR(16)   NULL,
            [lines_total]     INT            NOT NULL
                CONSTRAINT [DF_rfq_sync_attempts_lines_total] DEFAULT (0),
            [lines_sent]      INT            NOT NULL
                CONSTRAINT [DF_rfq_sync_attempts_lines_sent] DEFAULT (0),
            [lines_failed]    INT            NOT NULL
                CONSTRAINT [DF_rfq_sync_attempts_lines_failed] DEFAULT (0),
            [next_line]       INT            NOT NULL
                CONSTRAINT [DF_rfq_sync_attempts_next_line] DEFAULT (0),
            [progress]        NVARCHAR(MAX)  NULL,
            [result]          NVARCHAR(MAX)  NULL,
            [error]           NVARCHAR(MAX)  NULL,
            [reconcile_note]  NVARCHAR(MAX)  NULL,
            CONSTRAINT [PK_rfq_sync_attempts] PRIMARY KEY CLUSTERED ([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_sync_attempts_draft] ON [rfq].[rfq_sync_attempts]([draft_id], [created_at]);
        CREATE NONCLUSTERED INDEX [rfq_sync_attempts_pending] ON [rfq].[rfq_sync_attempts]([state], [created_at]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_sessions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_sessions] (
            [id]               CHAR(32)       NOT NULL,
            [created_at]       NVARCHAR(40)   NOT NULL,
            [created_by]       NVARCHAR(255)  NULL,
            [file_names]       NVARCHAR(MAX)  NULL,
            [quote_details]    NVARCHAR(MAX)  NULL,
            [line_count]       INT            NOT NULL,
            [draft_id]         CHAR(32)       NULL,
            [draft_revision]   INT            NULL,
            [actor_email]      NVARCHAR(255)  NULL,
            [actor_branch]     NVARCHAR(32)   NULL,
            [outcome]          NVARCHAR(64)   NULL,
            [legacy_unlinked]  TINYINT        NOT NULL
                CONSTRAINT [DF_rfq_sessions_legacy_unlinked] DEFAULT (1),
            CONSTRAINT [PK_rfq_sessions] PRIMARY KEY CLUSTERED ([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_sessions_actor] ON [rfq].[rfq_sessions]([actor_email]);
        CREATE NONCLUSTERED INDEX [rfq_sessions_branch] ON [rfq].[rfq_sessions]([actor_branch]);
        CREATE NONCLUSTERED INDEX [rfq_sessions_created_at] ON [rfq].[rfq_sessions]([created_at]);
        CREATE NONCLUSTERED INDEX [rfq_sessions_draft_id] ON [rfq].[rfq_sessions]([draft_id]);
        CREATE NONCLUSTERED INDEX [rfq_sessions_legacy_unlinked] ON [rfq].[rfq_sessions]([legacy_unlinked]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_entries]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_entries] (
            [id]                    CHAR(32)       NOT NULL,
            [session_id]            CHAR(32)       NOT NULL,
            [created_at]            NVARCHAR(40)   NOT NULL,
            [source_file]           NVARCHAR(400)  NULL,
            [sheet]                 NVARCHAR(200)  NULL,
            [source_row_number]     INT            NULL,
            [source_original_text]  NVARCHAR(MAX)  NULL,
            [entry_index]           INT            NULL,
            [split_reason]          NVARCHAR(64)   NULL,
            [system_raw_text]       NVARCHAR(MAX)  NULL,
            [system_status]         NVARCHAR(32)   NULL,
            [system_product_code]   NVARCHAR(64)   NULL,
            [system_confidence]     FLOAT          NULL,
            [system_fields]         NVARCHAR(MAX)  NULL,
            [system_candidates]     NVARCHAR(MAX)  NULL,
            [system_warnings]       NVARCHAR(MAX)  NULL,
            [final_product_code]    NVARCHAR(64)   NULL,
            [final_status]          NVARCHAR(32)   NULL,
            [final_length]          NVARCHAR(MAX)  NULL,
            [reviewer_note]         NVARCHAR(MAX)  NULL,
            [correction_reason]     NVARCHAR(128)  NULL,
            [full_payload]          NVARCHAR(MAX)  NOT NULL,
            [parse_tier]            NVARCHAR(40)   NULL,
            CONSTRAINT [PK_rfq_entries] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [rfq_entries_session_fk] FOREIGN KEY ([session_id]) REFERENCES [rfq].[rfq_sessions]([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_entries_session_id] ON [rfq].[rfq_entries]([session_id]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_correction_memory]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_correction_memory] (
            [normalized_text]  NVARCHAR(400)  NOT NULL,
            [product_code]     NVARCHAR(50)   NOT NULL,
            [confirmations]    INT            NOT NULL
                CONSTRAINT [DF_rfq_correction_memory_confirmations] DEFAULT (1),
            [created_at]       NVARCHAR(40)   NOT NULL,
            [updated_at]       NVARCHAR(40)   NOT NULL,
            CONSTRAINT [PK_rfq_correction_memory] PRIMARY KEY CLUSTERED ([normalized_text], [product_code])
        );
    END;

    IF OBJECT_ID(N'[rfq].[rfq_mail_audit]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_mail_audit] (
            [internet_message_id]  NVARCHAR(400)  NOT NULL,
            [graph_message_id]     NVARCHAR(400)  NULL,
            [rep_email]            NVARCHAR(255)  NULL,
            [original_sender]      NVARCHAR(255)  NULL,
            [customer_id]          INT            NULL,
            [quote_refno]          NVARCHAR(64)   NULL,
            [lines_pushed]         INT            NULL
                CONSTRAINT [DF_rfq_mail_audit_lines_pushed] DEFAULT (0),
            [lines_skipped]        INT            NULL
                CONSTRAINT [DF_rfq_mail_audit_lines_skipped] DEFAULT (0),
            [skipped_detail]       NVARCHAR(MAX)  NULL,
            [parser_version]       NVARCHAR(64)   NULL,
            [status]               NVARCHAR(32)   NULL,
            [error]                NVARCHAR(MAX)  NULL,
            [processed_at]         NVARCHAR(40)   NULL,
            CONSTRAINT [PK_rfq_mail_audit] PRIMARY KEY CLUSTERED ([internet_message_id])
        );
        CREATE NONCLUSTERED INDEX [rfq_mail_audit_processed_at] ON [rfq].[rfq_mail_audit]([processed_at]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_activity_log]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_activity_log] (
            [id]            CHAR(32)       NOT NULL,
            [created_at]    NVARCHAR(40)   NOT NULL,
            [actor_email]   NVARCHAR(255)  NULL,
            [actor_login]   NVARCHAR(255)  NULL,
            [actor_role]    NVARCHAR(64)   NULL,
            [actor_branch]  NVARCHAR(32)   NULL,
            [action]        NVARCHAR(64)   NOT NULL,
            [outcome]       NVARCHAR(32)   NOT NULL,
            [target_type]   NVARCHAR(64)   NULL,
            [target_id]     NVARCHAR(255)  NULL,
            [detail]        NVARCHAR(MAX)  NULL,
            [duration_ms]   INT            NULL,
            [source]        NVARCHAR(32)   NULL,
            CONSTRAINT [PK_rfq_activity_log] PRIMARY KEY CLUSTERED ([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_activity_log_actor] ON [rfq].[rfq_activity_log]([actor_email]);
        CREATE NONCLUSTERED INDEX [rfq_activity_log_branch] ON [rfq].[rfq_activity_log]([actor_branch]);
        CREATE NONCLUSTERED INDEX [rfq_activity_log_created_at] ON [rfq].[rfq_activity_log]([created_at]);
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
