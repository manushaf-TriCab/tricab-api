/* Read-only validation for alter-cc-report-orders-lines.sql. Expect every row to say OK. */
SET NOCOUNT ON;
SELECT N'Reports.OrderNo' AS [Check], IIF(COL_LENGTH(N'[cc_report].[Reports]', N'OrderNo') IS NOT NULL, N'OK', N'MISSING') AS [Result]
UNION ALL SELECT N'Reports.InvoiceNo', IIF(COL_LENGTH(N'[cc_report].[Reports]', N'InvoiceNo') IS NOT NULL, N'OK', N'MISSING')
UNION ALL SELECT N'Reports.CreditType', IIF(COL_LENGTH(N'[cc_report].[Reports]', N'CreditType') IS NOT NULL, N'OK', N'MISSING')
UNION ALL SELECT N'Reports.QuotedAmount', IIF(COL_LENGTH(N'[cc_report].[Reports]', N'QuotedAmount') IS NOT NULL, N'OK', N'MISSING')
UNION ALL SELECT N'Reports.InvoicedAmount', IIF(COL_LENGTH(N'[cc_report].[Reports]', N'InvoicedAmount') IS NOT NULL, N'OK', N'MISSING')
UNION ALL SELECT N'Reports.AddGst', IIF(COL_LENGTH(N'[cc_report].[Reports]', N'AddGst') IS NOT NULL, N'OK', N'MISSING')
UNION ALL SELECT N'Reports.RequestedActions (from create script)', IIF(COL_LENGTH(N'[cc_report].[Reports]', N'RequestedActions') IS NOT NULL, N'OK', N'MISSING')
UNION ALL SELECT N'ReportLines table', IIF(OBJECT_ID(N'[cc_report].[ReportLines]', N'U') IS NOT NULL, N'OK', N'MISSING')
UNION ALL SELECT N'ReportLines credit cap constraint', IIF(EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cc_report_ReportLines_Credit'), N'OK', N'MISSING')
UNION ALL SELECT N'Reports.ProductCode nullable (relax script)', IIF(EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID(N'[cc_report].[Reports]') AND name = N'ProductCode' AND is_nullable = 1), N'OK', N'NOT RELAXED');
