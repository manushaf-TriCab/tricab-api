/*
  DEVELOPMENT seed — MIGRATION.ADMIN for Administrators only.
  Idempotent. Does not grant the permission to portal or employee roles.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @PermissionId BIGINT;
    DECLARE @AdministratorRoleId BIGINT;

    IF NOT EXISTS (SELECT 1 FROM [identity].[Permissions] WHERE [PermissionCode] = N'MIGRATION.ADMIN')
    BEGIN
        INSERT INTO [identity].[Permissions] ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
        VALUES (N'MIGRATION.ADMIN', N'Administer Legacy Migration', N'Operate the reusable legacy migration framework. Administrators only.', N'migration', 1);
    END;

    SELECT @PermissionId = [PermissionId] FROM [identity].[Permissions] WHERE [PermissionCode] = N'MIGRATION.ADMIN';
    SELECT @AdministratorRoleId = [RoleId] FROM [identity].[Roles] WHERE [RoleCode] = N'ADMINISTRATOR';

    IF @PermissionId IS NOT NULL AND @AdministratorRoleId IS NOT NULL
    BEGIN
        IF NOT EXISTS (
            SELECT 1 FROM [identity].[RolePermissions]
            WHERE [RoleId] = @AdministratorRoleId AND [PermissionId] = @PermissionId
        )
        BEGIN
            INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
            VALUES (@AdministratorRoleId, @PermissionId, 1);
        END
        ELSE
        BEGIN
            UPDATE [identity].[RolePermissions]
            SET [IsActive] = 1
            WHERE [RoleId] = @AdministratorRoleId AND [PermissionId] = @PermissionId AND [IsActive] = 0;
        END
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
