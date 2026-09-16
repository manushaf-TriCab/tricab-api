/*
================================================================================
  Seed: TriExpense permissions + capability roles.

  Adds (idempotent):
    TRIEXPENSE.ACCESS
    TRIEXPENSE.APPROVE
    TRIEXPENSE.FINANCE
    TRIEXPENSE.ADMIN
    TRIEXPENSE.AUDIT

  Creates roles (RoleCode uppercase underscore, not dots):
    TRIEXPENSE_USER           → ACCESS
    TRIEXPENSE_APPROVER       → ACCESS, APPROVE
    TRIEXPENSE_FINANCE        → ACCESS, APPROVE, FINANCE, AUDIT
    TRIEXPENSE_ADMINISTRATOR  → ACCESS, ADMIN

  ADMINISTRATOR mapping:
    - Maps TRIEXPENSE.ADMIN only (so platform admins can manage TriExpense
      access via User Management).
    - Does NOT map FINANCE / APPROVE / AUDIT onto ADMINISTRATOR.
    - ACCESS may already be mapped by seed-admin-business-module-permissions.sql;
      this script leaves ACCESS↔ADMINISTRATOR alone.

  - Safe to re-run
  - Does not assign roles to users
  - Does not modify EMPLOYEE / GUEST / CUSTOMER_* permission sets

  DEVELOPMENT seed — review before production.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @PermissionsInserted INT = 0;
    DECLARE @PermissionsReused INT = 0;
    DECLARE @RoleMappingsInserted INT = 0;
    DECLARE @RoleMappingsExisted INT = 0;
    DECLARE @AdministratorAdminMapped INT = 0;
    DECLARE @AdministratorAdminExisted INT = 0;

    DECLARE @PermissionCatalog TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL,
        [ModuleCode] NVARCHAR(100) NOT NULL
    );

    INSERT INTO @PermissionCatalog ([PermissionCode], [PermissionName], [Description], [ModuleCode])
    VALUES
        (N'TRIEXPENSE.ACCESS', N'Access Tri Expense', N'Open Tri Expense and manage own receipts.', N'TRIEXPENSE'),
        (N'TRIEXPENSE.APPROVE', N'Access TriExpense Approvals', N'Approve assigned TriExpense receipts.', N'TRIEXPENSE'),
        (N'TRIEXPENSE.FINANCE', N'TriExpense Finance', N'TriExpense finance operations and approval override.', N'TRIEXPENSE'),
        (N'TRIEXPENSE.ADMIN', N'Administer TriExpense Access', N'Administer TriExpense access via User Management (not Finance).', N'TRIEXPENSE'),
        (N'TRIEXPENSE.AUDIT', N'View TriExpense Audit', N'View TriExpense audit history.', N'TRIEXPENSE');

    DECLARE @PermissionCode NVARCHAR(150);
    DECLARE @PermissionName NVARCHAR(200);
    DECLARE @PermissionDescription NVARCHAR(500);
    DECLARE @ModuleCode NVARCHAR(100);
    DECLARE @ResolvedPermissionId BIGINT;
    DECLARE @ResolvedPermissionIsActive BIT;

    DECLARE permission_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT [PermissionCode], [PermissionName], [Description], [ModuleCode]
        FROM @PermissionCatalog
        ORDER BY [PermissionCode];

    OPEN permission_cursor;
    FETCH NEXT FROM permission_cursor INTO @PermissionCode, @PermissionName, @PermissionDescription, @ModuleCode;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @ResolvedPermissionId = NULL;
        SET @ResolvedPermissionIsActive = NULL;

        SELECT TOP (1)
            @ResolvedPermissionId = p.[PermissionId],
            @ResolvedPermissionIsActive = p.[IsActive]
        FROM [identity].[Permissions] p
        WHERE UPPER(p.[PermissionCode]) = UPPER(@PermissionCode)
        ORDER BY p.[PermissionId];

        IF @ResolvedPermissionId IS NULL
        BEGIN
            INSERT INTO [identity].[Permissions] (
                [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
            )
            VALUES (
                @PermissionCode, @PermissionName, @PermissionDescription, @ModuleCode, 1
            );
            SET @PermissionsInserted = @PermissionsInserted + 1;
        END
        ELSE
        BEGIN
            IF @ResolvedPermissionIsActive = 0
            BEGIN
                UPDATE [identity].[Permissions]
                SET [IsActive] = 1,
                    [PermissionName] = @PermissionName,
                    [Description] = @PermissionDescription,
                    [ModuleCode] = @ModuleCode,
                    [UpdatedAt] = SYSDATETIMEOFFSET()
                WHERE [PermissionId] = @ResolvedPermissionId;
            END;
            SET @PermissionsReused = @PermissionsReused + 1;
        END;

        FETCH NEXT FROM permission_cursor INTO @PermissionCode, @PermissionName, @PermissionDescription, @ModuleCode;
    END;

    CLOSE permission_cursor;
    DEALLOCATE permission_cursor;

    /* ------------------------------------------------------------------
       Roles
       ------------------------------------------------------------------ */
    DECLARE @RoleCatalog TABLE (
        [RoleCode] NVARCHAR(64) NOT NULL PRIMARY KEY,
        [RoleName] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL
    );

    INSERT INTO @RoleCatalog ([RoleCode], [RoleName], [Description])
    VALUES
        (N'TRIEXPENSE_USER', N'TriExpense User', N'Submit and manage own TriExpense receipts.'),
        (N'TRIEXPENSE_APPROVER', N'TriExpense Approver', N'Approve assigned TriExpense receipts.'),
        (N'TRIEXPENSE_FINANCE', N'TriExpense Finance', N'TriExpense finance operations, approval override, and audit.'),
        (N'TRIEXPENSE_ADMINISTRATOR', N'TriExpense Administrator', N'Administer TriExpense access via User Management (not Finance).');

    DECLARE @RolePermissionMap TABLE (
        [RoleCode] NVARCHAR(64) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @RolePermissionMap ([RoleCode], [PermissionCode])
    VALUES
        (N'TRIEXPENSE_USER', N'TRIEXPENSE.ACCESS'),
        (N'TRIEXPENSE_APPROVER', N'TRIEXPENSE.ACCESS'),
        (N'TRIEXPENSE_APPROVER', N'TRIEXPENSE.APPROVE'),
        (N'TRIEXPENSE_FINANCE', N'TRIEXPENSE.ACCESS'),
        (N'TRIEXPENSE_FINANCE', N'TRIEXPENSE.APPROVE'),
        (N'TRIEXPENSE_FINANCE', N'TRIEXPENSE.FINANCE'),
        (N'TRIEXPENSE_FINANCE', N'TRIEXPENSE.AUDIT'),
        (N'TRIEXPENSE_ADMINISTRATOR', N'TRIEXPENSE.ACCESS'),
        (N'TRIEXPENSE_ADMINISTRATOR', N'TRIEXPENSE.ADMIN');

    DECLARE @RoleCode NVARCHAR(64);
    DECLARE @RoleName NVARCHAR(200);
    DECLARE @RoleDescription NVARCHAR(500);
    DECLARE @ResolvedRoleId BIGINT;
    DECLARE @ResolvedRoleIsActive BIT;
    DECLARE @RolesInserted INT = 0;
    DECLARE @RolesReused INT = 0;

    DECLARE role_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT [RoleCode], [RoleName], [Description]
        FROM @RoleCatalog
        ORDER BY [RoleCode];

    OPEN role_cursor;
    FETCH NEXT FROM role_cursor INTO @RoleCode, @RoleName, @RoleDescription;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @ResolvedRoleId = NULL;
        SET @ResolvedRoleIsActive = NULL;

        SELECT TOP (1)
            @ResolvedRoleId = r.[RoleId],
            @ResolvedRoleIsActive = r.[IsActive]
        FROM [identity].[Roles] r
        WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode)
        ORDER BY r.[RoleId];

        IF @ResolvedRoleId IS NULL
        BEGIN
            INSERT INTO [identity].[Roles] (
                [RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive]
            )
            VALUES (
                @RoleCode, @RoleName, @RoleDescription, 0, 1
            );
            SET @RolesInserted = @RolesInserted + 1;
        END
        ELSE
        BEGIN
            IF @ResolvedRoleIsActive = 0
            BEGIN
                UPDATE [identity].[Roles]
                SET [IsActive] = 1,
                    [RoleName] = @RoleName,
                    [Description] = @RoleDescription,
                    [UpdatedAt] = SYSDATETIMEOFFSET()
                WHERE [RoleId] = @ResolvedRoleId;
            END;
            SET @RolesReused = @RolesReused + 1;
        END;

        FETCH NEXT FROM role_cursor INTO @RoleCode, @RoleName, @RoleDescription;
    END;

    CLOSE role_cursor;
    DEALLOCATE role_cursor;

    /* ------------------------------------------------------------------
       Role → permission mappings
       ------------------------------------------------------------------ */
    DECLARE @MapRoleId BIGINT;
    DECLARE @MapPermissionId BIGINT;
    DECLARE @MapRoleCode NVARCHAR(64);
    DECLARE @MapPermissionCode NVARCHAR(150);
    DECLARE @ExistingMappingId BIGINT;
    DECLARE @ExistingMappingIsActive BIT;

    DECLARE map_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT r.[RoleId], p.[PermissionId], m.[RoleCode], m.[PermissionCode]
        FROM @RolePermissionMap m
        INNER JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = UPPER(m.[RoleCode])
        INNER JOIN [identity].[Permissions] p ON UPPER(p.[PermissionCode]) = UPPER(m.[PermissionCode])
        WHERE r.[IsActive] = 1
          AND p.[IsActive] = 1
        ORDER BY m.[RoleCode], m.[PermissionCode];

    OPEN map_cursor;
    FETCH NEXT FROM map_cursor INTO @MapRoleId, @MapPermissionId, @MapRoleCode, @MapPermissionCode;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @ExistingMappingId = NULL;
        SET @ExistingMappingIsActive = NULL;

        SELECT TOP (1)
            @ExistingMappingId = rp.[RolePermissionId],
            @ExistingMappingIsActive = rp.[IsActive]
        FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = @MapRoleId
          AND rp.[PermissionId] = @MapPermissionId
        ORDER BY rp.[RolePermissionId];

        IF @ExistingMappingId IS NULL
        BEGIN
            INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
            VALUES (@MapRoleId, @MapPermissionId, 1);
            SET @RoleMappingsInserted = @RoleMappingsInserted + 1;
        END
        ELSE IF @ExistingMappingIsActive = 0
        BEGIN
            UPDATE [identity].[RolePermissions]
            SET [IsActive] = 1
            WHERE [RolePermissionId] = @ExistingMappingId;
            SET @RoleMappingsInserted = @RoleMappingsInserted + 1;
        END
        ELSE
        BEGIN
            SET @RoleMappingsExisted = @RoleMappingsExisted + 1;
        END;

        FETCH NEXT FROM map_cursor INTO @MapRoleId, @MapPermissionId, @MapRoleCode, @MapPermissionCode;
    END;

    CLOSE map_cursor;
    DEALLOCATE map_cursor;

    /* ------------------------------------------------------------------
       ADMINISTRATOR ← TRIEXPENSE.ADMIN only (not FINANCE/APPROVE/AUDIT)
       ------------------------------------------------------------------ */
    DECLARE @AdministratorRoleId BIGINT = NULL;
    DECLARE @AdminPermissionId BIGINT = NULL;

    SELECT TOP (1) @AdministratorRoleId = r.[RoleId]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
      AND r.[IsActive] = 1
    ORDER BY r.[RoleId];

    SELECT TOP (1) @AdminPermissionId = p.[PermissionId]
    FROM [identity].[Permissions] p
    WHERE UPPER(p.[PermissionCode]) = N'TRIEXPENSE.ADMIN'
      AND p.[IsActive] = 1
    ORDER BY p.[PermissionId];

    IF @AdministratorRoleId IS NOT NULL AND @AdminPermissionId IS NOT NULL
    BEGIN
        SET @ExistingMappingId = NULL;
        SET @ExistingMappingIsActive = NULL;

        SELECT TOP (1)
            @ExistingMappingId = rp.[RolePermissionId],
            @ExistingMappingIsActive = rp.[IsActive]
        FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = @AdministratorRoleId
          AND rp.[PermissionId] = @AdminPermissionId
        ORDER BY rp.[RolePermissionId];

        IF @ExistingMappingId IS NULL
        BEGIN
            INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
            VALUES (@AdministratorRoleId, @AdminPermissionId, 1);
            SET @AdministratorAdminMapped = 1;
        END
        ELSE IF @ExistingMappingIsActive = 0
        BEGIN
            UPDATE [identity].[RolePermissions]
            SET [IsActive] = 1
            WHERE [RolePermissionId] = @ExistingMappingId;
            SET @AdministratorAdminMapped = 1;
        END
        ELSE
        BEGIN
            SET @AdministratorAdminExisted = 1;
        END;
    END;

    COMMIT TRANSACTION;

    SELECT
        @PermissionsInserted AS PermissionsInserted,
        @PermissionsReused AS PermissionsReused,
        @RolesInserted AS RolesInserted,
        @RolesReused AS RolesReused,
        @RoleMappingsInserted AS RoleMappingsInserted,
        @RoleMappingsExisted AS RoleMappingsExisted,
        @AdministratorAdminMapped AS AdministratorAdminMapped,
        @AdministratorAdminExisted AS AdministratorAdminExisted,
        CASE
            WHEN @AdministratorRoleId IS NULL THEN N'skipped_role_missing'
            WHEN @AdminPermissionId IS NULL THEN N'skipped_permission_missing'
            ELSE N'mapped_admin_only'
        END AS AdministratorMappingStatus;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
