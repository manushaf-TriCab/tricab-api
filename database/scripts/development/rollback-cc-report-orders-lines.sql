/*
================================================================================
  Rollback for alter-cc-report-orders-lines.sql and relax-cc-report-product-code.sql.
  STATUS: PREPARED, NOT APPLIED. DESTRUCTIVE: drops the order columns and the
  lines table, and the data in them. Take a backup first. Run only with approval.
  Refuses (THROW) when rows exist that the older shape cannot hold.
================================================================================
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'[cc_report].[ReportLines]', N'U') IS NOT NULL
   AND EXISTS (SELECT 1 FROM [cc_report].[ReportLines])
    THROW 51000, N'cc_report.ReportLines has rows. Export them before rolling back.', 1;
GO

/* Step 2 of the relax script: product code required again. */
IF EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'[cc_report].[Reports]') AND name = N'ProductCode' AND is_nullable = 1)
BEGIN
    BEGIN TRANSACTION;
    IF EXISTS (SELECT 1 FROM [cc_report].[Reports] WHERE [ProductCode] IS NULL)
        THROW 51001, N'Rows with no product code exist. Backfill them before restoring NOT NULL.', 1;
    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cc_report_Reports_RequiredText')
        ALTER TABLE [cc_report].[Reports] DROP CONSTRAINT [CK_cc_report_Reports_RequiredText];
    ALTER TABLE [cc_report].[Reports] ALTER COLUMN [ProductCode] NVARCHAR(255) NOT NULL;
    ALTER TABLE [cc_report].[Reports] WITH CHECK ADD CONSTRAINT [CK_cc_report_Reports_RequiredText]
        CHECK (
            LEN(LTRIM(RTRIM([CustomerCode]))) > 0
            AND LEN(LTRIM(RTRIM([ProductCode]))) > 0
            AND LEN(LTRIM(RTRIM([Description]))) > 0
            AND LEN(LTRIM(RTRIM([CreatedByEmail]))) > 0
        );
    COMMIT TRANSACTION;
END
GO

IF OBJECT_ID(N'[cc_report].[ReportLines]', N'U') IS NOT NULL
    DROP TABLE [cc_report].[ReportLines];
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cc_report_Reports_OrderNo' AND object_id = OBJECT_ID(N'[cc_report].[Reports]'))
    DROP INDEX [IX_cc_report_Reports_OrderNo] ON [cc_report].[Reports];
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cc_report_Reports_InvoiceNo' AND object_id = OBJECT_ID(N'[cc_report].[Reports]'))
    DROP INDEX [IX_cc_report_Reports_InvoiceNo] ON [cc_report].[Reports];
GO
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cc_report_Reports_CreditType')
    ALTER TABLE [cc_report].[Reports] DROP CONSTRAINT [CK_cc_report_Reports_CreditType];
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cc_report_Reports_AddGst')
    ALTER TABLE [cc_report].[Reports] DROP CONSTRAINT [CK_cc_report_Reports_AddGst];
GO
DECLARE @c TABLE (n SYSNAME);
INSERT @c VALUES (N'OrderNo'),(N'InvoiceNo'),(N'CreditType'),(N'QuotedAmount'),(N'InvoicedAmount'),(N'AddGst');
DECLARE @col SYSNAME, @sql NVARCHAR(400);
DECLARE cur CURSOR LOCAL FAST_FORWARD FOR SELECT n FROM @c;
OPEN cur; FETCH NEXT FROM cur INTO @col;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF COL_LENGTH(N'[cc_report].[Reports]', @col) IS NOT NULL
    BEGIN
        SET @sql = N'ALTER TABLE [cc_report].[Reports] DROP COLUMN ' + QUOTENAME(@col);
        EXEC(@sql);
    END
    FETCH NEXT FROM cur INTO @col;
END
CLOSE cur; DEALLOCATE cur;
GO
