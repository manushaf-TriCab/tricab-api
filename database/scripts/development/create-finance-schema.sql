/*
================================================================================
  Finance: the [finance] schema and the tables every finance app shares
  (Phase 1, task F1.3). Written 6 Oct 2026.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only. The [finance] schema already exists, empty, in tc
  on tricab-mel, owned by manushaf@tricab.com; applying this script there is
  Manusha's or Jeff's.

  Four tables, shaped on the finance portal's (inventory/portal.md section 3,
  and _work-portal-entra-20261005/database/azure-sql-schema.sql), with the
  column names kept:

    [finance].[mailboxes]          registry of shared mailboxes
                                   (portal platform_mailboxes)
    [finance].[mailbox_grants]     per app and mailbox: may read, may send
                                   (portal platform_application_mailbox_grants)
    [finance].[mail_deliveries]    ledger of every send
                                   (portal platform_mail_deliveries)
    [finance].[audit_events]       who did what
                                   (portal platform_audit_events)

  What changed from the portal's shape, and why:

    - Conventions (decisions D7, D8): timestamps are DATETIME2(6) in UTC with a
      SYSUTCDATETIME() default (the portal used DATETIMEOFFSET); text is
      NVARCHAR; booleans are BIT. No money here.
    - ids are CHAR(32), the 32-hex ids the portal already uses (uuid4 hex), as
      the RFQ tables do, so the portal's rows move across unchanged.
    - [application_key] holds the finance app key (TASK, CREDIT, COLLECT, AR,
      AP) in place of the portal's application key (unique-fin-flow-sync, ...)
      and, in mailbox_grants, in place of its [application_id] foreign key.
      That is a value change in the data migration, not a column change. On
      [audit_events] FINANCE is also allowed, for module-level events.
    - [actor_user_id] is BIGINT NULL, an identity.Users.UserId (decision D7),
      where the portal held its own 32-character user id. Null for a scheduled
      send or job: there is no actor. There is no foreign key to [identity]:
      like the RFQ tables, finance has no foreign key outside its own schema,
      so a finance-only restore (plan 11) stays possible and migrated rows
      whose portal user has no platform user can keep a null actor.
    - Columns holding JSON stay NVARCHAR(MAX) and are never sorted on or
      indexed: [to_addresses_json], [cc_addresses_json],
      [attachment_names_json], [details_json].
    - [body_sha256] is CHAR(64): a lowercase hex SHA-256, ASCII only. The
      ledger stores a hash of the body and the attachment names, never the
      content.

  Rules the schema enforces:

    - A mailbox address is unique.
    - One grant per app and mailbox.
    - An idempotency key is unique per app (a filtered unique index, where the
      key is not null). The caller's refusal of a key that is already sent or
      failed is the mail service's rule (F1.4), not the index's.
    - [status] is pending, sent or failed.

  Guarded and idempotent: the schema is created only if missing, each table
  only if missing, and a second run changes nothing. The schema is created with
  a plain CREATE SCHEMA: AUTHORIZATION [dbo] is refused for the account that
  applies scripts to tricab-mel (plan 5.1).

  Later finance scripts are additive and separate: attachments (F1.9), scoped
  grants and the portal user map (F1.10), Odoo reference lists (F1.12), and
  each app's own tables in its phase.
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

    IF OBJECT_ID(N'[finance].[mailboxes]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[mailboxes] (
            [id]            CHAR(32)       NOT NULL,
            [email]         NVARCHAR(320)  NOT NULL,
            [display_name]  NVARCHAR(200)  NOT NULL,
            [purpose]       NVARCHAR(300)  NOT NULL
                CONSTRAINT [DF_finance_mailboxes_purpose] DEFAULT (N''),
            [is_active]     BIT            NOT NULL
                CONSTRAINT [DF_finance_mailboxes_is_active] DEFAULT (1),
            [created_at]    DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_mailboxes_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at]    DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_mailboxes_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_mailboxes] PRIMARY KEY CLUSTERED ([id])
        );

        CREATE UNIQUE INDEX [UX_finance_mailboxes_email]
            ON [finance].[mailboxes] ([email]);
    END;

    IF OBJECT_ID(N'[finance].[mailbox_grants]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[mailbox_grants] (
            [id]               CHAR(32)      NOT NULL,
            [application_key]  NVARCHAR(16)  NOT NULL,
            [mailbox_id]       CHAR(32)      NOT NULL,
            [can_read]         BIT           NOT NULL
                CONSTRAINT [DF_finance_mailbox_grants_can_read] DEFAULT (0),
            [can_send]         BIT           NOT NULL
                CONSTRAINT [DF_finance_mailbox_grants_can_send] DEFAULT (0),
            [created_at]       DATETIME2(6)  NOT NULL
                CONSTRAINT [DF_finance_mailbox_grants_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at]       DATETIME2(6)  NOT NULL
                CONSTRAINT [DF_finance_mailbox_grants_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_mailbox_grants] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_mailbox_grants_mailbox] FOREIGN KEY ([mailbox_id])
                REFERENCES [finance].[mailboxes] ([id]) ON DELETE CASCADE,
            CONSTRAINT [UQ_finance_mailbox_grants_app_mailbox] UNIQUE ([application_key], [mailbox_id]),
            CONSTRAINT [CK_finance_mailbox_grants_application_key]
                CHECK ([application_key] IN (N'TASK', N'CREDIT', N'COLLECT', N'AR', N'AP'))
        );
    END;

    IF OBJECT_ID(N'[finance].[mail_deliveries]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[mail_deliveries] (
            [id]                     CHAR(32)       NOT NULL,
            [application_key]        NVARCHAR(16)   NOT NULL,
            [actor_user_id]          BIGINT         NULL,
            [mailbox_email]          NVARCHAR(320)  NOT NULL,
            [to_addresses_json]      NVARCHAR(MAX)  NOT NULL,
            [cc_addresses_json]      NVARCHAR(MAX)  NOT NULL,
            [subject]                NVARCHAR(500)  NOT NULL,
            [body_sha256]            CHAR(64)       NOT NULL,
            [attachment_names_json]  NVARCHAR(MAX)  NOT NULL,
            [idempotency_key]        NVARCHAR(200)  NULL,
            [status]                 NVARCHAR(20)   NOT NULL,
            [error_message]          NVARCHAR(500)  NULL,
            [created_at]             DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_mail_deliveries_created_at] DEFAULT (SYSUTCDATETIME()),
            [sent_at]                DATETIME2(6)   NULL,
            CONSTRAINT [PK_finance_mail_deliveries] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_mail_deliveries_application_key]
                CHECK ([application_key] IN (N'TASK', N'CREDIT', N'COLLECT', N'AR', N'AP')),
            CONSTRAINT [CK_finance_mail_deliveries_status]
                CHECK ([status] IN (N'pending', N'sent', N'failed', N'suppressed'))
        );

        CREATE INDEX [IX_finance_mail_deliveries_actor_user_id]
            ON [finance].[mail_deliveries] ([actor_user_id]);
        CREATE INDEX [IX_finance_mail_deliveries_application_key]
            ON [finance].[mail_deliveries] ([application_key]);
        CREATE INDEX [IX_finance_mail_deliveries_mailbox_email]
            ON [finance].[mail_deliveries] ([mailbox_email]);
        CREATE INDEX [IX_finance_mail_deliveries_status]
            ON [finance].[mail_deliveries] ([status]);
        CREATE UNIQUE INDEX [UX_finance_mail_deliveries_idempotency]
            ON [finance].[mail_deliveries] ([application_key], [idempotency_key])
            WHERE [idempotency_key] IS NOT NULL;
    END;

    IF OBJECT_ID(N'[finance].[audit_events]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[audit_events] (
            [id]               CHAR(32)       NOT NULL,
            [actor_user_id]    BIGINT         NULL,
            [application_key]  NVARCHAR(16)   NOT NULL
                CONSTRAINT [DF_finance_audit_events_application_key] DEFAULT (N'FINANCE'),
            [action]           NVARCHAR(120)  NOT NULL,
            [entity_type]      NVARCHAR(80)   NOT NULL,
            [entity_id]        NVARCHAR(100)  NULL,
            [details_json]     NVARCHAR(MAX)  NOT NULL
                CONSTRAINT [DF_finance_audit_events_details_json] DEFAULT (N'{}'),
            [created_at]       DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_audit_events_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_audit_events] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_audit_events_application_key]
                CHECK ([application_key] IN (N'FINANCE', N'TASK', N'CREDIT', N'COLLECT', N'AR', N'AP'))
        );

        CREATE INDEX [IX_finance_audit_events_action]
            ON [finance].[audit_events] ([action]);
        CREATE INDEX [IX_finance_audit_events_actor_user_id]
            ON [finance].[audit_events] ([actor_user_id]);
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
