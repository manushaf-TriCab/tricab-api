/*
================================================================================
  Finance attachments: the file store's table (Phase 1, task F1.9).
  Written 7 Oct 2026. Additive to create-finance-schema.sql; run after it.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  One table:

    [finance].[attachments]   one row per stored file, for every finance app

  The bytes are not here: they are in the private container named by
  BLOB_CONTAINER_FINANCE, at [blob_path]. The path is generated from the app, the
  month and the row's own id and never from the file's name, so [original_name] is
  only what the sender called the file (the apps today keep a "secure" version of
  the name on disk and Tri-AR overwrites same-named files).

  A file is UNATTACHED from upload until an app puts it on the record it belongs
  to; [record_type] and [record_id] are then set together with [attached_at], and
  the check constraint refuses a half-attached row. A file still unattached after
  a day is removed by a job. [record_id] is text, because the five apps' own ids
  are numbers, 32-hex ids and document numbers; it names a record in the app's own
  tables with no foreign key, as nothing here points outside [finance].

  [uploaded_by_user_id] is an identity.Users.UserId (decision D7), null for a file
  no person uploaded (a mailbox scan). No foreign key to [identity].

  Conventions as create-finance-schema.sql: DATETIME2(6) in UTC, NVARCHAR text.

  Guarded and idempotent: the table is created only if missing.
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

    IF OBJECT_ID(N'[finance].[attachments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[attachments] (
            [id]                   CHAR(32)       NOT NULL,
            [application_key]      NVARCHAR(16)   NOT NULL,
            [record_type]          NVARCHAR(40)   NULL,
            [record_id]            NVARCHAR(100)  NULL,
            [blob_path]            NVARCHAR(400)  NOT NULL,
            [original_name]        NVARCHAR(255)  NOT NULL,
            [content_type]         NVARCHAR(150)  NOT NULL,
            [byte_size]            BIGINT         NOT NULL,
            [sha256]               CHAR(64)       NOT NULL,
            [uploaded_by_user_id]  BIGINT         NULL,
            [created_at]           DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_attachments_created_at] DEFAULT (SYSUTCDATETIME()),
            [attached_at]          DATETIME2(6)   NULL,
            CONSTRAINT [PK_finance_attachments] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_attachments_application_key]
                CHECK ([application_key] IN (N'TASK', N'CREDIT', N'COLLECT', N'AR', N'AP', N'AP2')),
            CONSTRAINT [CK_finance_attachments_byte_size]
                CHECK ([byte_size] >= 0),
            CONSTRAINT [CK_finance_attachments_attached]
                CHECK (
                    ([record_type] IS NULL AND [record_id] IS NULL AND [attached_at] IS NULL)
                    OR ([record_type] IS NOT NULL AND [record_id] IS NOT NULL AND [attached_at] IS NOT NULL)
                )
        );

        CREATE UNIQUE INDEX [UX_finance_attachments_blob_path]
            ON [finance].[attachments] ([blob_path]);
        CREATE INDEX [IX_finance_attachments_record]
            ON [finance].[attachments] ([application_key], [record_type], [record_id])
            WHERE [record_id] IS NOT NULL;
        CREATE INDEX [IX_finance_attachments_unattached]
            ON [finance].[attachments] ([created_at])
            WHERE [record_id] IS NULL;
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
