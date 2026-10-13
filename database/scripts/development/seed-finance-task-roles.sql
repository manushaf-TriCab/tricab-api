/*
================================================================================
  DEVELOPMENT seed - Tri-Task roles (finance Phase 2, task T2.1).

  - Idempotent / safe to re-run
  - Creates three roles, FINANCE_TASK_USER, FINANCE_TASK_MANAGER and
    FINANCE_TASK_ADMIN, and ASSIGNS THEM TO NOBODY
  - Does not modify permissions, EMPLOYEE, GUEST, ADMINISTRATOR or FINANCE_ADMIN
  - WRITTEN, NOT EXECUTED against any shared database (7 Oct 2026).
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.
  - Run after seed-finance-permissions.sql and seed-finance-task-permissions.sql

    FINANCE_TASK_USER      portal label Team Member: FINANCE.ACCESS, FINANCE.TASK.ACCESS
                           and the five everyday task permissions (not
                           SETUP.MANAGE: Manusha, 12 Oct 2026).
    FINANCE_TASK_MANAGER   the above plus SETUP.MANAGE, READ_ALL, UPDATE, DELETE and
                           ANALYTICS.READ (13 Oct 2026).
    FINANCE_TASK_ADMIN     the above plus SETTINGS.MANAGE.

  Each role also holds FINANCE.ACCESS and FINANCE.TASK.ACCESS so that a person
  given one role can open the launcher and the app (permissions.md section 2).
  Assigning people is done in the platform's user administration, not here.

  Must match TriCab\Core\Finance\Module::TASK_ROLE_PERMISSIONS;
  tests/Finance/Task/FinanceTaskSeedScriptTest.php fails if they drift. This
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
        (N'FINANCE_TASK_USER', N'Finance Tri-Task Team Member',
         N'Tri-Task: one''s own tasks, the setup lists, and task mail.'),
        (N'FINANCE_TASK_MANAGER', N'Finance Tri-Task Manager',
         N'Everything a Team Member can do, plus every task: read, change and delete any, and analytics.'),
        (N'FINANCE_TASK_ADMIN', N'Finance Tri-Task Administrator',
         N'Everything a Manager can do, plus the daily summary settings.');

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

    /* Grants. Generated from Module::TASK_ROLE_PERMISSIONS. */
    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'FINANCE_TASK_USER', N'FINANCE.ACCESS'),
        (N'FINANCE_TASK_USER', N'FINANCE.TASK.ACCESS'),
        (N'FINANCE_TASK_USER', N'FINANCE.TASK.TASKS.READ'),
        (N'FINANCE_TASK_USER', N'FINANCE.TASK.TASKS.CREATE'),
        (N'FINANCE_TASK_USER', N'FINANCE.TASK.TASKS.UPDATE_OWN'),
        (N'FINANCE_TASK_USER', N'FINANCE.TASK.TASKS.DELETE_OWN'),
        (N'FINANCE_TASK_USER', N'FINANCE.TASK.MAIL.SEND'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.ACCESS'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.ACCESS'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.TASKS.READ'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.TASKS.CREATE'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.TASKS.UPDATE_OWN'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.TASKS.DELETE_OWN'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.SETUP.MANAGE'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.MAIL.SEND'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.TASKS.READ_ALL'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.TASKS.UPDATE'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.TASKS.DELETE'),
        (N'FINANCE_TASK_MANAGER', N'FINANCE.TASK.ANALYTICS.READ'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.ACCESS'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.ACCESS'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.TASKS.READ'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.TASKS.CREATE'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.TASKS.UPDATE_OWN'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.TASKS.DELETE_OWN'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.SETUP.MANAGE'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.MAIL.SEND'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.TASKS.READ_ALL'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.TASKS.UPDATE'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.TASKS.DELETE'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.ANALYTICS.READ'),
        (N'FINANCE_TASK_ADMIN', N'FINANCE.TASK.SETTINGS.MANAGE');

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
            CONCAT(N'Tri-Task roles hold ', @Granted, N' of ', @Expected, N' expected grants. Run seed-finance-permissions.sql and seed-finance-task-permissions.sql first.');
        THROW 54111, @GrantedMessage, 1;
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
