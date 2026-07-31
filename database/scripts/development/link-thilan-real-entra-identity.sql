/*
================================================================================
  Real Microsoft Entra identity mapping for local integration testing.
  Do not replace oid with email, sub, or development placeholder values.

  Links the real Microsoft Entra object ID to the existing TriCab user
  thilanh@tricab.com without removing the development mapping:

    ProviderCode      = microsoft-entra
    TenantIdentifier  = f0ec33ca-6286-41fd-a659-e288f9934dda
    SubjectIdentifier = 8ed7f6be-8637-4417-8222-bfbc2b1ffc1d  (real oid)
    EmailClaim        = thilanh@tricab.com

  Do not deploy this seed to production as a substitute for proper
  provisioning workflows.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @TargetEmail NVARCHAR(320) = N'thilanh@tricab.com';
    DECLARE @ProviderCode NVARCHAR(50) = N'microsoft-entra';
    DECLARE @TenantIdentifier NVARCHAR(100) = N'f0ec33ca-6286-41fd-a659-e288f9934dda';
    DECLARE @SubjectIdentifier NVARCHAR(200) = N'8ed7f6be-8637-4417-8222-bfbc2b1ffc1d';
    DECLARE @EmailClaim NVARCHAR(320) = N'thilanh@tricab.com';
    DECLARE @NormalizedEmailClaim NVARCHAR(320) = N'THILANH@TRICAB.COM';

    DECLARE @MatchCount INT;
    DECLARE @UserId BIGINT;
    DECLARE @UserEmail NVARCHAR(320);
    DECLARE @AlreadyLinked BIT = 0;
    DECLARE @LinkedToOtherUser BIT = 0;

    -- Validate real oid is a UUID (8-4-4-4-12 hex).
    IF @SubjectIdentifier NOT LIKE '[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]-[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]-[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]-[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]-[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]'
    BEGIN
        THROW 50010, N'SubjectIdentifier (oid) is not a valid UUID.', 1;
    END;

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

    IF EXISTS (
        SELECT 1
        FROM [identity].[ExternalIdentities] e
        WHERE e.[ProviderCode] = @ProviderCode
          AND e.[TenantIdentifier] = @TenantIdentifier
          AND e.[SubjectIdentifier] = @SubjectIdentifier
          AND e.[UserId] <> @UserId
    )
    BEGIN
        THROW 50003, N'This Microsoft Entra identity is already linked to another TriCab user.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM [identity].[ExternalIdentities] e
        WHERE e.[ProviderCode] = @ProviderCode
          AND e.[TenantIdentifier] = @TenantIdentifier
          AND e.[SubjectIdentifier] = @SubjectIdentifier
          AND e.[UserId] = @UserId
    )
    BEGIN
        SET @AlreadyLinked = 1;

        UPDATE e
        SET
            e.[EmailClaim] = @EmailClaim,
            e.[NormalizedEmailClaim] = @NormalizedEmailClaim,
            e.[IsActive] = 1,
            e.[UpdatedAt] = SYSDATETIMEOFFSET()
        FROM [identity].[ExternalIdentities] e
        WHERE e.[ProviderCode] = @ProviderCode
          AND e.[TenantIdentifier] = @TenantIdentifier
          AND e.[SubjectIdentifier] = @SubjectIdentifier
          AND e.[UserId] = @UserId
          AND (
                ISNULL(e.[EmailClaim], N'') <> @EmailClaim
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

    -- Verification: real Entra mapping + confirm development mapping still present.
    SELECT
        e.[ExternalIdentityId],
        e.[UserId],
        u.[EmailAddress],
        e.[ProviderCode],
        e.[TenantIdentifier],
        e.[SubjectIdentifier],
        e.[EmailClaim],
        e.[IsActive],
        CASE
            WHEN e.[TenantIdentifier] = N'development-tenant' THEN N'development'
            WHEN e.[SubjectIdentifier] = @SubjectIdentifier THEN N'real_entra'
            ELSE N'other'
        END AS [MappingKind],
        CASE WHEN @AlreadyLinked = 1 THEN N'already_present_or_updated' ELSE N'inserted' END AS [LinkAction]
    FROM [identity].[ExternalIdentities] e
    INNER JOIN [identity].[Users] u
        ON u.[UserId] = e.[UserId]
    WHERE e.[ProviderCode] = @ProviderCode
      AND e.[UserId] = @UserId
      AND (
            (e.[TenantIdentifier] = @TenantIdentifier AND e.[SubjectIdentifier] = @SubjectIdentifier)
         OR (e.[TenantIdentifier] = N'development-tenant' AND e.[SubjectIdentifier] = N'development-thilan')
          )
    ORDER BY e.[ExternalIdentityId];

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
