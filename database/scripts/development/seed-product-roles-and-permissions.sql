/*
================================================================================
  Seed: Product Module roles + permissions (Phase 1 foundation / Phase 7 prep).

  - Idempotent / safe to re-run
  - Reuses PRODUCT.READ when present
  - Creates admin PRODUCT.* permission catalog
  - Maps catalog to ADMINISTRATOR when that role exists
  - Does NOT grant pricing permissions to portal CUSTOMER_* roles
  - Does NOT modify GUEST / EMPLOYEE permission sets beyond PRODUCT.READ reuse

  DEVELOPMENT seed — review before production.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @PermissionsInserted INT = 0;
    DECLARE @PermissionsReused INT = 0;
    DECLARE @AdministratorMappingsInserted INT = 0;
    DECLARE @AdministratorMappingsExisted INT = 0;

    DECLARE @PermissionCatalog TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL,
        [ModuleCode] NVARCHAR(100) NOT NULL
    );

    INSERT INTO @PermissionCatalog ([PermissionCode], [PermissionName], [Description], [ModuleCode])
    VALUES
        (N'PRODUCT.READ', N'Read products', N'View product catalogue and admin product records.', N'product'),
        (N'PRODUCT.CREATE', N'Create products', N'Create product records.', N'product'),
        (N'PRODUCT.UPDATE', N'Update products', N'Update product records.', N'product'),
        (N'PRODUCT.PUBLISH', N'Publish products', N'Publish or unpublish products.', N'product'),
        (N'PRODUCT.ARCHIVE', N'Archive products', N'Archive products.', N'product'),
        (N'PRODUCT.RESTORE', N'Restore products', N'Restore archived products.', N'product'),
        (N'PRODUCT.VARIANTS.MANAGE', N'Manage product variants', N'Create, update, and archive product variants.', N'product'),
        (N'PRODUCT.SPECIFICATIONS.MANAGE', N'Manage specifications', N'Manage specification definitions and product values.', N'product'),
        (N'PRODUCT.MEDIA.MANAGE', N'Manage product media', N'Upload and manage product media.', N'product'),
        (N'PRODUCT.DOCUMENTS.MANAGE', N'Manage product documents', N'Upload and manage product documents.', N'product'),
        (N'PRODUCT.RELATIONSHIPS.MANAGE', N'Manage product relationships', N'Manage accessories, related, and replacement products.', N'product'),
        (N'PRODUCT.CODES.MANAGE', N'Manage product codes', N'Manage product coding rules and codes.', N'product'),
        (N'PRODUCT.PRICING.VIEW', N'View product pricing', N'View product and variant prices.', N'product'),
        (N'PRODUCT.PRICING.MANAGE', N'Manage product pricing', N'Create and update product pricing.', N'product'),
        (N'PRODUCT.LOOKUPS.MANAGE', N'Manage product lookups', N'Manage families, categories, series, regions, and other lookups.', N'product'),
        (N'PRODUCT.STYLES.READ', N'View product styles', N'View product style hierarchy and inherited resources.', N'product'),
        (N'PRODUCT.STYLES.MANAGE', N'Manage product styles', N'Create and maintain product style hierarchy.', N'product'),
        (N'PRODUCT.STYLES.INHERITANCE.MANAGE', N'Manage product style inheritance', N'Configure style inheritance and make local copies.', N'product'),
        (N'PRODUCT.MIGRATION.MANAGE', N'Manage product migration', N'Run product legacy migration tooling.', N'product'),
        (N'PRODUCT.AUDIT.VIEW', N'View product audit history', N'View product audit events.', N'product');

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

    /* Map all PRODUCT.* permissions to ADMINISTRATOR */
    DECLARE @AdministratorRoleId BIGINT = NULL;
    SELECT TOP (1) @AdministratorRoleId = r.[RoleId]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
    ORDER BY r.[RoleId];

    IF @AdministratorRoleId IS NOT NULL
    BEGIN
        DECLARE @MapPermissionId BIGINT;
        DECLARE @ExistingMappingId BIGINT;
        DECLARE @ExistingMappingIsActive BIT;

        DECLARE map_cursor CURSOR LOCAL FAST_FORWARD FOR
            SELECT p.[PermissionId]
            FROM [identity].[Permissions] p
            INNER JOIN @PermissionCatalog c ON UPPER(c.[PermissionCode]) = UPPER(p.[PermissionCode])
            WHERE p.[IsActive] = 1;

        OPEN map_cursor;
        FETCH NEXT FROM map_cursor INTO @MapPermissionId;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            SET @ExistingMappingId = NULL;
            SET @ExistingMappingIsActive = NULL;

            SELECT TOP (1)
                @ExistingMappingId = rp.[RolePermissionId],
                @ExistingMappingIsActive = rp.[IsActive]
            FROM [identity].[RolePermissions] rp
            WHERE rp.[RoleId] = @AdministratorRoleId
              AND rp.[PermissionId] = @MapPermissionId
            ORDER BY rp.[RolePermissionId];

            IF @ExistingMappingId IS NULL
            BEGIN
                INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
                VALUES (@AdministratorRoleId, @MapPermissionId, 1);
                SET @AdministratorMappingsInserted = @AdministratorMappingsInserted + 1;
            END
            ELSE IF @ExistingMappingIsActive = 0
            BEGIN
                UPDATE [identity].[RolePermissions]
                SET [IsActive] = 1
                WHERE [RolePermissionId] = @ExistingMappingId;
                SET @AdministratorMappingsInserted = @AdministratorMappingsInserted + 1;
            END
            ELSE
            BEGIN
                SET @AdministratorMappingsExisted = @AdministratorMappingsExisted + 1;
            END;

            FETCH NEXT FROM map_cursor INTO @MapPermissionId;
        END;

        CLOSE map_cursor;
        DEALLOCATE map_cursor;
    END;

    COMMIT TRANSACTION;

    SELECT
        @PermissionsInserted AS PermissionsInserted,
        @PermissionsReused AS PermissionsReused,
        @AdministratorMappingsInserted AS AdministratorMappingsInserted,
        @AdministratorMappingsExisted AS AdministratorMappingsExisted,
        CASE WHEN @AdministratorRoleId IS NULL THEN N'skipped_role_missing' ELSE N'mapped' END AS AdministratorMappingStatus;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();
    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
