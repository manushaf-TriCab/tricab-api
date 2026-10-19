/*
================================================================================
  DEVELOPMENT seed - CableFit permissions.

  - Idempotent / safe to re-run
  - Creates the permissions TSD.CABLEFIT.USE and TSD.CABLEFIT.RESEARCH
  - Grants both to the role named 'TSD Engineer', looked up BY NAME. That role
    is created by another team's seed, which must run first; if it does not
    exist yet the grant is skipped with a PRINT and the script can be re-run.
  - Creates no users and no roles

  Run after seed-tsd-permissions.sql.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'tsd';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'TSD.CABLEFIT.USE', N'Use CableFit',
         N'Create, edit and save CableFit candidate designs.'),
        (N'TSD.CABLEFIT.RESEARCH', N'CableFit research',
         N'Use the CableFit research tools.');

    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    DECLARE @RoleId BIGINT = (
        SELECT TOP (1) [RoleId] FROM [identity].[Roles] WHERE [RoleName] = N'TSD Engineer'
    );

    IF @RoleId IS NULL
    BEGIN
        PRINT N'Role "TSD Engineer" not found: CableFit permissions created but not granted. Re-run after the role is seeded.';
    END
    ELSE
    BEGIN
        INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
        SELECT @RoleId, perm.[PermissionId], 1
        FROM [identity].[Permissions] perm
        JOIN @Permissions p ON UPPER(perm.[PermissionCode]) = UPPER(p.[PermissionCode])
        WHERE NOT EXISTS (
            SELECT 1 FROM [identity].[RolePermissions] rp
            WHERE rp.[RoleId] = @RoleId AND rp.[PermissionId] = perm.[PermissionId]
        );

        UPDATE rp
        SET rp.[IsActive] = 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Permissions] perm ON perm.[PermissionId] = rp.[PermissionId]
        JOIN @Permissions p ON UPPER(perm.[PermissionCode]) = UPPER(p.[PermissionCode])
        WHERE rp.[RoleId] = @RoleId AND rp.[IsActive] = 0;
    END;

    SELECT COUNT(*) AS [CableFitPermissions]
    FROM [identity].[Permissions] WHERE [PermissionCode] LIKE N'TSD.CABLEFIT.%';

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
