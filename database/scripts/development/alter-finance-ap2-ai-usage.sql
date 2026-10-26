/*
================================================================================
  Tri-AP2 L3 (AI usage, Copilot history, extraction on sync).
  Written 27 Oct 2026.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. A fresh build of
  create-finance-ap2-schema.sql does NOT have the objects below; run this once
  after that script (and after alter-finance-ap2-mail-round3.sql).

  Adds:
    [finance].[ap2_ai_usage]              one row per AI call (or Copilot turn):
                                          purpose, model, tokens, cost, ok. No
                                          mail text.
    [finance].[ap2_mail].[extraction_json] the extractor's reading of the mail's
                                          invoice PDF, stored by mail sync as a
                                          suggestion so create-invoice can reuse
                                          it without a second model call.
    Two filtered indexes on [finance].[ap2_copilot_messages] for reading one
    person's conversation about one mail or one supplier.

  Idempotent: each object is created only if it is missing. No existing row is
  changed.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
-- The filtered indexes below need these on; sqlcmd starts with QUOTED_IDENTIFIER off unless run with -I.
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[ap2_mail]', N'U') IS NULL
        OR OBJECT_ID(N'[finance].[ap2_copilot_messages]', N'U') IS NULL
    BEGIN
        THROW 54321, N'The Tri-AP2 tables do not exist. Run create-finance-ap2-schema.sql first.', 1;
    END;

    IF OBJECT_ID(N'[finance].[ap2_ai_usage]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_ai_usage] (
            [id]                  BIGINT IDENTITY(1,1) NOT NULL,
            [purpose]             NVARCHAR(40)    NOT NULL,
            [model]               NVARCHAR(100)   NOT NULL,
            [prompt_version]      NVARCHAR(60)    NOT NULL
                CONSTRAINT [DF_finance_ap2_ai_usage_prompt_version] DEFAULT (N''),
            [input_tokens]        INT             NOT NULL,
            [output_tokens]       INT             NOT NULL,
            [cache_read_tokens]   INT             NOT NULL
                CONSTRAINT [DF_finance_ap2_ai_usage_cache_read_tokens] DEFAULT (0),
            [cache_write_tokens]  INT             NOT NULL
                CONSTRAINT [DF_finance_ap2_ai_usage_cache_write_tokens] DEFAULT (0),
            [cost_usd]            DECIMAL(12,6)   NOT NULL,
            [duration_ms]         INT             NOT NULL,
            [calls]               INT             NOT NULL,
            [ok]                  BIT             NOT NULL,
            [created_at]          DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_ai_usage_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_ai_usage] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_ap2_ai_usage_counts]
                CHECK ([input_tokens] >= 0 AND [output_tokens] >= 0 AND [cache_read_tokens] >= 0
                       AND [cache_write_tokens] >= 0 AND [duration_ms] >= 0 AND [calls] >= 0),
            CONSTRAINT [CK_finance_ap2_ai_usage_cost] CHECK ([cost_usd] >= 0)
        );

        CREATE INDEX [IX_finance_ap2_ai_usage_created]
            ON [finance].[ap2_ai_usage] ([created_at]) INCLUDE ([purpose], [model]);
    END;

    IF COL_LENGTH(N'[finance].[ap2_mail]', N'extraction_json') IS NULL
    BEGIN
        ALTER TABLE [finance].[ap2_mail] ADD [extraction_json] NVARCHAR(MAX) NULL
            CONSTRAINT [CK_finance_ap2_mail_extraction_json]
                CHECK ([extraction_json] IS NULL OR ISJSON([extraction_json]) = 1);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE [name] = N'IX_finance_ap2_copilot_messages_mail'
                     AND [object_id] = OBJECT_ID(N'[finance].[ap2_copilot_messages]'))
    BEGIN
        CREATE INDEX [IX_finance_ap2_copilot_messages_mail]
            ON [finance].[ap2_copilot_messages] ([user_id], [mail_id], [created_at]) WHERE [mail_id] IS NOT NULL;
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE [name] = N'IX_finance_ap2_copilot_messages_supplier'
                     AND [object_id] = OBJECT_ID(N'[finance].[ap2_copilot_messages]'))
    BEGIN
        CREATE INDEX [IX_finance_ap2_copilot_messages_supplier]
            ON [finance].[ap2_copilot_messages] ([user_id], [supplier_id], [created_at])
            WHERE [supplier_id] IS NOT NULL AND [mail_id] IS NULL;
    END;

    SELECT OBJECT_ID(N'[finance].[ap2_ai_usage]', N'U') AS [AiUsageTable],
           COL_LENGTH(N'[finance].[ap2_mail]', N'extraction_json') AS [ExtractionJsonColumn];

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
