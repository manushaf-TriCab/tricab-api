/*
================================================================================
  DEVELOPMENT seed — Onboarding module permissions and role mappings.

  - Idempotent / safe to re-run
  - Creates no users and no onboarding data
  - Does not modify permissions outside the ONBOARDING.* family
  - Reactivates an ONBOARDING.* mapping it previously deactivated, and nothing else

  Role mapping, and why:

    ADMINISTRATOR   ONBOARDING.ADMIN only.
    EMPLOYEE        ACCESS + SELF.READ + SELF.SUBMIT.
    GUEST           ACCESS + SELF.READ + SELF.SUBMIT.

  ADMINISTRATOR held all eight until 21 Sep 2026. That was the bootstrap state —
  somebody had to be able to reach the module before ONBOARDING_ADMIN existed —
  and it is not the intended one. A platform administrator should be able to
  GRANT access to a module without being able to READ its data: the person who
  manages roles is not automatically entitled to a colleague's medical
  questionnaire, and holding both by default means every platform admin is
  inside the most sensitive data the platform holds.

  This is the TriExpense pattern, and the narrowing waited on real people
  holding ONBOARDING_ADMIN. All four now do.

  ONBOARDING.ADMIN stays because it is module administration rather than data
  access — it gates the settings screen and nothing that reads a trainee's
  record. The other seven are withdrawn below.

  This script OWNS the mapping for ADMINISTRATOR, EMPLOYEE and GUEST: @Grants
  states it in full, and any ONBOARDING.* mapping on those three roles that is
  not in @Grants is deactivated. ONBOARDING_ADMIN is not touched — its five
  permissions belong to seed-onboarding-roles.sql, and two scripts writing one
  role is how they end up disagreeing.

  EMPLOYEE and GUEST get the same self-service set deliberately. Some employees
  — warehouse operators and similar — are never issued a TriCab mailbox and
  authenticate by personal-address email OTP for their whole time here, exactly
  as a pre-start candidate does. Scoping self-service to GUEST alone would make
  those staff permanent guests, and scoping it to EMPLOYEE alone would lock out
  candidates. Access follows the permission, never the login method or the role
  (work order §4.15).

  No administrative ONBOARDING permission is granted to EMPLOYEE or GUEST.

  RE-RUN REQUIRED on any database where the bootstrap revision granted
  ADMINISTRATOR all eight. Re-running deactivates the seven it should not have.
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
    VALUES
        (N'ADMINISTRATOR', N'ONBOARDING.ADMIN'),
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
       Withdraw any ONBOARDING.* mapping on the three roles this script owns
       that @Grants does not state. In practice that is the seven permissions
       ADMINISTRATOR held from the bootstrap grant.

       Deactivation, not deletion, following the platform's pattern for
       RolePermissions: the withdrawal is then visible in Roles & Permissions,
       and putting it back is a flag rather than an INSERT.

       Scoped to ADMINISTRATOR, EMPLOYEE and GUEST by name. A blanket
       "deactivate every ONBOARDING.* mapping not in @Grants" would strip
       ONBOARDING_ADMIN of the five permissions seed-onboarding-roles.sql
       grants it, which is precisely the kind of cross-script accident the
       ownership rule exists to prevent.
       ------------------------------------------------------------------ */
    UPDATE rp
    SET rp.[IsActive] = 0
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (N'ADMINISTRATOR', N'EMPLOYEE', N'GUEST')
      AND UPPER(p.[PermissionCode]) LIKE N'ONBOARDING.%'
      AND NOT EXISTS (
          SELECT 1 FROM @Grants g
          WHERE UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
            AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
      );

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

    /* ------------------------------------------------------------------
       Guard: ADMINISTRATOR holds module administration and nothing that reads
       a trainee's record. This is the whole point of the narrowing, so it
       fails the script rather than logging a warning nobody reads.
       ------------------------------------------------------------------ */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
          AND UPPER(p.[PermissionCode]) LIKE N'ONBOARDING.%'
          AND UPPER(p.[PermissionCode]) <> N'ONBOARDING.ADMIN'
    )
    BEGIN
        THROW 52002, N'ADMINISTRATOR still holds an ONBOARDING permission other than ONBOARDING.ADMIN. Refusing to continue.', 1;
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
