/*
================================================================================
  DEVELOPMENT seed — TSD module permissions.

  - Idempotent / safe to re-run
  - Creates no users, no roles and no TSD data
  - Maps the permission to NO role: nobody holds TSD.ACCESS until a role is
    created and assigned at rollout (plan NPR-TSD-MIGRATION-PLAN N5.1)
  - Does not modify permissions outside the TSD.* family
  - WRITTEN, NOT EXECUTED against any shared database

  Must match TriCab\Core\Tsd\Module::PERMISSIONS; tests/Tsd/TsdSeedScriptTest.php
  fails if they drift.
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
        (N'TSD.ACCESS', N'Access TSD',
         N'Open the TSD admin module.');

    /* ------------------------------------------------------------------
       Permissions: insert what is missing, leave existing rows alone
       ------------------------------------------------------------------ */
    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [TsdPermissions];

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
