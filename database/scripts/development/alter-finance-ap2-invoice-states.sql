/*
================================================================================
  Tri-AP2 invoice states, business approval and the Odoo bill template
  (Nick's 1 Nov 2026 update, finance-new/Tri-AP-complete-updated).

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. A fresh build of
  create-finance-ap2-schema.sql already has the objects below.

  Run BEFORE deploying the matching API: the API reads the new columns and
  writes only the three new stage values.

  1. [finance].[ap2_invoices] gets:
       [approval_required]  BIT, default 0. Set to 1 for every invoice that has
                            an invoice approval request (the prototype's
                            migration: a request means approval was wanted).
       [external_id]        NVARCHAR(100) NULL (NULL = triap_<id>, as before).
       [journal]            NVARCHAR(100), default ''.
       [invoice_lines]      NVARCHAR(MAX) NULL, JSON (NULL = one line made from
                            the invoice's own coding).
  2. [stage] becomes exactly Needs verification / Confirmed / Posted:
       Posted, Partially settled, Settled -> Posted (settlement is the balance)
       Approved, Pending approval         -> Confirmed (both meant every check
                                             passed)
       Received, Needs verification,
       Awaiting matching, Excluded        -> Needs verification (exclusion stays
                                             in [excluded])
     The API re-derives the stage on the next write in any case.

  Nothing is deleted; no other table changes. Existing approvals stay current:
  the fingerprint only takes in the new fields once one is set.

  Idempotent. Run with sqlcmd -I (QUOTED_IDENTIFIER).
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[ap2_invoices]', N'U') IS NULL
    BEGIN
        THROW 54321, N'The Tri-AP2 tables do not exist. Run create-finance-ap2-schema.sql first.', 1;
    END;

    IF COL_LENGTH(N'[finance].[ap2_invoices]', N'approval_required') IS NULL
    BEGIN
        ALTER TABLE [finance].[ap2_invoices] ADD [approval_required] BIT NOT NULL
            CONSTRAINT [DF_finance_ap2_invoices_approval_required] DEFAULT (0);
        EXEC (N'UPDATE inv SET inv.[approval_required] = 1
                 FROM [finance].[ap2_invoices] inv
                WHERE EXISTS (SELECT 1 FROM [finance].[ap2_approvals] a
                               WHERE a.[invoice_id] = inv.[id] AND a.[kind] = N''Invoice'');');
    END;
    IF COL_LENGTH(N'[finance].[ap2_invoices]', N'external_id') IS NULL
        ALTER TABLE [finance].[ap2_invoices] ADD [external_id] NVARCHAR(100) NULL;
    IF COL_LENGTH(N'[finance].[ap2_invoices]', N'journal') IS NULL
        ALTER TABLE [finance].[ap2_invoices] ADD [journal] NVARCHAR(100) NOT NULL
            CONSTRAINT [DF_finance_ap2_invoices_journal] DEFAULT (N'');
    IF COL_LENGTH(N'[finance].[ap2_invoices]', N'invoice_lines') IS NULL
    BEGIN
        ALTER TABLE [finance].[ap2_invoices] ADD [invoice_lines] NVARCHAR(MAX) NULL;
        EXEC (N'ALTER TABLE [finance].[ap2_invoices] ADD CONSTRAINT [CK_finance_ap2_invoices_lines]
                  CHECK ([invoice_lines] IS NULL OR ISJSON([invoice_lines]) = 1);');
    END;

    -- The stage check: replace the nine-value one with the three-value one.
    IF NOT EXISTS (
        SELECT 1 FROM sys.check_constraints
         WHERE [name] = N'CK_finance_ap2_invoices_stage'
           AND [parent_object_id] = OBJECT_ID(N'[finance].[ap2_invoices]')
           AND [definition] LIKE N'%Confirmed%')
    BEGIN
        IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [name] = N'CK_finance_ap2_invoices_stage' AND [parent_object_id] = OBJECT_ID(N'[finance].[ap2_invoices]'))
            ALTER TABLE [finance].[ap2_invoices] DROP CONSTRAINT [CK_finance_ap2_invoices_stage];

        UPDATE [finance].[ap2_invoices]
           SET [stage] = CASE
                   WHEN [stage] IN (N'Posted', N'Partially settled', N'Settled') OR [import_state] = N'Posted' THEN N'Posted'
                   WHEN [stage] IN (N'Approved', N'Pending approval') THEN N'Confirmed'
                   ELSE N'Needs verification'
               END
         WHERE [stage] NOT IN (N'Needs verification', N'Confirmed', N'Posted')
            OR ([import_state] = N'Posted' AND [stage] <> N'Posted');

        ALTER TABLE [finance].[ap2_invoices] ADD CONSTRAINT [CK_finance_ap2_invoices_stage]
            CHECK ([stage] IN (N'Needs verification', N'Confirmed', N'Posted'));
    END;

    -- The stage default: Received is no longer a stage.
    IF EXISTS (
        SELECT 1 FROM sys.default_constraints
         WHERE [name] = N'DF_finance_ap2_invoices_stage'
           AND [parent_object_id] = OBJECT_ID(N'[finance].[ap2_invoices]')
           AND [definition] LIKE N'%Received%')
    BEGIN
        ALTER TABLE [finance].[ap2_invoices] DROP CONSTRAINT [DF_finance_ap2_invoices_stage];
        ALTER TABLE [finance].[ap2_invoices] ADD CONSTRAINT [DF_finance_ap2_invoices_stage]
            DEFAULT (N'Needs verification') FOR [stage];
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

SELECT [stage], [approval_required], COUNT(*) AS [Invoices]
  FROM [finance].[ap2_invoices]
 GROUP BY [stage], [approval_required]
 ORDER BY [stage], [approval_required];
GO
