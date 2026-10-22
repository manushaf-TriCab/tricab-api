/*
================================================================================
  DEVELOPMENT seed: NPR and TSD roles (plan NPR-TSD-MIGRATION-PLAN N1.2).

  - Idempotent / safe to re-run
  - Creates six roles and ASSIGNS THEM TO NOBODY (rollout is N5)
  - Does not modify EMPLOYEE, GUEST or ADMINISTRATOR
  - WRITTEN, NOT EXECUTED against any shared database
  - Run after seed-tsd-permissions.sql and seed-npr-permissions.sql. Fails
    clearly if TSD.ACCESS or any NPR.* permission is missing.

    NPR_REQUESTER   Sales: NPR.SELF.READ / CREATE / SUBMIT.
    NPR_MANAGER     Branch manager: NPR.SELF.READ, NPR.TEAM.ENDORSE.
    TSD_ENGINEER    NPR.TSD.READ, NPR.TSD.WORK, TSD.ACCESS.
    TSD_SIGNATORY   NPR.TSD.READ, NPR.SCREENING.DECIDE, TSD.ACCESS.
    NPR_VIEWER      NPR.TSD.READ, TSD.ACCESS (read-only).
    NPR_ADMIN       NPR.ADMIN, NPR.TSD.READ, TSD.ACCESS (manager routes, notifications).

  Must match TriCab\Core\Npr\Module::ROLE_PERMISSIONS; tests/Npr/NprSeedScriptTest.php
  fails if they drift. This script OWNS the NPR.* and TSD.ACCESS mappings of
  these six roles: anything else of those families on them is deactivated,
  never deleted.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) = N'TSD.ACCESS' AND [IsActive] = 1)
    BEGIN
        THROW 54021, N'TSD.ACCESS is missing. Run seed-tsd-permissions.sql first.', 1;
    END;

    DECLARE @Roles TABLE (
        [RoleCode]    NVARCHAR(100) NOT NULL PRIMARY KEY,
        [RoleName]    NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL
    );

    INSERT INTO @Roles ([RoleCode], [RoleName], [Description])
    VALUES
        (N'NPR_REQUESTER',  N'NPD Requester',
         N'Raise, edit and submit NPD Requests (New Product Design Requests), and read one''s own.'),
        (N'NPR_MANAGER',    N'NPD Request Manager',
         N'Read one''s own requests and endorse, return or reject those routed to the caller as branch manager.'),
        (N'TSD_ENGINEER',   N'TSD Engineer',
         N'Open the TSD module, read every NPD Request that reached TSD, and acknowledge, assign, return and request screening.'),
        (N'TSD_SIGNATORY',  N'TSD Signatory',
         N'Open the TSD module, read NPD Requests that reached TSD and record the final screening outcome.'),
        (N'NPR_VIEWER',     N'NPD Request Viewer',
         N'Open the TSD module and read NPD Requests that reached TSD. Read-only.'),
        (N'NPR_ADMIN',      N'NPD Request Admin',
         N'Administer NPD Request manager routes and notifications, and read the TSD queue.');

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

    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'NPR_REQUESTER', N'NPR.SELF.READ'),
        (N'NPR_REQUESTER', N'NPR.SELF.CREATE'),
        (N'NPR_REQUESTER', N'NPR.SELF.SUBMIT'),
        (N'NPR_MANAGER', N'NPR.SELF.READ'),
        (N'NPR_MANAGER', N'NPR.TEAM.ENDORSE'),
        (N'TSD_ENGINEER', N'NPR.TSD.READ'),
        (N'TSD_ENGINEER', N'NPR.TSD.WORK'),
        (N'TSD_ENGINEER', N'TSD.ACCESS'),
        (N'TSD_SIGNATORY', N'NPR.TSD.READ'),
        (N'TSD_SIGNATORY', N'NPR.SCREENING.DECIDE'),
        (N'TSD_SIGNATORY', N'TSD.ACCESS'),
        (N'NPR_VIEWER', N'NPR.TSD.READ'),
        (N'NPR_VIEWER', N'TSD.ACCESS'),
        (N'NPR_ADMIN', N'NPR.ADMIN'),
        (N'NPR_ADMIN', N'NPR.TSD.READ'),
        (N'NPR_ADMIN', N'TSD.ACCESS');

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

    /* Withdraw any other NPR.* or TSD.ACCESS mapping on these six roles. */
    UPDATE rp
    SET rp.[IsActive] = 0
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles)
      AND (UPPER(p.[PermissionCode]) LIKE N'NPR.%' OR UPPER(p.[PermissionCode]) = N'TSD.ACCESS')
      AND NOT EXISTS (
          SELECT 1 FROM @Grants g
          WHERE UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
            AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
      );

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
            CONCAT(N'NPR roles hold ', @Granted, N' of ', @Expected, N' expected grants. Run seed-tsd-permissions.sql and seed-npr-permissions.sql first.');
        THROW 54022, @GrantedMessage, 1;
    END;

    SELECT r.[RoleCode], COUNT(*) AS [ActivePermissions]
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles)
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
