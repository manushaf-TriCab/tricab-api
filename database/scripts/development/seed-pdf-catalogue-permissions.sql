/*
================================================================================
  Seed: CATALOGUE.READ for staff PDF catalogues.

  Maps to ADMINISTRATOR + EMPLOYEE (legacy staff menu parity).
  Does not modify GUEST / CUSTOMER_* role sets.

  DEVELOPMENT seed — review before production.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @PermissionCode NVARCHAR(150) = N'CATALOGUE.READ';
    DECLARE @PermissionName NVARCHAR(200) = N'Read PDF Catalogues';
    DECLARE @PermissionDescription NVARCHAR(500) = N'List and open TriCab product PDF catalogues.';
    DECLARE @ModuleCode NVARCHAR(100) = N'catalogue';
    DECLARE @ResolvedPermissionId BIGINT;
    DECLARE @ResolvedPermissionIsActive BIT;
    DECLARE @PermissionsInserted INT = 0;
    DECLARE @PermissionsReused INT = 0;
    DECLARE @AdministratorMappingsInserted INT = 0;
    DECLARE @EmployeeMappingsInserted INT = 0;

    SELECT
        @ResolvedPermissionId = [PermissionId],
        @ResolvedPermissionIsActive = [IsActive]
    FROM [identity].[Permissions]
    WHERE [PermissionCode] = @PermissionCode;

    IF @ResolvedPermissionId IS NULL
    BEGIN
        INSERT INTO [identity].[Permissions] (
            [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
        )
        VALUES (
            @PermissionCode, @PermissionName, @PermissionDescription, @ModuleCode, 1
        );
        SET @ResolvedPermissionId = SCOPE_IDENTITY();
        SET @PermissionsInserted = 1;
    END
    ELSE
    BEGIN
        SET @PermissionsReused = 1;
        IF @ResolvedPermissionIsActive = 0
        BEGIN
            UPDATE [identity].[Permissions]
            SET [IsActive] = 1,
                [PermissionName] = @PermissionName,
                [Description] = @PermissionDescription,
                [ModuleCode] = @ModuleCode
            WHERE [PermissionId] = @ResolvedPermissionId;
        END
    END

    DECLARE @AdministratorRoleId BIGINT =
        (SELECT [RoleId] FROM [identity].[Roles] WHERE [RoleCode] = N'ADMINISTRATOR');
    DECLARE @EmployeeRoleId BIGINT =
        (SELECT [RoleId] FROM [identity].[Roles] WHERE [RoleCode] = N'EMPLOYEE');

    IF @AdministratorRoleId IS NOT NULL
       AND NOT EXISTS (
            SELECT 1 FROM [identity].[RolePermissions]
            WHERE [RoleId] = @AdministratorRoleId AND [PermissionId] = @ResolvedPermissionId
       )
    BEGIN
        INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
        VALUES (@AdministratorRoleId, @ResolvedPermissionId, 1);
        SET @AdministratorMappingsInserted = 1;
    END

    IF @EmployeeRoleId IS NOT NULL
       AND NOT EXISTS (
            SELECT 1 FROM [identity].[RolePermissions]
            WHERE [RoleId] = @EmployeeRoleId AND [PermissionId] = @ResolvedPermissionId
       )
    BEGIN
        INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
        VALUES (@EmployeeRoleId, @ResolvedPermissionId, 1);
        SET @EmployeeMappingsInserted = 1;
    END

    COMMIT TRANSACTION;

    SELECT
        @PermissionCode AS PermissionCode,
        @PermissionsInserted AS PermissionsInserted,
        @PermissionsReused AS PermissionsReused,
        @AdministratorMappingsInserted AS AdministratorMappingsInserted,
        @EmployeeMappingsInserted AS EmployeeMappingsInserted;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
