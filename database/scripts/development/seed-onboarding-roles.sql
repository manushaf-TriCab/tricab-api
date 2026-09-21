/*
================================================================================
  DEVELOPMENT seed — Onboarding roles.

  There are two Onboarding user types, and this script is where that is stated:

    ADMIN     `ONBOARDING_ADMIN`. Creates, reads, updates and deletes trainees,
              allocates their induction, reviews what they hand in, authors
              content and reads reports. Holds all eight ONBOARDING.*
              permissions, including the self-service three, because an admin
              also has an induction of their own.

    TRAINEE   No new role. A trainee is anyone holding `EMPLOYEE` or `GUEST`,
              both of which already carry ACCESS + SELF.READ + SELF.SUBMIT from
              seed-onboarding-permissions.sql.

  Why the trainee side gets no role of its own: everyone at TriCab has an
  induction, and a role that has to be assigned per person is a role somebody
  will forget to assign — leaving a new starter locked out of their own
  onboarding on their first morning. Seeing `/onboarding` grants nothing by
  itself: allocation is explicit, so an employee with nothing allocated sees an
  empty page, which is the truth.

  There is deliberately no reviewer role. Reviewing is something the admin does,
  not a separate user type. The domain keeps `reviewer` as an *actor* — see
  AssignmentStatusPolicy — because "a trainee cannot mark their own induction
  complete" needs two actors to mean anything, even when one person can be both.
  That distinction is enforced by SelfReviewPolicy rather than by roles.

  `ADMINISTRATOR` is left exactly as seed-onboarding-permissions.sql left it,
  holding all eight. Narrowing it to ONBOARDING.ADMIN alone — the TriExpense
  pattern, where a platform admin can grant module access without reading
  module data — is the right end state, but it would remove access from the
  only role that currently has it. It waits until real people hold
  ONBOARDING_ADMIN.

  - Idempotent / safe to re-run
  - Creates no users and assigns no roles to anyone
  - Does not modify permissions, EMPLOYEE, GUEST, CUSTOMER_* or ADMINISTRATOR
  - Reactivates an ONBOARDING_ADMIN mapping it previously deactivated, nothing else

  Run after seed-onboarding-permissions.sql.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @RoleCode NVARCHAR(100) = N'ONBOARDING_ADMIN';
    DECLARE @RoleName NVARCHAR(200) = N'Onboarding Administrator';
    DECLARE @RoleDescription NVARCHAR(500) =
        N'Manage trainees and their inductions: create, read, update and delete trainee records, allocate modules, policies and forms, review submissions, author content and read reports.';

    /* ------------------------------------------------------------------
       The role. RoleCode is UPPERCASE_UNDERSCORE by platform convention —
       dots belong to permission codes, never to role codes.

       IsSystemRole = 0 so an administrator can edit it afterwards in
       Roles & Permissions without fighting the seed.
       ------------------------------------------------------------------ */
    IF NOT EXISTS (
        SELECT 1 FROM [identity].[Roles] WHERE UPPER([RoleCode]) = UPPER(@RoleCode)
    )
    BEGIN
        INSERT INTO [identity].[Roles]
            ([RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive])
        VALUES
            (@RoleCode, @RoleName, @RoleDescription, 0, 1);
    END
    ELSE
    BEGIN
        /* Reactivate a role somebody retired, and keep its wording current. */
        UPDATE [identity].[Roles]
        SET [IsActive] = 1,
            [RoleName] = @RoleName,
            [Description] = @RoleDescription,
            [UpdatedAt] = SYSDATETIMEOFFSET()
        WHERE UPPER([RoleCode]) = UPPER(@RoleCode)
          AND ([IsActive] = 0 OR [RoleName] <> @RoleName OR ISNULL([Description], N'') <> @RoleDescription);
    END;

    /* ------------------------------------------------------------------
       Every ONBOARDING.* permission. An admin who could not open a
       trainee's forms could not do the job the role exists for.
       ------------------------------------------------------------------ */
    INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
    SELECT r.[RoleId], p.[PermissionId], 1
    FROM [identity].[Roles] r
    CROSS JOIN [identity].[Permissions] p
    WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode)
      AND UPPER(p.[PermissionCode]) LIKE N'ONBOARDING.%'
      AND p.[IsActive] = 1
      AND NOT EXISTS (
          SELECT 1 FROM [identity].[RolePermissions] rp
          WHERE rp.[RoleId] = r.[RoleId] AND rp.[PermissionId] = p.[PermissionId]
      );

    UPDATE rp
    SET rp.[IsActive] = 1
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode)
      AND UPPER(p.[PermissionCode]) LIKE N'ONBOARDING.%'
      AND rp.[IsActive] = 0;

    /* ------------------------------------------------------------------
       Guards. Both would be a serious fault, so they fail the script
       rather than logging a warning nobody reads.
       ------------------------------------------------------------------ */

    /* The role must be able to do the whole job, or it is worse than useless:
       an admin would find out which permission is missing by hitting a 403
       halfway through onboarding somebody. */
    DECLARE @Granted INT = (
        SELECT COUNT(*)
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode)
          AND UPPER(p.[PermissionCode]) LIKE N'ONBOARDING.%'
          AND rp.[IsActive] = 1
    );

    IF @Granted <> 8
    BEGIN
        DECLARE @GrantedMessage NVARCHAR(200) =
            CONCAT(N'ONBOARDING_ADMIN holds ', @Granted, N' of 8 ONBOARDING permissions. Run seed-onboarding-permissions.sql first.');
        THROW 52010, @GrantedMessage, 1;
    END;

    /* Unchanged from seed-onboarding-permissions.sql: the trainee roles keep
       self-service only. Re-checked here because this script is the one that
       introduces a role holding administrative permissions, and a copy-paste
       into the wrong role is exactly the mistake worth catching. */
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
        THROW 52011, N'An administrative ONBOARDING permission is mapped to EMPLOYEE or GUEST. Refusing to continue.', 1;
    END;

    SELECT
        @RoleCode AS [Role],
        @Granted AS [OnboardingPermissionsGranted],
        (SELECT COUNT(*) FROM [identity].[UserRoles] ur
           JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
          WHERE UPPER(r.[RoleCode]) = UPPER(@RoleCode) AND ur.[IsActive] = 1) AS [UsersHoldingIt];

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
