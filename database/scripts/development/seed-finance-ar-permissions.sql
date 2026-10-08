/*
================================================================================
  DEVELOPMENT seed - Tri-AR permissions (finance Phase 5, task T5.1).

  - Idempotent / safe to re-run
  - Creates no users, no roles and no finance data
  - Touches only FINANCE.AR.* permissions
  - WRITTEN, NOT EXECUTED against any shared database (7 Oct 2026).
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.
  - Run after seed-finance-permissions.sql (which seeds FINANCE.AR.ACCESS).

  Must match TriCab\Core\Finance\Module::AR_PERMISSIONS;
  tests/Finance/Ar/FinanceArSeedScriptTest.php fails if they drift. The map
  from the portal's ar.* strings is docs/finance/permissions.md section 7.

  Four permissions. Three are the portal's; FINANCE.AR.ATTACHMENTS.READ is new
  (Phase 5 question Q5). The platform roles are not changed here: ADMINISTRATOR
  holds FINANCE.ADMIN only, EMPLOYEE and GUEST nothing. The three Tri-AR roles
  are created by seed-finance-ar-roles.sql.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'finance';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'FINANCE.AR.RECORDS.READ', N'Read Tri-AR records',
         N'See the register, each record and its original message, the credit note requests, the scan history and the Settings lists.'),
        (N'FINANCE.AR.RECORDS.WRITE', N'Change Tri-AR records',
         N'Change a record, move it to the Bin and back, scan the mailbox, send a reply, and send a credit note form.'),
        (N'FINANCE.AR.ATTACHMENTS.READ', N'Read Tri-AR attachments',
         N'Open the files kept from a message: customers'' remittance and payment documents.'),
        (N'FINANCE.AR.ADMIN', N'Administer Tri-AR',
         N'Manage the Category and Method lists in Settings.');

    /* Insert what is missing, leave existing rows alone. */
    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    /* ------------------------------------------------------------------
       Guards. Each would be a serious fault, so each fails the script.
       ------------------------------------------------------------------ */

    /* Nobody is a finance user by default: employees and guests hold nothing. */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(p.[PermissionCode]) LIKE N'FINANCE.%'
          AND UPPER(r.[RoleCode]) IN (N'GUEST', N'EMPLOYEE')
    )
    BEGIN
        THROW 54101, N'EMPLOYEE or GUEST holds a finance permission. Finance access is granted through the finance roles only. Refusing to continue.', 1;
    END;

    /* The platform administrator administers finance and opens no app. */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
          AND UPPER(p.[PermissionCode]) LIKE N'FINANCE.%'
          AND UPPER(p.[PermissionCode]) <> N'FINANCE.ADMIN'
    )
    BEGIN
        THROW 54102, N'ADMINISTRATOR holds a finance permission other than FINANCE.ADMIN. Refusing to continue.', 1;
    END;

    /* Every permission landed. */
    IF (SELECT COUNT(*) FROM @Permissions p
         JOIN [identity].[Permissions] e ON UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
         WHERE e.[IsActive] = 1) <> (SELECT COUNT(*) FROM @Permissions)
    BEGIN
        THROW 54152, N'A Tri-AR permission is missing or inactive after the seed. Refusing to continue.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [FinancePermissions],
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode
            AND UPPER([PermissionCode]) LIKE N'FINANCE.AR.%') AS [ArPermissionsIncludingAccess];

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
