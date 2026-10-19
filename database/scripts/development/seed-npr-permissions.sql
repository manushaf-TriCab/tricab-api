/*
================================================================================
  DEVELOPMENT seed: NPR module permissions (plan NPR-TSD-MIGRATION-PLAN N1.2).

  - Idempotent / safe to re-run
  - Creates no users, no roles and no NPR data
  - Maps the permissions to NO role (seed-npr-roles.sql does that)
  - Does not modify permissions outside the NPR.* family
  - WRITTEN, NOT EXECUTED against any shared database

  Must match TriCab\Core\Npr\Module::PERMISSIONS; tests/Npr/NprSeedScriptTest.php
  fails if they drift.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'npr';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'NPR.SELF.READ', N'Read own NPRs',
         N'List and open the new product requests the caller raised.'),
        (N'NPR.SELF.CREATE', N'Create NPRs',
         N'Start and edit a draft new product request.'),
        (N'NPR.SELF.SUBMIT', N'Submit NPRs',
         N'Submit own request to the branch manager, and resubmit after a return.'),
        (N'NPR.TEAM.ENDORSE', N'Endorse team NPRs',
         N'Endorse, return or reject requests routed to the caller as branch manager.'),
        (N'NPR.TSD.READ', N'Read NPRs as TSD',
         N'List and open every request that has reached TSD.'),
        (N'NPR.TSD.WORK', N'Work NPRs as TSD',
         N'Acknowledge, assign, return and request screening on requests with TSD.'),
        (N'NPR.SCREENING.DECIDE', N'Decide NPR screening',
         N'Record the final screening outcome as signatory.'),
        (N'NPR.ADMIN', N'Administer NPR',
         N'Manage manager routes and delegates and other NPR module administration.');

    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [NprPermissions];

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
