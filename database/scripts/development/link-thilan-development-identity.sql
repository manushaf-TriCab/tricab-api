/*
================================================================================
  Local development identity only.
  Do not deploy this seed to production.

  Links a non-production Microsoft Entra development mapping to the existing
  TriCab user thilanh@tricab.com:

    ProviderCode      = microsoft-entra
    TenantIdentifier  = development-tenant
    SubjectIdentifier = development-thilan
    EmailClaim        = thilanh@tricab.com

  These identifiers are development headers / local stubs — never treat them
  as a real production Microsoft Entra tenant, object ID, or subject.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @TargetEmail NVARCHAR(320) = N'thilanh@tricab.com';
    DECLARE @ProviderCode NVARCHAR(50) = N'microsoft-entra';
    DECLARE @TenantIdentifier NVARCHAR(100) = N'development-tenant';
    DECLARE @SubjectIdentifier NVARCHAR(200) = N'development-thilan';
    DECLARE @EmailClaim NVARCHAR(320) = N'thilanh@tricab.com';
    DECLARE @NormalizedEmailClaim NVARCHAR(320) = LOWER(LTRIM(RTRIM(@EmailClaim)));

    DECLARE @MatchCount INT;
    DECLARE @UserId BIGINT;
    DECLARE @UserEmail NVARCHAR(320);
    DECLARE @AlreadyLinked BIT = 0;

    SELECT
        @MatchCount = COUNT(*),
        @UserId = MAX(u.[UserId]),
        @UserEmail = MAX(u.[EmailAddress])
    FROM [identity].[Users] u
    INNER JOIN [identity].[UserStatuses] s
        ON s.[UserStatusId] = u.[UserStatusId]
    WHERE u.[NormalizedEmailAddress] = LOWER(LTRIM(RTRIM(@TargetEmail)))
      AND s.[StatusCode] = N'active'
      AND s.[AllowsLogin] = 1
      AND s.[IsActive] = 1;

    IF @MatchCount = 0
    BEGIN
        THROW 50001, N'No active TriCab user found for thilanh@tricab.com.', 1;
    END;

    IF @MatchCount > 1
    BEGIN
        THROW 50002, N'More than one active TriCab user found for thilanh@tricab.com.', 1;
    END;

    /*
      TriCab Core UserPublicId requires an RFC-style UUID:
        version nibble 1-8, variant nibble 8|9|a|b
      SQL Server NEWSEQUENTIALID() values can fail that check (e.g. version F).
      Repair development rows only so /api/v1/me can hydrate without changing Core.
    */
    DECLARE @CurrentPublicId NVARCHAR(36) = LOWER(CONVERT(NVARCHAR(36), (
        SELECT u.[PublicId]
        FROM [identity].[Users] u
        WHERE u.[UserId] = @UserId
    )));
    DECLARE @VersionNibble NCHAR(1) = SUBSTRING(@CurrentPublicId, 15, 1);
    DECLARE @VariantNibble NCHAR(1) = SUBSTRING(@CurrentPublicId, 20, 1);

    IF @CurrentPublicId IS NULL
       OR LEN(@CurrentPublicId) <> 36
       OR @VersionNibble NOT LIKE '[1-8]'
       OR @VariantNibble NOT LIKE '[89ab]'
    BEGIN
        UPDATE [identity].[Users]
        SET
            [PublicId] = '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            [UpdatedAt] = SYSDATETIMEOFFSET()
        WHERE [UserId] = @UserId
          AND NOT EXISTS (
              SELECT 1
              FROM [identity].[Users] other
              WHERE other.[PublicId] = '7c9e6679-7425-40de-944b-e07fc1f90ae7'
                AND other.[UserId] <> @UserId
          );
    END;

    IF EXISTS (
        SELECT 1
        FROM [identity].[ExternalIdentities] e
        WHERE e.[ProviderCode] = @ProviderCode
          AND e.[TenantIdentifier] = @TenantIdentifier
          AND e.[SubjectIdentifier] = @SubjectIdentifier
    )
    BEGIN
        SET @AlreadyLinked = 1;

        -- Ensure the existing mapping points at the expected user and is active.
        UPDATE e
        SET
            e.[UserId] = @UserId,
            e.[EmailClaim] = @EmailClaim,
            e.[NormalizedEmailClaim] = @NormalizedEmailClaim,
            e.[IsActive] = 1,
            e.[UpdatedAt] = SYSDATETIMEOFFSET()
        FROM [identity].[ExternalIdentities] e
        WHERE e.[ProviderCode] = @ProviderCode
          AND e.[TenantIdentifier] = @TenantIdentifier
          AND e.[SubjectIdentifier] = @SubjectIdentifier
          AND (
                e.[UserId] <> @UserId
             OR ISNULL(e.[EmailClaim], N'') <> @EmailClaim
             OR ISNULL(e.[NormalizedEmailClaim], N'') <> @NormalizedEmailClaim
             OR e.[IsActive] <> 1
          );
    END
    ELSE
    BEGIN
        INSERT INTO [identity].[ExternalIdentities] (
            [UserId],
            [ProviderCode],
            [TenantIdentifier],
            [SubjectIdentifier],
            [EmailClaim],
            [NormalizedEmailClaim],
            [IsActive]
        )
        VALUES (
            @UserId,
            @ProviderCode,
            @TenantIdentifier,
            @SubjectIdentifier,
            @EmailClaim,
            @NormalizedEmailClaim,
            1
        );
    END;

    -- Final verification result
    SELECT
        e.[ExternalIdentityId],
        e.[UserId] AS [LinkedUserId],
        CONVERT(NVARCHAR(36), u.[PublicId]) AS [UserPublicId],
        u.[EmailAddress] AS [UserEmail],
        e.[ProviderCode],
        e.[TenantIdentifier],
        e.[SubjectIdentifier],
        e.[EmailClaim],
        e.[IsActive],
        CASE WHEN @AlreadyLinked = 1 THEN N'already_present_or_updated' ELSE N'inserted' END AS [LinkAction]
    FROM [identity].[ExternalIdentities] e
    INNER JOIN [identity].[Users] u
        ON u.[UserId] = e.[UserId]
    WHERE e.[ProviderCode] = @ProviderCode
      AND e.[TenantIdentifier] = @TenantIdentifier
      AND e.[SubjectIdentifier] = @SubjectIdentifier;

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
