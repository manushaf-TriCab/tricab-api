/*
================================================================================
  DEVELOPMENT seed - Tri-AP permissions (finance Phase 6, task T6.1).

  - Idempotent / safe to re-run
  - Creates no users, no roles and no finance data
  - Touches only FINANCE.AP.* permissions
  - WRITTEN, NOT EXECUTED against any shared database (8 Oct 2026).
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.
  - Run after seed-finance-permissions.sql (which seeds FINANCE.AP.ACCESS).

  Must match TriCab\Core\Finance\Module::AP_PERMISSIONS;
  tests/Finance/Ap/FinanceApSeedScriptTest.php fails if they drift. The map
  from the portal's invoice.* strings is docs/finance/permissions.md section 8.

  Eight permissions, all the portal's (invoice.users.manage is not carried).
  The portal seeded them but checked only three; the platform checks one on
  every route (Phase 6 question Q1). The platform roles are not changed here:
  ADMINISTRATOR holds FINANCE.ADMIN only, EMPLOYEE and GUEST nothing. The four
  Tri-AP roles are created by seed-finance-ap-roles.sql.
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
        (N'FINANCE.AP.INVOICES.READ', N'Read Tri-AP invoices',
         N'See the register, each invoice and its document, the vendors, the approval requests and the Settings lists.'),
        (N'FINANCE.AP.INVOICES.WRITE', N'Change Tri-AP invoices',
         N'Capture and change an invoice, move it to the Bin and back, match its vendor, and reclassify a scanned email.'),
        (N'FINANCE.AP.INVOICES.DELETE', N'Delete Tri-AP invoices for good',
         N'Permanently delete an invoice that is in the Bin, with its document.'),
        (N'FINANCE.AP.MAIL.READ', N'Read the AP mailboxes',
         N'See the AP Mailbox, open a scanned email and its attachments, and scan the mailboxes.'),
        (N'FINANCE.AP.MAIL.SEND', N'Send Tri-AP mail',
         N'Draft and send a vendor email or a reply from a mailbox Tri-AP may send from.'),
        (N'FINANCE.AP.APPROVALS.PREPARE', N'Request Tri-AP approval',
         N'Put invoices in an approval request and send it to the approvers.'),
        (N'FINANCE.AP.APPROVALS.DECIDE', N'Decide Tri-AP approvals',
         N'Approve or reject an approval request.'),
        (N'FINANCE.AP.SETTINGS.MANAGE', N'Manage Tri-AP settings',
         N'Manage the Companies, Cost Centres and Account Categories lists in Settings.');

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
        THROW 54162, N'A Tri-AP permission is missing or inactive after the seed. Refusing to continue.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [FinancePermissions],
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode
            AND UPPER([PermissionCode]) LIKE N'FINANCE.AP.%') AS [ApPermissionsIncludingAccess];

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
