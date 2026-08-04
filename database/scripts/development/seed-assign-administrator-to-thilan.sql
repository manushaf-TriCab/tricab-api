/*
================================================================================
  LOCAL DEVELOPMENT ONLY — do not deploy to production.

  Idempotently assigns the ADMINISTRATOR role to:

      thilanh@tricab.com

  Prerequisites:
  - User must already exist and be active
  - ADMINISTRATOR role must already exist and be active
    (run seed-user-management-permissions.sql first)

  Behaviour:
  - Does not create a user
  - Does not create the ADMINISTRATOR role or permissions
  - Does not modify EMPLOYEE / GUEST roles
  - Does not grant permissions directly (roles only)
  - Safe to re-run
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @TargetEmail NVARCHAR(320) = N'thilanh@tricab.com';
    DECLARE @AdminRoleCode NVARCHAR(100) = N'ADMINISTRATOR';

    DECLARE @UserId BIGINT = NULL;
    DECLARE @UserEmail NVARCHAR(320) = NULL;
    DECLARE @UserPublicId NVARCHAR(36) = NULL;
    DECLARE @MatchCount INT = 0;

    DECLARE @AdminRoleId BIGINT = NULL;
    DECLARE @AdminRoleIsActive BIT = NULL;

    DECLARE @UserRoleAction NVARCHAR(40) = N'already_existed';
    DECLARE @ExistingUserRoleIsActive BIT = NULL;

    SELECT
        @MatchCount = COUNT(*),
        @UserId = MAX(u.[UserId]),
        @UserEmail = MAX(u.[EmailAddress]),
        @UserPublicId = MAX(LOWER(CONVERT(NVARCHAR(36), u.[PublicId])))
    FROM [identity].[Users] u
    INNER JOIN [identity].[UserStatuses] s
        ON s.[UserStatusId] = u.[UserStatusId]
    WHERE u.[NormalizedEmailAddress] = LOWER(LTRIM(RTRIM(@TargetEmail)))
      AND s.[StatusCode] = N'active'
      AND s.[AllowsLogin] = 1
      AND s.[IsActive] = 1;

    IF @MatchCount = 0
    BEGIN
        THROW 52101, N'No active TriCab user found for thilanh@tricab.com.', 1;
    END;

    IF @MatchCount > 1
    BEGIN
        THROW 52102, N'More than one active TriCab user found for thilanh@tricab.com.', 1;
    END;

    IF @UserId IS NULL
    BEGIN
        THROW 52103, N'Unable to resolve UserId for thilanh@tricab.com.', 1;
    END;

    SELECT TOP (1)
        @AdminRoleId = r.[RoleId],
        @AdminRoleIsActive = r.[IsActive]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = UPPER(@AdminRoleCode)
    ORDER BY r.[RoleId];

    IF @AdminRoleId IS NULL
    BEGIN
        THROW 52104, N'ADMINISTRATOR role not found. Run seed-user-management-permissions.sql first.', 1;
    END;

    IF @AdminRoleIsActive = 0
    BEGIN
        THROW 52105, N'ADMINISTRATOR role exists but IsActive = 0. Refusing to assign.', 1;
    END;

    SELECT TOP (1)
        @ExistingUserRoleIsActive = ur.[IsActive]
    FROM [identity].[UserRoles] ur
    WHERE ur.[UserId] = @UserId
      AND ur.[RoleId] = @AdminRoleId
    ORDER BY ur.[UserRoleId];

    IF @ExistingUserRoleIsActive IS NULL
    BEGIN
        INSERT INTO [identity].[UserRoles] (
            [UserId],
            [RoleId],
            [AssignedByUserId],
            [ExpiresAt],
            [IsActive]
        )
        VALUES (
            @UserId,
            @AdminRoleId,
            NULL,
            NULL,
            1
        );

        SET @UserRoleAction = N'inserted';
    END
    ELSE IF @ExistingUserRoleIsActive = 0
    BEGIN
        /* Development convenience: re-enable a previously deactivated assignment. */
        UPDATE [identity].[UserRoles]
        SET
            [IsActive] = 1,
            [ExpiresAt] = NULL,
            [AssignedAt] = SYSDATETIMEOFFSET()
        WHERE [UserId] = @UserId
          AND [RoleId] = @AdminRoleId
          AND [IsActive] = 0;

        SET @UserRoleAction = N'reactivated';
    END
    ELSE
    BEGIN
        SET @UserRoleAction = N'already_existed';
    END;

    /* Confirm effective assignment */
    IF NOT EXISTS (
        SELECT 1
        FROM [identity].[UserRoles] ur
        WHERE ur.[UserId] = @UserId
          AND ur.[RoleId] = @AdminRoleId
          AND ur.[IsActive] = 1
          AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
    )
    BEGIN
        THROW 52106, N'ADMINISTRATOR assignment for thilanh@tricab.com was not active after seed.', 1;
    END;

    SELECT
        N'development_only' AS [SeedScope],
        @UserRoleAction AS [UserRoleAction],
        @UserId AS [UserId],
        @UserEmail AS [EmailAddress],
        @UserPublicId AS [PublicId],
        @AdminRoleId AS [AdministratorRoleId],
        @AdminRoleCode AS [RoleCode];

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
