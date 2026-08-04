/*
================================================================================
  Seed: User / Role / Permission management catalog + ADMINISTRATOR role.

  - Idempotent / safe to re-run
  - Does not hard-code RoleId or PermissionId values
  - Reuses existing permission / role rows when present (case-insensitive codes)
  - Does not update existing PermissionName / Description / RoleName on reuse
  - Does not silently reactivate inactive roles, permissions, or mappings
  - Does not modify EMPLOYEE / employee or GUEST role permission sets
  - Does not grant the new permissions to EMPLOYEE or GUEST
  - Does not assign ADMINISTRATOR to any user
  - Grants the management permission set only to ADMINISTRATOR
  - Preserves ACCOUNT.LOGIN on existing roles; does not alter GUEST → ACCOUNT.LOGIN

  Column note: [identity].[Roles] uses [RoleName] (not DisplayName).
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @AdminRoleCode NVARCHAR(100) = N'ADMINISTRATOR';
    DECLARE @AdminRoleName NVARCHAR(150) = N'Administrator';
    DECLARE @AdminRoleDescription NVARCHAR(500) =
        N'Full administrative access to user, role and permission management.';

    DECLARE @AdminRoleId BIGINT = NULL;
    DECLARE @AdminRoleIsActive BIT = NULL;
    DECLARE @AdminRoleAction NVARCHAR(40) = N'already_existed';

    DECLARE @PermissionsInserted INT = 0;
    DECLARE @PermissionsReused INT = 0;
    DECLARE @MappingsInserted INT = 0;
    DECLARE @MappingsAlreadyExisted INT = 0;

    /* ------------------------------------------------------------------
       Permission catalog (codes match Core PermissionCode normalisation)
       ------------------------------------------------------------------ */
    DECLARE @PermissionCatalog TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL,
        [ModuleCode] NVARCHAR(100) NOT NULL
    );

    INSERT INTO @PermissionCatalog (
        [PermissionCode],
        [PermissionName],
        [Description],
        [ModuleCode]
    )
    VALUES
        (N'USER.READ', N'Read users', N'View users and user profiles in administration.', N'user'),
        (N'USER.CREATE', N'Create users', N'Create new TriCab user accounts.', N'user'),
        (N'USER.UPDATE', N'Update users', N'Update user profile details.', N'user'),
        (N'USER.STATUS.UPDATE', N'Update user status', N'Activate, suspend, or deactivate user accounts.', N'user'),
        (N'USER.ROLE.ASSIGN', N'Assign user roles', N'Assign roles to users.', N'user'),
        (N'USER.ROLE.REMOVE', N'Remove user roles', N'Remove roles from users.', N'user'),
        (N'ROLE.READ', N'Read roles', N'View roles and role details.', N'role'),
        (N'ROLE.CREATE', N'Create roles', N'Create new roles.', N'role'),
        (N'ROLE.UPDATE', N'Update roles', N'Update role names and descriptions.', N'role'),
        (N'ROLE.STATUS.UPDATE', N'Update role status', N'Activate or deactivate roles.', N'role'),
        (N'ROLE.PERMISSION.ASSIGN', N'Assign role permissions', N'Assign permissions to roles.', N'role'),
        (N'ROLE.PERMISSION.REMOVE', N'Remove role permissions', N'Remove permissions from roles.', N'role'),
        (N'PERMISSION.READ', N'Read permissions', N'View the permission catalog.', N'permission'),
        (N'PERMISSION.CREATE', N'Create permissions', N'Create new permission definitions.', N'permission'),
        (N'PERMISSION.UPDATE', N'Update permissions', N'Update permission metadata where safe.', N'permission'),
        (N'AUDIT.USER_MANAGEMENT.READ', N'Read user-management audit', N'View user, role and permission management audit history.', N'audit');

    DECLARE @PermissionCode NVARCHAR(150);
    DECLARE @PermissionName NVARCHAR(200);
    DECLARE @PermissionDescription NVARCHAR(500);
    DECLARE @ModuleCode NVARCHAR(100);

    DECLARE @ResolvedPermissionId BIGINT;
    DECLARE @ResolvedPermissionIsActive BIT;
    DECLARE @StoredPermissionCode NVARCHAR(150);
    DECLARE @MissingPermissionMessage NVARCHAR(300);
    DECLARE @InactivePermissionMessage NVARCHAR(400);

    DECLARE permission_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT
            c.[PermissionCode],
            c.[PermissionName],
            c.[Description],
            c.[ModuleCode]
        FROM @PermissionCatalog c
        ORDER BY c.[PermissionCode];

    OPEN permission_cursor;
    FETCH NEXT FROM permission_cursor INTO
        @PermissionCode,
        @PermissionName,
        @PermissionDescription,
        @ModuleCode;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @ResolvedPermissionId = NULL;
        SET @ResolvedPermissionIsActive = NULL;
        SET @StoredPermissionCode = NULL;
        SET @MissingPermissionMessage = NULL;
        SET @InactivePermissionMessage = NULL;

        SELECT TOP (1)
            @ResolvedPermissionId = p.[PermissionId],
            @ResolvedPermissionIsActive = p.[IsActive],
            @StoredPermissionCode = p.[PermissionCode]
        FROM [identity].[Permissions] p
        WHERE UPPER(p.[PermissionCode]) = UPPER(@PermissionCode)
        ORDER BY p.[PermissionId];

        IF @ResolvedPermissionId IS NULL
        BEGIN
            INSERT INTO [identity].[Permissions] (
                [PermissionCode],
                [PermissionName],
                [Description],
                [ModuleCode],
                [IsActive]
            )
            VALUES (
                @PermissionCode,
                @PermissionName,
                @PermissionDescription,
                @ModuleCode,
                1
            );

            SET @ResolvedPermissionId = SCOPE_IDENTITY();
            SET @ResolvedPermissionIsActive = 1;
            SET @StoredPermissionCode = @PermissionCode;
            SET @PermissionsInserted = @PermissionsInserted + 1;
        END
        ELSE
        BEGIN
            SET @PermissionsReused = @PermissionsReused + 1;
        END;

        IF @ResolvedPermissionId IS NULL
        BEGIN
            SET @MissingPermissionMessage =
                N'Permission could not be resolved: ' + @PermissionCode;
            THROW 52001, @MissingPermissionMessage, 1;
        END;

        IF @ResolvedPermissionIsActive = 0
        BEGIN
            SET @InactivePermissionMessage =
                N'Permission exists but IsActive = 0. Refusing to continue: '
                + ISNULL(@StoredPermissionCode, @PermissionCode);
            THROW 52002, @InactivePermissionMessage, 1;
        END;

        FETCH NEXT FROM permission_cursor INTO
            @PermissionCode,
            @PermissionName,
            @PermissionDescription,
            @ModuleCode;
    END;

    CLOSE permission_cursor;
    DEALLOCATE permission_cursor;

    /* ------------------------------------------------------------------
       Role: ADMINISTRATOR (system role)
       ------------------------------------------------------------------ */
    SELECT TOP (1)
        @AdminRoleId = r.[RoleId],
        @AdminRoleIsActive = r.[IsActive]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = UPPER(@AdminRoleCode)
    ORDER BY r.[RoleId];

    IF @AdminRoleId IS NULL
    BEGIN
        INSERT INTO [identity].[Roles] (
            [RoleCode],
            [RoleName],
            [Description],
            [IsSystemRole],
            [IsActive]
        )
        VALUES (
            @AdminRoleCode,
            @AdminRoleName,
            @AdminRoleDescription,
            1,
            1
        );

        SET @AdminRoleId = SCOPE_IDENTITY();
        SET @AdminRoleIsActive = 1;
        SET @AdminRoleAction = N'inserted';
    END
    ELSE IF @AdminRoleIsActive = 0
    BEGIN
        SET @AdminRoleAction = N'exists_inactive';
        PRINT N'ADMINISTRATOR role exists but IsActive = 0. Not reactivated.';
    END
    ELSE
    BEGIN
        SET @AdminRoleAction = N'already_existed';
    END;

    IF @AdminRoleId IS NULL
    BEGIN
        THROW 52003, N'ADMINISTRATOR role could not be resolved.', 1;
    END;

    IF @AdminRoleIsActive = 0
    BEGIN
        THROW 52004, N'ADMINISTRATOR role exists but is inactive. Reactivate manually if intended.', 1;
    END;

    /* ------------------------------------------------------------------
       Map catalog permissions to ADMINISTRATOR only (insert if absent)
       Do not reactivate inactive existing mappings.
       ------------------------------------------------------------------ */
    DECLARE @CatalogPermissionId BIGINT;
    DECLARE @CatalogPermissionCode NVARCHAR(150);
    DECLARE @ExistingMappingIsActive BIT;
    DECLARE @InactiveMappingMessage NVARCHAR(400);

    DECLARE mapping_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT
            p.[PermissionId],
            p.[PermissionCode]
        FROM [identity].[Permissions] p
        INNER JOIN @PermissionCatalog c
            ON UPPER(p.[PermissionCode]) = UPPER(c.[PermissionCode])
        ORDER BY p.[PermissionCode];

    OPEN mapping_cursor;
    FETCH NEXT FROM mapping_cursor INTO @CatalogPermissionId, @CatalogPermissionCode;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @ExistingMappingIsActive = NULL;
        SET @InactiveMappingMessage = NULL;

        SELECT TOP (1)
            @ExistingMappingIsActive = rp.[IsActive]
        FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = @AdminRoleId
          AND rp.[PermissionId] = @CatalogPermissionId
        ORDER BY rp.[RolePermissionId];

        IF @ExistingMappingIsActive IS NULL
        BEGIN
            INSERT INTO [identity].[RolePermissions] (
                [RoleId],
                [PermissionId],
                [IsActive]
            )
            VALUES (
                @AdminRoleId,
                @CatalogPermissionId,
                1
            );

            SET @MappingsInserted = @MappingsInserted + 1;
        END
        ELSE IF @ExistingMappingIsActive = 0
        BEGIN
            SET @InactiveMappingMessage =
                N'RolePermission mapping exists but IsActive = 0. Refusing to reactivate: ADMINISTRATOR → '
                + @CatalogPermissionCode;
            THROW 52006, @InactiveMappingMessage, 1;
        END
        ELSE
        BEGIN
            SET @MappingsAlreadyExisted = @MappingsAlreadyExisted + 1;
        END;

        FETCH NEXT FROM mapping_cursor INTO @CatalogPermissionId, @CatalogPermissionCode;
    END;

    CLOSE mapping_cursor;
    DEALLOCATE mapping_cursor;

    /* ------------------------------------------------------------------
       Explicit validation: every catalog permission must be actively mapped
       ------------------------------------------------------------------ */
    IF EXISTS (
        SELECT 1
        FROM @PermissionCatalog c
        WHERE NOT EXISTS (
            SELECT 1
            FROM [identity].[Permissions] p
            INNER JOIN [identity].[RolePermissions] rp
                ON rp.[PermissionId] = p.[PermissionId]
               AND rp.[RoleId] = @AdminRoleId
               AND rp.[IsActive] = 1
            WHERE UPPER(p.[PermissionCode]) = UPPER(c.[PermissionCode])
              AND p.[IsActive] = 1
        )
    )
    BEGIN
        THROW 52005, N'ADMINISTRATOR is missing one or more active user-management permission mappings.', 1;
    END;

    /* This seed never inserts or updates [identity].[UserRoles]. */

    SELECT
        @AdminRoleAction AS [AdministratorRoleAction],
        @AdminRoleId AS [AdministratorRoleId],
        @AdminRoleIsActive AS [AdministratorRoleIsActive],
        @PermissionsInserted AS [PermissionsInserted],
        @PermissionsReused AS [PermissionsReused],
        @MappingsInserted AS [RolePermissionMappingsInserted],
        @MappingsAlreadyExisted AS [RolePermissionMappingsAlreadyExisted],
        (
            SELECT COUNT(*)
            FROM [identity].[RolePermissions] rp
            WHERE rp.[RoleId] = @AdminRoleId
              AND rp.[IsActive] = 1
        ) AS [AdministratorActivePermissionCount];

    SELECT
        p.[PermissionCode],
        p.[PermissionName],
        p.[ModuleCode],
        p.[IsActive] AS [PermissionIsActive],
        rp.[IsActive] AS [MappingIsActive],
        CASE
            WHEN rp.[RolePermissionId] IS NULL THEN N'missing'
            WHEN rp.[IsActive] = 1 THEN N'active'
            ELSE N'inactive'
        END AS [MappingStatus]
    FROM @PermissionCatalog c
    INNER JOIN [identity].[Permissions] p
        ON UPPER(p.[PermissionCode]) = UPPER(c.[PermissionCode])
    LEFT JOIN [identity].[RolePermissions] rp
        ON rp.[PermissionId] = p.[PermissionId]
       AND rp.[RoleId] = @AdminRoleId
    ORDER BY p.[PermissionCode];

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF CURSOR_STATUS('local', 'permission_cursor') >= -1
    BEGIN
        CLOSE permission_cursor;
        DEALLOCATE permission_cursor;
    END;

    IF CURSOR_STATUS('local', 'mapping_cursor') >= -1
    BEGIN
        CLOSE mapping_cursor;
        DEALLOCATE mapping_cursor;
    END;

    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();

    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
