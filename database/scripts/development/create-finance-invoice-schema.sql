/*
================================================================================
  Tri-AP's tables in [finance] (Phase 6, task T6.2). Written 9 Oct 2026.
  Additive to create-finance-schema.sql and create-finance-attachments-schema.sql;
  run after both.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  Fourteen tables, shaped on Tri-AP's own SQLite tables (inventory/tri-ap.md
  section 3, server/store.py at 2db2411) with the September rewrite's prefix
  (decision D70). The columns are the LIVE ones, not the rewrite's, which is nine
  invoice columns and three tables behind:

    [finance].[invoice_invoices]            the Vendor Invoice Register
    [finance].[invoice_companies]           the TriCab entities an invoice is billed to
    [finance].[invoice_cost_centres]        reference list (Settings)
    [finance].[invoice_account_categories]  reference list (Settings)
    [finance].[invoice_signing_limits]      signing authority by D365 position
    [finance].[invoice_emails]              the AP Mailbox: one row per scanned message
    [finance].[invoice_email_attachments]   the files of a scanned message
    [finance].[invoice_scan_runs]           scan history and the claim
    [finance].[invoice_scan_skips]          tombstones: a deleted capture is not re-imported
    [finance].[invoice_outbound_messages]   vendor mail, replies and approval mail: a
                                            link to [finance].[mail_deliveries] that
                                            keeps the body (decision D73)
    [finance].[invoice_approval_batches]    an approval request
    [finance].[invoice_approval_items]      the invoices in a request
    [finance].[invoice_approval_decisions]  who decided, and how
    [finance].[invoice_audit_events]        what a person did to an invoice or an email

  The source's [users] and [mailboxes] are not carried (question Q14): people are
  the platform's, and mailboxes are [finance].[mailboxes].

  What changed from the source, and why:

    - Conventions (decision D71): ids are CHAR(32) lower-case hex generated in PHP;
      timestamps are DATETIME2(6) in UTC; text is NVARCHAR; flags are BIT; money is
      DECIMAL(18,2) (it was REAL). [invoice_date] and [due_date] are DATE (the
      source kept yyyy-MM-dd text). The values a page reads are kept as the source
      wrote them, capitals and all ('Needs Review', 'Cleared by user').
    - Overdue is NOT a stored status (question Q4): [status] is Needs Review,
      In Progress, Completed or Exported, and the check refuses Overdue. Overdue is
      derived on read from [due_date].
    - A file is a row of [finance].[attachments] (decision D72): [file_id] replaces
      the source's [attachment_url] and [stored_url], which were addresses of a
      file on the host's disk. [content_hash] stays on the invoice and on the
      attachment: exact-duplicate detection reads it.
    - Every person column holds identity.Users.UserId (decision D7), with the name
      or address at the time beside it and no foreign key outside [finance]. The
      source kept portal ids, and looked approvers up in a table that was empty
      (question Q10).
    - [invoice_outbound_messages] keeps [body] and takes the subject, recipients,
      status, error and sent time from the mail ledger through [delivery_id]
      (decision D73).
    - Lists sort and filter on bounded, indexed columns (question Q5): there are
      indexes on [deleted_at], [status], [mailbox], [due_date] and [email_date],
      none of which the source indexed. [notes], [review_reason] and the other long
      text are NVARCHAR(MAX) and are never sorted on.
    - [invoice_scan_runs] holds a claim (decision D76): a filtered unique index
      allows one run that is Listing or Running, and [claim_expires_at] lets a
      later scan fail a run that died holding it. A Tri-AP scan covers every
      mailbox the person may read in one run, so the claim is for the app, not for
      one mailbox; [mailboxes_json] records which were read.
    - [invoice_scan_skips] has an id for its key: the source's key (message id,
      file name) is wider than a clustered key may be. The pair is still unique.
    - [invoice_email_attachments] is unique on (email, Graph attachment id) only
      where the attachment id is present: SQL Server treats two NULLs as equal.
    - [invoice_approval_decisions].[decision] is checked: Approved or Rejected, the
      two the API writes. A table made before 10 Oct 2026 gets the check, and the
      wider [invoice_scan_skips].[attachment], from alter-finance-invoice-followups.sql.
    - Foreign keys inside [finance] only, with no cascades: a permanent delete
      removes the children first, in code, in one transaction.

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
        THROW 54231, N'[finance].[attachments] does not exist. Run create-finance-attachments-schema.sql first.', 1;
    END;

    /* ------------------------------------------------------------------
       Reference lists
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[invoice_companies]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_companies] (
            [id]            CHAR(32)        NOT NULL,
            [code]          NVARCHAR(50)    NOT NULL
                CONSTRAINT [DF_finance_invoice_companies_code] DEFAULT (N''),
            [name]          NVARCHAR(200)   NOT NULL,
            [sites]         NVARCHAR(2000)  NOT NULL
                CONSTRAINT [DF_finance_invoice_companies_sites] DEFAULT (N''),
            [former_names]  NVARCHAR(2000)  NOT NULL
                CONSTRAINT [DF_finance_invoice_companies_former_names] DEFAULT (N''),
            [is_active]     BIT             NOT NULL
                CONSTRAINT [DF_finance_invoice_companies_is_active] DEFAULT (1),
            [created_date]  DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_invoice_companies_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_companies] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [UQ_finance_invoice_companies_name] UNIQUE ([name])
        );
    END;

    IF OBJECT_ID(N'[finance].[invoice_cost_centres]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_cost_centres] (
            [id]            CHAR(32)       NOT NULL,
            [code_no]       NVARCHAR(50)   NOT NULL
                CONSTRAINT [DF_finance_invoice_cost_centres_code_no] DEFAULT (N''),
            [code]          NVARCHAR(100)  NOT NULL,
            [name]          NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_invoice_cost_centres_name] DEFAULT (N''),
            [is_active]     BIT            NOT NULL
                CONSTRAINT [DF_finance_invoice_cost_centres_is_active] DEFAULT (1),
            [created_date]  DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_cost_centres_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_cost_centres] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [UQ_finance_invoice_cost_centres_code] UNIQUE ([code])
        );
    END;

    IF OBJECT_ID(N'[finance].[invoice_account_categories]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_account_categories] (
            [id]            CHAR(32)       NOT NULL,
            [code]          NVARCHAR(100)  NOT NULL,
            [name]          NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_invoice_account_categories_name] DEFAULT (N''),
            [is_active]     BIT            NOT NULL
                CONSTRAINT [DF_finance_invoice_account_categories_is_active] DEFAULT (1),
            [created_date]  DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_account_categories_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_account_categories] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [UQ_finance_invoice_account_categories_code] UNIQUE ([code])
        );
    END;

    /* One row per D365 position, not per person. A NULL limit means no authority
       for that document type, which is not a limit of 0; 99999999999 is how the
       source writes "unlimited" and is kept as it is. */
    IF OBJECT_ID(N'[finance].[invoice_signing_limits]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_signing_limits] (
            [id]                CHAR(32)        NOT NULL,
            [authority_role]    NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_invoice_signing_limits_authority_role] DEFAULT (N''),
            [role_code]         NVARCHAR(50)    NOT NULL
                CONSTRAINT [DF_finance_invoice_signing_limits_role_code] DEFAULT (N''),
            [full_name]         NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_invoice_signing_limits_full_name] DEFAULT (N''),
            [email]             NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_invoice_signing_limits_email] DEFAULT (N''),
            [d365_position]     NVARCHAR(200)   NOT NULL,
            [po_inventory]      DECIMAL(18,2)   NULL,
            [po_non_inventory]  DECIMAL(18,2)   NULL,
            [expense_claim]     DECIMAL(18,2)   NULL,
            [customer_order]    DECIMAL(18,2)   NULL,
            [credit_notes]      DECIMAL(18,2)   NULL,
            [is_active]         BIT             NOT NULL
                CONSTRAINT [DF_finance_invoice_signing_limits_is_active] DEFAULT (1),
            [created_date]      DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_invoice_signing_limits_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_signing_limits] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [UQ_finance_invoice_signing_limits_position] UNIQUE ([d365_position])
        );
    END;

    /* ------------------------------------------------------------------
       Scans and the AP Mailbox
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[invoice_scan_runs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_scan_runs] (
            [id]                    CHAR(32)       NOT NULL,
            [requested_by_user_id]  BIGINT         NULL,
            [requested_by]          NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_requested_by] DEFAULT (N''),
            [from_date]             DATE           NULL,
            [to_date]               DATE           NULL,
            [mailboxes_json]        NVARCHAR(MAX)  NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_mailboxes_json] DEFAULT (N'[]'),
            [status]                NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_status] DEFAULT (N'Running'),
            [claim_scope]           NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_claim_scope] DEFAULT (N'ap'),
            [claim_expires_at]      DATETIME2(6)   NULL,
            [total_messages]        INT            NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_total_messages] DEFAULT (0),
            [processed_messages]    INT            NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_processed_messages] DEFAULT (0),
            [invoice_emails]        INT            NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_invoice_emails] DEFAULT (0),
            [other_emails]          INT            NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_other_emails] DEFAULT (0),
            [duplicate_invoices]    INT            NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_duplicate_invoices] DEFAULT (0),
            [errors]                INT            NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_errors] DEFAULT (0),
            [created_date]          DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date]          DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_runs_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_scan_runs] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_invoice_scan_runs_status]
                CHECK ([status] IN (N'Listing', N'Running', N'Complete', N'Failed'))
        );

        CREATE UNIQUE INDEX [UX_finance_invoice_scan_runs_one_open]
            ON [finance].[invoice_scan_runs] ([claim_scope])
            WHERE [status] IN (N'Listing', N'Running');

        CREATE INDEX [IX_finance_invoice_scan_runs_period]
            ON [finance].[invoice_scan_runs] ([from_date], [to_date]);

        CREATE INDEX [IX_finance_invoice_scan_runs_created]
            ON [finance].[invoice_scan_runs] ([created_date]);
    END;

    IF OBJECT_ID(N'[finance].[invoice_emails]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_emails] (
            [id]                         CHAR(32)        NOT NULL,
            [graph_message_id]           NVARCHAR(200)   NOT NULL,
            [mailbox]                    NVARCHAR(320)   NOT NULL,
            [received_at]                DATETIME2(6)    NULL,
            [sender_name]                NVARCHAR(400)   NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_sender_name] DEFAULT (N''),
            [sender_email]               NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_sender_email] DEFAULT (N''),
            [subject]                    NVARCHAR(400)   NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_subject] DEFAULT (N''),
            [body_preview]               NVARCHAR(1000)  NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_body_preview] DEFAULT (N''),
            [web_link]                   NVARCHAR(2000)  NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_web_link] DEFAULT (N''),
            [has_attachments]            BIT             NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_has_attachments] DEFAULT (0),
            [classification]             NVARCHAR(20)    NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_classification] DEFAULT (N'Other'),
            [classification_reason]      NVARCHAR(MAX)   NULL,
            [classification_confidence]  DECIMAL(9,8)    NULL,
            [review_status]              NVARCHAR(20)    NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_review_status] DEFAULT (N'Unverified'),
            [proposed_response]          NVARCHAR(MAX)   NULL,
            [scan_run_id]                CHAR(32)        NULL,
            [deleted_at]                 DATETIME2(6)    NULL,
            [deleted_by_user_id]         BIGINT          NULL,
            [deleted_by]                 NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_deleted_by] DEFAULT (N''),
            [created_date]               DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date]               DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_invoice_emails_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_emails] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [UQ_finance_invoice_emails_mailbox_message] UNIQUE ([mailbox], [graph_message_id]),
            CONSTRAINT [FK_finance_invoice_emails_scan_run]
                FOREIGN KEY ([scan_run_id]) REFERENCES [finance].[invoice_scan_runs] ([id]),
            CONSTRAINT [CK_finance_invoice_emails_classification]
                CHECK ([classification] IN (N'Invoice', N'Other', N'Error')),
            CONSTRAINT [CK_finance_invoice_emails_review_status]
                CHECK ([review_status] IN (N'Unverified', N'Verified', N'Needs review')),
            CONSTRAINT [CK_finance_invoice_emails_confidence]
                CHECK ([classification_confidence] IS NULL OR [classification_confidence] BETWEEN 0 AND 1)
        );

        CREATE INDEX [IX_finance_invoice_emails_period]
            ON [finance].[invoice_emails] ([received_at], [classification]);

        CREATE INDEX [IX_finance_invoice_emails_deleted_at]
            ON [finance].[invoice_emails] ([deleted_at]);

        CREATE INDEX [IX_finance_invoice_emails_mailbox]
            ON [finance].[invoice_emails] ([mailbox], [received_at]);
    END;

    /* ------------------------------------------------------------------
       The register
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[invoice_invoices]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_invoices] (
            [id]                       CHAR(32)        NOT NULL,
            /* Where it came from. [mailbox] NULL is a hand capture ("Uploaded"). */
            [email_id]                 NVARCHAR(200)   NULL,
            [mailbox]                  NVARCHAR(320)   NULL,
            [email_date]               DATETIME2(6)    NULL,
            [sender]                   NVARCHAR(400)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_sender] DEFAULT (N''),
            [subject]                  NVARCHAR(400)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_subject] DEFAULT (N''),
            [email_record_id]          CHAR(32)        NULL,
            [attachment_id]            NVARCHAR(400)   NULL,
            [attachment]               NVARCHAR(255)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_attachment] DEFAULT (N''),
            [file_id]                  CHAR(32)        NULL,
            [content_hash]             CHAR(64)        NULL,
            /* What the invoice says. */
            [invoice_no]               NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_invoice_no] DEFAULT (N''),
            [vendor]                   NVARCHAR(400)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_vendor] DEFAULT (N''),
            [vendor_email]             NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_vendor_email] DEFAULT (N''),
            [tax_id]                   NVARCHAR(50)    NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_tax_id] DEFAULT (N''),
            [invoice_date]             DATE            NULL,
            [due_date]                 DATE            NULL,
            [po_number]                NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_po_number] DEFAULT (N''),
            [currency]                 NVARCHAR(10)    NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_currency] DEFAULT (N''),
            [net_amount]               DECIMAL(18,2)   NULL,
            [tax_amount]               DECIMAL(18,2)   NULL,
            [total_amount]             DECIMAL(18,2)   NULL,
            [company]                  NVARCHAR(200)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_company] DEFAULT (N''),
            [account_category]         NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_account_category] DEFAULT (N''),
            [cost_centre]              NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_cost_centre] DEFAULT (N''),
            /* Workflow. */
            [status]                   NVARCHAR(20)    NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_status] DEFAULT (N'Needs Review'),
            [notes]                    NVARCHAR(MAX)   NULL,
            [is_revised]               BIT             NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_is_revised] DEFAULT (0),
            [duplicate_status]         NVARCHAR(20)    NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_duplicate_status] DEFAULT (N'Clear'),
            [duplicate_of_invoice_id]  CHAR(32)        NULL,
            [duplicate_reason]         NVARCHAR(1000)  NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_duplicate_reason] DEFAULT (N''),
            [approval_status]          NVARCHAR(20)    NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_approval_status] DEFAULT (N'Not requested'),
            [extraction_confidence]    DECIMAL(9,8)    NULL,
            [review_reason]            NVARCHAR(MAX)   NULL,
            [deleted_at]               DATETIME2(6)    NULL,
            [deleted_by_user_id]       BIGINT          NULL,
            [deleted_by]               NVARCHAR(320)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_deleted_by] DEFAULT (N''),
            /* Who the vendor is in Odoo. [vendor] stays what the invoice says. */
            [odoo_vendor_id]           BIGINT          NULL,
            [vendor_ref]               NVARCHAR(100)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_vendor_ref] DEFAULT (N''),
            [odoo_vendor_name]         NVARCHAR(400)   NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_odoo_vendor_name] DEFAULT (N''),
            [vendor_match]             NVARCHAR(20)    NULL,
            [vendor_match_note]        NVARCHAR(1000)  NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_vendor_match_note] DEFAULT (N''),
            [vendor_match_by_user_id]  BIGINT          NULL,
            [created_date]             DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date]             DATETIME2(6)    NOT NULL
                CONSTRAINT [DF_finance_invoice_invoices_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_invoices] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_invoice_invoices_email]
                FOREIGN KEY ([email_record_id]) REFERENCES [finance].[invoice_emails] ([id]),
            CONSTRAINT [FK_finance_invoice_invoices_file]
                FOREIGN KEY ([file_id]) REFERENCES [finance].[attachments] ([id]),
            CONSTRAINT [CK_finance_invoice_invoices_status]
                CHECK ([status] IN (N'Needs Review', N'In Progress', N'Completed', N'Exported')),
            CONSTRAINT [CK_finance_invoice_invoices_duplicate_status]
                CHECK ([duplicate_status] IN (N'Clear', N'Potential', N'Cleared by user')),
            CONSTRAINT [CK_finance_invoice_invoices_approval_status]
                CHECK ([approval_status] IN (N'Not requested', N'Pending approval', N'Approved', N'Rejected')),
            CONSTRAINT [CK_finance_invoice_invoices_vendor_match]
                CHECK ([vendor_match] IS NULL OR [vendor_match] IN (N'matched', N'confirmed', N'unmatched', N'new')),
            CONSTRAINT [CK_finance_invoice_invoices_confidence]
                CHECK ([extraction_confidence] IS NULL OR [extraction_confidence] BETWEEN 0 AND 1)
        );

        CREATE INDEX [IX_finance_invoice_invoices_email]
            ON [finance].[invoice_invoices] ([email_id], [attachment]);

        CREATE INDEX [IX_finance_invoice_invoices_hash]
            ON [finance].[invoice_invoices] ([content_hash])
            WHERE [content_hash] IS NOT NULL;

        CREATE INDEX [IX_finance_invoice_invoices_email_date]
            ON [finance].[invoice_invoices] ([email_date], [id]);

        CREATE INDEX [IX_finance_invoice_invoices_deleted_at]
            ON [finance].[invoice_invoices] ([deleted_at]);

        CREATE INDEX [IX_finance_invoice_invoices_status]
            ON [finance].[invoice_invoices] ([status]);

        CREATE INDEX [IX_finance_invoice_invoices_mailbox]
            ON [finance].[invoice_invoices] ([mailbox]);

        CREATE INDEX [IX_finance_invoice_invoices_due_date]
            ON [finance].[invoice_invoices] ([due_date])
            WHERE [due_date] IS NOT NULL;

        CREATE INDEX [IX_finance_invoice_invoices_invoice_no]
            ON [finance].[invoice_invoices] ([invoice_no]);

        CREATE INDEX [IX_finance_invoice_invoices_odoo_vendor]
            ON [finance].[invoice_invoices] ([odoo_vendor_id])
            WHERE [odoo_vendor_id] IS NOT NULL;

        CREATE INDEX [IX_finance_invoice_invoices_email_record]
            ON [finance].[invoice_invoices] ([email_record_id])
            WHERE [email_record_id] IS NOT NULL;
    END;

    IF OBJECT_ID(N'[finance].[invoice_email_attachments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_email_attachments] (
            [id]                       CHAR(32)       NOT NULL,
            [email_record_id]          CHAR(32)       NOT NULL,
            [graph_attachment_id]      NVARCHAR(400)  NULL,
            [name]                     NVARCHAR(255)  NOT NULL
                CONSTRAINT [DF_finance_invoice_email_attachments_name] DEFAULT (N''),
            [content_type]             NVARCHAR(150)  NOT NULL
                CONSTRAINT [DF_finance_invoice_email_attachments_content_type] DEFAULT (N''),
            [size]                     BIGINT         NULL,
            [content_hash]             CHAR(64)       NULL,
            [file_id]                  CHAR(32)       NULL,
            [is_invoice]               BIT            NOT NULL
                CONSTRAINT [DF_finance_invoice_email_attachments_is_invoice] DEFAULT (0),
            [invoice_id]               CHAR(32)       NULL,
            [duplicate_of_invoice_id]  CHAR(32)       NULL,
            [created_date]             DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_email_attachments_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date]             DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_email_attachments_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_email_attachments] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_invoice_email_attachments_email]
                FOREIGN KEY ([email_record_id]) REFERENCES [finance].[invoice_emails] ([id]),
            CONSTRAINT [FK_finance_invoice_email_attachments_file]
                FOREIGN KEY ([file_id]) REFERENCES [finance].[attachments] ([id]),
            CONSTRAINT [FK_finance_invoice_email_attachments_invoice]
                FOREIGN KEY ([invoice_id]) REFERENCES [finance].[invoice_invoices] ([id])
        );

        CREATE UNIQUE INDEX [UX_finance_invoice_email_attachments_email_graph]
            ON [finance].[invoice_email_attachments] ([email_record_id], [graph_attachment_id])
            WHERE [graph_attachment_id] IS NOT NULL;

        CREATE INDEX [IX_finance_invoice_email_attachments_email]
            ON [finance].[invoice_email_attachments] ([email_record_id]);

        CREATE INDEX [IX_finance_invoice_email_attachments_hash]
            ON [finance].[invoice_email_attachments] ([content_hash])
            WHERE [content_hash] IS NOT NULL;

        CREATE INDEX [IX_finance_invoice_email_attachments_invoice]
            ON [finance].[invoice_email_attachments] ([invoice_id])
            WHERE [invoice_id] IS NOT NULL;
    END;

    IF OBJECT_ID(N'[finance].[invoice_scan_skips]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_scan_skips] (
            [id]            CHAR(32)       NOT NULL,
            [email_id]      NVARCHAR(200)  NOT NULL,
            [attachment]    NVARCHAR(400)  NOT NULL,
            [created_date]  DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_scan_skips_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_scan_skips] PRIMARY KEY CLUSTERED ([id])
        );

        CREATE UNIQUE INDEX [UX_finance_invoice_scan_skips_email_attachment]
            ON [finance].[invoice_scan_skips] ([email_id], [attachment]);
    END;

    /* ------------------------------------------------------------------
       Approvals
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[invoice_approval_batches]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_approval_batches] (
            [id]                   CHAR(32)       NOT NULL,
            [prepared_by_user_id]  BIGINT         NULL,
            [prepared_by_name]     NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_batches_prepared_by_name] DEFAULT (N''),
            [prepared_by_email]    NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_batches_prepared_by_email] DEFAULT (N''),
            [status]               NVARCHAR(20)   NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_batches_status] DEFAULT (N'Pending'),
            [note]                 NVARCHAR(MAX)  NULL,
            [decided_by_user_id]   BIGINT         NULL,
            [decided_by_name]      NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_batches_decided_by_name] DEFAULT (N''),
            [decided_at]           DATETIME2(6)   NULL,
            [decision_comment]     NVARCHAR(MAX)  NULL,
            [created_date]         DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_batches_created_date] DEFAULT (SYSUTCDATETIME()),
            [updated_date]         DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_batches_updated_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_approval_batches] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_invoice_approval_batches_status]
                CHECK ([status] IN (N'Pending', N'Approved', N'Rejected'))
        );

        CREATE INDEX [IX_finance_invoice_approval_batches_created]
            ON [finance].[invoice_approval_batches] ([created_date], [id]);
    END;

    IF OBJECT_ID(N'[finance].[invoice_approval_items]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_approval_items] (
            [id]            CHAR(32)      NOT NULL,
            [batch_id]      CHAR(32)      NOT NULL,
            [invoice_id]    CHAR(32)      NOT NULL,
            [created_date]  DATETIME2(6)  NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_items_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_approval_items] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [UQ_finance_invoice_approval_items_batch_invoice] UNIQUE ([batch_id], [invoice_id]),
            CONSTRAINT [FK_finance_invoice_approval_items_batch]
                FOREIGN KEY ([batch_id]) REFERENCES [finance].[invoice_approval_batches] ([id]),
            CONSTRAINT [FK_finance_invoice_approval_items_invoice]
                FOREIGN KEY ([invoice_id]) REFERENCES [finance].[invoice_invoices] ([id])
        );

        CREATE INDEX [IX_finance_invoice_approval_items_invoice]
            ON [finance].[invoice_approval_items] ([invoice_id]);
    END;

    IF OBJECT_ID(N'[finance].[invoice_approval_decisions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_approval_decisions] (
            [id]                CHAR(32)       NOT NULL,
            [batch_id]          CHAR(32)       NOT NULL,
            [approver_user_id]  BIGINT         NULL,
            [approver_name]     NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_decisions_approver_name] DEFAULT (N''),
            [approver_email]    NVARCHAR(320)  NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_decisions_approver_email] DEFAULT (N''),
            [decision]          NVARCHAR(20)   NOT NULL,
            [comment]           NVARCHAR(MAX)  NULL,
            [created_date]      DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_approval_decisions_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_approval_decisions] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [CK_finance_invoice_approval_decisions_decision]
                CHECK ([decision] IN (N'Approved', N'Rejected')),
            CONSTRAINT [FK_finance_invoice_approval_decisions_batch]
                FOREIGN KEY ([batch_id]) REFERENCES [finance].[invoice_approval_batches] ([id])
        );

        CREATE INDEX [IX_finance_invoice_approval_decisions_batch]
            ON [finance].[invoice_approval_decisions] ([batch_id], [created_date]);
    END;

    /* ------------------------------------------------------------------
       Sent mail and the audit trail
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[finance].[invoice_outbound_messages]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_outbound_messages] (
            [id]                 CHAR(32)       NOT NULL,
            [invoice_id]         CHAR(32)       NULL,
            [email_id]           CHAR(32)       NULL,
            [approval_batch_id]  CHAR(32)       NULL,
            [delivery_id]        CHAR(32)       NOT NULL,
            [sender_user_id]     BIGINT         NULL,
            [body]               NVARCHAR(MAX)  NULL,
            [attach_invoice]     BIT            NOT NULL
                CONSTRAINT [DF_finance_invoice_outbound_messages_attach_invoice] DEFAULT (0),
            [created_date]       DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_outbound_messages_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_outbound_messages] PRIMARY KEY CLUSTERED ([id]),
            CONSTRAINT [FK_finance_invoice_outbound_messages_invoice]
                FOREIGN KEY ([invoice_id]) REFERENCES [finance].[invoice_invoices] ([id]),
            CONSTRAINT [FK_finance_invoice_outbound_messages_email]
                FOREIGN KEY ([email_id]) REFERENCES [finance].[invoice_emails] ([id]),
            CONSTRAINT [FK_finance_invoice_outbound_messages_batch]
                FOREIGN KEY ([approval_batch_id]) REFERENCES [finance].[invoice_approval_batches] ([id])
        );

        CREATE INDEX [IX_finance_invoice_outbound_messages_invoice]
            ON [finance].[invoice_outbound_messages] ([invoice_id], [created_date])
            WHERE [invoice_id] IS NOT NULL;

        CREATE INDEX [IX_finance_invoice_outbound_messages_email]
            ON [finance].[invoice_outbound_messages] ([email_id], [created_date])
            WHERE [email_id] IS NOT NULL;

        CREATE INDEX [IX_finance_invoice_outbound_messages_batch]
            ON [finance].[invoice_outbound_messages] ([approval_batch_id])
            WHERE [approval_batch_id] IS NOT NULL;
    END;

    IF OBJECT_ID(N'[finance].[invoice_audit_events]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[invoice_audit_events] (
            [id]             CHAR(32)       NOT NULL,
            [actor_user_id]  BIGINT         NULL,
            [actor_name]     NVARCHAR(200)  NOT NULL
                CONSTRAINT [DF_finance_invoice_audit_events_actor_name] DEFAULT (N''),
            [action]         NVARCHAR(60)   NOT NULL,
            [entity_type]    NVARCHAR(40)   NOT NULL,
            [entity_id]      NVARCHAR(100)  NOT NULL,
            [details]        NVARCHAR(MAX)  NULL,
            [created_date]   DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_invoice_audit_events_created_date] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_invoice_audit_events] PRIMARY KEY CLUSTERED ([id])
        );

        CREATE INDEX [IX_finance_invoice_audit_events_entity]
            ON [finance].[invoice_audit_events] ([entity_type], [entity_id], [created_date]);
    END;

    SELECT
        (SELECT COUNT(*) FROM sys.tables t JOIN sys.schemas s ON s.[schema_id] = t.[schema_id]
          WHERE s.[name] = N'finance' AND t.[name] LIKE N'invoice[_]%') AS [InvoiceTables],
        (SELECT COUNT(*) FROM sys.indexes i JOIN sys.tables t ON t.[object_id] = i.[object_id]
          JOIN sys.schemas s ON s.[schema_id] = t.[schema_id]
          WHERE s.[name] = N'finance' AND t.[name] LIKE N'invoice[_]%' AND i.[index_id] > 0) AS [InvoiceIndexes];

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
