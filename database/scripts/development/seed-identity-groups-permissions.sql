/*
================================================================================
  Seed — IDENTITY.GROUPS.MANAGE (Tri-Task company plan, Phase 1).

  Lets an Administrator list, create, edit and delete platform groups, edit their
  members, link/unlink Entra groups and sync them, and use the group lookup.

  - Idempotent / safe to re-run
  - Creates no users and assigns no role to anyone
  - Granted to ADMINISTRATOR only (the plan: all group management is Administrator only)
  - Needs the ADMINISTRATOR role (seed-user-management-permissions.sql). If it is
    missing the permission is still created and this script says so.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Code NVARCHAR(150) = N'IDENTITY.GROUPS.MANAGE';
    DECLARE @Name NVARCHAR(200) = N'Manage groups';
    DECLARE @Description NVARCHAR(500) =
        N'Create and edit platform groups, link Entra mail groups, edit group members and sync groups.';

    IF NOT EXISTS (SELECT 1 FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) = UPPER(@Code))
    BEGIN
        INSERT INTO [identity].[Permissions] ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
        VALUES (@Code, @Name, @Description, N'identity', 1);
    END
    ELSE
    BEGIN
        UPDATE [identity].[Permissions]
        SET [PermissionName] = @Name, [Description] = @Description, [ModuleCode] = N'identity',
            [IsActive] = 1, [UpdatedAt] = SYSDATETIMEOFFSET()
        WHERE UPPER([PermissionCode]) = UPPER(@Code)
          AND ([IsActive] = 0 OR [PermissionName] <> @Name OR ISNULL([Description], N'') <> @Description OR ISNULL([ModuleCode], N'') <> N'identity');
    END;

    DECLARE @PermissionId BIGINT = (SELECT [PermissionId] FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) = UPPER(@Code));
    DECLARE @RoleId BIGINT = (SELECT [RoleId] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = N'ADMINISTRATOR');

    IF @RoleId IS NULL
    BEGIN
        PRINT N'ADMINISTRATOR role not found: the permission exists but nobody holds it. Run seed-user-management-permissions.sql, then re-run this.';
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM [identity].[RolePermissions] WHERE [RoleId] = @RoleId AND [PermissionId] = @PermissionId)
        BEGIN
            INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive]) VALUES (@RoleId, @PermissionId, 1);
        END
        ELSE
        BEGIN
            UPDATE [identity].[RolePermissions] SET [IsActive] = 1
            WHERE [RoleId] = @RoleId AND [PermissionId] = @PermissionId AND [IsActive] = 0;
        END;
    END;

    SELECT @Code AS [Permission],
           (SELECT COUNT(*) FROM [identity].[RolePermissions] rp WHERE rp.[PermissionId] = @PermissionId AND rp.[IsActive] = 1) AS [RolesHoldingIt];

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
