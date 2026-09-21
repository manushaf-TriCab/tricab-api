/*
================================================================================
  DEVELOPMENT seed — Onboarding module permissions and role mappings.

  - Idempotent / safe to re-run
  - Creates no users and no onboarding data
  - Does not modify permissions outside the ONBOARDING.* family
  - Reactivates an ONBOARDING.* mapping it previously deactivated, and nothing else

  Role mapping, and why:

    ADMINISTRATOR   every ONBOARDING.* permission.
    EMPLOYEE        ACCESS + SELF.READ + SELF.SUBMIT.
    GUEST           ACCESS + SELF.READ + SELF.SUBMIT.

  EMPLOYEE and GUEST get the same self-service set deliberately. Some employees
  — warehouse operators and similar — are never issued a TriCab mailbox and
  authenticate by personal-address email OTP for their whole time here, exactly
  as a pre-start candidate does. Scoping self-service to GUEST alone would make
  those staff permanent guests, and scoping it to EMPLOYEE alone would lock out
  candidates. Access follows the permission, never the login method or the role
  (work order §4.15).

  No administrative ONBOARDING permission is granted to EMPLOYEE or GUEST.

  NOT RUN against any shared database by this change. Apply through the normal
  rebuild process.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'onboarding';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'ONBOARDING.ACCESS',         N'Access Onboarding',
         N'Reach the Onboarding module.'),
        (N'ONBOARDING.SELF.READ',      N'Read own onboarding',
         N'Read one''s own onboarding record, assignments and documents.'),
        (N'ONBOARDING.SELF.SUBMIT',    N'Submit own onboarding work',
         N'Submit one''s own attempts, acknowledgements and forms.'),
        (N'ONBOARDING.REVIEW',         N'Review onboarding submissions',
         N'Work the reviewer queue and set permitted assignment statuses.'),
        (N'ONBOARDING.PEOPLE.MANAGE',  N'Manage onboarding people',
         N'Invite people and allocate modules, policies and forms.'),
        (N'ONBOARDING.CONTENT.MANAGE', N'Manage onboarding content',
         N'Author modules, policy versions and form definitions.'),
        (N'ONBOARDING.REPORTS.READ',   N'Read onboarding reports',
         N'Operational and compliance reporting.'),
        (N'ONBOARDING.ADMIN',          N'Administer Onboarding',
         N'Administer the Onboarding module.');

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

    /* ------------------------------------------------------------------
       Role mappings
       ------------------------------------------------------------------ */
    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    SELECT N'ADMINISTRATOR', p.[PermissionCode] FROM @Permissions p;

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'EMPLOYEE', N'ONBOARDING.ACCESS'),
        (N'EMPLOYEE', N'ONBOARDING.SELF.READ'),
        (N'EMPLOYEE', N'ONBOARDING.SELF.SUBMIT'),
        (N'GUEST',    N'ONBOARDING.ACCESS'),
        (N'GUEST',    N'ONBOARDING.SELF.READ'),
        (N'GUEST',    N'ONBOARDING.SELF.SUBMIT');

    INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
    SELECT r.[RoleId], p.[PermissionId], 1
    FROM @Grants g
    JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = UPPER(g.[RoleCode])
    JOIN [identity].[Permissions] p ON UPPER(p.[PermissionCode]) = UPPER(g.[PermissionCode])
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = r.[RoleId] AND rp.[PermissionId] = p.[PermissionId]
    );

    /* Reactivate only ONBOARDING.* mappings this script owns. */
    UPDATE rp
    SET rp.[IsActive] = 1
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    JOIN @Grants g
      ON UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
     AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
    WHERE rp.[IsActive] = 0;

    /* ------------------------------------------------------------------
       Guard: no administrative permission may reach EMPLOYEE or GUEST
       ------------------------------------------------------------------ */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) IN (N'EMPLOYEE', N'GUEST')
          AND UPPER(p.[PermissionCode]) LIKE N'ONBOARDING.%'
          AND UPPER(p.[PermissionCode]) NOT IN (
              N'ONBOARDING.ACCESS', N'ONBOARDING.SELF.READ', N'ONBOARDING.SELF.SUBMIT'
          )
    )
    BEGIN
        THROW 52001, N'An administrative ONBOARDING permission is mapped to EMPLOYEE or GUEST. Refusing to continue.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [OnboardingPermissions],
        (SELECT COUNT(*)
           FROM [identity].[RolePermissions] rp
           JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
          WHERE p.[ModuleCode] = @ModuleCode AND rp.[IsActive] = 1) AS [ActiveOnboardingMappings];

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
