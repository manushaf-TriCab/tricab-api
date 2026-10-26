/*
================================================================================
  DEVELOPMENT seed - Finance roles.

  - Idempotent / safe to re-run
  - Creates one role, FINANCE_ADMIN, and ASSIGNS IT TO NOBODY
  - Does not modify permissions, EMPLOYEE, GUEST or ADMINISTRATOR
  - WRITTEN, NOT EXECUTED against any shared database (plan 9, 6 Oct 2026).
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.
  - Run after seed-finance-permissions.sql

    FINANCE_ADMIN      all eight finance permissions: the launcher, every app's
                       access permission, and finance administration.

  App roles (FINANCE_<APP>_<ROLE>, decision D2) are not seeded here. They come
  with each app's phase; the map is docs/finance/permissions.md. Assigning
  people is done in the platform's user administration, not by this script.

  Must match TriCab\Core\Finance\Module::ROLE_PERMISSIONS;
  tests/Finance/FinanceSeedScriptTest.php fails if they drift. This script OWNS
  the FINANCE.* mappings of the roles it creates: anything else FINANCE.* on
  them is deactivated, never deleted.
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
        (N'FINANCE_ADMIN',     N'Finance Administrator',
         N'The finance launcher, every finance app''s access permission, and finance administration. Grants no app''s records: those come with each app''s own roles.');

    /* ------------------------------------------------------------------
       The roles. IsSystemRole = 0 so an administrator can edit them in
       Roles & Permissions without fighting the seed.
       ------------------------------------------------------------------ */
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

    /* ------------------------------------------------------------------
       Grants. Generated from Module::ROLE_PERMISSIONS.
       ------------------------------------------------------------------ */
    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'FINANCE_ADMIN', N'FINANCE.ACCESS'),
        (N'FINANCE_ADMIN', N'FINANCE.TASK.ACCESS'),
        (N'FINANCE_ADMIN', N'FINANCE.CREDIT.ACCESS'),
        (N'FINANCE_ADMIN', N'FINANCE.COLLECT.ACCESS'),
        (N'FINANCE_ADMIN', N'FINANCE.AR.ACCESS'),
        (N'FINANCE_ADMIN', N'FINANCE.AP.ACCESS'),
        (N'FINANCE_ADMIN', N'FINANCE.AP2.ACCESS'),
        (N'FINANCE_ADMIN', N'FINANCE.ADMIN');

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
       Guard: every grant landed. A role missing a permission is found by a
       person hitting a 403 on the launcher.
       ------------------------------------------------------------------ */
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
            CONCAT(N'Finance roles hold ', @Granted, N' of ', @Expected, N' expected grants. Run seed-finance-permissions.sql first.');
        THROW 54111, @GrantedMessage, 1;
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
