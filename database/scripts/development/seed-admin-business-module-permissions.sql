/*
================================================================================
  Seed: Admin business-module permissions for new TriCab Admin navigation.

  Adds (idempotent):
    PRICE_LOOKUP.READ
    QUOTE.READ / CREATE / UPDATE / DELETE
    CUSTOMER_NOTE.READ / CREATE / UPDATE / DELETE
    ALIFLEX_ACCREDITATION.ACCESS
    TRIEXPENSE.ACCESS
    CRM.ACCESS
    ERP.ACCESS
    REGION.READ / CREATE / UPDATE / DELETE

  - Safe to re-run
  - Does not rename or remove existing permission codes
  - Reuses QUOTE.READ / QUOTE.CREATE when already present
  - Maps the catalog to ADMINISTRATOR when that role exists
  - Does not modify EMPLOYEE / GUEST / CUSTOMER_* role sets

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
        (N'PRICE_LOOKUP.READ', N'Read price lookup', N'Access the admin Price Lookup module.', N'price_lookup'),
        (N'QUOTE.READ', N'Read quotes', N'View quotes in TriCab Admin.', N'quote'),
        (N'QUOTE.CREATE', N'Create quotes', N'Create quotes in TriCab Admin.', N'quote'),
        (N'QUOTE.UPDATE', N'Update quotes', N'Update quotes in TriCab Admin.', N'quote'),
        (N'QUOTE.DELETE', N'Delete quotes', N'Delete quotes in TriCab Admin.', N'quote'),
        (N'CUSTOMER_NOTE.READ', N'Read customer notes', N'View customer notes in TriCab Admin.', N'customer_note'),
        (N'CUSTOMER_NOTE.CREATE', N'Create customer notes', N'Create customer notes in TriCab Admin.', N'customer_note'),
        (N'CUSTOMER_NOTE.UPDATE', N'Update customer notes', N'Update customer notes in TriCab Admin.', N'customer_note'),
        (N'CUSTOMER_NOTE.DELETE', N'Delete customer notes', N'Delete customer notes in TriCab Admin.', N'customer_note'),
        (N'ALIFLEX_ACCREDITATION.ACCESS', N'Access ALI-FLEX Accreditation', N'Open the ALI-FLEX Accreditation external system from Admin.', N'aliflex'),
        (N'TRIEXPENSE.ACCESS', N'Access Tri Expense', N'Open Tri Expense from Admin.', N'triexpense'),
        (N'CRM.ACCESS', N'Access CRM', N'Open CRM from Admin.', N'crm'),
        (N'ERP.ACCESS', N'Access ERP', N'Open ERP from Admin.', N'erp'),
        (N'REGION.READ', N'Read regions', N'View website regions in TriCab Admin.', N'region'),
        (N'REGION.CREATE', N'Create regions', N'Create website regions in TriCab Admin.', N'region'),
        (N'REGION.UPDATE', N'Update regions', N'Update and activate website regions in TriCab Admin.', N'region'),
        (N'REGION.DELETE', N'Delete regions', N'Delete unused website regions in TriCab Admin.', N'region');

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
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
