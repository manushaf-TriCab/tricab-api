/*
================================================================================
  DEVELOPMENT seed - Tri-AR roles (finance Phase 5, task T5.1).

  - Idempotent / safe to re-run
  - Creates three roles, FINANCE_AR_REVIEWER, FINANCE_AR_OPERATOR and
    FINANCE_AR_ADMIN, and ASSIGNS THEM TO NOBODY
  - Does not modify permissions, EMPLOYEE, GUEST, ADMINISTRATOR or FINANCE_ADMIN
  - WRITTEN, NOT EXECUTED against any shared database (7 Oct 2026).
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.
  - Run after seed-finance-permissions.sql and seed-finance-ar-permissions.sql

    FINANCE_AR_REVIEWER   FINANCE.ACCESS, FINANCE.AR.ACCESS, RECORDS.READ and
                          ATTACHMENTS.READ.
    FINANCE_AR_OPERATOR   the above plus RECORDS.WRITE.
    FINANCE_AR_ADMIN      the above plus ADMIN (the Settings lists).

  Each role also holds FINANCE.ACCESS and FINANCE.AR.ACCESS so that a person
  given one role can open the launcher and the app (permissions.md section 2).
  Assigning people is done in the platform's user administration, not here.

  Must match TriCab\Core\Finance\Module::AR_ROLE_PERMISSIONS;
  tests/Finance/Ar/FinanceArSeedScriptTest.php fails if they drift. This script
  OWNS the FINANCE.* mappings of the roles it creates: anything else FINANCE.*
  on them is deactivated, never deleted.
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
        (N'FINANCE_AR_REVIEWER', N'Finance Tri-AR Reviewer',
         N'Tri-AR: read the register, the records and their attachments. Changes nothing.'),
        (N'FINANCE_AR_OPERATOR', N'Finance Tri-AR Operator',
         N'Everything a Reviewer can do, plus change records, scan the mailbox and send mail.'),
        (N'FINANCE_AR_ADMIN', N'Finance Tri-AR Administrator',
         N'Everything an Operator can do, plus the Category and Method lists in Settings.');

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

    /* Grants. Generated from Module::AR_ROLE_PERMISSIONS. */
    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'FINANCE_AR_REVIEWER', N'FINANCE.ACCESS'),
        (N'FINANCE_AR_REVIEWER', N'FINANCE.AR.ACCESS'),
        (N'FINANCE_AR_REVIEWER', N'FINANCE.AR.RECORDS.READ'),
        (N'FINANCE_AR_REVIEWER', N'FINANCE.AR.ATTACHMENTS.READ'),
        (N'FINANCE_AR_OPERATOR', N'FINANCE.ACCESS'),
        (N'FINANCE_AR_OPERATOR', N'FINANCE.AR.ACCESS'),
        (N'FINANCE_AR_OPERATOR', N'FINANCE.AR.RECORDS.READ'),
        (N'FINANCE_AR_OPERATOR', N'FINANCE.AR.ATTACHMENTS.READ'),
        (N'FINANCE_AR_OPERATOR', N'FINANCE.AR.RECORDS.WRITE'),
        (N'FINANCE_AR_ADMIN', N'FINANCE.ACCESS'),
        (N'FINANCE_AR_ADMIN', N'FINANCE.AR.ACCESS'),
        (N'FINANCE_AR_ADMIN', N'FINANCE.AR.RECORDS.READ'),
        (N'FINANCE_AR_ADMIN', N'FINANCE.AR.ATTACHMENTS.READ'),
        (N'FINANCE_AR_ADMIN', N'FINANCE.AR.RECORDS.WRITE'),
        (N'FINANCE_AR_ADMIN', N'FINANCE.AR.ADMIN');

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
            CONCAT(N'Tri-AR roles hold ', @Granted, N' of ', @Expected, N' expected grants. Run seed-finance-permissions.sql and seed-finance-ar-permissions.sql first.');
        THROW 54151, @GrantedMessage, 1;
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
