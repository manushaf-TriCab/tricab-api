/*
================================================================================
  Additive development migration: Identity Module foundation.

  Creates the [identity] schema and its nine tables:
    UserStatuses, Users, Roles, Permissions, RolePermissions, UserRoles,
    ExternalIdentities, GuestSessions, EmailOtpChallenges

  PROVENANCE — read before trusting this against production:
    This schema was RECONSTRUCTED from the codebase, not scripted from Azure.
    The [identity] tables were created ad-hoc against tricab-mel and their DDL
    never existed in this repo. Every column, type and constraint below is
    derived from evidence in:
      - src/Identity/Persistence/AzureSql*Repository.php (INSERT/UPDATE/SELECT)
      - database/scripts/development/seed-*.sql (explicit INSERT column lists)
      - FK targets in create-product-schema.sql and create-customer-schema.sql
      - tricab-core Identity value objects (UserStatus, SessionTokenHash)

    Columns that the application never touches may exist in Azure and are
    therefore absent here. Types are inferred from usage, so widths may be
    narrower or wider than production. Verify with scripts/verify-identity-
    schema.php once the Azure database is reachable again (it is paused until
    2026-09-01 under the free-tier allowance).

  Conventions follow create-product-schema.sql:
    - BIGINT IDENTITY clustered PKs + PublicId UNIQUEIDENTIFIER (NEWSEQUENTIALID)
    - Identity module uses DATETIMEOFFSET(7) CreatedAt / UpdatedAt
      (SYSDATETIMEOFFSET()); session/OTP tables use UTC DATETIME2(7) columns
      suffixed *Utc, matching the repository SQL.
    - Azure SQL-compatible, idempotent guards, additive only.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'identity')
    BEGIN
        EXEC(N'CREATE SCHEMA [identity]');
    END;


    /* ------------------------------------------------------------------
       [identity].[UserStatuses]
       Values constrained by TriCab\Core\Identity\Domain\ValueObject\UserStatus
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[UserStatuses]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[UserStatuses] (
            [UserStatusId] BIGINT IDENTITY(1,1) NOT NULL,
            [StatusCode] NVARCHAR(50) NOT NULL,
            [AllowsLogin] BIT NOT NULL CONSTRAINT DF_identity_UserStatuses_AllowsLogin DEFAULT (0),
            [IsActive] BIT NOT NULL CONSTRAINT DF_identity_UserStatuses_IsActive DEFAULT (1),
            CONSTRAINT PK_identity_UserStatuses PRIMARY KEY CLUSTERED ([UserStatusId]),
            CONSTRAINT UQ_identity_UserStatuses_StatusCode UNIQUE ([StatusCode])
        );
    END;


    /* ------------------------------------------------------------------
       [identity].[Users]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[Users]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[Users] (
            [UserId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_Users_PublicId DEFAULT (NEWSEQUENTIALID()),
            [EmailAddress] NVARCHAR(256) NOT NULL,
            [NormalizedEmailAddress] NVARCHAR(256) NOT NULL,
            [DisplayName] NVARCHAR(200) NULL,
            [UserStatusId] BIGINT NOT NULL,
            [LastSuccessfulLoginAt] DATETIMEOFFSET(7) NULL,
            [CreatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_Users_CreatedAt DEFAULT (SYSDATETIMEOFFSET()),
            [UpdatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_Users_UpdatedAt DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT PK_identity_Users PRIMARY KEY CLUSTERED ([UserId]),
            CONSTRAINT UQ_identity_Users_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_identity_Users_NormalizedEmailAddress UNIQUE ([NormalizedEmailAddress]),
            CONSTRAINT CK_identity_Users_EmailAddress_NotEmpty CHECK (LEN(LTRIM(RTRIM([EmailAddress]))) > 0),
            CONSTRAINT FK_identity_Users_UserStatus FOREIGN KEY ([UserStatusId])
                REFERENCES [identity].[UserStatuses] ([UserStatusId])
        );
    END;


    /* ------------------------------------------------------------------
       [identity].[Roles]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[Roles]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[Roles] (
            [RoleId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_Roles_PublicId DEFAULT (NEWSEQUENTIALID()),
            [RoleCode] NVARCHAR(100) NOT NULL,
            [RoleName] NVARCHAR(200) NOT NULL,
            [Description] NVARCHAR(500) NULL,
            [IsSystemRole] BIT NOT NULL CONSTRAINT DF_identity_Roles_IsSystemRole DEFAULT (0),
            [IsActive] BIT NOT NULL CONSTRAINT DF_identity_Roles_IsActive DEFAULT (1),
            [CreatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_Roles_CreatedAt DEFAULT (SYSDATETIMEOFFSET()),
            [UpdatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_Roles_UpdatedAt DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT PK_identity_Roles PRIMARY KEY CLUSTERED ([RoleId]),
            CONSTRAINT UQ_identity_Roles_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_identity_Roles_RoleCode UNIQUE ([RoleCode])
        );
    END;


    /* ------------------------------------------------------------------
       [identity].[Permissions]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[Permissions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[Permissions] (
            [PermissionId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_Permissions_PublicId DEFAULT (NEWSEQUENTIALID()),
            [PermissionCode] NVARCHAR(150) NOT NULL,
            [PermissionName] NVARCHAR(200) NOT NULL,
            [ModuleCode] NVARCHAR(100) NULL,
            [Description] NVARCHAR(500) NULL,
            [IsActive] BIT NOT NULL CONSTRAINT DF_identity_Permissions_IsActive DEFAULT (1),
            [CreatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_Permissions_CreatedAt DEFAULT (SYSDATETIMEOFFSET()),
            [UpdatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_Permissions_UpdatedAt DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT PK_identity_Permissions PRIMARY KEY CLUSTERED ([PermissionId]),
            CONSTRAINT UQ_identity_Permissions_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_identity_Permissions_PermissionCode UNIQUE ([PermissionCode])
        );
    END;


    /* ------------------------------------------------------------------
       [identity].[RolePermissions]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[RolePermissions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[RolePermissions] (
            [RolePermissionId] BIGINT IDENTITY(1,1) NOT NULL,
            [RoleId] BIGINT NOT NULL,
            [PermissionId] BIGINT NOT NULL,
            [IsActive] BIT NOT NULL CONSTRAINT DF_identity_RolePermissions_IsActive DEFAULT (1),
            [AssignedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_RolePermissions_AssignedAt DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT PK_identity_RolePermissions PRIMARY KEY CLUSTERED ([RolePermissionId]),
            CONSTRAINT UQ_identity_RolePermissions_Role_Permission UNIQUE ([RoleId], [PermissionId]),
            CONSTRAINT FK_identity_RolePermissions_Role FOREIGN KEY ([RoleId])
                REFERENCES [identity].[Roles] ([RoleId]),
            CONSTRAINT FK_identity_RolePermissions_Permission FOREIGN KEY ([PermissionId])
                REFERENCES [identity].[Permissions] ([PermissionId])
        );
    END;


    /* ------------------------------------------------------------------
       [identity].[UserRoles]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[UserRoles]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[UserRoles] (
            [UserRoleId] BIGINT IDENTITY(1,1) NOT NULL,
            [UserId] BIGINT NOT NULL,
            [RoleId] BIGINT NOT NULL,
            [AssignedByUserId] BIGINT NULL,
            [AssignedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_UserRoles_AssignedAt DEFAULT (SYSDATETIMEOFFSET()),
            [ExpiresAt] DATETIMEOFFSET(7) NULL,
            [IsActive] BIT NOT NULL CONSTRAINT DF_identity_UserRoles_IsActive DEFAULT (1),
            CONSTRAINT PK_identity_UserRoles PRIMARY KEY CLUSTERED ([UserRoleId]),
            CONSTRAINT UQ_identity_UserRoles_User_Role UNIQUE ([UserId], [RoleId]),
            CONSTRAINT FK_identity_UserRoles_User FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_identity_UserRoles_Role FOREIGN KEY ([RoleId])
                REFERENCES [identity].[Roles] ([RoleId]),
            CONSTRAINT FK_identity_UserRoles_AssignedBy FOREIGN KEY ([AssignedByUserId])
                REFERENCES [identity].[Users] ([UserId])
        );
    END;


    /* ------------------------------------------------------------------
       [identity].[ExternalIdentities]
       ProviderCode is matched with both underscore and hyphen spellings by
       AzureSqlUserRepository::findByExternalIdentity.
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[ExternalIdentities]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[ExternalIdentities] (
            [ExternalIdentityId] BIGINT IDENTITY(1,1) NOT NULL,
            [UserId] BIGINT NOT NULL,
            [ProviderCode] NVARCHAR(50) NOT NULL,
            [TenantIdentifier] NVARCHAR(100) NOT NULL,
            [SubjectIdentifier] NVARCHAR(200) NOT NULL,
            [EmailClaim] NVARCHAR(256) NULL,
            [NormalizedEmailClaim] NVARCHAR(256) NULL,
            [IsActive] BIT NOT NULL CONSTRAINT DF_identity_ExternalIdentities_IsActive DEFAULT (1),
            [LastAuthenticatedAt] DATETIMEOFFSET(7) NULL,
            [UpdatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_ExternalIdentities_UpdatedAt DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT PK_identity_ExternalIdentities PRIMARY KEY CLUSTERED ([ExternalIdentityId]),
            CONSTRAINT UQ_identity_ExternalIdentities_Provider_Tenant_Subject
                UNIQUE ([ProviderCode], [TenantIdentifier], [SubjectIdentifier]),
            CONSTRAINT FK_identity_ExternalIdentities_User FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId])
        );
    END;


    /* ------------------------------------------------------------------
       [identity].[GuestSessions]
       SessionTokenHash is bound as SqlBinary (SHA-256 => 32 bytes).
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[GuestSessions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[GuestSessions] (
            [GuestSessionId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_GuestSessions_PublicId DEFAULT (NEWSEQUENTIALID()),
            [UserId] BIGINT NOT NULL,
            [SessionTokenHash] VARBINARY(32) NOT NULL,
            [JwtId] NVARCHAR(64) NULL,
            [IssuedAtUtc] DATETIME2(7) NOT NULL,
            [ExpiresAtUtc] DATETIME2(7) NOT NULL,
            [RevokedAtUtc] DATETIME2(7) NULL,
            [LastUsedAtUtc] DATETIME2(7) NULL,
            [CreatedIpAddress] NVARCHAR(45) NULL,
            [CreatedUserAgent] NVARCHAR(400) NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_GuestSessions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT PK_identity_GuestSessions PRIMARY KEY CLUSTERED ([GuestSessionId]),
            CONSTRAINT UQ_identity_GuestSessions_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_identity_GuestSessions_SessionTokenHash UNIQUE ([SessionTokenHash]),
            CONSTRAINT CK_identity_GuestSessions_ExpiryAfterIssue CHECK ([ExpiresAtUtc] >= [IssuedAtUtc]),
            CONSTRAINT FK_identity_GuestSessions_User FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId])
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'[identity].[GuestSessions]')
          AND name = N'IX_identity_GuestSessions_User_Active'
    )
    BEGIN
        CREATE NONCLUSTERED INDEX IX_identity_GuestSessions_User_Active
            ON [identity].[GuestSessions] ([UserId], [ExpiresAtUtc])
            WHERE [RevokedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [identity].[EmailOtpChallenges]
       OtpHash is read back as a string (AzureSqlValueMapper::stringValue).
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[identity].[EmailOtpChallenges]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[EmailOtpChallenges] (
            [EmailOtpChallengeId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_EmailOtpChallenges_PublicId DEFAULT (NEWSEQUENTIALID()),
            [EmailAddress] NVARCHAR(256) NOT NULL,
            [OtpHash] NVARCHAR(255) NOT NULL,
            [PurposeCode] NVARCHAR(50) NOT NULL,
            [ExpiresAtUtc] DATETIME2(7) NOT NULL,
            [AttemptCount] INT NOT NULL CONSTRAINT DF_identity_EmailOtpChallenges_AttemptCount DEFAULT (0),
            [MaximumAttempts] INT NOT NULL CONSTRAINT DF_identity_EmailOtpChallenges_MaximumAttempts DEFAULT (5),
            [ConsumedAtUtc] DATETIME2(7) NULL,
            [InvalidatedAtUtc] DATETIME2(7) NULL,
            [RequestedIpAddress] NVARCHAR(45) NULL,
            [RequestedUserAgent] NVARCHAR(400) NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_EmailOtpChallenges_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT PK_identity_EmailOtpChallenges PRIMARY KEY CLUSTERED ([EmailOtpChallengeId]),
            CONSTRAINT UQ_identity_EmailOtpChallenges_PublicId UNIQUE ([PublicId]),
            CONSTRAINT CK_identity_EmailOtpChallenges_AttemptCount CHECK ([AttemptCount] >= 0)
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'[identity].[EmailOtpChallenges]')
          AND name = N'IX_identity_EmailOtpChallenges_Email_Purpose'
    )
    BEGIN
        CREATE NONCLUSTERED INDEX IX_identity_EmailOtpChallenges_Email_Purpose
            ON [identity].[EmailOtpChallenges] ([EmailAddress], [PurposeCode], [ExpiresAtUtc])
            WHERE [ConsumedAtUtc] IS NULL AND [InvalidatedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       Seed [identity].[UserStatuses]
       Codes must match TriCab\Core\Identity\Domain\ValueObject\UserStatus.
       ------------------------------------------------------------------ */
    MERGE [identity].[UserStatuses] AS target
    USING (VALUES
        (N'active',   CAST(1 AS BIT)),
        (N'pending',  CAST(0 AS BIT)),
        (N'disabled', CAST(0 AS BIT))
    ) AS source ([StatusCode], [AllowsLogin])
        ON target.[StatusCode] = source.[StatusCode]
    WHEN NOT MATCHED BY TARGET THEN
        INSERT ([StatusCode], [AllowsLogin], [IsActive])
        VALUES (source.[StatusCode], source.[AllowsLogin], 1);

    COMMIT TRANSACTION;
    PRINT N'identity schema created/verified.';
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;
    THROW;
END CATCH;
