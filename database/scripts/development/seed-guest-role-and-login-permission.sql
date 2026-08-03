/*
================================================================================
  Development seed: GUEST role + ACCOUNT.LOGIN permission mapping.

  - Idempotent / safe to re-run
  - Does not create a guest user
  - Does not modify EMPLOYEE / employee or other roles
  - Reuses existing ACCOUNT.LOGIN / account.login permission when present
  - Reports (does not silently reactivate) an inactive GUEST role

  Column note: [identity].[Roles] uses [RoleName] (not DisplayName).
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @GuestRoleCode NVARCHAR(100) = N'GUEST';
    DECLARE @GuestRoleName NVARCHAR(150) = N'Guest';
    DECLARE @LoginPermissionCode NVARCHAR(150) = N'ACCOUNT.LOGIN';
    DECLARE @LoginPermissionName NVARCHAR(200) = N'Log in to TriCab';
    DECLARE @LoginModuleCode NVARCHAR(100) = N'identity';

    DECLARE @GuestRoleId BIGINT = NULL;
    DECLARE @GuestRoleIsActive BIT = NULL;
    DECLARE @LoginPermissionId BIGINT = NULL;
    DECLARE @LoginPermissionIsActive BIT = NULL;
    DECLARE @LoginPermissionStoredCode NVARCHAR(150) = NULL;

    DECLARE @RoleAction NVARCHAR(40) = N'already_existed';
    DECLARE @PermissionAction NVARCHAR(40) = N'reused';
    DECLARE @MappingAction NVARCHAR(40) = N'already_existed';
    DECLARE @InactiveGuestWarning NVARCHAR(200) = NULL;

    /* ------------------------------------------------------------------
       Permission: reuse ACCOUNT.LOGIN / account.login (case-insensitive)
       ------------------------------------------------------------------ */
    SELECT TOP (1)
        @LoginPermissionId = p.[PermissionId],
        @LoginPermissionIsActive = p.[IsActive],
        @LoginPermissionStoredCode = p.[PermissionCode]
    FROM [identity].[Permissions] p
    WHERE UPPER(p.[PermissionCode]) = UPPER(@LoginPermissionCode)
    ORDER BY p.[PermissionId];

    IF @LoginPermissionId IS NULL
    BEGIN
        INSERT INTO [identity].[Permissions] (
            [PermissionCode],
            [PermissionName],
            [Description],
            [ModuleCode],
            [IsActive]
        )
        VALUES (
            @LoginPermissionCode,
            @LoginPermissionName,
            N'Allows a user to sign in to the TriCab platform.',
            @LoginModuleCode,
            1
        );

        SET @LoginPermissionId = SCOPE_IDENTITY();
        SET @LoginPermissionIsActive = 1;
        SET @LoginPermissionStoredCode = @LoginPermissionCode;
        SET @PermissionAction = N'inserted';
    END
    ELSE
    BEGIN
        SET @PermissionAction = N'reused';
    END;

    IF @LoginPermissionId IS NULL
    BEGIN
        THROW 51001, N'ACCOUNT.LOGIN permission could not be resolved.', 1;
    END;

    IF @LoginPermissionIsActive = 0
    BEGIN
        THROW 51002, N'ACCOUNT.LOGIN permission exists but IsActive = 0. Refusing to continue.', 1;
    END;

    /* ------------------------------------------------------------------
       Role: insert GUEST if absent; report if inactive
       ------------------------------------------------------------------ */
    SELECT TOP (1)
        @GuestRoleId = r.[RoleId],
        @GuestRoleIsActive = r.[IsActive]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = UPPER(@GuestRoleCode)
    ORDER BY r.[RoleId];

    IF @GuestRoleId IS NULL
    BEGIN
        INSERT INTO [identity].[Roles] (
            [RoleCode],
            [RoleName],
            [Description],
            [IsSystemRole],
            [IsActive]
        )
        VALUES (
            @GuestRoleCode,
            @GuestRoleName,
            N'Authenticated guest user with limited TriCab access.',
            1,
            1
        );

        SET @GuestRoleId = SCOPE_IDENTITY();
        SET @GuestRoleIsActive = 1;
        SET @RoleAction = N'inserted';
    END
    ELSE IF @GuestRoleIsActive = 0
    BEGIN
        SET @RoleAction = N'exists_inactive';
        SET @InactiveGuestWarning = N'GUEST role exists but IsActive = 0. Not reactivated.';
        PRINT @InactiveGuestWarning;
    END
    ELSE
    BEGIN
        SET @RoleAction = N'already_existed';
    END;

    IF @GuestRoleId IS NULL
    BEGIN
        THROW 51003, N'GUEST role could not be resolved.', 1;
    END;

    IF @GuestRoleIsActive = 0
    BEGIN
        THROW 51004, N'GUEST role exists but is inactive. Reactivate manually if intended.', 1;
    END;

    /* ------------------------------------------------------------------
       Mapping: GUEST → ACCOUNT.LOGIN only if absent
       ------------------------------------------------------------------ */
    IF NOT EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = @GuestRoleId
          AND rp.[PermissionId] = @LoginPermissionId
    )
    BEGIN
        INSERT INTO [identity].[RolePermissions] (
            [RoleId],
            [PermissionId],
            [IsActive]
        )
        VALUES (
            @GuestRoleId,
            @LoginPermissionId,
            1
        );

        SET @MappingAction = N'inserted';
    END
    ELSE
    BEGIN
        SET @MappingAction = N'already_existed';
    END;

    /* Result summary (no guest user created) */
    SELECT
        @RoleAction AS [GuestRoleAction],
        @GuestRoleId AS [GuestRoleId],
        @GuestRoleIsActive AS [GuestRoleIsActive],
        @PermissionAction AS [LoginPermissionAction],
        @LoginPermissionId AS [LoginPermissionId],
        @LoginPermissionStoredCode AS [LoginPermissionStoredCode],
        @LoginPermissionIsActive AS [LoginPermissionIsActive],
        @MappingAction AS [RolePermissionMappingAction],
        @InactiveGuestWarning AS [InactiveGuestWarning];

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
