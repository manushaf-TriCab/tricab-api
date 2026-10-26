/*
================================================================================
  DEVELOPMENT seed - Tri-AP2 roles (Tri-AP2 backend, Phase 1).

  STATUS: NOT APPLIED to any shared database. Written 26 Oct 2026.
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.

  - Idempotent / safe to re-run
  - Creates four roles, FINANCE_AP2_OPERATOR, FINANCE_AP2_APPROVER,
    FINANCE_AP2_BANK_VERIFIER and FINANCE_AP2_ADMIN, and ASSIGNS THEM TO NOBODY
  - Does not modify permissions, EMPLOYEE, GUEST, ADMINISTRATOR or FINANCE_ADMIN
  - Run after seed-finance-permissions.sql and seed-finance-ap2-permissions.sql

    FINANCE_AP2_OPERATOR       INVOICES.READ/WRITE, MAIL.READ/SEND, APPROVALS.REQUEST,
                               EXPORT, PAYMENTS.PREPARE.
    FINANCE_AP2_APPROVER       INVOICES.READ, MAIL.READ, APPROVALS.DECIDE, PAYMENTS.APPROVE.
    FINANCE_AP2_BANK_VERIFIER  INVOICES.READ and BANK.VERIFY.
    FINANCE_AP2_ADMIN          every Tri-AP2 permission.

  Each role also holds FINANCE.ACCESS and FINANCE.AP2.ACCESS so that a person
  given one role can open the launcher and the app. Assigning people is done in
  the platform's user administration, not here.

  Must match TriCab\Core\Finance\Module::AP2_ROLE_PERMISSIONS;
  tests/Finance/Ap2/FinanceAp2SeedScriptTest.php fails if they drift. This
  script OWNS the FINANCE.* mappings of the roles it creates: anything else
  FINANCE.* on them is deactivated, never deleted.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Roles TABLE (
        [RoleCode]    NVARCHAR(100) NOT NULL PRIMARY KEY,
        [RoleName]    NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL
    );

    INSERT INTO @Roles ([RoleCode], [RoleName], [Description])
    VALUES
        (N'FINANCE_AP2_OPERATOR', N'Finance Tri-AP2 Operator',
         N'Tri-AP2: read and change invoices and suppliers, work the mailbox, request approvals, export to Odoo and prepare payments.'),
        (N'FINANCE_AP2_APPROVER', N'Finance Tri-AP2 Approver',
         N'Tri-AP2: read invoices, approve or reject invoice requests and approve payments.'),
        (N'FINANCE_AP2_BANK_VERIFIER', N'Finance Tri-AP2 Bank Verifier',
         N'Tri-AP2: read invoices and record independent verification of a supplier bank change.'),
        (N'FINANCE_AP2_ADMIN', N'Finance Tri-AP2 Administrator',
         N'Everything in Tri-AP2, including settings.');

    /* The roles. IsSystemRole = 0 so an administrator can edit them in
       Roles & Permissions without fighting the seed. */
    INSERT INTO [identity].[Roles] ([RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive])
    SELECT r.[RoleCode], r.[RoleName], r.[Description], 0, 1
    FROM @Roles r
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Roles] e WHERE UPPER(e.[RoleCode]) = UPPER(r.[RoleCode])
    );

    UPDATE e
    SET e.[IsActive] = 1,
        e.[RoleName] = r.[RoleName],
        e.[Description] = r.[Description],
        e.[UpdatedAt] = SYSDATETIMEOFFSET()
    FROM [identity].[Roles] e
    JOIN @Roles r ON UPPER(r.[RoleCode]) = UPPER(e.[RoleCode])
    WHERE e.[IsActive] = 0 OR e.[RoleName] <> r.[RoleName] OR ISNULL(e.[Description], N'') <> r.[Description];

    /* Grants. Generated from Module::AP_ROLE_PERMISSIONS. */
    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.ACCESS'),
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.AP2.ACCESS'),
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.AP2.INVOICES.READ'),
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.AP2.INVOICES.WRITE'),
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.AP2.MAIL.READ'),
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.AP2.MAIL.SEND'),
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.AP2.APPROVALS.REQUEST'),
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.AP2.EXPORT'),
        (N'FINANCE_AP2_OPERATOR', N'FINANCE.AP2.PAYMENTS.PREPARE'),
        (N'FINANCE_AP2_APPROVER', N'FINANCE.ACCESS'),
        (N'FINANCE_AP2_APPROVER', N'FINANCE.AP2.ACCESS'),
        (N'FINANCE_AP2_APPROVER', N'FINANCE.AP2.INVOICES.READ'),
        (N'FINANCE_AP2_APPROVER', N'FINANCE.AP2.MAIL.READ'),
        (N'FINANCE_AP2_APPROVER', N'FINANCE.AP2.APPROVALS.DECIDE'),
        (N'FINANCE_AP2_APPROVER', N'FINANCE.AP2.PAYMENTS.APPROVE'),
        (N'FINANCE_AP2_BANK_VERIFIER', N'FINANCE.ACCESS'),
        (N'FINANCE_AP2_BANK_VERIFIER', N'FINANCE.AP2.ACCESS'),
        (N'FINANCE_AP2_BANK_VERIFIER', N'FINANCE.AP2.INVOICES.READ'),
        (N'FINANCE_AP2_BANK_VERIFIER', N'FINANCE.AP2.BANK.VERIFY'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.ACCESS'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.ACCESS'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.INVOICES.READ'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.INVOICES.WRITE'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.MAIL.READ'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.MAIL.SEND'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.APPROVALS.REQUEST'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.APPROVALS.DECIDE'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.PAYMENTS.PREPARE'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.PAYMENTS.APPROVE'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.BANK.VERIFY'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.EXPORT'),
        (N'FINANCE_AP2_ADMIN', N'FINANCE.AP2.SETTINGS.MANAGE');

    INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
    SELECT r.[RoleId], p.[PermissionId], 1
    FROM @Grants g
    JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = UPPER(g.[RoleCode])
    JOIN [identity].[Permissions] p ON UPPER(p.[PermissionCode]) = UPPER(g.[PermissionCode])
    WHERE p.[IsActive] = 1
      AND NOT EXISTS (
          SELECT 1 FROM [identity].[RolePermissions] rp
          WHERE rp.[RoleId] = r.[RoleId] AND rp.[PermissionId] = p.[PermissionId]
      );

    UPDATE rp
    SET rp.[IsActive] = 1
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    JOIN @Grants g
      ON UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
     AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
    WHERE rp.[IsActive] = 0;

    /* Withdraw any other FINANCE.* mapping on these roles. Scoped to FINANCE.*:
       an unrelated permission somebody deliberately added in Roles &
       Permissions is theirs to unmake, not this script's. */
    UPDATE rp
    SET rp.[IsActive] = 0
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles)
      AND UPPER(p.[PermissionCode]) LIKE N'FINANCE.%'
      AND NOT EXISTS (
          SELECT 1 FROM @Grants g
          WHERE UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
            AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
      );

    /* ------------------------------------------------------------------
       Guards.
       ------------------------------------------------------------------ */

    /* Every grant landed. A role missing a permission is found by a person
       hitting a 403. */
    DECLARE @Expected INT = (SELECT COUNT(*) FROM @Grants);
    DECLARE @Granted INT = (
        SELECT COUNT(*)
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        JOIN @Grants g
          ON UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
         AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
        WHERE rp.[IsActive] = 1
    );

    IF @Granted <> @Expected
    BEGIN
        DECLARE @GrantedMessage NVARCHAR(300) =
            CONCAT(N'Tri-AP2 roles hold ', @Granted, N' of ', @Expected, N' expected grants. Run seed-finance-permissions.sql and seed-finance-ap2-permissions.sql first.');
        THROW 54301, @GrantedMessage, 1;
    END;

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

    SELECT r.[RoleCode], COUNT(*) AS [ActiveFinancePermissions]
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles)
      AND UPPER(p.[PermissionCode]) LIKE N'FINANCE.%'
    GROUP BY r.[RoleCode];

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
