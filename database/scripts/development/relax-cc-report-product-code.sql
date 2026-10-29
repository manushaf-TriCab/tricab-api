/*
================================================================================
  Customer Contact reports: make Product code optional.

  STATUS: PREPARED, NOT APPLIED. Run only with explicit approval.

  Live no longer requires a product code on create (it comes from the ticked
  order lines, and a phoned-in complaint may have none). This script RELAXES
  two things, and removes no data:
    1. [ProductCode] becomes NULLable.
    2. CK_cc_report_Reports_RequiredText is recreated without the ProductCode test.
  It is separate from alter-cc-report-orders-lines.sql because it changes an
  existing constraint. Rollback: rollback-cc-report-orders-lines.sql (step 2),
  which refuses to run while any row has a NULL product code.
================================================================================
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'[cc_report].[Reports]') AND name = N'ProductCode' AND is_nullable = 0)
BEGIN
    /* One transaction: the constraint is never left dropped if a later step fails. */
    BEGIN TRANSACTION;
    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cc_report_Reports_RequiredText')
        ALTER TABLE [cc_report].[Reports] DROP CONSTRAINT [CK_cc_report_Reports_RequiredText];

    ALTER TABLE [cc_report].[Reports] ALTER COLUMN [ProductCode] NVARCHAR(255) NULL;

    ALTER TABLE [cc_report].[Reports] WITH CHECK ADD CONSTRAINT [CK_cc_report_Reports_RequiredText]
        CHECK (
            LEN(LTRIM(RTRIM([CustomerCode]))) > 0
            AND LEN(LTRIM(RTRIM([Description]))) > 0
            AND LEN(LTRIM(RTRIM([CreatedByEmail]))) > 0
            AND ([ProductCode] IS NULL OR LEN(LTRIM(RTRIM([ProductCode]))) > 0)
        );
    COMMIT TRANSACTION;
END
GO
