/*
================================================================================
  Tri-AR - empty the app's data. DESTRUCTIVE. The undo of the data migration
  (bin/migrate-finance-ar.php) and the cleanup before a second attempt
  (FINANCE-PHASE-5-RUNBOOK.md, gaps 4 and 5).

  Written 8 Oct 2026. STATUS: NOT APPLIED. Run by Manusha or Jeff, never by a
  builder, and only while nobody is using Tri-AR on the platform
  (FINANCE_AR_ENABLED off).

  As written it only COUNTS: it deletes nothing until the line below is changed
  to    DECLARE @Confirm NVARCHAR(40) = N'DELETE TRI-AR DATA';

  It deletes every row of the eight finance.ar_* tables and the finance.attachments
  rows that belong to Tri-AR (application_key 'AR'). That includes the Category and
  Method lists, so the migration can bring the team's own lists (decision D63);
  the platform seeds its defaults again on first use if nothing is migrated.

  It does NOT delete:
    - the files in the blob container. Their paths are the [blob_path] values this
      script prints before deleting the rows; remove exactly those from the
      finance-attachments container afterwards.
    - finance.mail_deliveries, finance.job_runs or any audit row: a record of
      what was sent and run stays.
    - permissions, roles, role assignments, mailboxes or grants.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @Confirm NVARCHAR(40) = N'';

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[ar_email_records]', N'U') IS NULL
    BEGIN
        THROW 54221, N'The Tri-AR tables do not exist; there is nothing to reset.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [finance].[ar_email_records])        AS [Records],
        (SELECT COUNT(*) FROM [finance].[ar_email_attachments])    AS [AttachmentRows],
        (SELECT COUNT(*) FROM [finance].[ar_remittance_lines])     AS [RemittanceLines],
        (SELECT COUNT(*) FROM [finance].[ar_outbound_messages])    AS [OutboundMessages],
        (SELECT COUNT(*) FROM [finance].[ar_credit_note_requests]) AS [CreditNoteRequests],
        (SELECT COUNT(*) FROM [finance].[ar_scan_runs])            AS [ScanRuns],
        (SELECT COUNT(*) FROM [finance].[ar_option_lists])         AS [OptionRows],
        (SELECT COUNT(*) FROM [finance].[ar_deleted_messages])     AS [Tombstones],
        (SELECT COUNT(*) FROM [finance].[attachments] WHERE [application_key] = N'AR') AS [Files],
        CASE WHEN @Confirm = N'DELETE TRI-AR DATA' THEN N'DELETING' ELSE N'COUNT ONLY - nothing deleted' END AS [Mode];

    IF @Confirm = N'DELETE TRI-AR DATA'
    BEGIN
        /* The files to remove from the blob container afterwards. */
        SELECT [blob_path] FROM [finance].[attachments] WHERE [application_key] = N'AR' ORDER BY [blob_path];

        DELETE FROM [finance].[ar_remittance_lines];
        DELETE FROM [finance].[ar_email_attachments];
        DELETE FROM [finance].[ar_outbound_messages];
        DELETE FROM [finance].[ar_credit_note_requests];
        DELETE FROM [finance].[ar_email_records];
        DELETE FROM [finance].[ar_scan_runs];
        DELETE FROM [finance].[ar_option_lists];
        DELETE FROM [finance].[ar_deleted_messages];
        DELETE FROM [finance].[attachments] WHERE [application_key] = N'AR';

        IF EXISTS (SELECT 1 FROM [finance].[ar_email_records])
           OR EXISTS (SELECT 1 FROM [finance].[attachments] WHERE [application_key] = N'AR')
        BEGIN
            THROW 54222, N'Tri-AR rows remain after the delete. Nothing was changed.', 1;
        END;
    END;

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
