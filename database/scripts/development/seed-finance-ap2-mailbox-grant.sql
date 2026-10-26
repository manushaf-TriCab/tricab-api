/*
================================================================================
  Tri-AP2: the AP2 application's grant on the shared AP mailbox
  (Tri-AP2 backend, Phase 3 and 4). Written 26 Oct 2026.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (Manusha or Jeff edit the
  mailbox registry). Needs alter-finance-ap2-app-key.sql first, so that the
  application key AP2 passes the mailbox_grants CHECK.

  One grant, ap@tricab.com only (Finance decision, 26 Oct: Tri-AP2 does not
  read anyone's work inbox):
    AP2  can_read = 1   the manual / scheduled mail sync reads the Inbox. Tri-AP2
                        never moves, flags or marks a message read.
    AP2  can_send = 1   threaded replies and approval emails are sent from
                        ap@. Nothing is sent unless FINANCE_AP2_SENDING_ENABLED
                        is true; while testing keep FINANCE_MAIL_TEST_RECIPIENT
                        set so every send is redirected.

  The mailbox row is created only if ap@ is missing (production and local seeds
  already have it). Inserts only: a grant that exists is never changed.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    INSERT INTO [finance].[mailboxes] ([id], [email], [display_name], [purpose], [is_active])
    SELECT '0c1a0000000000000000000000000001', N'ap@tricab.com', N'Accounts Payable', N'Vendor invoices and AP approvals', 1
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[mailboxes] e WHERE e.[email] = N'ap@tricab.com');

    INSERT INTO [finance].[mailbox_grants] ([id], [application_key], [mailbox_id], [can_read], [can_send])
    SELECT '0c1b0000000000000000000000000008', N'AP2', mb.[id], 1, 1
    FROM [finance].[mailboxes] mb
    WHERE mb.[email] = N'ap@tricab.com'
      AND NOT EXISTS (
          SELECT 1 FROM [finance].[mailbox_grants] e
          WHERE e.[application_key] = N'AP2' AND e.[mailbox_id] = mb.[id]
      );

    IF NOT EXISTS (
        SELECT 1
        FROM [finance].[mailbox_grants] e
        JOIN [finance].[mailboxes] mb ON mb.[id] = e.[mailbox_id]
        WHERE mb.[email] = N'ap@tricab.com' AND mb.[is_active] = 1 AND e.[application_key] = N'AP2' AND e.[can_read] = 1
    )
    BEGIN
        THROW 54322, N'The AP2 grant on ap@tricab.com is not in place. Nothing was changed.', 1;
    END;

    SELECT mb.[email], e.[application_key], e.[can_read], e.[can_send]
    FROM [finance].[mailbox_grants] e
    JOIN [finance].[mailboxes] mb ON mb.[id] = e.[mailbox_id]
    WHERE mb.[email] = N'ap@tricab.com'
    ORDER BY e.[application_key];

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
