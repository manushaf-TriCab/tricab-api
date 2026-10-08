/*
================================================================================
  LOCAL ONLY. NOT FOR ANY SHARED DATABASE. Tri-AR sample data (Phase 5, T5.12).
  Refuses to run against Azure SQL (see the guard below); run against the local
  Docker database only, after create-finance-ar-schema.sql, create-finance-
  attachments-schema.sql, seed-finance-ar-permissions.sql, seed-finance-ar-roles.sql
  and seed-finance-mailboxes-local.sql. Applying it to tricab-mel is forbidden.

  The dataset behind the 85 Tri-AR reference screenshots (finance-reference/
  screenshots/tri-ar, 8 Oct 2026), so a page can be compared with its reference:

      16 register records (15 live and one in the Bin) across eleven categories and
      both methods: in progress, needs review, completed, two overdue on any day
      this is run, one with a due date today plus a few days, one assigned to
      nobody; 5 attachments (three remittance advices with 9 lines in all, one of
      which does not add up, a credit application that was skipped, and a document
      whose reading failed); 2 sent-mail rows on one record (one sent, one failed);
      3 credit note requests (form sent, submitted, completed); 3 scan runs.

  Invented throughout: customers, senders (example.test), invoices, amounts and
  PDFs. The people are local test users with invented names and addresses
  (@sample.local), the same ones seed-finance-task-sample-local.sql creates, so
  the two samples can be applied in either order.

  The five files' bytes are NOT in this script: they are made by
  src/Finance/Ar/ArSampleFiles.php and loaded into the in-memory blob store when
  FINANCE_AR_SAMPLE_FILES=true (development only). The rows here name each file's
  blob path, size and SHA-256, and FinanceArSampleSeedScriptTest pins that they
  match that class.

  Dates are relative to the run: due dates to today in Australia/Melbourne, times
  to the moment it is run, so "overdue" stays overdue.

  Idempotent: every row has a fixed id and is inserted only if missing, so running
  it twice adds nothing. It never changes a row that exists.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

/* ---------------------------------------------------------------------------
   Guard: local SQL Server only. Azure SQL Database, Managed Instance and Azure
   Synapse report engine editions 5, 8 and 6; the local Docker server is 3
   (Enterprise and Developer), 2 (Standard) or 4 (Express).
   --------------------------------------------------------------------------- */
IF CAST(SERVERPROPERTY('EngineEdition') AS INT) NOT IN (2, 3, 4)
   OR @@SERVERNAME LIKE N'%database.windows.net%'
   OR @@SERVERNAME LIKE N'%tricab-mel%'
BEGIN
    THROW 54130, N'The Tri-AR sample seed is for the local Docker database only. Refusing to run against Azure SQL.', 1;
END;
IF OBJECT_ID(N'[finance].[ar_email_records]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[attachments]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[mail_deliveries]', N'U') IS NULL
   OR OBJECT_ID(N'[identity].[Users]', N'U') IS NULL
BEGIN
    THROW 54131, N'Run create-finance-ar-schema.sql, create-finance-attachments-schema.sql and the identity schema first.', 1;
END;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Today DATE = CAST(SYSDATETIMEOFFSET() AT TIME ZONE N'AUS Eastern Standard Time' AS DATE);
    DECLARE @Now DATETIME2(6) = SYSUTCDATETIME();

    /* ------------------------------------------------------------------
       People: five local test users and their Tri-AR roles.
       ------------------------------------------------------------------ */
    DECLARE @People TABLE (
        [Key]       NVARCHAR(20)  NOT NULL PRIMARY KEY,
        [Email]     NVARCHAR(256) NOT NULL,
        [Name]      NVARCHAR(200) NOT NULL,
        [RoleCode]  NVARCHAR(100) NOT NULL
    );
    INSERT INTO @People ([Key], [Email], [Name], [RoleCode]) VALUES
        (N'admin', N'sample.admin@sample.local', N'Sample Administrator', N'FINANCE_AR_ADMIN'),
        (N'lena',  N'lena.marsh@sample.local',   N'Lena Marsh',           N'FINANCE_AR_OPERATOR'),
        (N'mia',   N'mia.ford@sample.local',     N'Mia Ford',             N'FINANCE_AR_OPERATOR'),
        (N'noah',  N'noah.hale@sample.local',    N'Noah Hale',            N'FINANCE_AR_OPERATOR'),
        (N'owen',  N'owen.reed@sample.local',    N'Owen Reed',            N'FINANCE_AR_REVIEWER');

    INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
    SELECT p.[Email], LOWER(p.[Email]), p.[Name], s.[UserStatusId]
    FROM @People p
    CROSS JOIN [identity].[UserStatuses] s
    WHERE s.[StatusCode] = N'active'
      AND NOT EXISTS (SELECT 1 FROM [identity].[Users] u WHERE u.[NormalizedEmailAddress] = LOWER(p.[Email]));

    INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [IsActive])
    SELECT u.[UserId], r.[RoleId], 1
    FROM @People p
    JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(p.[Email])
    JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = p.[RoleCode]
    WHERE NOT EXISTS (SELECT 1 FROM [identity].[UserRoles] ur WHERE ur.[UserId] = u.[UserId] AND ur.[RoleId] = r.[RoleId]);

    IF (SELECT COUNT(*) FROM @People p
         JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(p.[Email])
         JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1
         JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND UPPER(r.[RoleCode]) = p.[RoleCode]) < 5
    BEGIN
        THROW 54132, N'The Tri-AR roles are missing. Run seed-finance-ar-permissions.sql and seed-finance-ar-roles.sql first.', 1;
    END;

    DECLARE @Ids TABLE ([Key] NVARCHAR(20) NOT NULL PRIMARY KEY, [UserId] BIGINT NOT NULL, [Name] NVARCHAR(200) NOT NULL, [Email] NVARCHAR(256) NOT NULL);
    INSERT INTO @Ids ([Key], [UserId], [Name], [Email])
    SELECT p.[Key], u.[UserId], p.[Name], p.[Email] FROM @People p JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(p.[Email]);

    /* ------------------------------------------------------------------
       The five files' rows. The bytes come from ArSampleFiles (see above).
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[attachments] ([id], [application_key], [record_type], [record_id], [blob_path], [original_name], [content_type], [byte_size], [sha256], [uploaded_by_user_id], [created_at], [attached_at])
    SELECT v.[id], N'AR', N'email_record', v.[record], v.[blob], v.[name], N'application/pdf', v.[size], v.[sha], NULL, DATEADD(HOUR, -1, @Now), DATEADD(HOUR, -1, @Now)
    FROM (VALUES
        ('5a100000000000000000000000000001', '5a0e0000000000000000000000000001', N'ar/sample/5a100000000000000000000000000001', N'Remittance_4471.pdf', 799, '675489889338e3da4dc4cc4228ac7502ba0309d291bca2410ce81015f46d77e8'),
        ('5a100000000000000000000000000002', '5a0e0000000000000000000000000003', N'ar/sample/5a100000000000000000000000000002', N'EFT_remittance.pdf', 791, 'e237acec7f8bdcc6a276259168e8193990410f1e8adb22a638e21cf13edd000e'),
        ('5a100000000000000000000000000003', '5a0e000000000000000000000000000c', N'ar/sample/5a100000000000000000000000000003', N'Remittance_4398.pdf', 735, 'e74da13ecd496da9df14077a42990c897ffff1e852678f8cb8e7c4f8d1f98db9'),
        ('5a100000000000000000000000000004', '5a0e0000000000000000000000000008', N'ar/sample/5a100000000000000000000000000004', N'Credit_application_Bluewater.pdf', 689, 'ef516eb52196875d68b448798aa300917b1488c0954ec8e6f9bb6b8a8d1b875f'),
        ('5a100000000000000000000000000005', '5a0e0000000000000000000000000006', N'ar/sample/5a100000000000000000000000000005', N'Damaged_drum_photo_report.pdf', 677, 'd388f1fa5623f11e41c8f5ec5f06e6b55c64402221cd6156e5a7d7b191399410')
    ) v ([id], [record], [blob], [name], [size], [sha])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[attachments] a WHERE a.[id] = v.[id]);

    /* ------------------------------------------------------------------
       The register: 16 records, one in the Bin.
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[ar_email_records]
        ([id], [email_date], [customer_name], [sender_email], [sender_name], [subject], [body], [ai_summary], [follow_up_category], [assigned_user_id], [assigned_name],
         [proposed_response_type], [proposed_response], [follow_up_status], [due_date], [final_outcome], [source_message_id], [message_unreachable], [mailbox],
         [classification_confidence], [status], [assigned_sales_rep_email], [credit_note_request_id], [reminder_sent_at], [deleted_at], [deleted_by_user_id], [deleted_by],
         [created_date], [updated_date])
    SELECT v.[id], DATEADD(HOUR, -v.[hours], @Now), v.[customer], v.[senderName], v.[sender], v.[subject], v.[body], v.[summary], v.[category], i.[UserId], ISNULL(i.[Name], N''),
           v.[method], v.[response], v.[fstatus], CASE WHEN v.[due] IS NULL THEN NULL ELSE DATEADD(DAY, v.[due], @Today) END, v.[outcome], v.[msg], 0, N'ar@tricab.com',
           v.[conf], v.[status], v.[rep], v.[cn], NULL,
           CASE WHEN v.[binned] = 1 THEN DATEADD(HOUR, -6, @Now) END, CASE WHEN v.[binned] = 1 THEN (SELECT [UserId] FROM @Ids WHERE [Key] = N'admin') END,
           CASE WHEN v.[binned] = 1 THEN N'sample.admin@sample.local' ELSE N'' END,
           DATEADD(MINUTE, 4, DATEADD(HOUR, -v.[hours], @Now)), DATEADD(MINUTE, 4, DATEADD(HOUR, -v.[hours], @Now))
    FROM (VALUES
        ('5a0e0000000000000000000000000001', 3, N'Northgate Electrical Pty Ltd', N'Priya Raman', N'accounts@northgate-electrical.example.test', N'Remittance advice - payment 4471', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Payment of $18,420.50 covering three September invoices.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Priya Raman' + NCHAR(10) + N'Northgate Electrical Pty Ltd' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Payment of $18,420.50 covering three September invoices.', N'remittance_advice', N'lena', N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'in_progress', 2, N'', N'SAMPLE-MESSAGE-001', 0.97, N'new', N'', NULL, 0),
        ('5a0e0000000000000000000000000002', 5, N'Harbour Line Contractors', N'Tom Ellery', N'tom.ellery@harbourline.example.test', N'RE: Statement request for September', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Customer asks for a September statement and copies of two invoices.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Tom Ellery' + NCHAR(10) + N'Harbour Line Contractors' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Customer asks for a September statement and copies of two invoices.', N'invoice_statement_requests', N'mia', N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'in_progress', 1, N'', N'SAMPLE-MESSAGE-002', 0.91, N'reviewed', N'', NULL, 0),
        ('5a0e0000000000000000000000000003', 8, N'Kestrel Mining Services', N'Accounts Payable', N'ap@kestrelmining.example.test', N'Remittance - EFT 20261007', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Remittance for four invoices; the lines do not add up to the stated total.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Accounts Payable' + NCHAR(10) + N'Kestrel Mining Services' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Remittance for four invoices; the lines do not add up to the stated total.', N'remittance_advice', NULL, N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'needs_review', NULL, N'', N'SAMPLE-MESSAGE-003', 0.62, N'new', N'', NULL, 0),
        ('5a0e0000000000000000000000000004', 20, N'Southbank Fitout Co', N'Dana Whitlock', N'dana@southbankfitout.example.test', N'Credit limit increase request', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Asks to raise the credit limit from $50,000 to $80,000 for a new project.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Dana Whitlock' + NCHAR(10) + N'Southbank Fitout Co' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Asks to raise the credit limit from $50,000 to $80,000 for a new project.', N'exceeding_credit_limits', N'lena', N'phone', N'Thanks for your email. We have received this and will come back to you shortly.', N'in_progress', 4, N'', N'SAMPLE-MESSAGE-004', 0.88, N'new', N'', NULL, 0),
        ('5a0e0000000000000000000000000005', 26, N'Ridgeway Solar', N'Marcus Oyelaran', N'marcus@ridgewaysolar.example.test', N'Please release order SO-20418', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Order on credit hold; customer says the overdue invoice was paid on Monday.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Marcus Oyelaran' + NCHAR(10) + N'Ridgeway Solar' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Order on credit hold; customer says the overdue invoice was paid on Monday.', N'release_customer_orders', N'mia', N'phone', N'Thanks for your email. We have received this and will come back to you shortly.', N'in_progress', -1, N'', N'SAMPLE-MESSAGE-005', 0.93, N'new', N'', NULL, 0),
        ('5a0e0000000000000000000000000006', 30, N'Calder & Finch Builders', N'Jo Finch', N'jo@calderfinch.example.test', N'Credit note for damaged drum - INV-88213', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Requests a credit for one damaged drum delivered on invoice INV-88213.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Jo Finch' + NCHAR(10) + N'Calder & Finch Builders' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Requests a credit for one damaged drum delivered on invoice INV-88213.', N'credit_notes', N'noah', N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'in_progress', 3, N'', N'SAMPLE-MESSAGE-006', 0.90, N'new', N'theo.park@sample.local', '5a130000000000000000000000000001', 0),
        ('5a0e0000000000000000000000000007', 44, N'Harbour Line Contractors', N'Tom Ellery', N'tom.ellery@harbourline.example.test', N'Overdue account - payment plan', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Proposes paying the overdue balance in three instalments.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Tom Ellery' + NCHAR(10) + N'Harbour Line Contractors' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Proposes paying the overdue balance in three instalments.', N'overdue_collections', N'noah', N'phone', N'Thanks for your email. We have received this and will come back to you shortly.', N'in_progress', -3, N'', N'SAMPLE-MESSAGE-007', 0.86, N'reviewed', N'', NULL, 0),
        ('5a0e0000000000000000000000000008', 50, N'Bluewater Marine Electrical', N'Sarah Okafor', N'sarah.okafor@bluewatermarine.example.test', N'New trade account application', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Completed credit application with trade references attached.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Sarah Okafor' + NCHAR(10) + N'Bluewater Marine Electrical' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Completed credit application with trade references attached.', N'credit_application', NULL, N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'needs_review', NULL, N'', N'SAMPLE-MESSAGE-008', 0.71, N'new', N'', NULL, 0),
        ('5a0e0000000000000000000000000009', 55, N'Vantage Compliance Group', N'Supplier Onboarding', N'onboarding@vantagecompliance.example.test', N'Annual supplier questionnaire 2026', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Yearly supplier questionnaire to be completed by 31 October.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Supplier Onboarding' + NCHAR(10) + N'Vantage Compliance Group' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Yearly supplier questionnaire to be completed by 31 October.', N'compliance_questionnaire', NULL, N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'needs_review', NULL, N'', N'SAMPLE-MESSAGE-009', 0.83, N'new', N'', NULL, 0),
        ('5a0e000000000000000000000000000a', 70, N'TriCab Sales (Brisbane)', N'Ivan Cole', N'ivan.cole@sample.local', N'Can we extend terms for Ridgeway?', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Sales asks whether Ridgeway Solar can move to 45-day terms.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Ivan Cole' + NCHAR(10) + N'TriCab Sales (Brisbane)' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Sales asks whether Ridgeway Solar can move to 45-day terms.', N'internal_sales_query', N'lena', N'phone', N'Thanks for your email. We have received this and will come back to you shortly.', N'in_progress', 5, N'', N'SAMPLE-MESSAGE-010', 0.89, N'new', N'', NULL, 0),
        ('5a0e000000000000000000000000000b', 74, N'Cable Expo Australia', N'Events Team', N'news@cableexpo.example.test', N'Early bird tickets now open', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Promotional mailing. No action needed.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Events Team' + NCHAR(10) + N'Cable Expo Australia' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Promotional mailing. No action needed.', N'marketing_no_action', NULL, N'email', N'', N'completed', NULL, N'No action: marketing.', N'SAMPLE-MESSAGE-011', 0.99, N'skipped', N'', NULL, 0),
        ('5a0e000000000000000000000000000c', 96, N'Northgate Electrical Pty Ltd', N'Priya Raman', N'accounts@northgate-electrical.example.test', N'Remittance advice - payment 4398', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Payment of $6,912.00 for two August invoices.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Priya Raman' + NCHAR(10) + N'Northgate Electrical Pty Ltd' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Payment of $6,912.00 for two August invoices.', N'remittance_advice', N'mia', N'email', N'', N'completed', NULL, N'Receipted and allocated in the ledger.', N'SAMPLE-MESSAGE-012', 0.98, N'reviewed', N'', NULL, 0),
        ('5a0e000000000000000000000000000d', 120, N'Outback Pump & Power', N'Glen Maddox', N'glen@outbackpump.example.test', N'Question about your bank details', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Asks to confirm bank details before making a first payment.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Glen Maddox' + NCHAR(10) + N'Outback Pump & Power' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Asks to confirm bank details before making a first payment.', N'general_inquiry', NULL, N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'needs_review', NULL, N'', N'SAMPLE-MESSAGE-013', 0.58, N'new', N'', NULL, 0),
        ('5a0e000000000000000000000000000e', 150, N'Kestrel Mining Services', N'Accounts Payable', N'ap@kestrelmining.example.test', N'Invoice copy needed - INV-87990', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Requested a copy of invoice INV-87990.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Accounts Payable' + NCHAR(10) + N'Kestrel Mining Services' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Requested a copy of invoice INV-87990.', N'invoice_statement_requests', N'mia', N'email', N'', N'completed', NULL, N'Invoice copy sent by email.', N'SAMPLE-MESSAGE-014', 0.95, N'sent', N'', NULL, 0),
        ('5a0e000000000000000000000000000f', 170, N'Unknown sender', N'', N'no-reply@mailer.example.test', N'Undeliverable: Statement for August', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Bounce message for a statement sent to an old address.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Mail system' + NCHAR(10) + N'Unknown sender' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Bounce message for a statement sent to an old address.', N'other', NULL, N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'needs_review', NULL, N'', N'SAMPLE-MESSAGE-015', 0.41, N'new', N'', NULL, 0),
        ('5a0e0000000000000000000000000010', 200, N'Southbank Fitout Co', N'Dana Whitlock', N'dana@southbankfitout.example.test', N'Old thread - please ignore', N'Hi team,' + NCHAR(10) + N'' + NCHAR(10) + N'Superseded by a later email.' + NCHAR(10) + N'' + NCHAR(10) + N'Could you let me know once this has been looked at?' + NCHAR(10) + N'' + NCHAR(10) + N'Kind regards,' + NCHAR(10) + N'Dana Whitlock' + NCHAR(10) + N'Southbank Fitout Co' + NCHAR(10) + N'' + NCHAR(10) + N'(This message is invented sample data.)', N'Superseded by a later email.', N'general_inquiry', NULL, N'email', N'Thanks for your email. We have received this and will come back to you shortly.', N'in_progress', NULL, N'', N'SAMPLE-MESSAGE-016', 0.80, N'new', N'', NULL, 1)
    ) v ([id], [hours], [customer], [senderName], [sender], [subject], [body], [summary], [category], [assignee], [method], [response], [fstatus], [due], [outcome], [msg], [conf], [status], [rep], [cn], [binned])
    LEFT JOIN @Ids i ON i.[Key] = v.[assignee]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ar_email_records] r WHERE r.[id] = v.[id] OR r.[source_message_id] = v.[msg]);

    /* ------------------------------------------------------------------
       The files on the records, with what the model read from them.
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[ar_email_attachments]
        ([id], [email_record_id], [attachment_id], [is_remittance], [payer_name], [payment_date], [payment_reference], [currency], [total_amount], [extraction_confidence], [reconciles],
         [extraction_status], [extraction_error], [notes], [extracted_at], [created_date], [updated_date])
    SELECT v.[id], v.[record], v.[file], v.[remit], v.[payer], v.[pdate], v.[pref], N'AUD', v.[total], v.[conf], v.[rec], v.[status], v.[err], v.[notes],
           CASE WHEN v.[status] = N'extracted' THEN DATEADD(HOUR, -1, @Now) END, DATEADD(HOUR, -1, @Now), DATEADD(HOUR, -1, @Now)
    FROM (VALUES
        ('5a0f0000000000000000000000000001', '5a0e0000000000000000000000000001', '5a100000000000000000000000000001', 1, N'Northgate Electrical Pty Ltd', CONVERT(NVARCHAR(40), DATEADD(DAY, -1, @Today), 23), N'4471', 18420.50, 0.96, 1, N'extracted', NULL, NULL),
        ('5a0f0000000000000000000000000002', '5a0e0000000000000000000000000003', '5a100000000000000000000000000002', 1, N'Kestrel Mining Services', CONVERT(NVARCHAR(40), DATEADD(DAY, -1, @Today), 23), N'EFT 20261007', 16000.00, 0.74, 0, N'extracted', NULL, N'The invoice lines add to 16,185.40, not the stated total.'),
        ('5a0f0000000000000000000000000003', '5a0e000000000000000000000000000c', '5a100000000000000000000000000003', 1, N'Northgate Electrical Pty Ltd', CONVERT(NVARCHAR(40), DATEADD(DAY, -4, @Today), 23), N'4398', 6912.00, 0.98, 1, N'extracted', NULL, NULL),
        ('5a0f0000000000000000000000000004', '5a0e0000000000000000000000000008', '5a100000000000000000000000000004', 0, NULL, NULL, NULL, NULL, NULL, NULL, N'skipped', NULL, NULL),
        ('5a0f0000000000000000000000000005', '5a0e0000000000000000000000000006', '5a100000000000000000000000000005', 0, NULL, NULL, NULL, NULL, NULL, NULL, N'failed', N'The document could not be read as a remittance advice.', NULL)
    ) v ([id], [record], [file], [remit], [payer], [pdate], [pref], [total], [conf], [rec], [status], [err], [notes])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ar_email_attachments] a WHERE a.[id] = v.[id]);

    INSERT INTO [finance].[ar_remittance_lines] ([id], [email_attachment_id], [email_record_id], [line_no], [invoice_number], [invoice_date], [gross_amount], [discount_amount], [paid_amount], [created_date])
    SELECT v.[id], v.[attachment], v.[record], v.[line], v.[invoice], CONVERT(NVARCHAR(40), DATEADD(DAY, v.[dateOffset], @Today), 23), v.[gross], 0, v.[paid], @Now
    FROM (VALUES
        ('5a110000000000000000000000000001', '5a0f0000000000000000000000000001', '5a0e0000000000000000000000000001', 1, N'INV-88101', -36, 6140.00, 6140.00),
        ('5a110000000000000000000000000002', '5a0f0000000000000000000000000001', '5a0e0000000000000000000000000001', 2, N'INV-88130', -31, 7880.50, 7880.50),
        ('5a110000000000000000000000000003', '5a0f0000000000000000000000000001', '5a0e0000000000000000000000000001', 3, N'INV-88164', -27, 4400.00, 4400.00),
        ('5a110000000000000000000000000004', '5a0f0000000000000000000000000002', '5a0e0000000000000000000000000003', 1, N'INV-87950', -48, 2310.00, 2310.00),
        ('5a110000000000000000000000000005', '5a0f0000000000000000000000000002', '5a0e0000000000000000000000000003', 2, N'INV-87990', -44, 9450.00, 9450.00),
        ('5a110000000000000000000000000006', '5a0f0000000000000000000000000002', '5a0e0000000000000000000000000003', 3, N'INV-88012', -40, 1125.40, 1125.40),
        ('5a110000000000000000000000000007', '5a0f0000000000000000000000000002', '5a0e0000000000000000000000000003', 4, N'INV-88077', -33, 3300.00, 3300.00),
        ('5a110000000000000000000000000008', '5a0f0000000000000000000000000003', '5a0e000000000000000000000000000c', 1, N'INV-87611', -70, 3456.00, 3456.00),
        ('5a110000000000000000000000000009', '5a0f0000000000000000000000000003', '5a0e000000000000000000000000000c', 2, N'INV-87640', -66, 3456.00, 3456.00)
    ) v ([id], [attachment], [record], [line], [invoice], [dateOffset], [gross], [paid])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ar_remittance_lines] l WHERE l.[id] = v.[id]);

    /* ------------------------------------------------------------------
       Sent mail on one record: the ledger rows (nothing is sent) and the links
       that keep the body. One sent, one failed.
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[mail_deliveries]
        ([id], [application_key], [actor_user_id], [mailbox_email], [to_addresses_json], [cc_addresses_json], [subject], [body_sha256],
         [attachment_names_json], [idempotency_key], [status], [error_message], [created_at], [sent_at])
    SELECT v.[id], N'AR', (SELECT [UserId] FROM @Ids WHERE [Key] = N'mia'), N'ar@tricab.com', N'["ap@kestrelmining.example.test"]', N'[]', N'RE: Invoice copy needed - INV-87990',
           REPLICATE(N'0', 64), N'[]', NULL, v.[status], v.[error], DATEADD(HOUR, -v.[hoursAgo], @Now), CASE WHEN v.[status] = N'sent' THEN DATEADD(HOUR, -v.[hoursAgo], @Now) END
    FROM (VALUES
        ('5a150000000000000000000000000001', N'failed', N'The mailbox could not send this message.', 148),
        ('5a150000000000000000000000000002', N'sent',   CAST(NULL AS NVARCHAR(500)),                   147)
    ) v ([id], [status], [error], [hoursAgo])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[mail_deliveries] d WHERE d.[id] = v.[id]);

    INSERT INTO [finance].[ar_outbound_messages] ([id], [email_record_id], [delivery_id], [sender_user_id], [sender_user], [threaded], [body], [created_date])
    SELECT v.[id], '5a0e000000000000000000000000000e', v.[delivery], (SELECT [UserId] FROM @Ids WHERE [Key] = N'mia'), N'mia.ford@sample.local', 1,
           N'Hi,' + NCHAR(10) + NCHAR(10) + N'Please find the invoice copy attached.' + NCHAR(10) + NCHAR(10) + N'Regards,' + NCHAR(10) + N'Accounts Receivable',
           DATEADD(HOUR, -v.[hoursAgo], @Now)
    FROM (VALUES
        ('5a120000000000000000000000000001', '5a150000000000000000000000000001', 148),
        ('5a120000000000000000000000000002', '5a150000000000000000000000000002', 147)
    ) v ([id], [delivery], [hoursAgo])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ar_outbound_messages] o WHERE o.[id] = v.[id]);

    /* ------------------------------------------------------------------
       Credit note requests in three states. Only the SHA-256 of each token is
       stored (question Q6); the sample tokens are printed at the end so a form can
       be opened locally.
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[ar_credit_note_requests]
        ([id], [email_record_id], [customer_name], [customer_email], [sales_rep_name], [sales_rep_email], [invoice_number], [credit_amount], [reason], [original_request],
         [token_hash], [token_expires_at], [status], [form_sent_date], [submitted_date], [reviewed_at], [created_date], [updated_date])
    SELECT v.[id], v.[record], v.[customer], v.[customerEmail], v.[repName], v.[repEmail], v.[invoice], v.[amount], v.[reason], N'Customer asked for a credit (sample).',
           LOWER(CONVERT(CHAR(64), HASHBYTES('SHA2_256', v.[token]), 2)), DATEADD(HOUR, v.[expiresH], @Now), v.[status],
           DATEADD(HOUR, -2, DATEADD(DAY, -v.[sentDays], @Now)),
           CASE WHEN v.[submitted] IS NULL THEN NULL ELSE DATEADD(DAY, v.[submitted], @Now) END,
           CASE WHEN v.[reviewed] IS NULL THEN NULL ELSE DATEADD(DAY, v.[reviewed], @Now) END,
           DATEADD(HOUR, -2, DATEADD(DAY, -v.[sentDays], @Now)), DATEADD(HOUR, -2, DATEADD(DAY, -v.[sentDays], @Now))
    FROM (VALUES
        ('5a130000000000000000000000000001', '5a0e0000000000000000000000000006', N'Calder & Finch Builders', N'jo@calderfinch.example.test', N'Theo Park', N'theo.park@sample.local', N'INV-88213', 0.00, N'', N'form_sent', 'sample-token-01-local-run-only', 60, NULL, NULL, 0),
        ('5a130000000000000000000000000002', NULL, N'Southbank Fitout Co', N'dana@southbankfitout.example.test', N'Rosa Wynn', N'rosa.wynn@sample.local', N'INV-87702', 1840.00, N'Short delivery: 2 of 10 drums not received.', N'submitted', 'sample-token-02-local-run-only', 30, -1, NULL, 2),
        ('5a130000000000000000000000000003', NULL, N'Ridgeway Solar', N'marcus@ridgewaysolar.example.test', N'Ivan Cole', N'ivan.cole@sample.local', N'INV-86950', 512.60, N'Pricing error on line 3.', N'completed', 'sample-token-03-local-run-only', -200, -9, -8, 12)
    ) v ([id], [record], [customer], [customerEmail], [repName], [repEmail], [invoice], [amount], [reason], [status], [token], [expiresH], [submitted], [reviewed], [sentDays])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ar_credit_note_requests] c WHERE c.[id] = v.[id]);

    /* ------------------------------------------------------------------
       Scan history, so "already scanned" has something to list: two daily scans
       and one period scan that an operator stopped.
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[ar_scan_runs]
        ([id], [started_at], [finished_at], [mailbox], [status], [watermark], [total_fetched], [new_records], [skipped_records], [failed_enrichments], [errors],
         [from_date], [to_date], [requested_by_user_id], [requested_by], [total_messages], [processed_messages], [claim_expires_at], [created_date])
    SELECT v.[id], DATEADD(HOUR, -1, DATEADD(DAY, -v.[daysAgo], @Now)), DATEADD(MINUTE, 3, DATEADD(HOUR, -1, DATEADD(DAY, -v.[daysAgo], @Now))), N'ar@tricab.com', v.[status],
           CASE WHEN v.[status] = N'completed' THEN DATEADD(HOUR, -1, DATEADD(DAY, -v.[daysAgo], @Now)) END,
           v.[fetched], v.[new], v.[fetched] - v.[new], 0, NULL,
           CASE WHEN v.[period] = 1 THEN DATEADD(DAY, -1, @Today) END, CASE WHEN v.[period] = 1 THEN @Today END,
           CASE WHEN v.[period] = 1 THEN (SELECT [UserId] FROM @Ids WHERE [Key] = N'admin') END,
           CASE WHEN v.[period] = 1 THEN N'Sample Administrator' ELSE N'Scheduled scan' END,
           v.[fetched], CASE WHEN v.[period] = 1 THEN v.[new] ELSE v.[fetched] END, NULL, DATEADD(HOUR, -1, DATEADD(DAY, -v.[daysAgo], @Now))
    FROM (VALUES
        ('5a140000000000000000000000000001', 2, N'completed', 9, 9, 0),
        ('5a140000000000000000000000000002', 1, N'completed', 5, 5, 0),
        ('5a140000000000000000000000000003', 0, N'stopped',   4, 2, 1)
    ) v ([id], [daysAgo], [status], [fetched], [new], [period])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ar_scan_runs] s WHERE s.[id] = v.[id]);

    /* ------------------------------------------------------------------
       Guard: the dataset is whole.
       ------------------------------------------------------------------ */
    IF (SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [id] LIKE '5a0e%' AND [deleted_at] IS NULL) <> 15
       OR (SELECT COUNT(*) FROM [finance].[ar_email_records] WHERE [id] LIKE '5a0e%' AND [deleted_at] IS NOT NULL) <> 1
       OR (SELECT COUNT(*) FROM [finance].[ar_email_attachments] WHERE [id] LIKE '5a0f%') <> 5
       OR (SELECT COUNT(*) FROM [finance].[ar_remittance_lines] WHERE [id] LIKE '5a11%') <> 9
       OR (SELECT COUNT(*) FROM [finance].[ar_outbound_messages] WHERE [id] LIKE '5a12%') <> 2
       OR (SELECT COUNT(*) FROM [finance].[ar_credit_note_requests] WHERE [id] LIKE '5a13%') <> 3
       OR (SELECT COUNT(*) FROM [finance].[ar_scan_runs] WHERE [id] LIKE '5a14%') <> 3
    BEGIN
        THROW 54133, N'The Tri-AR sample data is not whole (expected 15 live records and 1 binned, 5 attachments, 9 lines, 2 outbound, 3 credit notes, 3 scan runs).', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [finance].[ar_email_records])        AS [Records],
        (SELECT COUNT(*) FROM [finance].[ar_email_attachments])    AS [Attachments],
        (SELECT COUNT(*) FROM [finance].[ar_remittance_lines])     AS [RemittanceLines],
        (SELECT COUNT(*) FROM [finance].[ar_outbound_messages])    AS [OutboundMessages],
        (SELECT COUNT(*) FROM [finance].[ar_credit_note_requests]) AS [CreditNoteRequests],
        (SELECT COUNT(*) FROM [finance].[ar_scan_runs])            AS [ScanRuns];

    COMMIT TRANSACTION;

    /* The sample forms, for a local run (the first is open, the second submitted, the third completed). */
    PRINT N'Credit note form (form_sent):  http://localhost:5173/finance/ar/credit-note-form?id=5a130000000000000000000000000001&token=sample-token-01-local-run-only';
    PRINT N'Credit note form (submitted):  http://localhost:5173/finance/ar/credit-note-form?id=5a130000000000000000000000000002&token=sample-token-02-local-run-only';
    PRINT N'Credit note form (completed):  http://localhost:5173/finance/ar/credit-note-form?id=5a130000000000000000000000000003&token=sample-token-03-local-run-only';
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
