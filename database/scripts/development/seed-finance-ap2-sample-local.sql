/*
================================================================================
  LOCAL ONLY. NOT FOR ANY SHARED DATABASE. Tri-AP2 sample data (round 4).
  INVENTED data for a developer's own database, taken from the prototype's
  site/data.js (platform/tri-ap-new/Tri-AP-AWS): 8 suppliers, 10 invoices, 11
  mails, 2 statements, 2 PO forecasts. Refuses to run against Azure SQL (see the
  guard below). Run after create-finance-ap2-schema.sql, the AP2 permission and
  role seeds and local-db/seed-local-me.sql (which makes the people it uses).

  The scenarios the prototype was built around:
    * B001 CM-2200 (AUD 22,000) with credit note CN-2200 (net 2,000 + tax 200)
      arriving as mail MCN001, not yet applied;
    * B003 and B005 are the same OH-004 invoice (a duplicate pair, same
      supplier and number; B005 carries the sample fingerprint the duplicate
      check matches on);
    * S007 CleanWorks has a bank change waiting and its payments on hold
      (mail M008, category Bank detail change);
    * B006, B007, B008 and B010 are posted or settled in Odoo; B007 and B010
      are part-paid or settled.

  Owners: the prototype's Alex Chen and Priya Shah are the local manushaf@ and
  ap2.approver@ people (NULL if they do not exist). Dates are relative to the run
  (today in Australia/Melbourne). No attachments: the prototype has none.

  Idempotent: every row has a fixed id (prefix a2 + kind) and is inserted only if
  missing; it never changes a row that exists. One transaction.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF CAST(SERVERPROPERTY('EngineEdition') AS INT) NOT IN (2, 3, 4)
   OR @@SERVERNAME LIKE N'%database.windows.net%'
   OR @@SERVERNAME LIKE N'%tricab-mel%'
   OR DB_NAME() NOT IN (N'tc')
BEGIN
    THROW 54250, N'The Tri-AP2 sample seed is for the local Docker database only. Refusing to run against Azure SQL.', 1;
END;
IF OBJECT_ID(N'[finance].[ap2_invoices]', N'U') IS NULL OR OBJECT_ID(N'[identity].[Users]', N'U') IS NULL
BEGIN
    THROW 54251, N'Run create-finance-ap2-schema.sql and the identity schema first.', 1;
END;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Today DATE = CAST(SYSDATETIMEOFFSET() AT TIME ZONE N'AUS Eastern Standard Time' AS DATE);
    DECLARE @AlexId BIGINT = (SELECT TOP 1 [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = N'manushaf@tricab.com');
    DECLARE @PriyaId BIGINT = (SELECT TOP 1 [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = N'ap2.approver@tricab.com');

    /* Ids: 'a2' + kind digit + the number, padded to 32 hex characters. */
    /* ---------------- suppliers ---------------- */
    DECLARE @S TABLE ([n] INT, [name] NVARCHAR(200), [industry] NVARCHAR(100), [email] NVARCHAR(200));
    INSERT INTO @S VALUES
        (1, N'Cable Materials Pty Ltd', N'Manufacturing', N'accounts@cable.example'),
        (2, N'Metro Freight', N'Logistics', N'billing@metro.example'),
        (3, N'Office Hub', N'Office supplies', N'ar@office.example'),
        (4, N'Power Energy', N'Utilities', N'billing@power.example'),
        (5, N'River Equipment', N'Equipment', N'accounts@river.example'),
        (6, N'Digital Systems', N'Technology', N'ar@digital.example'),
        (7, N'CleanWorks', N'Services', N'finance@clean.example'),
        (8, N'Safety Supplies', N'Industrial supplies', N'accounts@safety.example');

    INSERT INTO [finance].[ap2_suppliers]
        ([id], [name], [industry], [email], [contact], [cc], [entity], [phone], [terms],
         [owner_user_id], [owner_name], [owner_email], [bank_verified], [bank_change], [hold], [notes])
    SELECT 'a21' + RIGHT('00000000000000000000000000000' + CAST(s.[n] AS VARCHAR(4)), 29),
           s.[name], s.[industry], s.[email], N'Accounts team', N'', N'Tricab Australia', N'', 30,
           CASE WHEN s.[n] % 2 = 0 THEN @PriyaId ELSE @AlexId END,
           CASE WHEN s.[n] % 2 = 0 THEN N'Priya Shah' ELSE N'Alex Chen' END,
           CASE WHEN s.[n] % 2 = 0 THEN N'ap2.approver@tricab.com' ELSE N'manushaf@tricab.com' END,
           CASE WHEN s.[n] = 7 THEN 0 ELSE 1 END, CASE WHEN s.[n] = 7 THEN 1 ELSE 0 END,
           CASE WHEN s.[n] = 7 THEN 1 ELSE 0 END, N''
    FROM @S s
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_suppliers] x
                      WHERE x.[id] = 'a21' + RIGHT('00000000000000000000000000000' + CAST(s.[n] AS VARCHAR(4)), 29));

    /* ---------------- invoices ---------------- */
    DECLARE @B TABLE ([n] INT, [sup] INT, [number] NVARCHAR(50), [net] DECIMAL(18,2), [tax] DECIMAL(18,2),
                      [issuedOff] INT, [dueOff] INT, [kind] NVARCHAR(20), [po] NVARCHAR(50),
                      [qty] DECIMAL(18,4), [rcv] DECIMAL(18,4), [stage] NVARCHAR(40));
    INSERT INTO @B VALUES
        (1, 1, N'CM-2200', 20000, 2000, -10, 27, N'Goods PO', N'PO-1001', 1000, 900, N'Needs verification'),
        (2, 2, N'MF-4421', 3000, 300, -5, 5, N'Service PO', N'PO-1002', 1, 0, N'Needs verification'),
        (3, 3, N'OH-004', 1000, 100, 0, 14, N'No PO', N'', 1, 0, N'Needs verification'),
        (4, 4, N'PE-8821', 2000, 200, 0, 3, N'Recurring', N'', 1, 1, N'Confirmed'),
        (5, 3, N'OH-004', 1000, 100, 0, 14, N'No PO', N'', 1, 0, N'Needs verification'),
        (6, 5, N'RE-1350', 5000, 500, -45, -15, N'Goods PO', N'PO-0990', 10, 10, N'Posted'),
        (7, 6, N'DS-9921', 4000, 400, -27, 3, N'Service PO', N'PO-0992', 1, 1, N'Posted'),
        (8, 7, N'CW-3002', 1500, 150, -10, 2, N'Recurring', N'', 1, 1, N'Posted'),
        (9, 8, N'SS-1108', 800, 80, -3, 7, N'Goods PO', N'PO-1007', 4, 4, N'Needs verification'),
        (10, 3, N'OH-001', 600, 60, -60, -30, N'No PO', N'', 1, 1, N'Posted');

    INSERT INTO [finance].[ap2_invoices]
        ([id], [supplier_id], [number], [entity], [currency], [net], [tax], [total], [issued], [due], [kind], [po],
         [quantity], [ordered_qty], [received_qty], [po_unit_price], [receipt_ref], [service_accepted], [no_po_reason],
         [gl], [tax_code], [cost_centre], [credit], [credit_ref], [credit_net], [credit_tax], [credit_resolved], [paid],
         [stage], [verified], [import_state], [odoo_ref], [excluded], [payment_hold], [source_doc], [confidence],
         [source_fingerprint], [mail_id], [owner_user_id], [owner_name], [owner_email])
    SELECT 'a22' + RIGHT('00000000000000000000000000000' + CAST(b.[n] AS VARCHAR(4)), 29),
           'a21' + RIGHT('00000000000000000000000000000' + CAST(b.[sup] AS VARCHAR(4)), 29),
           b.[number], N'Tricab Australia', 'AUD', b.[net], b.[tax], b.[net] + b.[tax],
           DATEADD(DAY, b.[issuedOff], @Today), DATEADD(DAY, b.[dueOff], @Today), b.[kind], b.[po],
           b.[qty], b.[qty], b.[rcv], CASE WHEN b.[qty] > 0 THEN b.[net] / b.[qty] ELSE 0 END,
           CASE WHEN b.[n] IN (4, 6, 7, 8, 9, 10) THEN N'Sample acceptance B' + RIGHT('000' + CAST(b.[n] AS VARCHAR(4)), 3) ELSE N'' END,
           CASE WHEN b.[n] IN (4, 6, 7, 8, 9, 10) THEN 1 ELSE 0 END,
           CASE WHEN b.[n] = 10 THEN N'Approved office supplies purchase' ELSE N'' END,
           N'6000', CASE WHEN b.[n] = 9 THEN N'' ELSE N'GST10' END, N'Operations', 0, N'', 0, 0, 0,
           CASE WHEN b.[n] = 7 THEN 1100 WHEN b.[n] = 10 THEN 660 ELSE 0 END,
           b.[stage], CASE WHEN b.[n] IN (4, 6, 7, 8, 9, 10) THEN 1 ELSE 0 END,
           CASE WHEN b.[n] IN (6, 7, 8, 10) THEN N'Posted' ELSE N'Not exported' END,
           CASE WHEN b.[n] IN (6, 7, 8, 10) THEN N'DEMO-ODOO-B' + RIGHT('000' + CAST(b.[n] AS VARCHAR(4)), 3) ELSE N'' END,
           0, 0, N'supplier-' + b.[number] + N'.pdf', CASE WHEN b.[n] = 3 THEN 0.72 ELSE 0.98 END,
           CASE WHEN b.[n] = 5 THEN N'sample-OH-004' ELSE N'sample-B' + RIGHT('000' + CAST(b.[n] AS VARCHAR(4)), 3) END,
           CASE WHEN b.[n] = 10 THEN NULL ELSE 'a23' + RIGHT('00000000000000000000000000000' + CAST(b.[n] AS VARCHAR(4)), 29) END,
           CASE WHEN b.[n] % 2 = 0 THEN @PriyaId ELSE @AlexId END,
           CASE WHEN b.[n] % 2 = 0 THEN N'Priya Shah' ELSE N'Alex Chen' END,
           CASE WHEN b.[n] % 2 = 0 THEN N'ap2.approver@tricab.com' ELSE N'manushaf@tricab.com' END
    FROM @B b
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_invoices] x
                      WHERE x.[id] = 'a22' + RIGHT('00000000000000000000000000000' + CAST(b.[n] AS VARCHAR(4)), 29));

    /* ---------------- mail (9 per invoice except B010, MST001, MCN001) ---------------- */
    DECLARE @M TABLE ([key] VARCHAR(10), [idn] INT, [sup] INT, [inv] INT, [dayOff] INT, [hhmm] VARCHAR(5), [subject] NVARCHAR(300),
                      [body] NVARCHAR(1000), [category] NVARCHAR(60), [status] NVARCHAR(24), [due] INT, [draft] NVARCHAR(1000));
    INSERT INTO @M VALUES
        ('M001', 1, 1, 1, 0, '09:50', N'Invoice CM-2200', N'Please find invoice CM-2200 for 1,000 units, AUD 22,000 including tax, against PO-1001.', N'Supplier invoice', N'In progress', 27, N'Thank you for sending invoice CM-2200. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'),
        ('M002', 2, 2, 2, 0, '09:46', N'Service invoice and payment query', N'Please confirm receipt of our freight services invoice.', N'Payment enquiry', N'In progress', 5, N'Thank you for sending invoice MF-4421. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'),
        ('M003', 3, 3, 3, 0, '09:42', N'Invoice OH-004', N'Please find our invoice attached and advise if further information is needed.', N'Supplier invoice', N'In progress', 14, N'Thank you for sending invoice OH-004. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'),
        ('M004', 4, 4, 4, 0, '09:38', N'Invoice PE-8821', N'Please find our invoice attached and advise if further information is needed.', N'Supplier invoice', N'In progress', 3, N'Thank you for sending invoice PE-8821. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'),
        ('M005', 5, 3, 5, 0, '09:34', N'Invoice OH-004 — resent copy', N'Please find our invoice attached and advise if further information is needed.', N'Supplier invoice', N'In progress', 14, N'Thank you for sending invoice OH-004. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'),
        ('M006', 6, 5, 6, -1, '08:30', N'Invoice RE-1350', N'Please find our invoice attached and advise if further information is needed.', N'Supplier invoice', N'In progress', -15, N'Thank you for sending invoice RE-1350. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'),
        ('M007', 7, 6, 7, 0, '08:46', N'Invoice DS-9921', N'Please find our invoice attached and advise if further information is needed.', N'Supplier invoice', N'In progress', 3, N'Thank you for sending invoice DS-9921. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'),
        ('M008', 8, 7, 8, 0, '08:42', N'Bank details changed — please update', N'Please update our bank details before the next payment.', N'Bank detail change', N'On hold', 2, N'Thank you for your email. Bank detail changes require independent verification through our existing trusted contact. No account details have been changed.'),
        ('M009', 9, 8, 9, 0, '08:38', N'Invoice SS-1108', N'Please find our invoice attached and advise if further information is needed.', N'Supplier invoice', N'In progress', 7, N'Thank you for sending invoice SS-1108. We have received it and will review it against our purchase and receipt records. We will contact you if any information is needed.'),
        ('MST001', 10, 5, NULL, 0, '07:50', N'Supplier statement — River Equipment', N'Our statement shows AUD 6,600 outstanding. Please reconcile against your records.', N'Supplier statement', N'In progress', 0, N'Thank you for your statement. We will reconcile it against our posted invoices and contact you regarding any differences.'),
        ('MCN001', 11, 1, 1, 0, '07:35', N'Credit note CN-2200 for short receipt', N'Please find credit note CN-2200: net AUD 2,000 plus tax AUD 200, gross AUD 2,200, relating to CM-2200.', N'Credit note', N'In progress', 0, N'Thank you for credit note CN-2200. We will verify it against the invoice and receipt evidence before updating the record.');

    /* Mail ids: invoice mails are a23 + the invoice number (so the invoice's mail_id above points at them). */
    INSERT INTO [finance].[ap2_mail]
        ([id], [supplier_id], [invoice_id], [mailbox], [received_at], [subject], [body_preview], [body], [category],
         [owner_user_id], [owner_name], [owner_email], [status], [is_read], [due], [from_address], [cc], [reply_to],
         [record_history], [draft], [sent])
    SELECT CASE WHEN m.[idn] <= 9 THEN 'a23' + RIGHT('00000000000000000000000000000' + CAST(m.[idn] AS VARCHAR(4)), 29)
                ELSE 'a24' + RIGHT('00000000000000000000000000000' + CAST(m.[idn] AS VARCHAR(4)), 29) END,
           'a21' + RIGHT('00000000000000000000000000000' + CAST(m.[sup] AS VARCHAR(4)), 29),
           CASE WHEN m.[inv] IS NULL THEN NULL ELSE 'a22' + RIGHT('00000000000000000000000000000' + CAST(m.[inv] AS VARCHAR(4)), 29) END,
           N'ap@tricab.com',
           DATEADD(SECOND, DATEDIFF(SECOND, CAST('00:00' AS TIME(0)), CAST(m.[hhmm] AS TIME(0))), CAST(DATEADD(DAY, m.[dayOff], @Today) AS DATETIME2(6))),
           m.[subject], LEFT(m.[body], 2000), m.[body], m.[category],
           CASE WHEN m.[sup] % 2 = 0 THEN @PriyaId ELSE @AlexId END,
           CASE WHEN m.[sup] % 2 = 0 THEN N'Priya Shah' ELSE N'Alex Chen' END,
           CASE WHEN m.[sup] % 2 = 0 THEN N'ap2.approver@tricab.com' ELSE N'manushaf@tricab.com' END,
           m.[status], 0, DATEADD(DAY, m.[due], @Today), s.[email], N'', s.[email], 1, m.[draft], 0
    FROM @M m JOIN @S s ON s.[n] = m.[sup]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_mail] x
                      WHERE x.[id] = CASE WHEN m.[idn] <= 9 THEN 'a23' + RIGHT('00000000000000000000000000000' + CAST(m.[idn] AS VARCHAR(4)), 29)
                                          ELSE 'a24' + RIGHT('00000000000000000000000000000' + CAST(m.[idn] AS VARCHAR(4)), 29) END);

    /* ---------------- statements ---------------- */
    INSERT INTO [finance].[ap2_statements] ([id], [supplier_id], [date], [amount], [currency], [reference], [status])
    SELECT v.[id], v.[sup], @Today, v.[amount], 'AUD', v.[ref], N'Unreviewed'
    FROM (VALUES
        ('a25' + REPLICATE('0', 28) + '1', 'a21' + REPLICATE('0', 28) + '5', CAST(6600 AS DECIMAL(18,2)), N'River-statement.pdf'),
        ('a25' + REPLICATE('0', 28) + '2', 'a21' + REPLICATE('0', 28) + '6', CAST(3300 AS DECIMAL(18,2)), N'Digital-statement.pdf')
    ) v([id], [sup], [amount], [ref])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_statements] x WHERE x.[id] = v.[id]);

    /* ---------------- PO forecasts ---------------- */
    INSERT INTO [finance].[ap2_po_forecast] ([id], [po], [supplier_id], [amount], [currency], [date])
    SELECT v.[id], v.[po], v.[sup], v.[amount], 'AUD', DATEADD(DAY, v.[off], @Today)
    FROM (VALUES
        ('a26' + REPLICATE('0', 28) + '1', N'PO-1001', 'a21' + REPLICATE('0', 28) + '1', CAST(22000 AS DECIMAL(18,2)), 27),
        ('a26' + REPLICATE('0', 28) + '2', N'PO-1010', 'a21' + REPLICATE('0', 28) + '5', CAST(4800 AS DECIMAL(18,2)), 12)
    ) v([id], [po], [sup], [amount], [off])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_po_forecast] x WHERE x.[id] = v.[id]);

    /* ---------------- history: one line per invoice (append only; skipped if the invoice already has one) ---- */
    INSERT INTO [finance].[ap2_history] ([id], [supplier_id], [invoice_id], [type], [detail], [actor_name])
    SELECT 'a27' + RIGHT('00000000000000000000000000000' + CAST(b.[n] AS VARCHAR(4)), 29),
           'a21' + RIGHT('00000000000000000000000000000' + CAST(b.[sup] AS VARCHAR(4)), 29),
           'a22' + RIGHT('00000000000000000000000000000' + CAST(b.[n] AS VARCHAR(4)), 29),
           N'Invoice received', b.[number] + N' · sample data', N'Sample data'
    FROM @B b
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_history] x
                      WHERE x.[id] = 'a27' + RIGHT('00000000000000000000000000000' + CAST(b.[n] AS VARCHAR(4)), 29));

    COMMIT TRANSACTION;

    SELECT (SELECT COUNT(*) FROM [finance].[ap2_suppliers]) AS [suppliers], (SELECT COUNT(*) FROM [finance].[ap2_invoices]) AS [invoices],
           (SELECT COUNT(*) FROM [finance].[ap2_mail]) AS [mail], (SELECT COUNT(*) FROM [finance].[ap2_statements]) AS [statements],
           (SELECT COUNT(*) FROM [finance].[ap2_po_forecast]) AS [po_forecast];
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @Msg NVARCHAR(4000) = ERROR_MESSAGE();
    RAISERROR(@Msg, 16, 1);
END CATCH;
