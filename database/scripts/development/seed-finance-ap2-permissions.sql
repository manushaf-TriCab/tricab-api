/*
================================================================================
  DEVELOPMENT seed - Tri-AP2 permissions (Tri-AP2 backend, Phase 1).

  STATUS: NOT APPLIED to any shared database. Written 26 Oct 2026.
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.

  - Idempotent / safe to re-run
  - Creates no users, no roles and no finance data
  - Touches only FINANCE.AP2.* permissions
  - Run after seed-finance-permissions.sql (which seeds FINANCE.AP2.ACCESS).

  Must match TriCab\Core\Finance\Module::AP2_PERMISSIONS;
  tests/Finance/Ap2/FinanceAp2SeedScriptTest.php fails if they drift.

  Eleven permissions, each route checks exactly one (contract: platform/
  tri-ap-new/TRI-AP2-API-CONTRACT.md). The platform roles are not changed here:
  ADMINISTRATOR holds FINANCE.ADMIN only, EMPLOYEE and GUEST nothing. The four
  Tri-AP2 roles are created by seed-finance-ap2-roles.sql.
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
        (N'FINANCE.AP2.INVOICES.READ', N'Read Tri-AP2 invoices',
         N'See the invoice workbench, each invoice with its computed checks, the suppliers, the dashboard and a supplier''s history.'),
        (N'FINANCE.AP2.INVOICES.WRITE', N'Change Tri-AP2 invoices and suppliers',
         N'Create and change an invoice or a supplier, exclude a duplicate, and record a supplier action.'),
        (N'FINANCE.AP2.MAIL.READ', N'Read the Tri-AP2 mailbox',
         N'See the supplier inbox, open an email and its attachments, and prepare a draft.'),
        (N'FINANCE.AP2.MAIL.SEND', N'Send Tri-AP2 mail',
         N'Send a reviewed reply or share a supplier''s history from the AP mailbox.'),
        (N'FINANCE.AP2.APPROVALS.REQUEST', N'Request Tri-AP2 approval',
         N'Put an invoice, an email or a payment in an approval request and send it to an approver.'),
        (N'FINANCE.AP2.APPROVALS.DECIDE', N'Decide Tri-AP2 approvals',
         N'Approve or reject an approval request addressed to oneself.'),
        (N'FINANCE.AP2.PAYMENTS.PREPARE', N'Prepare Tri-AP2 payments',
         N'Propose a payment for a posted, approved invoice and cancel a proposal.'),
        (N'FINANCE.AP2.PAYMENTS.APPROVE', N'Approve Tri-AP2 payments',
         N'Approve or reject a payment proposal.'),
        (N'FINANCE.AP2.BANK.VERIFY', N'Verify supplier bank changes',
         N'Record the independent evidence that a supplier''s bank details are right.'),
        (N'FINANCE.AP2.EXPORT', N'Export Tri-AP2 invoices to Odoo',
         N'Export approved invoices as an Odoo CSV, record the import result and post to Odoo.'),
        (N'FINANCE.AP2.SETTINGS.MANAGE', N'Manage Tri-AP2 settings',
         N'Change the Tri-AP2 defaults, limits and approval expiry.');

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
        THROW 54302, N'A Tri-AP2 permission is missing or inactive after the seed. Refusing to continue.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [FinancePermissions],
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode
            AND UPPER([PermissionCode]) LIKE N'FINANCE.AP2.%') AS [Ap2PermissionsIncludingAccess];

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
