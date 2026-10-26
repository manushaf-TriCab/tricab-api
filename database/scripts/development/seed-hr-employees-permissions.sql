/*
================================================================================
  DEVELOPMENT seed — HR Employees permissions and the HR role.

  - Idempotent / safe to re-run
  - Creates no users
  - Does not modify permissions outside the HR.EMPLOYEES.* family
  - Touches no [hr] table, so it does not depend on create-hr-employees-schema.sql.
    Run after create-identity-schema.sql and, for the role assignment to find
    anyone, after create-legacy-staff-user-mappings.sql.

  Permissions (ModuleCode `hr`):

    HR.EMPLOYEES.READ             The employee directory (non-sensitive fields).
    HR.EMPLOYEES.RESTRICTED.READ  Date of birth, home address, personal mobile
                                  and email, leave balances. Reads should be
                                  audited.
    HR.EMPLOYEES.SYNC             Sync run status / trigger.

  Role `HR` holds all three. Held by nobody else: ADMINISTRATOR is NOT given
  any of them, the same narrowing as Onboarding — the person who manages roles
  is not entitled to a colleague's date of birth. (Plan decision 4: HR only.)

  Who holds HR, and how the people are found
  ------------------------------------------
  Plan decision 4 / Q2: eloised, diemn, ricm. These are PAYROLL/legacy logins,
  and identity emails are mostly personal, so email is never used. The
  login -> user mapping is [migration].[LegacyStaffUserMappings]
  (LegacyUserLogin -> UserId), the same table the Staff -> Entra link uses.
  Matching is case-insensitive and must be unambiguous: exactly one mapping row
  and an active user. Anyone who does not resolve is skipped with a PRINT; no
  user is ever created, and the grant is simply re-run once they exist.
  If [migration].[LegacyStaffUserMappings] is absent the block prints that and
  assigns nobody.
  AssignedByUserId is NULL (bootstrap, no actor). Further holders are added in
  User Management, which records who granted it.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'hr';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'HR.EMPLOYEES.READ', N'Read employee directory',
         N'Read the employee directory: name, code, department, factory, manager, start date and work email.'),
        (N'HR.EMPLOYEES.RESTRICTED.READ', N'Read restricted employee details',
         N'Read sensitive personal data: date of birth, home address, personal mobile and email, leave balances.'),
        (N'HR.EMPLOYEES.SYNC', N'Run and monitor the employee sync',
         N'See employee sync runs and trigger a sync from payroll.');

    /* Insert what is missing; keep wording and module current on our own rows. */
    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    UPDATE e
    SET e.[PermissionName] = p.[PermissionName],
        e.[Description] = p.[Description],
        e.[ModuleCode] = @ModuleCode,
        e.[IsActive] = 1,
        e.[UpdatedAt] = SYSDATETIMEOFFSET()
    FROM [identity].[Permissions] e
    JOIN @Permissions p ON UPPER(p.[PermissionCode]) = UPPER(e.[PermissionCode])
    WHERE e.[IsActive] = 0
       OR ISNULL(e.[ModuleCode], N'') <> @ModuleCode
       OR e.[PermissionName] <> p.[PermissionName]
       OR ISNULL(e.[Description], N'') <> p.[Description];

    /* ------------------------------------------------------------------
       The role. RoleCode is UPPERCASE_UNDERSCORE; `HR` follows the
       single-word EMPLOYEE / GUEST / ADMINISTRATOR style because it names
       who holds it, and the plan calls it the HR role. IsSystemRole = 0 so
       it can be edited in Roles & Permissions.
       ------------------------------------------------------------------ */
    DECLARE @RoleCode NVARCHAR(100) = N'HR';
    DECLARE @RoleName NVARCHAR(200) = N'HR';
    DECLARE @RoleDescription NVARCHAR(500) =
        N'Human resources: read the employee directory and restricted personal details, and monitor the payroll sync.';

    IF NOT EXISTS (SELECT 1 FROM [identity].[Roles] WHERE UPPER([RoleCode]) = UPPER(@RoleCode))
    BEGIN
        INSERT INTO [identity].[Roles]
            ([RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive])
        VALUES
            (@RoleCode, @RoleName, @RoleDescription, 0, 1);
    END
    ELSE
    BEGIN
        UPDATE [identity].[Roles]
        SET [IsActive] = 1,
            [RoleName] = @RoleName,
            [Description] = @RoleDescription,
            [UpdatedAt] = SYSDATETIMEOFFSET()
        WHERE UPPER([RoleCode]) = UPPER(@RoleCode)
          AND ([IsActive] = 0 OR [RoleName] <> @RoleName OR ISNULL([Description], N'') <> @RoleDescription);
    END;

    INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
    SELECT r.[RoleId], p.[PermissionId], 1
    FROM [identity].[Roles] r
    JOIN [identity].[Permissions] p ON UPPER(p.[PermissionCode]) IN (SELECT UPPER([PermissionCode]) FROM @Permissions)
    WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode)
      AND NOT EXISTS (
          SELECT 1 FROM [identity].[RolePermissions] rp
          WHERE rp.[RoleId] = r.[RoleId] AND rp.[PermissionId] = p.[PermissionId]
      );

    UPDATE rp
    SET rp.[IsActive] = 1
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode)
      AND UPPER(p.[PermissionCode]) IN (SELECT UPPER([PermissionCode]) FROM @Permissions)
      AND rp.[IsActive] = 0;

    /* Withdraw any other HR.EMPLOYEES.* mapping on the role (none expected). */
    UPDATE rp
    SET rp.[IsActive] = 0
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode)
      AND UPPER(p.[PermissionCode]) LIKE N'HR.EMPLOYEES.%'
      AND UPPER(p.[PermissionCode]) NOT IN (SELECT UPPER([PermissionCode]) FROM @Permissions)
      AND rp.[IsActive] = 1;

    /* Guard: the role must hold all three, and ADMINISTRATOR none. */
    DECLARE @Granted INT = (
        SELECT COUNT(*)
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode)
          AND UPPER(p.[PermissionCode]) IN (SELECT UPPER([PermissionCode]) FROM @Permissions)
          AND rp.[IsActive] = 1
    );
    IF @Granted <> 3
    BEGIN
        THROW 52030, N'The HR role does not hold all three HR.EMPLOYEES permissions. Refusing to continue.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) <> UPPER(@RoleCode)
          AND UPPER(p.[PermissionCode]) LIKE N'HR.EMPLOYEES.%'
    )
    BEGIN
        THROW 52031, N'A role other than HR holds an HR.EMPLOYEES permission. HR is HR-only (plan decision 4); refusing to continue.', 1;
    END;

    /* ------------------------------------------------------------------
       Assign the role to the initial HR holders by LEGACY LOGIN.
       Never creates a user; skips and PRINTs anyone who does not resolve.
       ------------------------------------------------------------------ */
    DECLARE @RoleId BIGINT = (SELECT [RoleId] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = UPPER(@RoleCode));

    DECLARE @Logins TABLE ([Login] NVARCHAR(60) NOT NULL PRIMARY KEY);
    INSERT INTO @Logins ([Login]) VALUES (N'eloised'), (N'diemn'), (N'ricm');

    IF OBJECT_ID(N'[migration].[LegacyStaffUserMappings]', N'U') IS NULL
    BEGIN
        PRINT N'[migration].[LegacyStaffUserMappings] does not exist: no login -> user mapping, so the HR role was assigned to nobody.';
    END
    ELSE
    BEGIN
        DECLARE @Resolved TABLE ([Login] NVARCHAR(60) NOT NULL, [UserId] BIGINT NULL, [Matches] INT NOT NULL);

        INSERT INTO @Resolved ([Login], [UserId], [Matches])
        EXEC(N'
            SELECT l.[Login], MIN(ISNULL(m.[UserId], -1)), COUNT(DISTINCT m.[UserId])
            FROM (VALUES (N''eloised''), (N''diemn''), (N''ricm'')) AS l([Login])
            LEFT JOIN [migration].[LegacyStaffUserMappings] m
              ON LOWER(m.[LegacyUserLogin]) = LOWER(l.[Login])
             AND EXISTS (SELECT 1 FROM [identity].[Users] u WHERE u.[UserId] = m.[UserId])
            GROUP BY l.[Login]');

        DECLARE @Login NVARCHAR(60), @UserId BIGINT, @Matches INT;
        DECLARE login_cursor CURSOR LOCAL FAST_FORWARD FOR
            SELECT [Login], [UserId], [Matches] FROM @Resolved ORDER BY [Login];
        OPEN login_cursor;
        FETCH NEXT FROM login_cursor INTO @Login, @UserId, @Matches;
        WHILE @@FETCH_STATUS = 0
        BEGIN
            IF @Matches = 0
                PRINT CONCAT(N'SKIPPED ', @Login, N': no identity user is mapped to this login. No user was created; re-run once they exist.');
            ELSE IF @Matches > 1
                PRINT CONCAT(N'SKIPPED ', @Login, N': ', @Matches, N' identity users are mapped to this login. Ambiguous; resolve by hand.');
            ELSE IF EXISTS (SELECT 1 FROM [identity].[UserRoles] WHERE [UserId] = @UserId AND [RoleId] = @RoleId)
            BEGIN
                UPDATE [identity].[UserRoles]
                SET [IsActive] = 1, [ExpiresAt] = NULL
                WHERE [UserId] = @UserId AND [RoleId] = @RoleId AND ([IsActive] = 0 OR [ExpiresAt] IS NOT NULL);
                PRINT CONCAT(N'OK ', @Login, N': already holds HR (user ', @UserId, N').');
            END
            ELSE
            BEGIN
                INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [AssignedByUserId], [IsActive])
                VALUES (@UserId, @RoleId, NULL, 1);
                PRINT CONCAT(N'ASSIGNED HR to ', @Login, N' (user ', @UserId, N').');
            END;
            FETCH NEXT FROM login_cursor INTO @Login, @UserId, @Matches;
        END;
        CLOSE login_cursor;
        DEALLOCATE login_cursor;
    END;

    SELECT
        @RoleCode AS [Role],
        @Granted AS [PermissionsGranted],
        (SELECT COUNT(*) FROM [identity].[UserRoles] ur
          WHERE ur.[RoleId] = @RoleId AND ur.[IsActive] = 1) AS [UsersHoldingIt];

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
