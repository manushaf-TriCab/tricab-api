/*
================================================================================
  LOCAL DEVELOPMENT seed - finance mailbox registry. NOT FOR ANY SHARED DATABASE.

  - Idempotent / safe to re-run
  - Inserts rows only where the mailbox, or the app's grant on it, is missing; it
    never changes a row that exists
  - Run after create-finance-schema.sql, against the local Docker database only

  The finance portal's registry is deployment configuration (MAILBOX_SEED_JSON),
  not code, so there is no source list to copy. These rows exist so the local app
  and the tests have a registry to read. They name the two shared mailboxes plan
  4.5 records, and grant them as that section describes the apps' use:

    ap@tricab.com   Tri-AP reads and sends; Tri-Task sends.
    ar@tricab.com   Tri-AR reads and sends.

  Nothing here sends mail: with FINANCE_MAIL_ENABLED off, a send makes no network
  call. The real registry is edited by SQL by Manusha or Jeff once the mailbox
  grants are decided; this is not that list.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Mailboxes TABLE (
        [id]           CHAR(32)      NOT NULL,
        [email]        NVARCHAR(320) NOT NULL,
        [display_name] NVARCHAR(200) NOT NULL,
        [purpose]      NVARCHAR(300) NOT NULL
    );
    INSERT INTO @Mailboxes ([id], [email], [display_name], [purpose])
    VALUES
        ('0c1a0000000000000000000000000001', N'ap@tricab.com', N'Accounts Payable', N'Vendor invoices and AP approvals (local development row)'),
        ('0c1a0000000000000000000000000002', N'ar@tricab.com', N'Accounts Receivable', N'Remittances and customer replies (local development row)');

    INSERT INTO [finance].[mailboxes] ([id], [email], [display_name], [purpose], [is_active])
    SELECT m.[id], m.[email], m.[display_name], m.[purpose], 1
    FROM @Mailboxes m
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[mailboxes] e WHERE e.[email] = m.[email]);

    DECLARE @Grants TABLE (
        [id]              CHAR(32)     NOT NULL,
        [email]           NVARCHAR(320) NOT NULL,
        [application_key] NVARCHAR(16) NOT NULL,
        [can_read]        BIT          NOT NULL,
        [can_send]        BIT          NOT NULL
    );
    INSERT INTO @Grants ([id], [email], [application_key], [can_read], [can_send])
    VALUES
        ('0c1b0000000000000000000000000001', N'ap@tricab.com', N'AP',   1, 1),
        ('0c1b0000000000000000000000000002', N'ap@tricab.com', N'TASK', 0, 1),
        ('0c1b0000000000000000000000000003', N'ar@tricab.com', N'AR',   1, 1);

    INSERT INTO [finance].[mailbox_grants] ([id], [application_key], [mailbox_id], [can_read], [can_send])
    SELECT g.[id], g.[application_key], mb.[id], g.[can_read], g.[can_send]
    FROM @Grants g
    JOIN [finance].[mailboxes] mb ON mb.[email] = g.[email]
    WHERE NOT EXISTS (
        SELECT 1 FROM [finance].[mailbox_grants] e
        WHERE e.[application_key] = g.[application_key] AND e.[mailbox_id] = mb.[id]
    );

    SELECT
        (SELECT COUNT(*) FROM [finance].[mailboxes])      AS [Mailboxes],
        (SELECT COUNT(*) FROM [finance].[mailbox_grants]) AS [Grants];

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
