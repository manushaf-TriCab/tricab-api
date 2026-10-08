/*
================================================================================
  Finance mail ledger: allow the `suppressed` status (F1.4, 7 Oct 2026).
  Additive to create-finance-schema.sql; run after it.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  A send made while FINANCE_MAIL_ENABLED is off is recorded as `suppressed`
  (nothing was sent; the same idempotency key may be sent again later).
  create-finance-schema.sql now creates [mail_deliveries] with that status
  allowed. This script is for a table created BEFORE that change, whose check
  constraint still reads pending/sent/failed: it replaces that one constraint.
  On a table that already allows `suppressed` it does nothing, so it is safe to
  run any number of times. It changes no data and no other object.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF EXISTS (
    SELECT 1 FROM sys.check_constraints
     WHERE [name] = N'CK_finance_mail_deliveries_status'
       AND [parent_object_id] = OBJECT_ID(N'[finance].[mail_deliveries]')
       AND [definition] NOT LIKE N'%suppressed%')
BEGIN
    BEGIN TRY
        BEGIN TRANSACTION;

        ALTER TABLE [finance].[mail_deliveries] DROP CONSTRAINT [CK_finance_mail_deliveries_status];
        ALTER TABLE [finance].[mail_deliveries] ADD CONSTRAINT [CK_finance_mail_deliveries_status]
            CHECK ([status] IN (N'pending', N'sent', N'failed', N'suppressed'));

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
END;
GO
