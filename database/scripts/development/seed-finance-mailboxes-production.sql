/*
================================================================================
  PRODUCTION registry - finance mailboxes and each app's grant on them.

  Written 8 Oct 2026 for the Tri-Task and Tri-AR go-live (FINANCE-PHASE-5-RUNBOOK.md,
  gap 1). STATUS: NOT APPLIED. Applied to tricab-mel by Manusha or Jeff, never by
  a builder. The local database uses seed-finance-mailboxes-local.sql instead.

  - Idempotent / safe to re-run: a mailbox or grant that is missing is inserted,
    one that exists is set to the values below
  - Run after create-finance-schema.sql
  - Touches [finance].[mailboxes] and [finance].[mailbox_grants] only

  The rows are the finance portal's registry as Manusha gave it on 8 Oct 2026
  (the portal's Mailboxes page), for the three apps that are moving:

    ap@tricab.com          Tri-AP    read + send
    ar@tricab.com          Tri-AR    read + send
    christine@tricab.com   Tri-AP    read + send
    financeau@tricab.com   Tri-Task  send        (the portal also grants read; Tri-Task reads no mail)
    melissaw@tricab.com    Tri-AR    read + send
    olivern@tricab.com     Tri-AR    read + send

  Left out on purpose: the portal's Tri-Credit Notes and Argo Collect grants
  (on ar@, melissaw@ and olivern@). Both apps are deferred (plan section 8) and
  stay on AWS; their grants arrive with their own phase.

  A grant only says what an app MAY do. Nothing is read while
  FINANCE_MAILBOX_READ_ENABLED is off, nothing is sent while FINANCE_MAIL_ENABLED
  is off, and Tri-AR sends nothing while FINANCE_AR_SENDING_ENABLED is off.
  Tri-AR scans only the mailbox named in FINANCE_AR_MAILBOX.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[mailboxes]', N'U') IS NULL OR OBJECT_ID(N'[finance].[mailbox_grants]', N'U') IS NULL
    BEGIN
        THROW 54201, N'The finance mailbox registry does not exist. Run create-finance-schema.sql first.', 1;
    END;

    DECLARE @Mailboxes TABLE (
        [id]           CHAR(32)      NOT NULL,
        [email]        NVARCHAR(320) NOT NULL PRIMARY KEY,
        [display_name] NVARCHAR(200) NOT NULL,
        [purpose]      NVARCHAR(300) NOT NULL
    );
    INSERT INTO @Mailboxes ([id], [email], [display_name], [purpose])
    VALUES
        ('0c1a0000000000000000000000000001', N'ap@tricab.com',        N'AP Mailbox',        N''),
        ('0c1a0000000000000000000000000002', N'ar@tricab.com',        N'AR Mailbox',        N'Read AR inbox'),
        ('0c1a0000000000000000000000000003', N'christine@tricab.com', N'Christine Mailbox', N'Invoice Intake'),
        ('0c1a0000000000000000000000000004', N'financeau@tricab.com', N'Finance AU',        N'Tri-Task Notifications'),
        ('0c1a0000000000000000000000000005', N'melissaw@tricab.com',  N'Melissa Wu',        N''),
        ('0c1a0000000000000000000000000006', N'olivern@tricab.com',   N'Ollie Mailbox',     N'AR Mailbox monitoring');

    INSERT INTO [finance].[mailboxes] ([id], [email], [display_name], [purpose], [is_active])
    SELECT m.[id], m.[email], m.[display_name], m.[purpose], 1
    FROM @Mailboxes m
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[mailboxes] e WHERE e.[email] = m.[email]);

    UPDATE e
    SET e.[display_name] = m.[display_name],
        e.[purpose] = m.[purpose],
        e.[is_active] = 1,
        e.[updated_at] = SYSUTCDATETIME()
    FROM [finance].[mailboxes] e
    JOIN @Mailboxes m ON m.[email] = e.[email]
    WHERE e.[display_name] <> m.[display_name] OR e.[purpose] <> m.[purpose] OR e.[is_active] = 0;

    DECLARE @Grants TABLE (
        [id]              CHAR(32)      NOT NULL,
        [email]           NVARCHAR(320) NOT NULL,
        [application_key] NVARCHAR(16)  NOT NULL,
        [can_read]        BIT           NOT NULL,
        [can_send]        BIT           NOT NULL,
        PRIMARY KEY ([email], [application_key])
    );
    INSERT INTO @Grants ([id], [email], [application_key], [can_read], [can_send])
    VALUES
        ('0c1b0000000000000000000000000001', N'ap@tricab.com',        N'AP',   1, 1),
        ('0c1b0000000000000000000000000003', N'ar@tricab.com',        N'AR',   1, 1),
        ('0c1b0000000000000000000000000004', N'christine@tricab.com', N'AP',   1, 1),
        ('0c1b0000000000000000000000000005', N'financeau@tricab.com', N'TASK', 0, 1),
        ('0c1b0000000000000000000000000006', N'melissaw@tricab.com',  N'AR',   1, 1),
        ('0c1b0000000000000000000000000007', N'olivern@tricab.com',   N'AR',   1, 1);

    INSERT INTO [finance].[mailbox_grants] ([id], [application_key], [mailbox_id], [can_read], [can_send])
    SELECT g.[id], g.[application_key], mb.[id], g.[can_read], g.[can_send]
    FROM @Grants g
    JOIN [finance].[mailboxes] mb ON mb.[email] = g.[email]
    WHERE NOT EXISTS (
        SELECT 1 FROM [finance].[mailbox_grants] e
        WHERE e.[application_key] = g.[application_key] AND e.[mailbox_id] = mb.[id]
    );

    UPDATE e
    SET e.[can_read] = g.[can_read],
        e.[can_send] = g.[can_send],
        e.[updated_at] = SYSUTCDATETIME()
    FROM [finance].[mailbox_grants] e
    JOIN [finance].[mailboxes] mb ON mb.[id] = e.[mailbox_id]
    JOIN @Grants g ON g.[email] = mb.[email] AND g.[application_key] = e.[application_key]
    WHERE e.[can_read] <> g.[can_read] OR e.[can_send] <> g.[can_send];

    /* Every grant landed. */
    IF (SELECT COUNT(*)
        FROM @Grants g
        JOIN [finance].[mailboxes] mb ON mb.[email] = g.[email] AND mb.[is_active] = 1
        JOIN [finance].[mailbox_grants] e
          ON e.[mailbox_id] = mb.[id] AND e.[application_key] = g.[application_key]
         AND e.[can_read] = g.[can_read] AND e.[can_send] = g.[can_send]) <> (SELECT COUNT(*) FROM @Grants)
    BEGIN
        THROW 54202, N'Not every finance mailbox grant is in place. Nothing was changed.', 1;
    END;

    SELECT mb.[email], e.[application_key], e.[can_read], e.[can_send]
    FROM [finance].[mailbox_grants] e
    JOIN [finance].[mailboxes] mb ON mb.[id] = e.[mailbox_id]
    ORDER BY mb.[email], e.[application_key];

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
