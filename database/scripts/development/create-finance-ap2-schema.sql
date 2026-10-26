/*
================================================================================
  Tri-AP2's tables in [finance] (Tri-AP2 backend, Phases 1-2). Written 26 Oct 2026.
  Additive to create-finance-schema.sql and create-finance-attachments-schema.sql;
  run after both. Contract: platform/tri-ap-new/TRI-AP2-API-CONTRACT.md.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. Run against the local Docker
  database only; applying it to tricab-mel is Manusha's or Jeff's.

  Sixteen tables, all of the contract's, so a later phase adds code and not
  schema. Phase 1-2 code reads and writes ap2_suppliers, ap2_invoices, ap2_history,
  ap2_approvals (read only), ap2_po_forecast and ap2_settings; the rest wait for
  their phase.

    [finance].[ap2_settings]          key/value defaults (GL, tax code, limits)
    [finance].[ap2_suppliers]         the supplier master
    [finance].[ap2_invoices]          the invoices (the prototype's "bills")
    [finance].[ap2_mail]              supplier inbox, one row per message
    [finance].[ap2_mail_attachments]  files of a message (bytes in finance.attachments)
    [finance].[ap2_statements]        supplier statements for reconciliation
    [finance].[ap2_approvals]         invoice / email / payment approval requests
    [finance].[ap2_approval_events]   what happened to a request
    [finance].[ap2_payments]          payment proposals (Odoo does the paying)
    [finance].[ap2_history]           the supplier activity ledger. APPEND ONLY
    [finance].[ap2_notifications]     a person's bell
    [finance].[ap2_exports]           Odoo CSV exports
    [finance].[ap2_priorities]        a person's flags and pins
    [finance].[ap2_po_forecast]       uninvoiced PO estimates for the cash forecast
    [finance].[ap2_copilot_messages]  Copilot conversation turns
    [finance].[ap2_sync_runs]         mailbox / Odoo sync runs

  Conventions (the same as create-finance-invoice-schema.sql):
    - ids are CHAR(32) lower-case hex generated in PHP; the one exception is
      [ap2_exports].[id], which is the contract's CSV-0001 style reference;
    - timestamps are DATETIME2(6) in UTC; dates the page shows are DATE (the Sydney
      calendar day), NULL when unknown;
    - money is DECIMAL(18,2); a currency is AUD, USD, NZD or SGD (a CHECK);
    - a person is identity.Users.UserId (BIGINT) with the name and address at the
      time beside it, and NO foreign key outside [finance];
    - foreign keys inside [finance] only, with no cascades;
    - flags are BIT.

  [ap2_history] IS APPEND ONLY. The application never updates or deletes a row
  (the store has no such method) and an INSTEAD OF trigger refuses it here too:
  the ledger is the audit trail of what a person did. There is no existing
  trigger pattern in these scripts, so this is new. A DBA who must correct a row
  drops the trigger, deliberately, and says so.

  Guarded and idempotent: each table is created only if missing, in one
  transaction, and a second run changes nothing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF SCHEMA_ID(N'finance') IS NULL
    EXEC (N'CREATE SCHEMA [finance];');
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[attachments]', N'U') IS NULL
    BEGIN
        THROW 54311, N'[finance].[attachments] does not exist. Run create-finance-attachments-schema.sql first.', 1;
    END;

    /* ------------------------------------------------------------------
       Settings
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_settings]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_settings] (
            [setting_key]        NVARCHAR(100)  NOT NULL,
            [setting_value]      NVARCHAR(MAX)  NOT NULL,
            [updated_at]         DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ap2_settings_updated_at] DEFAULT (SYSUTCDATETIME()),
            [updated_by_user_id] BIGINT         NULL,
            CONSTRAINT [PK_finance_ap2_settings] PRIMARY KEY CLUSTERED ([setting_key])
        );
    END;

    /* ------------------------------------------------------------------
       Suppliers
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_suppliers]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_suppliers] (
            [id]                CHAR(32)        NOT NULL,
            [name]              NVARCHAR(200)   NOT NULL,
            [industry]          NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_industry] DEFAULT (N''),
            [email]             NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_email] DEFAULT (N''),
            [contact]           NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_contact] DEFAULT (N''),
            [cc]                NVARCHAR(1000)  NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_cc] DEFAULT (N''),
            [entity]            NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_entity] DEFAULT (N''),
            [phone]             NVARCHAR(50)    NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_phone] DEFAULT (N''),
            [terms]             INT             NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_terms] DEFAULT (30),
            [owner_user_id]     BIGINT          NULL,
            [owner_name]        NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_owner_name] DEFAULT (N''),
            [owner_email]       NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_owner_email] DEFAULT (N''),
            [bank_verified]     BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_bank_verified] DEFAULT (0),
            [bank_change]       BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_bank_change] DEFAULT (0),
            [hold]              BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_hold] DEFAULT (0),
            [notes]             NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_notes] DEFAULT (N''),
            [odoo_partner_id]   BIGINT          NULL,
            [created_at]        DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at]        DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_suppliers_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_suppliers] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_ap2_suppliers_terms] CHECK ([terms] BETWEEN 0 AND 365),
            CONSTRAINT [CK_finance_ap2_suppliers_name] CHECK (LEN(LTRIM(RTRIM([name]))) > 0)
        );

        CREATE INDEX [IX_finance_ap2_suppliers_name] ON [finance].[ap2_suppliers] ([name]);

        CREATE UNIQUE INDEX [UX_finance_ap2_suppliers_odoo_partner]
            ON [finance].[ap2_suppliers] ([odoo_partner_id])
            WHERE [odoo_partner_id] IS NOT NULL;
    END;

    /* ------------------------------------------------------------------
       Approvals (written by Phase 5; the invoice rules read the snapshot now)
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_approvals]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_approvals] (
            [id]                  CHAR(32)        NOT NULL,
            [kind]                NVARCHAR(10)    NOT NULL,
            [retry_of]            CHAR(32)        NULL,
            [invoice_id]          CHAR(32)        NULL,
            [mail_id]             CHAR(32)        NULL,
            [payment_id]          CHAR(32)        NULL,
            [supplier_id]         CHAR(32)        NULL,
            [subject]             NVARCHAR(400)   NOT NULL
                CONSTRAINT [DF_finance_ap2_approvals_subject] DEFAULT (N''),
            [summary]             NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_approvals_summary] DEFAULT (N''),
            /* The invoice fingerprint (SHA-256, hex) the approver saw. An approval
               is current only while the invoice still hashes to it. */
            [snapshot]            CHAR(64)        NULL,
            [requester_user_id]   BIGINT          NOT NULL,
            [requester_name]      NVARCHAR(200)   NOT NULL,
            [requester_email]     NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_approvals_requester_email] DEFAULT (N''),
            [approver_user_id]    BIGINT          NOT NULL,
            [approver_name]       NVARCHAR(200)   NOT NULL,
            [approver_email]      NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_approvals_approver_email] DEFAULT (N''),
            [status]              NVARCHAR(12)    NOT NULL
                CONSTRAINT [DF_finance_ap2_approvals_status] DEFAULT (N'Pending'),
            [requested_at]        DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_approvals_requested_at] DEFAULT (SYSUTCDATETIME()),
            [decided_at]          DATETIME2(6)    NULL,
            [comment]             NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_approvals_comment] DEFAULT (N''),
            [expires_at]          DATETIME2(6)    NULL,
            [updated_at]          DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_approvals_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_approvals] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_approvals_retry_of]
                FOREIGN KEY ([retry_of]) REFERENCES [finance].[ap2_approvals] ([id]),
            CONSTRAINT [FK_finance_ap2_approvals_supplier]
                FOREIGN KEY ([supplier_id]) REFERENCES [finance].[ap2_suppliers] ([id]),
            CONSTRAINT [CK_finance_ap2_approvals_kind] CHECK ([kind] IN (N'Invoice', N'Email', N'Payment')),
            CONSTRAINT [CK_finance_ap2_approvals_status]
                CHECK ([status] IN (N'Pending', N'Approved', N'Rejected', N'Superseded', N'Expired'))
        );

        CREATE INDEX [IX_finance_ap2_approvals_invoice]
            ON [finance].[ap2_approvals] ([invoice_id], [requested_at]) WHERE [invoice_id] IS NOT NULL;
        CREATE INDEX [IX_finance_ap2_approvals_approver]
            ON [finance].[ap2_approvals] ([approver_user_id], [status]);
        CREATE INDEX [IX_finance_ap2_approvals_status]
            ON [finance].[ap2_approvals] ([status], [requested_at]);
    END;

    /* ------------------------------------------------------------------
       Invoices
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_invoices]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_invoices] (
            [id]                  CHAR(32)        NOT NULL,
            [supplier_id]         CHAR(32)        NOT NULL,
            [number]              NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_number] DEFAULT (N''),
            [entity]              NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_entity] DEFAULT (N''),
            [currency]            CHAR(3)         NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_currency] DEFAULT ('AUD'),
            /* Amounts and dates. */
            [net]                 DECIMAL(18,2)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_net] DEFAULT (0),
            [tax]                 DECIMAL(18,2)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_tax] DEFAULT (0),
            [total]               DECIMAL(18,2)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_total] DEFAULT (0),
            [issued]              DATE            NULL,
            [due]                 DATE            NULL,
            /* Matching. */
            [kind]                NVARCHAR(12)    NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_kind] DEFAULT (N'No PO'),
            [po]                  NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_po] DEFAULT (N''),
            [quantity]            DECIMAL(18,4)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_quantity] DEFAULT (1),
            [ordered_qty]         DECIMAL(18,4)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_ordered_qty] DEFAULT (0),
            [received_qty]        DECIMAL(18,4)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_received_qty] DEFAULT (0),
            [po_unit_price]       DECIMAL(18,4)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_po_unit_price] DEFAULT (0),
            [receipt_ref]         NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_receipt_ref] DEFAULT (N''),
            [service_accepted]    BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_service_accepted] DEFAULT (0),
            [no_po_reason]        NVARCHAR(500)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_no_po_reason] DEFAULT (N''),
            /* Coding. */
            [gl]                  NVARCHAR(50)    NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_gl] DEFAULT (N''),
            [tax_code]            NVARCHAR(50)    NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_tax_code] DEFAULT (N''),
            [cost_centre]         NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_cost_centre] DEFAULT (N''),
            /* Credit. */
            [credit]              DECIMAL(18,2)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_credit] DEFAULT (0),
            [credit_ref]          NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_credit_ref] DEFAULT (N''),
            [credit_net]          DECIMAL(18,2)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_credit_net] DEFAULT (0),
            [credit_tax]          DECIMAL(18,2)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_credit_tax] DEFAULT (0),
            [credit_resolved]     BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_credit_resolved] DEFAULT (0),
            /* Status. [paid] is what Odoo reports settled (Phase 9 reads it back). */
            [paid]                DECIMAL(18,2)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_paid] DEFAULT (0),
            [stage]               NVARCHAR(20)    NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_stage] DEFAULT (N'Received'),
            [verified]            BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_verified] DEFAULT (0),
            [import_state]        NVARCHAR(20)    NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_import_state] DEFAULT (N'Not exported'),
            [odoo_ref]            NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_odoo_ref] DEFAULT (N''),
            [odoo_move_id]        BIGINT          NULL,
            [approval_id]         CHAR(32)        NULL,
            [excluded]            BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_excluded] DEFAULT (0),
            [excluded_reason]     NVARCHAR(500)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_excluded_reason] DEFAULT (N''),
            [payment_hold]        BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_payment_hold] DEFAULT (0),
            /* Source. [source_fingerprint] identifies the document; it is not the
               approval fingerprint, which is derived from the fields on read. */
            [source_doc]          NVARCHAR(255)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_source_doc] DEFAULT (N''),
            [source_file_id]      CHAR(32)        NULL,
            [confidence]          DECIMAL(9,8)    NULL,
            [source_fingerprint]  NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_source_fingerprint] DEFAULT (N''),
            [mail_id]             CHAR(32)        NULL,
            [owner_user_id]       BIGINT          NULL,
            [owner_name]          NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_owner_name] DEFAULT (N''),
            [owner_email]         NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_owner_email] DEFAULT (N''),
            [created_at]          DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at]          DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_invoices_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_invoices] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_invoices_supplier]
                FOREIGN KEY ([supplier_id]) REFERENCES [finance].[ap2_suppliers] ([id]),
            CONSTRAINT [FK_finance_ap2_invoices_approval]
                FOREIGN KEY ([approval_id]) REFERENCES [finance].[ap2_approvals] ([id]),
            CONSTRAINT [FK_finance_ap2_invoices_source_file]
                FOREIGN KEY ([source_file_id]) REFERENCES [finance].[attachments] ([id]),
            CONSTRAINT [CK_finance_ap2_invoices_currency] CHECK ([currency] IN ('AUD', 'USD', 'NZD', 'SGD')),
            CONSTRAINT [CK_finance_ap2_invoices_kind]
                CHECK ([kind] IN (N'Goods PO', N'Service PO', N'No PO', N'Recurring')),
            CONSTRAINT [CK_finance_ap2_invoices_stage]
                CHECK ([stage] IN (N'Received', N'Needs verification', N'Awaiting matching', N'Pending approval',
                                   N'Approved', N'Posted', N'Partially settled', N'Settled', N'Excluded')),
            CONSTRAINT [CK_finance_ap2_invoices_import_state]
                CHECK ([import_state] IN (N'Not exported', N'Exported CSV', N'Imported draft', N'Posted', N'Import failed')),
            CONSTRAINT [CK_finance_ap2_invoices_confidence]
                CHECK ([confidence] IS NULL OR [confidence] BETWEEN 0 AND 1)
        );

        CREATE INDEX [IX_finance_ap2_invoices_supplier]
            ON [finance].[ap2_invoices] ([supplier_id], [due]);
        CREATE INDEX [IX_finance_ap2_invoices_number]
            ON [finance].[ap2_invoices] ([supplier_id], [entity], [number]);
        CREATE INDEX [IX_finance_ap2_invoices_import_state]
            ON [finance].[ap2_invoices] ([import_state], [due]);
        CREATE INDEX [IX_finance_ap2_invoices_po]
            ON [finance].[ap2_invoices] ([po]) WHERE [po] <> N'';
    END;

    /* ------------------------------------------------------------------
       Mail (Phase 3)
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_mail]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_mail] (
            [id]                  CHAR(32)        NOT NULL,
            [supplier_id]         CHAR(32)        NULL,
            [invoice_id]          CHAR(32)        NULL,
            [mailbox]             NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_mailbox] DEFAULT (N''),
            [graph_message_id]    NVARCHAR(400)   NULL,
            [received_at]         DATETIME2(6)    NOT NULL,
            [subject]             NVARCHAR(400)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_subject] DEFAULT (N''),
            [body_preview]        NVARCHAR(1000)  NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_body_preview] DEFAULT (N''),
            [body]                NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_body] DEFAULT (N''),
            [category]            NVARCHAR(30)    NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_category] DEFAULT (N'Other'),
            [owner_user_id]       BIGINT          NULL,
            [owner_name]          NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_owner_name] DEFAULT (N''),
            [owner_email]         NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_owner_email] DEFAULT (N''),
            [status]              NVARCHAR(12)    NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_status] DEFAULT (N'In progress'),
            [is_read]             BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_is_read] DEFAULT (0),
            [due]                 DATE            NULL,
            [from_address]        NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_from_address] DEFAULT (N''),
            [cc]                  NVARCHAR(1000)  NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_cc] DEFAULT (N''),
            [reply_to]            NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_reply_to] DEFAULT (N''),
            [record_history]      BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_record_history] DEFAULT (1),
            [draft]               NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_draft] DEFAULT (N''),
            [draft_reviewed]      BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_draft_reviewed] DEFAULT (0),
            [sent]                BIT             NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_sent] DEFAULT (0),
            [sent_at]             DATETIME2(6)    NULL,
            [approval_id]         CHAR(32)        NULL,
            [ai_summary]          NVARCHAR(MAX)   NULL,
            [ai_confidence]       DECIMAL(9,8)    NULL,
            [created_at]          DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at]          DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_mail] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_mail_supplier]
                FOREIGN KEY ([supplier_id]) REFERENCES [finance].[ap2_suppliers] ([id]),
            CONSTRAINT [FK_finance_ap2_mail_invoice]
                FOREIGN KEY ([invoice_id]) REFERENCES [finance].[ap2_invoices] ([id]),
            CONSTRAINT [FK_finance_ap2_mail_approval]
                FOREIGN KEY ([approval_id]) REFERENCES [finance].[ap2_approvals] ([id]),
            CONSTRAINT [CK_finance_ap2_mail_category]
                CHECK ([category] IN (N'Supplier invoice', N'Credit note', N'Supplier statement', N'Payment enquiry',
                                      N'Missing PO / receipt', N'Bank detail change', N'Other')),
            CONSTRAINT [CK_finance_ap2_mail_status] CHECK ([status] IN (N'In progress', N'Done', N'On hold')),
            CONSTRAINT [CK_finance_ap2_mail_ai_confidence]
                CHECK ([ai_confidence] IS NULL OR [ai_confidence] BETWEEN 0 AND 1)
        );

        CREATE INDEX [IX_finance_ap2_mail_received] ON [finance].[ap2_mail] ([received_at]);
        CREATE INDEX [IX_finance_ap2_mail_status] ON [finance].[ap2_mail] ([status], [received_at]);
        CREATE INDEX [IX_finance_ap2_mail_supplier]
            ON [finance].[ap2_mail] ([supplier_id], [received_at]) WHERE [supplier_id] IS NOT NULL;
        CREATE UNIQUE INDEX [UX_finance_ap2_mail_graph_message]
            ON [finance].[ap2_mail] ([mailbox], [graph_message_id]) WHERE [graph_message_id] IS NOT NULL;
    END;

    IF OBJECT_ID(N'[finance].[ap2_mail_attachments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_mail_attachments] (
            [id]                   CHAR(32)        NOT NULL,
            [mail_id]              CHAR(32)        NOT NULL,
            [file_id]              CHAR(32)        NULL,
            [graph_attachment_id]  NVARCHAR(400)   NULL,
            [name]                 NVARCHAR(255)   NOT NULL,
            [size]                 BIGINT          NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_attachments_size] DEFAULT (0),
            [content_type]         NVARCHAR(150)   NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_attachments_content_type] DEFAULT (N''),
            [created_at]           DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_mail_attachments_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_mail_attachments] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_mail_attachments_mail]
                FOREIGN KEY ([mail_id]) REFERENCES [finance].[ap2_mail] ([id]),
            CONSTRAINT [FK_finance_ap2_mail_attachments_file]
                FOREIGN KEY ([file_id]) REFERENCES [finance].[attachments] ([id]),
            CONSTRAINT [CK_finance_ap2_mail_attachments_size] CHECK ([size] >= 0)
        );

        CREATE INDEX [IX_finance_ap2_mail_attachments_mail] ON [finance].[ap2_mail_attachments] ([mail_id]);
        CREATE UNIQUE INDEX [UX_finance_ap2_mail_attachments_graph]
            ON [finance].[ap2_mail_attachments] ([mail_id], [graph_attachment_id]) WHERE [graph_attachment_id] IS NOT NULL;
    END;

    /* ------------------------------------------------------------------
       Statements
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_statements]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_statements] (
            [id]                 CHAR(32)        NOT NULL,
            [supplier_id]        CHAR(32)        NOT NULL,
            [mail_id]            CHAR(32)        NULL,
            [date]               DATE            NOT NULL,
            [amount]             DECIMAL(18,2)   NOT NULL,
            [currency]           CHAR(3)         NOT NULL
                CONSTRAINT [DF_finance_ap2_statements_currency] DEFAULT ('AUD'),
            [reference]          NVARCHAR(255)   NOT NULL
                CONSTRAINT [DF_finance_ap2_statements_reference] DEFAULT (N''),
            [status]             NVARCHAR(12)    NOT NULL
                CONSTRAINT [DF_finance_ap2_statements_status] DEFAULT (N'Unreviewed'),
            [evidence]           NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_statements_evidence] DEFAULT (N''),
            [notes]              NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_statements_notes] DEFAULT (N''),
            [reviewed_by_user_id] BIGINT         NULL,
            [reviewed_by_name]   NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_statements_reviewed_by_name] DEFAULT (N''),
            [reviewed_at]        DATETIME2(6)    NULL,
            [created_at]         DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_statements_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at]         DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_statements_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_statements] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_statements_supplier]
                FOREIGN KEY ([supplier_id]) REFERENCES [finance].[ap2_suppliers] ([id]),
            CONSTRAINT [FK_finance_ap2_statements_mail]
                FOREIGN KEY ([mail_id]) REFERENCES [finance].[ap2_mail] ([id]),
            CONSTRAINT [CK_finance_ap2_statements_currency] CHECK ([currency] IN ('AUD', 'USD', 'NZD', 'SGD')),
            CONSTRAINT [CK_finance_ap2_statements_status] CHECK ([status] IN (N'Unreviewed', N'Matched', N'Discrepancy'))
        );

        CREATE INDEX [IX_finance_ap2_statements_supplier] ON [finance].[ap2_statements] ([supplier_id], [date]);
    END;

    IF OBJECT_ID(N'[finance].[ap2_approval_events]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_approval_events] (
            [id]            CHAR(32)       NOT NULL,
            [approval_id]   CHAR(32)       NOT NULL,
            [action]        NVARCHAR(60)   NOT NULL,
            [by_user_id]    BIGINT         NULL,
            [by_name]       NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_ap2_approval_events_by_name] DEFAULT (N''),
            [by_email]      NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_ap2_approval_events_by_email] DEFAULT (N''),
            [at]            DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ap2_approval_events_at] DEFAULT (SYSUTCDATETIME()),
            [comment]       NVARCHAR(MAX)  NOT NULL
                CONSTRAINT [DF_finance_ap2_approval_events_comment] DEFAULT (N''),
            CONSTRAINT [PK_finance_ap2_approval_events] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_approval_events_approval]
                FOREIGN KEY ([approval_id]) REFERENCES [finance].[ap2_approvals] ([id])
        );

        CREATE INDEX [IX_finance_ap2_approval_events_approval]
            ON [finance].[ap2_approval_events] ([approval_id], [at]);
    END;

    /* ------------------------------------------------------------------
       Payments (Odoo pays; this is the proposal and what Odoo reports back)
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_payments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_payments] (
            [id]                 CHAR(32)        NOT NULL,
            [supplier_id]        CHAR(32)        NOT NULL,
            [invoice_id]         CHAR(32)        NOT NULL,
            [amount]             DECIMAL(18,2)   NOT NULL,
            [currency]           CHAR(3)         NOT NULL
                CONSTRAINT [DF_finance_ap2_payments_currency] DEFAULT ('AUD'),
            [date]               DATE            NOT NULL,
            [status]             NVARCHAR(30)    NOT NULL
                CONSTRAINT [DF_finance_ap2_payments_status] DEFAULT (N'Proposed'),
            [approval_id]        CHAR(32)        NULL,
            [odoo_payment_ref]   NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_ap2_payments_odoo_payment_ref] DEFAULT (N''),
            [rejection]          NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_payments_rejection] DEFAULT (N''),
            [created_by_user_id] BIGINT          NULL,
            [created_by_name]    NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_payments_created_by_name] DEFAULT (N''),
            [created_at]         DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_payments_created_at] DEFAULT (SYSUTCDATETIME()),
            [updated_at]         DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_payments_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_payments] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_payments_supplier]
                FOREIGN KEY ([supplier_id]) REFERENCES [finance].[ap2_suppliers] ([id]),
            CONSTRAINT [FK_finance_ap2_payments_invoice]
                FOREIGN KEY ([invoice_id]) REFERENCES [finance].[ap2_invoices] ([id]),
            CONSTRAINT [FK_finance_ap2_payments_approval]
                FOREIGN KEY ([approval_id]) REFERENCES [finance].[ap2_approvals] ([id]),
            CONSTRAINT [CK_finance_ap2_payments_currency] CHECK ([currency] IN ('AUD', 'USD', 'NZD', 'SGD')),
            CONSTRAINT [CK_finance_ap2_payments_amount] CHECK ([amount] > 0),
            CONSTRAINT [CK_finance_ap2_payments_status]
                CHECK ([status] IN (N'Proposed', N'Awaiting payment approval', N'Payment approved', N'Sent to Odoo',
                                    N'Paid', N'Cancelled', N'Rejected'))
        );

        CREATE INDEX [IX_finance_ap2_payments_invoice] ON [finance].[ap2_payments] ([invoice_id], [status]);
        CREATE INDEX [IX_finance_ap2_payments_status] ON [finance].[ap2_payments] ([status], [date]);
    END;

    /* ------------------------------------------------------------------
       History: the supplier activity ledger. APPEND ONLY.
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_history]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_history] (
            [id]             CHAR(32)        NOT NULL,
            [supplier_id]    CHAR(32)        NOT NULL,
            [invoice_id]     CHAR(32)        NULL,
            [mail_id]        CHAR(32)        NULL,
            [type]           NVARCHAR(60)    NOT NULL,
            [detail]         NVARCHAR(MAX)   NOT NULL
                CONSTRAINT [DF_finance_ap2_history_detail] DEFAULT (N''),
            [actor_user_id]  BIGINT          NULL,
            [actor_name]     NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_ap2_history_actor_name] DEFAULT (N''),
            [actor_email]    NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_ap2_history_actor_email] DEFAULT (N''),
            [at]             DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_ap2_history_at] DEFAULT (SYSUTCDATETIME()),
            [extra]          NVARCHAR(MAX)   NULL,
            CONSTRAINT [PK_finance_ap2_history] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_history_supplier]
                FOREIGN KEY ([supplier_id]) REFERENCES [finance].[ap2_suppliers] ([id]),
            CONSTRAINT [CK_finance_ap2_history_extra] CHECK ([extra] IS NULL OR ISJSON([extra]) = 1)
        );

        CREATE INDEX [IX_finance_ap2_history_supplier] ON [finance].[ap2_history] ([supplier_id], [at]);
        CREATE INDEX [IX_finance_ap2_history_invoice]
            ON [finance].[ap2_history] ([invoice_id], [at]) WHERE [invoice_id] IS NOT NULL;
    END;

    /* The ledger is never edited or removed (see the header). CREATE TRIGGER
       must be alone in its batch, so it goes through EXEC. */
    IF OBJECT_ID(N'[finance].[TR_ap2_history_append_only]', N'TR') IS NULL
    BEGIN
        EXEC (N'CREATE TRIGGER [finance].[TR_ap2_history_append_only]
            ON [finance].[ap2_history]
            INSTEAD OF UPDATE, DELETE
            AS
            BEGIN
                THROW 54312, N''finance.ap2_history is append only: its rows are never changed or deleted.'', 1;
            END;');
    END;

    /* ------------------------------------------------------------------
       The rest
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[ap2_notifications]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_notifications] (
            [id]       CHAR(32)       NOT NULL,
            [user_id]  BIGINT         NOT NULL,
            [title]    NVARCHAR(300)  NOT NULL,
            [body]     NVARCHAR(MAX)  NOT NULL
                CONSTRAINT [DF_finance_ap2_notifications_body] DEFAULT (N''),
            [at]       DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ap2_notifications_at] DEFAULT (SYSUTCDATETIME()),
            [is_read]  BIT            NOT NULL
                CONSTRAINT [DF_finance_ap2_notifications_is_read] DEFAULT (0),
            [link]     NVARCHAR(500)  NOT NULL
                CONSTRAINT [DF_finance_ap2_notifications_link] DEFAULT (N''),
            CONSTRAINT [PK_finance_ap2_notifications] PRIMARY KEY CLUSTERED ([id])
        );

        CREATE INDEX [IX_finance_ap2_notifications_user] ON [finance].[ap2_notifications] ([user_id], [is_read], [at]);
    END;

    IF OBJECT_ID(N'[finance].[ap2_exports]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_exports] (
            /* CSV-0001 style (the contract), not a CHAR(32) id. */
            [id]                  NVARCHAR(20)   NOT NULL,
            [invoice_ids]         NVARCHAR(MAX)  NOT NULL,
            [headers]             NVARCHAR(MAX)  NOT NULL,
            [rows]                NVARCHAR(MAX)  NOT NULL,
            [csv]                 NVARCHAR(MAX)  NOT NULL,
            [created_at]          DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ap2_exports_created_at] DEFAULT (SYSUTCDATETIME()),
            [created_by_user_id]  BIGINT         NULL,
            [created_by_name]     NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_ap2_exports_created_by_name] DEFAULT (N''),
            [created_by_email]    NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_ap2_exports_created_by_email] DEFAULT (N''),
            CONSTRAINT [PK_finance_ap2_exports] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_ap2_exports_json]
                CHECK (ISJSON([invoice_ids]) = 1 AND ISJSON([headers]) = 1 AND ISJSON([rows]) = 1)
        );
    END;

    IF OBJECT_ID(N'[finance].[ap2_priorities]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_priorities] (
            [user_id]  BIGINT        NOT NULL,
            [kind]     NVARCHAR(20)  NOT NULL,
            [item_id]  NVARCHAR(40)  NOT NULL,
            [flag]     BIT           NOT NULL
                CONSTRAINT [DF_finance_ap2_priorities_flag] DEFAULT (0),
            [pin]      BIT           NOT NULL
                CONSTRAINT [DF_finance_ap2_priorities_pin] DEFAULT (0),
            [updated_at] DATETIME2(6) NOT NULL
                CONSTRAINT [DF_finance_ap2_priorities_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_priorities] PRIMARY KEY CLUSTERED ([user_id], [kind], [item_id]),
            CONSTRAINT [CK_finance_ap2_priorities_kind]
                CHECK ([kind] IN (N'invoice', N'supplier', N'mail', N'approval', N'payment', N'statement'))
        );
    END;

    IF OBJECT_ID(N'[finance].[ap2_po_forecast]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_po_forecast] (
            [id]           CHAR(32)       NOT NULL,
            [po]           NVARCHAR(100)  NOT NULL,
            [supplier_id]  CHAR(32)       NOT NULL,
            [amount]       DECIMAL(18,2)  NOT NULL,
            [currency]     CHAR(3)        NOT NULL
                CONSTRAINT [DF_finance_ap2_po_forecast_currency] DEFAULT ('AUD'),
            [date]         DATE           NOT NULL,
            [updated_at]   DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ap2_po_forecast_updated_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_po_forecast] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_po_forecast_supplier]
                FOREIGN KEY ([supplier_id]) REFERENCES [finance].[ap2_suppliers] ([id]),
            CONSTRAINT [UQ_finance_ap2_po_forecast_po] UNIQUE ([po]),
            CONSTRAINT [CK_finance_ap2_po_forecast_currency] CHECK ([currency] IN ('AUD', 'USD', 'NZD', 'SGD')),
            CONSTRAINT [CK_finance_ap2_po_forecast_amount] CHECK ([amount] >= 0)
        );
    END;

    IF OBJECT_ID(N'[finance].[ap2_copilot_messages]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_copilot_messages] (
            [id]            CHAR(32)       NOT NULL,
            [user_id]       BIGINT         NOT NULL,
            [mail_id]       CHAR(32)       NULL,
            [supplier_id]   CHAR(32)       NULL,
            [role]          NVARCHAR(10)   NOT NULL,
            [message]       NVARCHAR(MAX)  NOT NULL,
            [proposals]     NVARCHAR(MAX)  NULL,
            [created_at]    DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ap2_copilot_messages_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_ap2_copilot_messages] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_ap2_copilot_messages_mail]
                FOREIGN KEY ([mail_id]) REFERENCES [finance].[ap2_mail] ([id]),
            CONSTRAINT [FK_finance_ap2_copilot_messages_supplier]
                FOREIGN KEY ([supplier_id]) REFERENCES [finance].[ap2_suppliers] ([id]),
            CONSTRAINT [CK_finance_ap2_copilot_messages_role] CHECK ([role] IN (N'user', N'assistant')),
            CONSTRAINT [CK_finance_ap2_copilot_messages_proposals]
                CHECK ([proposals] IS NULL OR ISJSON([proposals]) = 1)
        );

        CREATE INDEX [IX_finance_ap2_copilot_messages_user]
            ON [finance].[ap2_copilot_messages] ([user_id], [created_at]);
    END;

    IF OBJECT_ID(N'[finance].[ap2_sync_runs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[ap2_sync_runs] (
            [id]                  CHAR(32)       NOT NULL,
            [kind]                NVARCHAR(20)   NOT NULL,
            [status]              NVARCHAR(12)   NOT NULL
                CONSTRAINT [DF_finance_ap2_sync_runs_status] DEFAULT (N'Running'),
            [from_date]           DATE           NULL,
            [to_date]             DATE           NULL,
            [fetched]             INT            NOT NULL
                CONSTRAINT [DF_finance_ap2_sync_runs_fetched] DEFAULT (0),
            [created]             INT            NOT NULL
                CONSTRAINT [DF_finance_ap2_sync_runs_created] DEFAULT (0),
            [skipped]             INT            NOT NULL
                CONSTRAINT [DF_finance_ap2_sync_runs_skipped] DEFAULT (0),
            [error]               NVARCHAR(MAX)  NULL,
            [started_by_user_id]  BIGINT         NULL,
            [started_at]          DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_ap2_sync_runs_started_at] DEFAULT (SYSUTCDATETIME()),
            [finished_at]         DATETIME2(6)   NULL,
            CONSTRAINT [PK_finance_ap2_sync_runs] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_ap2_sync_runs_kind] CHECK ([kind] IN (N'mail', N'odoo-bill-state', N'odoo-po')),
            CONSTRAINT [CK_finance_ap2_sync_runs_status] CHECK ([status] IN (N'Running', N'Succeeded', N'Failed'))
        );

        CREATE INDEX [IX_finance_ap2_sync_runs_kind] ON [finance].[ap2_sync_runs] ([kind], [started_at]);
    END;

    SELECT
        (SELECT COUNT(*) FROM sys.tables t JOIN sys.schemas s ON s.[schema_id] = t.[schema_id]
          WHERE s.[name] = N'finance' AND t.[name] LIKE N'ap2[_]%') AS [Ap2Tables];

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
