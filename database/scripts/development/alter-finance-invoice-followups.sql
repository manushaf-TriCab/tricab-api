/*
================================================================================
  Tri-AP tables: two follow-ups to create-finance-invoice-schema.sql (10 Oct 2026).
  Additive; run after it.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Applying it to
  tricab-mel is Manusha's or Jeff's.

  create-finance-invoice-schema.sql now creates both tables this way. This script
  is for tables created BEFORE that change (tricab-mel's, applied 9 Oct 2026):

    1. [invoice_approval_decisions].[decision] gets the check the schema left out
       until its values were re-read: Approved or Rejected, the only two the API
       writes. If a row already holds anything else the script stops and changes
       nothing.
    2. [invoice_scan_skips].[attachment] is widened from 255 to 400 characters, to
       match [invoice_invoices].[attachment_id], whose value it stores. A longer
       attachment id could not be tombstoned, so a deleted capture could return on
       the next scan. The unique index on (email_id, attachment) is kept.

  On tables that already have both it does nothing, so it is safe to run any number
  of times. It changes no data and no other object.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[invoice_approval_decisions]', N'U') IS NULL
       OR OBJECT_ID(N'[finance].[invoice_scan_skips]', N'U') IS NULL
    BEGIN
        THROW 54261, N'Tri-AP''s tables do not exist. Run create-finance-invoice-schema.sql first.', 1;
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.check_constraints
         WHERE [name] = N'CK_finance_invoice_approval_decisions_decision'
           AND [parent_object_id] = OBJECT_ID(N'[finance].[invoice_approval_decisions]'))
    BEGIN
        IF EXISTS (SELECT 1 FROM [finance].[invoice_approval_decisions] WHERE [decision] NOT IN (N'Approved', N'Rejected'))
        BEGIN
            THROW 54262, N'An approval decision holds a value other than Approved or Rejected. Nothing was changed.', 1;
        END;

        ALTER TABLE [finance].[invoice_approval_decisions] ADD CONSTRAINT [CK_finance_invoice_approval_decisions_decision]
            CHECK ([decision] IN (N'Approved', N'Rejected'));
    END;

    IF EXISTS (
        SELECT 1 FROM sys.columns
         WHERE [object_id] = OBJECT_ID(N'[finance].[invoice_scan_skips]')
           AND [name] = N'attachment'
           AND [max_length] < 800)
    BEGIN
        ALTER TABLE [finance].[invoice_scan_skips] ALTER COLUMN [attachment] NVARCHAR(400) NOT NULL;
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
