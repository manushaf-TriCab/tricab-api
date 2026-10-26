/*
================================================================================
  Tri-AP2 round 3 (Phase 3 and 4: mail intake, drafts and sending).
  Written 26 Oct 2026.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. A fresh build of
  create-finance-ap2-schema.sql does NOT have the column below; run this once
  after that script.

  Adds [finance].[ap2_mail].[draft_attachment_ids]: a JSON array of the ids of
  the mail's own attachments chosen for the reply (the contract's
  `draftAttachmentIds`). The server clears it when the supplier changes.

  Idempotent: the column is added only if it is missing. No row is changed
  beyond the default '[]' on existing mail.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[ap2_mail]', N'U') IS NULL
    BEGIN
        THROW 54321, N'[finance].[ap2_mail] does not exist. Run create-finance-ap2-schema.sql first.', 1;
    END;

    IF COL_LENGTH(N'[finance].[ap2_mail]', N'draft_attachment_ids') IS NULL
    BEGIN
        ALTER TABLE [finance].[ap2_mail] ADD [draft_attachment_ids] NVARCHAR(MAX) NOT NULL
            CONSTRAINT [DF_finance_ap2_mail_draft_attachment_ids] DEFAULT (N'[]');
    END;

    SELECT COL_LENGTH(N'[finance].[ap2_mail]', N'draft_attachment_ids') AS [DraftAttachmentIdsColumn];

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
