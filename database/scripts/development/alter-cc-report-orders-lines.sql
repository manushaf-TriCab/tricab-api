/*
================================================================================
  Customer Contact reports: orders, invoices, credit type and order lines.

  STATUS: PREPARED, NOT APPLIED. Do not run against any shared or production
  database without explicit approval. No rows are copied or read from legacy
  MySQL (tc.cc_*). This only changes the shape of [cc_report].

  ADDITIVE ONLY. Idempotent: each column, table and index is created only when
  missing; re-running changes nothing. Existing rows keep NULLs in the new
  columns and are unaffected.

  Adds to [cc_report].[Reports]:
    OrderNo, InvoiceNo          4JS order and invoice numbers (live: order_no, invoice_no)
    CreditType                  'Returned goods' | 'Overcharged'
    QuotedAmount, InvoicedAmount free text, as live
    AddGst                      'Yes' | 'No' (overcharge only)
  Adds [cc_report].[ReportLines] (live: tc.cc_lines). One row per credited line.
  [Reports].[RequestedActions] already exists in create-cc-report-schema.sql.

  Product code is made optional by the SEPARATE script
  relax-cc-report-product-code.sql, which relaxes (not removes) a constraint.
  The API release needs both scripts.

  Apply later, dry-run first:
    php bin/run-azure-sql-script.php database/scripts/development/alter-cc-report-orders-lines.sql
    php bin/run-azure-sql-script.php database/scripts/development/alter-cc-report-orders-lines.sql --apply --server=<server> --database=<db>
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF COL_LENGTH(N'[cc_report].[Reports]', N'OrderNo') IS NULL
    ALTER TABLE [cc_report].[Reports] ADD [OrderNo] NVARCHAR(32) NULL;
GO
IF COL_LENGTH(N'[cc_report].[Reports]', N'InvoiceNo') IS NULL
    ALTER TABLE [cc_report].[Reports] ADD [InvoiceNo] NVARCHAR(32) NULL;
GO
IF COL_LENGTH(N'[cc_report].[Reports]', N'CreditType') IS NULL
    ALTER TABLE [cc_report].[Reports] ADD [CreditType] NVARCHAR(20) NULL;
GO
IF COL_LENGTH(N'[cc_report].[Reports]', N'QuotedAmount') IS NULL
    ALTER TABLE [cc_report].[Reports] ADD [QuotedAmount] NVARCHAR(100) NULL;
GO
IF COL_LENGTH(N'[cc_report].[Reports]', N'InvoicedAmount') IS NULL
    ALTER TABLE [cc_report].[Reports] ADD [InvoicedAmount] NVARCHAR(100) NULL;
GO
IF COL_LENGTH(N'[cc_report].[Reports]', N'AddGst') IS NULL
    ALTER TABLE [cc_report].[Reports] ADD [AddGst] NVARCHAR(3) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cc_report_Reports_CreditType')
    ALTER TABLE [cc_report].[Reports] WITH CHECK ADD CONSTRAINT [CK_cc_report_Reports_CreditType]
        CHECK ([CreditType] IS NULL OR [CreditType] IN (N'Returned goods', N'Overcharged'));
GO
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cc_report_Reports_AddGst')
    ALTER TABLE [cc_report].[Reports] WITH CHECK ADD CONSTRAINT [CK_cc_report_Reports_AddGst]
        CHECK ([AddGst] IS NULL OR [AddGst] IN (N'Yes', N'No'));
GO

/* Register search and filters use order and invoice numbers. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cc_report_Reports_OrderNo' AND object_id = OBJECT_ID(N'[cc_report].[Reports]'))
    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_OrderNo]
        ON [cc_report].[Reports]([OrderNo]) WHERE [OrderNo] IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cc_report_Reports_InvoiceNo' AND object_id = OBJECT_ID(N'[cc_report].[Reports]'))
    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_InvoiceNo]
        ON [cc_report].[Reports]([InvoiceNo]) WHERE [InvoiceNo] IS NOT NULL;
GO

/* ============================================================================
   cc_report.ReportLines
   Rebuilt from 4JS by the server. Replaced as a set on amend (live: DELETE then
   INSERT, in one transaction). No delete workflow for reports, so no cascade.
   LineNumber, not LineNo: LINENO is a T-SQL reserved word.
   ============================================================================ */
IF OBJECT_ID(N'[cc_report].[ReportLines]', N'U') IS NULL
BEGIN
    CREATE TABLE [cc_report].[ReportLines] (
        [LineId]        BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId]      BIGINT NULL,
        [ReportId]      BIGINT NOT NULL,
        [OrderNo]       NVARCHAR(32) NOT NULL,
        [InvoiceNo]     NVARCHAR(32) NOT NULL
            CONSTRAINT [DF_cc_report_ReportLines_InvoiceNo] DEFAULT (N''),
        [LineNumber]    INT NOT NULL,
        [ProductCode]   NVARCHAR(100) NOT NULL
            CONSTRAINT [DF_cc_report_ReportLines_ProductCode] DEFAULT (N''),
        [Description]   NVARCHAR(255) NOT NULL
            CONSTRAINT [DF_cc_report_ReportLines_Description] DEFAULT (N''),
        [Qty]           DECIMAL(18,4) NOT NULL,
        [Unit]          NVARCHAR(20) NOT NULL
            CONSTRAINT [DF_cc_report_ReportLines_Unit] DEFAULT (N''),
        [Packs]         INT NOT NULL
            CONSTRAINT [DF_cc_report_ReportLines_Packs] DEFAULT (0),
        [PackSize]      DECIMAL(18,4) NOT NULL
            CONSTRAINT [DF_cc_report_ReportLines_PackSize] DEFAULT (0),
        [UnitPrice]     DECIMAL(18,4) NOT NULL,
        [Net]           DECIMAL(18,2) NOT NULL,
        [CreditQty]     DECIMAL(18,4) NOT NULL,
        [CreditRate]    DECIMAL(18,4) NOT NULL,
        [CreditNet]     DECIMAL(18,2) NOT NULL,
        /* Fraction (0.10). NULL when only an order was ticked. */
        [GstRate]       DECIMAL(9,4) NULL,
        [Warehouse]     NVARCHAR(20) NOT NULL
            CONSTRAINT [DF_cc_report_ReportLines_Warehouse] DEFAULT (N''),
        [Comment]       NVARCHAR(500) NOT NULL
            CONSTRAINT [DF_cc_report_ReportLines_Comment] DEFAULT (N''),
        CONSTRAINT [PK_cc_report_ReportLines] PRIMARY KEY CLUSTERED ([LineId]),
        CONSTRAINT [FK_cc_report_ReportLines_ReportId]
            FOREIGN KEY ([ReportId]) REFERENCES [cc_report].[Reports]([ReportId]) ON DELETE NO ACTION,
        CONSTRAINT [UQ_cc_report_ReportLines_Report_Order_Line] UNIQUE ([ReportId], [OrderNo], [LineNumber]),
        /* Server caps: credit never exceeds what 4JS invoiced. */
        CONSTRAINT [CK_cc_report_ReportLines_Credit]
            CHECK ([CreditQty] > 0 AND [CreditQty] <= [Qty]
               AND [CreditRate] >= 0
               AND [CreditNet] >= 0 AND [CreditNet] <= [Net])
    );

    CREATE UNIQUE NONCLUSTERED INDEX [UX_cc_report_ReportLines_LegacyId]
        ON [cc_report].[ReportLines]([LegacyId]) WHERE [LegacyId] IS NOT NULL;
END
GO
