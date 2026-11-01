/*
================================================================================
  Tri-AP -> Tri-AP2 data migration (Phase 10). Written 26 Oct 2026.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (Manusha or Jeff). It was run
  against the LOCAL Docker database only, after seed-finance-ap-sample-local.sql.

  Reads Tri-AP's [finance].[invoice_*] tables and COPIES into the [finance].[ap2_*]
  tables. It never changes or deletes a Tri-AP row, never copies a blob (an
  attachment keeps its [finance].[attachments] row, application key AP: the
  ap2_mail_attachments.file_id and ap2_invoices.source_file_id rows point at it,
  and Ap2FileService reads a Tri-AP invoice file for them), and sends nothing.
  The one object it creates is [finance].[ap2_migration_map] (even on a dry run).

  Parameters (top of the script):
    @Apply              N'NO' (default) = DRY RUN: does everything in a
                        transaction, prints the counts and ROLLS BACK.
                        N'YES' = commit.
    @OnlyOpenItems      0 (default) = migrate every row.
                        1 = only open items: invoices that are not in the Bin and
                        not Exported (Needs Review, In Progress, Completed), the
                        emails that belong to them, and emails still Unverified or
                        Needs review. Suppliers follow the invoices.
    @AllowSharedDatabase 0 (default) refuses to run on Azure SQL. Only the owner
                        sets 1, after approving the run.

  Idempotent: [finance].[ap2_migration_map](source_table, source_id, target_id)
  records every row copied; a second run finds each source row in the map and adds
  nothing. A source row changed in Tri-AP after it was copied is NOT updated (run
  once, at cut-over, with Tri-AP's scanning stopped). History, approval events and
  the migrated notes are keyed by a hash of the source id, so they repeat safely.
  Everything it writes to ap2_history is type 'Migrated from Tri-AP'.

  Field mapping:
    suppliers   one per odoo_vendor_id; a vendor with no Odoo match is one per
                lower(name) + lower(email) (and joins the Odoo supplier with the
                same name and email); an existing ap2_suppliers row with that
                odoo_partner_id, or the same name and email, is reused. A vendor
                name that is blank becomes '(No vendor recorded)'.
    invoices    invoice_no -> number, company -> entity, po_number -> po (kind
                'Goods PO' with a PO number, else 'No PO'), account_category -> gl,
                cost_centre, net/tax/total (null -> 0), invoice_date -> issued,
                due_date -> due, currency (an invoice in a currency other than
                AUD/USD/NZD/SGD is LEFT OUT and listed in the report; a blank
                currency with no amount becomes AUD),
                attachment -> source_doc, file_id -> source_file_id, content_hash
                -> source_fingerprint, extraction_confidence -> confidence,
                email_record_id -> mail_id.
                status -> stage / import_state: every invoice lands as Needs
                verification (verified = 0, so no checks have passed in Tri-AP2
                yet; the stage is one of Needs verification / Confirmed /
                Posted since the 1 Nov update):
                  Exported                -> import_state 'Exported CSV'
                  in the Bin (deleted_at) -> excluded = 1, reason says who put
                                             it in the Bin
                The stage is recomputed by the rules at the next edit.
                approval_required = 1 for an invoice whose Tri-AP approval
                status was anything but 'Not requested'.
    approvals   approval_status other than 'Not requested' -> one ap2_approvals
                row (kind Invoice) from the invoice's latest Tri-AP batch: the
                requester is the batch's preparer, the approver the decision's
                approver. Approved -> Approved (and the invoice's approval_id),
                Rejected -> Rejected. Pending -> Expired: a Tri-AP batch names no
                approver, so a pending request cannot be decided in Tri-AP2 and
                must be asked again. Subject and comment say "migrated"; an
                ap2_approval_events row 'Migrated' is written. The snapshot is
                NULL, so an Approved migration reads as "approval no longer
                current" until it is re-approved or backfilled (see the report).
    emails      invoice_emails -> ap2_mail: sender_email -> from_address and
                reply_to, body_preview -> body_preview and body (Tri-AP stored no
                body), classification Invoice -> 'Supplier invoice' and anything
                else -> 'Other', review_status Verified or in the Bin -> status
                'Done', else 'In progress', proposed_response -> draft,
                classification_reason / confidence -> ai_summary / ai_confidence,
                supplier = the supplier of its first invoice, else the supplier
                whose address matches the sender. invoice_email_attachments ->
                ap2_mail_attachments (file_id reused; size null -> 0).
    outbound    invoice_outbound_messages (with their mail_deliveries) -> one
                ap2_history line 'Email sent (migrated)' on the supplier; the
                linked email, when the delivery was sent, gets sent = 1.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @Apply               NVARCHAR(3) = N'NO';
DECLARE @OnlyOpenItems       BIT         = 0;
DECLARE @AllowSharedDatabase BIT         = 0;

IF @Apply NOT IN (N'NO', N'YES')
    THROW 54260, N'@Apply must be N''NO'' (dry run) or N''YES''.', 1;

IF (CAST(SERVERPROPERTY('EngineEdition') AS INT) NOT IN (2, 3, 4) OR @@SERVERNAME LIKE N'%database.windows.net%')
   AND @AllowSharedDatabase = 0
    THROW 54261, N'This looks like a shared (Azure SQL) database. The Tri-AP to Tri-AP2 migration was not approved for it: refusing. Run it locally, or set @AllowSharedDatabase = 1 only with the owner''s approval.', 1;

IF OBJECT_ID(N'[finance].[invoice_invoices]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[invoice_emails]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[invoice_email_attachments]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[invoice_approval_batches]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[invoice_outbound_messages]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[mail_deliveries]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[attachments]', N'U') IS NULL
    THROW 54262, N'Tri-AP''s tables are missing (create-finance-invoice-schema.sql and its follow-ups).', 1;

IF OBJECT_ID(N'[finance].[ap2_invoices]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[ap2_mail]', N'U') IS NULL
   OR OBJECT_ID(N'[finance].[ap2_history]', N'U') IS NULL
    THROW 54263, N'Tri-AP2''s tables are missing (create-finance-ap2-schema.sql).', 1;

/* The map is the one object this script creates outside a transaction's reach. */
IF OBJECT_ID(N'[finance].[ap2_migration_map]', N'U') IS NULL
BEGIN
    CREATE TABLE [finance].[ap2_migration_map] (
        [source_table] NVARCHAR(80)  NOT NULL,
        [source_id]    NVARCHAR(200) NOT NULL,
        [target_id]    CHAR(32)      NOT NULL,
        [migrated_at]  DATETIME2(6)  NOT NULL CONSTRAINT [DF_finance_ap2_migration_map_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_finance_ap2_migration_map] PRIMARY KEY CLUSTERED ([source_table], [source_id])
    );
END;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Now DATETIME2(6) = SYSUTCDATETIME();

    /* ------------------------------------------------------------------
       Scope: which Tri-AP rows this run takes.
       ------------------------------------------------------------------ */
    SELECT i.[id]
    INTO #inv
    FROM [finance].[invoice_invoices] i
    WHERE @OnlyOpenItems = 0
       OR (i.[deleted_at] IS NULL AND i.[status] <> N'Exported');

    /* Tri-AP kept the invoice's own currency; Tri-AP2 holds AUD, USD, NZD and SGD only (a CHECK). Writing EUR 100 as
       AUD 100 would be a wrong amount, so an invoice in any other currency is left out and listed in the report. */
    SELECT i.[id], i.[invoice_no], i.[vendor], i.[currency], i.[total_amount]
    INTO #unsupported
    FROM [finance].[invoice_invoices] i
    JOIN #inv s ON s.[id] = i.[id]
    WHERE UPPER(LTRIM(RTRIM(i.[currency]))) NOT IN (N'AUD', N'USD', N'NZD', N'SGD')
      AND NOT (LTRIM(RTRIM(COALESCE(i.[currency], N''))) = N'' AND COALESCE(i.[total_amount], 0) = 0 AND COALESCE(i.[net_amount], 0) = 0);
    DELETE s FROM #inv s JOIN #unsupported u ON u.[id] = s.[id];

    SELECT e.[id]
    INTO #eml
    FROM [finance].[invoice_emails] e
    WHERE @OnlyOpenItems = 0
       OR (e.[deleted_at] IS NULL
           AND (e.[review_status] IN (N'Unverified', N'Needs review')
                OR EXISTS (SELECT 1 FROM [finance].[invoice_invoices] i JOIN #inv s ON s.[id] = i.[id] WHERE i.[email_record_id] = e.[id])));

    /* ------------------------------------------------------------------
       Suppliers
       ------------------------------------------------------------------ */
    SELECT i.[id] AS [invoice_id],
           i.[odoo_vendor_id],
           COALESCE(NULLIF(LTRIM(RTRIM(COALESCE(NULLIF(i.[odoo_vendor_name], N''), i.[vendor]))), N''), N'(No vendor recorded)') AS [vname],
           LTRIM(RTRIM(i.[vendor_email])) AS [vemail],
           i.[company],
           CONVERT(VARCHAR(64), HASHBYTES('SHA2_256', LOWER(COALESCE(NULLIF(LTRIM(RTRIM(COALESCE(NULLIF(i.[odoo_vendor_name], N''), i.[vendor]))), N''), N'(No vendor recorded)'))
                                                      + N'|' + LOWER(LTRIM(RTRIM(i.[vendor_email])))), 2) AS [name_hash]
    INTO #v
    FROM [finance].[invoice_invoices] i
    JOIN #inv s ON s.[id] = i.[id];

    /* Unmatched vendors join the Odoo supplier with the same name and email. */
    SELECT v.[invoice_id],
           CASE
               WHEN v.[odoo_vendor_id] IS NOT NULL THEN N'odoo:' + CAST(v.[odoo_vendor_id] AS NVARCHAR(30))
               WHEN o.[odoo_id] IS NOT NULL THEN N'odoo:' + CAST(o.[odoo_id] AS NVARCHAR(30))
               ELSE N'vendor:' + v.[name_hash]
           END AS [skey],
           v.[vname], v.[vemail], v.[company],
           CASE WHEN v.[odoo_vendor_id] IS NOT NULL THEN v.[odoo_vendor_id] ELSE o.[odoo_id] END AS [odoo_id]
    INTO #vk
    FROM #v v
    OUTER APPLY (
        SELECT MIN(x.[odoo_vendor_id]) AS [odoo_id]
        FROM #v x
        WHERE v.[odoo_vendor_id] IS NULL AND x.[odoo_vendor_id] IS NOT NULL AND x.[name_hash] = v.[name_hash]
    ) o;

    SELECT k.[skey],
           MAX(k.[odoo_id]) AS [odoo_id],
           MAX(k.[vname]) AS [vname],
           COALESCE(MAX(NULLIF(k.[vemail], N'')), N'') AS [vemail],
           MAX(k.[company]) AS [company]
    INTO #sup
    FROM #vk k
    GROUP BY k.[skey];

    /* Reuse an ap2 supplier that already stands for the vendor: same Odoo partner, else same name and email. */
    INSERT INTO [finance].[ap2_migration_map] ([source_table], [source_id], [target_id])
    SELECT N'supplier_key', s.[skey], x.[id]
    FROM #sup s
    CROSS APPLY (
        SELECT TOP 1 a.[id]
        FROM [finance].[ap2_suppliers] a
        WHERE (s.[odoo_id] IS NOT NULL AND a.[odoo_partner_id] = s.[odoo_id])
           OR (LOWER(a.[name]) = LOWER(s.[vname]) AND LOWER(a.[email]) = LOWER(s.[vemail]))
        ORDER BY CASE WHEN a.[odoo_partner_id] = s.[odoo_id] THEN 0 ELSE 1 END, a.[created_at]
    ) x
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] m WHERE m.[source_table] = N'supplier_key' AND m.[source_id] = s.[skey]);

    DECLARE @SuppliersNew INT, @SuppliersMapped INT;

    INSERT INTO [finance].[ap2_migration_map] ([source_table], [source_id], [target_id])
    SELECT N'supplier_key', s.[skey], LOWER(REPLACE(CONVERT(CHAR(36), NEWID()), '-', ''))
    FROM #sup s
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] m WHERE m.[source_table] = N'supplier_key' AND m.[source_id] = s.[skey]);

    INSERT INTO [finance].[ap2_suppliers] ([id], [name], [industry], [email], [contact], [cc], [entity], [phone], [terms],
                                           [owner_name], [owner_email], [bank_verified], [bank_change], [hold], [notes], [odoo_partner_id])
    SELECT m.[target_id], LEFT(s.[vname], 400), N'', LEFT(s.[vemail], 640), N'', N'', LEFT(COALESCE(s.[company], N''), 400), N'', 30,
           N'', N'', 0, 0, 0, N'Migrated from Tri-AP. Bank details are not carried over: verify them before paying.', s.[odoo_id]
    FROM #sup s
    JOIN [finance].[ap2_migration_map] m ON m.[source_table] = N'supplier_key' AND m.[source_id] = s.[skey]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_suppliers] a WHERE a.[id] = m.[target_id]);
    SET @SuppliersNew = @@ROWCOUNT;
    SELECT @SuppliersMapped = COUNT(*) FROM #sup;

    /* ------------------------------------------------------------------
       Invoices
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[ap2_migration_map] ([source_table], [source_id], [target_id])
    SELECT N'invoice_invoices', s.[id], LOWER(REPLACE(CONVERT(CHAR(36), NEWID()), '-', ''))
    FROM #inv s
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] m WHERE m.[source_table] = N'invoice_invoices' AND m.[source_id] = s.[id]);

    /* Emails get their ids now too, so an invoice can name its mail. */
    INSERT INTO [finance].[ap2_migration_map] ([source_table], [source_id], [target_id])
    SELECT N'invoice_emails', s.[id], LOWER(REPLACE(CONVERT(CHAR(36), NEWID()), '-', ''))
    FROM #eml s
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] m WHERE m.[source_table] = N'invoice_emails' AND m.[source_id] = s.[id]);

    DECLARE @InvoicesNew INT;

    INSERT INTO [finance].[ap2_invoices]
        ([id], [supplier_id], [number], [entity], [currency], [net], [tax], [total], [issued], [due], [kind], [po],
         [gl], [tax_code], [cost_centre], [stage], [verified], [import_state], [excluded], [excluded_reason],
         [source_doc], [source_file_id], [confidence], [source_fingerprint], [mail_id], [approval_required], [created_at], [updated_at])
    SELECT mi.[target_id], ms.[target_id],
           LEFT(i.[invoice_no], 200), LEFT(i.[company], 400),
           CASE WHEN LTRIM(RTRIM(COALESCE(i.[currency], N''))) = N'' THEN 'AUD' ELSE UPPER(LTRIM(RTRIM(i.[currency]))) END,
           COALESCE(i.[net_amount], 0), COALESCE(i.[tax_amount], 0),
           COALESCE(i.[total_amount], COALESCE(i.[net_amount], 0) + COALESCE(i.[tax_amount], 0)),
           i.[invoice_date], i.[due_date],
           CASE WHEN i.[po_number] <> N'' THEN N'Goods PO' ELSE N'No PO' END, LEFT(i.[po_number], 200),
           LEFT(i.[account_category], 100), N'', LEFT(i.[cost_centre], 200),
           N'Needs verification',
           0,
           CASE WHEN i.[status] = N'Exported' THEN N'Exported CSV' ELSE N'Not exported' END,
           CASE WHEN i.[deleted_at] IS NULL THEN 0 ELSE 1 END,
           CASE WHEN i.[deleted_at] IS NULL THEN N'' ELSE LEFT(N'Put in the Bin in Tri-AP' + CASE WHEN i.[deleted_by] <> N'' THEN N' by ' + i.[deleted_by] ELSE N'' END, 1000) END,
           LEFT(i.[attachment], 255), i.[file_id], i.[extraction_confidence], COALESCE(i.[content_hash], N''),
           CASE WHEN me.[target_id] IS NULL THEN NULL ELSE me.[target_id] END,
           CASE WHEN COALESCE(i.[approval_status], N'Not requested') = N'Not requested' THEN 0 ELSE 1 END,
           i.[created_date], i.[updated_date]
    FROM [finance].[invoice_invoices] i
    JOIN #inv s ON s.[id] = i.[id]
    JOIN [finance].[ap2_migration_map] mi ON mi.[source_table] = N'invoice_invoices' AND mi.[source_id] = i.[id]
    JOIN #vk k ON k.[invoice_id] = i.[id]
    JOIN [finance].[ap2_migration_map] ms ON ms.[source_table] = N'supplier_key' AND ms.[source_id] = k.[skey]
    LEFT JOIN [finance].[ap2_migration_map] me ON me.[source_table] = N'invoice_emails' AND me.[source_id] = i.[email_record_id]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_invoices] x WHERE x.[id] = mi.[target_id]);
    SET @InvoicesNew = @@ROWCOUNT;

    /* ------------------------------------------------------------------
       Approvals: one per invoice that had one requested, from the latest batch.
       ------------------------------------------------------------------ */
    SELECT i.[id] AS [invoice_id], i.[approval_status], b.[id] AS [batch_id], b.[prepared_by_user_id], b.[prepared_by_name], b.[prepared_by_email],
           b.[created_date] AS [requested_at], b.[note],
           d.[approver_user_id], d.[approver_name], d.[approver_email], d.[created_date] AS [decided_at], d.[comment] AS [decision_comment]
    INTO #apr
    FROM [finance].[invoice_invoices] i
    JOIN #inv s ON s.[id] = i.[id]
    OUTER APPLY (
        SELECT TOP 1 b2.*
        FROM [finance].[invoice_approval_items] it
        JOIN [finance].[invoice_approval_batches] b2 ON b2.[id] = it.[batch_id]
        WHERE it.[invoice_id] = i.[id]
        ORDER BY b2.[created_date] DESC, b2.[id] DESC
    ) b
    OUTER APPLY (
        SELECT TOP 1 d2.*
        FROM [finance].[invoice_approval_decisions] d2
        WHERE d2.[batch_id] = b.[id]
        ORDER BY d2.[created_date] DESC, d2.[id] DESC
    ) d
    WHERE i.[approval_status] <> N'Not requested';

    INSERT INTO [finance].[ap2_migration_map] ([source_table], [source_id], [target_id])
    SELECT N'invoice_approval', a.[invoice_id], LOWER(REPLACE(CONVERT(CHAR(36), NEWID()), '-', ''))
    FROM #apr a
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] m WHERE m.[source_table] = N'invoice_approval' AND m.[source_id] = a.[invoice_id]);

    DECLARE @ApprovalsNew INT;
    INSERT INTO [finance].[ap2_approvals]
        ([id], [kind], [invoice_id], [supplier_id], [subject], [summary], [requester_user_id], [requester_name], [requester_email],
         [approver_user_id], [approver_name], [approver_email], [status], [requested_at], [decided_at], [comment], [updated_at])
    SELECT ma.[target_id], N'Invoice', mi.[target_id], inv.[supplier_id],
           LEFT(N'Invoice approval · ' + inv.[number] + N' (migrated from Tri-AP)', 800),
           N'Migrated from Tri-AP: approval ' + LOWER(a.[approval_status]) + COALESCE(N' · ' + NULLIF(a.[note], N''), N'')
             + CASE WHEN a.[approval_status] = N'Pending approval' THEN N' · it named no approver, so it is closed: ask for approval again in Tri-AP2' ELSE N'' END,
           COALESCE(a.[prepared_by_user_id], 0), COALESCE(NULLIF(a.[prepared_by_name], N''), N'Tri-AP (migrated)'), COALESCE(a.[prepared_by_email], N''),
           COALESCE(a.[approver_user_id], 0), COALESCE(NULLIF(a.[approver_name], N''), N'Not recorded (migrated)'), COALESCE(a.[approver_email], N''),
           CASE a.[approval_status] WHEN N'Approved' THEN N'Approved' WHEN N'Rejected' THEN N'Rejected' ELSE N'Expired' END,
           COALESCE(a.[requested_at], @Now),
           CASE WHEN a.[approval_status] IN (N'Approved', N'Rejected') THEN COALESCE(a.[decided_at], a.[requested_at], @Now) ELSE NULL END,
           N'Migrated from Tri-AP' + COALESCE(N' · ' + NULLIF(a.[decision_comment], N''), N''),
           @Now
    FROM #apr a
    JOIN [finance].[ap2_migration_map] ma ON ma.[source_table] = N'invoice_approval' AND ma.[source_id] = a.[invoice_id]
    JOIN [finance].[ap2_migration_map] mi ON mi.[source_table] = N'invoice_invoices' AND mi.[source_id] = a.[invoice_id]
    JOIN [finance].[ap2_invoices] inv ON inv.[id] = mi.[target_id]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_approvals] x WHERE x.[id] = ma.[target_id]);
    SET @ApprovalsNew = @@ROWCOUNT;

    /* The current approval of an invoice is the Approved one. */
    UPDATE inv
    SET inv.[approval_id] = ma.[target_id]
    FROM [finance].[ap2_invoices] inv
    JOIN [finance].[ap2_migration_map] mi ON mi.[target_id] = inv.[id] AND mi.[source_table] = N'invoice_invoices'
    JOIN #apr a ON a.[invoice_id] = mi.[source_id] AND a.[approval_status] = N'Approved'
    JOIN [finance].[ap2_migration_map] ma ON ma.[source_table] = N'invoice_approval' AND ma.[source_id] = a.[invoice_id]
    WHERE inv.[approval_id] IS NULL;

    INSERT INTO [finance].[ap2_approval_events] ([id], [approval_id], [action], [by_user_id], [by_name], [by_email], [at], [comment])
    SELECT LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|approval-event|' + a.[invoice_id]), 2)),
           ma.[target_id], N'Migrated', NULL, N'Tri-AP migration', N'', @Now,
           N'Tri-AP approval status was ' + a.[approval_status]
    FROM #apr a
    JOIN [finance].[ap2_migration_map] ma ON ma.[source_table] = N'invoice_approval' AND ma.[source_id] = a.[invoice_id]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_approval_events] x WHERE x.[id] = LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|approval-event|' + a.[invoice_id]), 2)));

    /* ------------------------------------------------------------------
       Emails and their attachments
       ------------------------------------------------------------------ */
    SELECT e.[id] AS [email_id],
           COALESCE(
               (SELECT TOP 1 ms.[target_id]
                FROM [finance].[invoice_invoices] i
                JOIN #vk k ON k.[invoice_id] = i.[id]
                JOIN [finance].[ap2_migration_map] ms ON ms.[source_table] = N'supplier_key' AND ms.[source_id] = k.[skey]
                WHERE i.[email_record_id] = e.[id]
                ORDER BY i.[created_date], i.[id]),
               (SELECT TOP 1 a.[id] FROM [finance].[ap2_suppliers] a
                WHERE a.[email] <> N'' AND LOWER(a.[email]) = LOWER(e.[sender_email]) ORDER BY a.[created_at])
           ) AS [supplier_id],
           (SELECT TOP 1 mi.[target_id]
            FROM [finance].[invoice_invoices] i
            JOIN [finance].[ap2_migration_map] mi ON mi.[source_table] = N'invoice_invoices' AND mi.[source_id] = i.[id]
            WHERE i.[email_record_id] = e.[id]
            ORDER BY i.[created_date], i.[id]) AS [invoice_id],
           CASE WHEN EXISTS (SELECT 1 FROM [finance].[invoice_outbound_messages] o
                             JOIN [finance].[mail_deliveries] d ON d.[id] = o.[delivery_id]
                             WHERE o.[email_id] = e.[id] AND d.[status] = N'sent') THEN 1 ELSE 0 END AS [was_sent],
           (SELECT MAX(d.[sent_at]) FROM [finance].[invoice_outbound_messages] o
                             JOIN [finance].[mail_deliveries] d ON d.[id] = o.[delivery_id]
                             WHERE o.[email_id] = e.[id] AND d.[status] = N'sent') AS [sent_at]
    INTO #em
    FROM [finance].[invoice_emails] e
    JOIN #eml s ON s.[id] = e.[id];

    DECLARE @MailNew INT, @MailAttachmentsNew INT;
    INSERT INTO [finance].[ap2_mail]
        ([id], [supplier_id], [invoice_id], [mailbox], [graph_message_id], [received_at], [subject], [body_preview], [body], [category],
         [status], [is_read], [from_address], [cc], [reply_to], [record_history], [draft], [draft_reviewed], [sent], [sent_at],
         [ai_summary], [ai_confidence], [created_at], [updated_at])
    SELECT mm.[target_id], x.[supplier_id], x.[invoice_id], LEFT(e.[mailbox], 640), LEFT(e.[graph_message_id], 800),
           COALESCE(e.[received_at], e.[created_date]), LEFT(e.[subject], 800), LEFT(e.[body_preview], 2000), e.[body_preview],
           CASE WHEN e.[classification] = N'Invoice' THEN N'Supplier invoice' ELSE N'Other' END,
           CASE WHEN e.[review_status] = N'Verified' OR e.[deleted_at] IS NOT NULL THEN N'Done' ELSE N'In progress' END,
           CASE WHEN e.[review_status] = N'Verified' THEN 1 ELSE 0 END,
           LEFT(e.[sender_email], 640), N'', LEFT(e.[sender_email], 640), 1,
           COALESCE(e.[proposed_response], N''), 0, x.[was_sent], x.[sent_at],
           NULLIF(LEFT(COALESCE(e.[classification_reason], N''), 1000), N''), e.[classification_confidence],
           e.[created_date], e.[updated_date]
    FROM [finance].[invoice_emails] e
    JOIN #em x ON x.[email_id] = e.[id]
    JOIN [finance].[ap2_migration_map] mm ON mm.[source_table] = N'invoice_emails' AND mm.[source_id] = e.[id]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_mail] y WHERE y.[id] = mm.[target_id])
      AND NOT EXISTS (SELECT 1 FROM [finance].[ap2_mail] y WHERE y.[mailbox] = LEFT(e.[mailbox], 640) AND y.[graph_message_id] = LEFT(e.[graph_message_id], 800));
    SET @MailNew = @@ROWCOUNT;

    /* An invoice named a mail id that was not inserted (the message already existed in ap2_mail): drop the dangling pointer. */
    UPDATE inv SET inv.[mail_id] = NULL
    FROM [finance].[ap2_invoices] inv
    WHERE inv.[mail_id] IS NOT NULL AND NOT EXISTS (SELECT 1 FROM [finance].[ap2_mail] m WHERE m.[id] = inv.[mail_id])
      AND EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] mi WHERE mi.[source_table] = N'invoice_invoices' AND mi.[target_id] = inv.[id]);

    INSERT INTO [finance].[ap2_migration_map] ([source_table], [source_id], [target_id])
    SELECT N'invoice_email_attachments', a.[id], LOWER(REPLACE(CONVERT(CHAR(36), NEWID()), '-', ''))
    FROM [finance].[invoice_email_attachments] a
    JOIN #eml s ON s.[id] = a.[email_record_id]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] m WHERE m.[source_table] = N'invoice_email_attachments' AND m.[source_id] = a.[id]);

    INSERT INTO [finance].[ap2_mail_attachments] ([id], [mail_id], [file_id], [graph_attachment_id], [name], [size], [content_type], [created_at])
    SELECT ma.[target_id], mm.[target_id], a.[file_id], LEFT(a.[graph_attachment_id], 800), LEFT(a.[name], 510), COALESCE(a.[size], 0), LEFT(a.[content_type], 300), a.[created_date]
    FROM [finance].[invoice_email_attachments] a
    JOIN [finance].[ap2_migration_map] mm ON mm.[source_table] = N'invoice_emails' AND mm.[source_id] = a.[email_record_id]
    JOIN [finance].[ap2_migration_map] ma ON ma.[source_table] = N'invoice_email_attachments' AND ma.[source_id] = a.[id]
    JOIN [finance].[ap2_mail] m ON m.[id] = mm.[target_id]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_mail_attachments] y WHERE y.[id] = ma.[target_id])
      AND NOT EXISTS (SELECT 1 FROM [finance].[ap2_mail_attachments] y WHERE y.[mail_id] = mm.[target_id] AND y.[graph_attachment_id] = LEFT(a.[graph_attachment_id], 800));
    SET @MailAttachmentsNew = @@ROWCOUNT;

    /* ------------------------------------------------------------------
       History: every migrated supplier, invoice, email, approval and sent message is marked.
       The ids are hashes of the source id, so a rerun adds nothing.
       ------------------------------------------------------------------ */
    DECLARE @HistoryNew INT = 0, @n INT;

    INSERT INTO [finance].[ap2_history] ([id], [supplier_id], [invoice_id], [mail_id], [type], [detail], [actor_name], [at], [extra])
    SELECT LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|invoice|' + i.[id]), 2)),
           inv.[supplier_id], inv.[id], NULL, N'Migrated from Tri-AP',
           LEFT(inv.[number] + N' · Tri-AP status ' + i.[status] + N', approval ' + i.[approval_status]
                + CASE WHEN i.[duplicate_status] = N'Potential' THEN N', flagged as a possible duplicate' ELSE N'' END
                + COALESCE(N' · notes: ' + NULLIF(i.[notes], N''), N'')
                + COALESCE(N' · review: ' + NULLIF(i.[review_reason], N''), N''), 4000),
           N'Tri-AP migration', @Now,
           (SELECT N'tri-ap' AS [source], i.[id] AS [invoiceId], i.[status] AS [status], i.[approval_status] AS [approvalStatus],
                   i.[duplicate_status] AS [duplicateStatus], i.[duplicate_of_invoice_id] AS [duplicateOfInvoiceId], i.[vendor_match] AS [vendorMatch]
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
    FROM [finance].[invoice_invoices] i
    JOIN [finance].[ap2_migration_map] mi ON mi.[source_table] = N'invoice_invoices' AND mi.[source_id] = i.[id]
    JOIN [finance].[ap2_invoices] inv ON inv.[id] = mi.[target_id]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_history] x WHERE x.[id] = LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|invoice|' + i.[id]), 2)));
    SET @n = @@ROWCOUNT; SET @HistoryNew += @n;

    INSERT INTO [finance].[ap2_history] ([id], [supplier_id], [invoice_id], [mail_id], [type], [detail], [actor_name], [at], [extra])
    SELECT LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|supplier|' + s.[skey]), 2)),
           ms.[target_id], NULL, NULL, N'Migrated from Tri-AP',
           LEFT(s.[vname] + N' · supplier made from Tri-AP vendors' + COALESCE(N' (Odoo partner ' + CAST(s.[odoo_id] AS NVARCHAR(30)) + N')', N' (no Odoo match)'), 4000),
           N'Tri-AP migration', @Now,
           (SELECT N'tri-ap' AS [source], s.[skey] AS [vendorKey] FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
    FROM #sup s
    JOIN [finance].[ap2_migration_map] ms ON ms.[source_table] = N'supplier_key' AND ms.[source_id] = s.[skey]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_history] x WHERE x.[id] = LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|supplier|' + s.[skey]), 2)))
      AND EXISTS (SELECT 1 FROM [finance].[ap2_suppliers] a WHERE a.[id] = ms.[target_id]);
    SET @n = @@ROWCOUNT; SET @HistoryNew += @n;

    /* Mail with no supplier cannot sit in a supplier's history (supplier_id is required): those are counted below. */
    INSERT INTO [finance].[ap2_history] ([id], [supplier_id], [invoice_id], [mail_id], [type], [detail], [actor_name], [at], [extra])
    SELECT LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|mail|' + e.[id]), 2)),
           x.[supplier_id], x.[invoice_id], mm.[target_id], N'Migrated from Tri-AP',
           LEFT(e.[subject] + N' · email from ' + e.[sender_email] + N', Tri-AP review status ' + e.[review_status], 4000),
           N'Tri-AP migration', @Now,
           (SELECT N'tri-ap' AS [source], e.[id] AS [emailRecordId], e.[classification] AS [classification], e.[review_status] AS [reviewStatus] FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
    FROM [finance].[invoice_emails] e
    JOIN #em x ON x.[email_id] = e.[id]
    JOIN [finance].[ap2_migration_map] mm ON mm.[source_table] = N'invoice_emails' AND mm.[source_id] = e.[id]
    WHERE x.[supplier_id] IS NOT NULL
      AND EXISTS (SELECT 1 FROM [finance].[ap2_mail] m WHERE m.[id] = mm.[target_id])
      AND NOT EXISTS (SELECT 1 FROM [finance].[ap2_history] h WHERE h.[id] = LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|mail|' + e.[id]), 2)));
    SET @n = @@ROWCOUNT; SET @HistoryNew += @n;

    INSERT INTO [finance].[ap2_history] ([id], [supplier_id], [invoice_id], [mail_id], [type], [detail], [actor_name], [at], [extra])
    SELECT LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|approval|' + a.[invoice_id]), 2)),
           ap.[supplier_id], ap.[invoice_id], NULL, N'Migrated from Tri-AP',
           LEFT(N'Approval ' + LOWER(a.[approval_status]) + N' in Tri-AP · ' + ap.[subject], 4000),
           N'Tri-AP migration', @Now,
           (SELECT N'tri-ap' AS [source], a.[batch_id] AS [approvalBatchId], ma.[target_id] AS [approvalId] FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
    FROM #apr a
    JOIN [finance].[ap2_migration_map] ma ON ma.[source_table] = N'invoice_approval' AND ma.[source_id] = a.[invoice_id]
    JOIN [finance].[ap2_approvals] ap ON ap.[id] = ma.[target_id]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[ap2_history] h WHERE h.[id] = LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|approval|' + a.[invoice_id]), 2)));
    SET @n = @@ROWCOUNT; SET @HistoryNew += @n;

    /* Outbound messages (vendor emails, replies, approval requests): the delivery's own row stays in finance.mail_deliveries.
       One history line each, on the supplier of its invoice or, failing that, of its email. */
    DECLARE @OutboundNew INT, @OutboundSkipped INT;
    SELECT o.[id] AS [outbound_id], o.[invoice_id], o.[email_id], d.[id] AS [delivery_id], d.[status], d.[subject], d.[to_addresses_json],
           d.[actor_user_id], COALESCE(d.[sent_at], d.[created_at]) AS [at],
           (SELECT inv.[supplier_id] FROM [finance].[ap2_migration_map] mi JOIN [finance].[ap2_invoices] inv ON inv.[id] = mi.[target_id]
            WHERE mi.[source_table] = N'invoice_invoices' AND mi.[source_id] = o.[invoice_id]) AS [inv_supplier],
           (SELECT m.[supplier_id] FROM [finance].[ap2_migration_map] me JOIN [finance].[ap2_mail] m ON m.[id] = me.[target_id]
            WHERE me.[source_table] = N'invoice_emails' AND me.[source_id] = o.[email_id]) AS [mail_supplier],
           (SELECT mi.[target_id] FROM [finance].[ap2_migration_map] mi WHERE mi.[source_table] = N'invoice_invoices' AND mi.[source_id] = o.[invoice_id]) AS [ap2_invoice],
           (SELECT me.[target_id] FROM [finance].[ap2_migration_map] me
             JOIN [finance].[ap2_mail] m ON m.[id] = me.[target_id]
             WHERE me.[source_table] = N'invoice_emails' AND me.[source_id] = o.[email_id]) AS [ap2_mail]
    INTO #out
    FROM [finance].[invoice_outbound_messages] o
    JOIN [finance].[mail_deliveries] d ON d.[id] = o.[delivery_id]
    WHERE (o.[invoice_id] IS NOT NULL AND EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] mi WHERE mi.[source_table] = N'invoice_invoices' AND mi.[source_id] = o.[invoice_id]))
       OR (o.[email_id] IS NOT NULL AND EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] me WHERE me.[source_table] = N'invoice_emails' AND me.[source_id] = o.[email_id]));

    INSERT INTO [finance].[ap2_history] ([id], [supplier_id], [invoice_id], [mail_id], [type], [detail], [actor_user_id], [actor_name], [at], [extra])
    SELECT LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|outbound|' + t.[outbound_id]), 2)),
           COALESCE(t.[inv_supplier], t.[mail_supplier]), t.[ap2_invoice], t.[ap2_mail], N'Email sent (migrated)',
           LEFT(t.[subject] + N' · ' + t.[status] + N' · to ' + t.[to_addresses_json], 4000),
           t.[actor_user_id], N'Tri-AP migration', t.[at],
           (SELECT N'tri-ap' AS [source], t.[outbound_id] AS [outboundMessageId], t.[delivery_id] AS [deliveryId], t.[status] AS [status] FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)
    FROM #out t
    WHERE COALESCE(t.[inv_supplier], t.[mail_supplier]) IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM [finance].[ap2_history] h WHERE h.[id] = LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|outbound|' + t.[outbound_id]), 2)));
    SET @OutboundNew = @@ROWCOUNT;
    SELECT @OutboundSkipped = COUNT(*) FROM #out t WHERE COALESCE(t.[inv_supplier], t.[mail_supplier]) IS NULL;

    INSERT INTO [finance].[ap2_migration_map] ([source_table], [source_id], [target_id])
    SELECT N'invoice_outbound_messages', t.[outbound_id], LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|outbound|' + t.[outbound_id]), 2))
    FROM #out t
    WHERE EXISTS (SELECT 1 FROM [finance].[ap2_history] h WHERE h.[id] = LOWER(CONVERT(CHAR(32), HASHBYTES('MD5', N'ap2-migrate|outbound|' + t.[outbound_id]), 2)))
      AND NOT EXISTS (SELECT 1 FROM [finance].[ap2_migration_map] m WHERE m.[source_table] = N'invoice_outbound_messages' AND m.[source_id] = t.[outbound_id]);
    SET @HistoryNew += @OutboundNew;

    /* ------------------------------------------------------------------
       Report: what this run added, and what is left out and why.
       ------------------------------------------------------------------ */
    SELECT N'migrate-finance-ap-to-ap2' AS [script],
           CASE WHEN @Apply = N'YES' THEN N'COMMITTED' ELSE N'DRY RUN (rolled back)' END AS [mode],
           CASE WHEN @OnlyOpenItems = 1 THEN N'open items only' ELSE N'everything' END AS [scope];
    SELECT N'tri-ap invoices in scope' AS [what], COUNT(*) AS [rows] FROM #inv
    UNION ALL SELECT N'tri-ap emails in scope', COUNT(*) FROM #eml
    UNION ALL SELECT N'suppliers (distinct vendors)', @SuppliersMapped
    UNION ALL SELECT N'  of which new ap2_suppliers rows', @SuppliersNew
    UNION ALL SELECT N'ap2_invoices added', @InvoicesNew
    UNION ALL SELECT N'invoices LEFT OUT: currency not AUD/USD/NZD/SGD (listed below)', COUNT(*) FROM #unsupported
    UNION ALL SELECT N'ap2_approvals added', @ApprovalsNew
    UNION ALL SELECT N'ap2_mail added', @MailNew
    UNION ALL SELECT N'ap2_mail_attachments added (files reused, no blobs copied)', @MailAttachmentsNew
    UNION ALL SELECT N'outbound messages written to history', @OutboundNew
    UNION ALL SELECT N'outbound messages with no supplier (left out)', @OutboundSkipped
    UNION ALL SELECT N'ap2_history lines added', @HistoryNew
    UNION ALL SELECT N'emails with no supplier (stored, no history line)', COUNT(*) FROM #em WHERE [supplier_id] IS NULL
    UNION ALL SELECT N'ap2_migration_map rows (all runs)', COUNT(*) FROM [finance].[ap2_migration_map];

    SELECT N'left out: currency' AS [what], u.[id] AS [tri_ap_invoice_id], u.[invoice_no], u.[vendor], u.[currency], u.[total_amount]
    FROM #unsupported u ORDER BY u.[currency], u.[invoice_no];

    IF @Apply = N'YES'
        COMMIT TRANSACTION;
    ELSE
        ROLLBACK TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
