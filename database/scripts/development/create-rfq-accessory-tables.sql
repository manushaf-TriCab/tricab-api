/*
================================================================================
  RFQ store: the two tables the live MySQL store gained on 18 Oct 2026, in the
  same shape, so their data moves across unchanged.

  Source of truth: tc-mysql-live / tc, information_schema read on 20 Oct 2026.
  Same rules as create-rfq-schema.sql: the same table, column and index names;
  varchar(n) -> NVARCHAR(n), text/longtext -> NVARCHAR(MAX), char(n) -> CHAR(n),
  int -> INT; timestamps stay ISO-8601 text.

    - rfq_quote_relationships: a source quote and the accessory quote made
      from it. Five secondary indexes, as in MySQL; only the idempotency key is
      unique. MySQL lets a UNIQUE index hold many NULLs and SQL Server does
      not, so that one is filtered to non-NULL keys. No foreign keys, as in
      MySQL.
    - rfq_user_accessory_preferences: one row per user email.

  Run after create-rfq-schema.sql. Guarded and idempotent: each table is
  created only if missing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[rfq].[rfq_draft_quotes]', N'U') IS NULL
        THROW 50001, N'The [rfq] tables are missing. Run create-rfq-schema.sql first.', 1;

    IF OBJECT_ID(N'[rfq].[rfq_quote_relationships]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_quote_relationships] (
            [id]                  CHAR(32)       NOT NULL,
            [source_draft_id]     CHAR(32)       NOT NULL,
            [accessory_draft_id]  CHAR(32)       NOT NULL,
            [relationship_type]   NVARCHAR(32)   NOT NULL
                CONSTRAINT [DF_rfq_quote_relationships_relationship_type] DEFAULT (N'accessory_quote'),
            [source_refno]        NVARCHAR(64)   NULL,
            [source_refver]       NVARCHAR(32)   NULL,
            [accessory_refno]     NVARCHAR(64)   NULL,
            [accessory_refver]    NVARCHAR(32)   NULL,
            [idempotency_key]     NVARCHAR(128)  NULL,
            [source_fingerprint]  NVARCHAR(64)   NULL,
            [generation_meta]     NVARCHAR(MAX)  NULL,
            [created_at]          NVARCHAR(40)   NOT NULL,
            [created_by_email]    NVARCHAR(255)  NULL,
            CONSTRAINT [PK_rfq_quote_relationships] PRIMARY KEY CLUSTERED ([id])
        );
        CREATE NONCLUSTERED INDEX [rfq_quote_relationships_accessory] ON [rfq].[rfq_quote_relationships]([accessory_draft_id]);
        CREATE UNIQUE NONCLUSTERED INDEX [rfq_quote_relationships_idemp] ON [rfq].[rfq_quote_relationships]([idempotency_key])
            WHERE [idempotency_key] IS NOT NULL;
        CREATE NONCLUSTERED INDEX [rfq_quote_relationships_a_refno] ON [rfq].[rfq_quote_relationships]([accessory_refno]);
        CREATE NONCLUSTERED INDEX [rfq_quote_relationships_s_refno] ON [rfq].[rfq_quote_relationships]([source_refno]);
        CREATE NONCLUSTERED INDEX [rfq_quote_relationships_source] ON [rfq].[rfq_quote_relationships]([source_draft_id]);
    END;

    IF OBJECT_ID(N'[rfq].[rfq_user_accessory_preferences]', N'U') IS NULL
    BEGIN
        CREATE TABLE [rfq].[rfq_user_accessory_preferences] (
            [user_email]          NVARCHAR(255)  NOT NULL,
            [selected_types]      NVARCHAR(MAX)  NOT NULL,
            [destination]         NVARCHAR(32)   NOT NULL
                CONSTRAINT [DF_rfq_user_accessory_preferences_destination] DEFAULT (N'new_quote'),
            [arrangement]         NVARCHAR(32)   NOT NULL
                CONSTRAINT [DF_rfq_user_accessory_preferences_arrangement] DEFAULT (N'consolidated'),
            [preference_version]  INT            NOT NULL
                CONSTRAINT [DF_rfq_user_accessory_preferences_preference_version] DEFAULT (1),
            [updated_at]          NVARCHAR(40)   NOT NULL,
            CONSTRAINT [PK_rfq_user_accessory_preferences] PRIMARY KEY CLUSTERED ([user_email])
        );
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
