/*
================================================================================
  Additive development migration: Guest Passkey (WebAuthn) tables.

  Creates:
    [identity].[UserWebAuthnHandles]
    [identity].[UserPasskeys]
    [identity].[GuestPasskeySetupTokens]
    [identity].[PasskeyChallenges]
    [identity].[PasskeyRegistrationSessions]

  Additive only. Does not alter ExternalIdentities rows or existing providers.
  Stores public WebAuthn credentials only — never private keys or biometrics.
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

    IF OBJECT_ID(N'[identity].[UserWebAuthnHandles]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[UserWebAuthnHandles] (
            [UserId] BIGINT NOT NULL,
            [UserHandle] VARBINARY(32) NOT NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_UserWebAuthnHandles_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT PK_identity_UserWebAuthnHandles PRIMARY KEY CLUSTERED ([UserId]),
            CONSTRAINT UQ_identity_UserWebAuthnHandles_UserHandle UNIQUE ([UserHandle]),
            CONSTRAINT FK_identity_UserWebAuthnHandles_User FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId])
        );
    END;

    IF OBJECT_ID(N'[identity].[UserPasskeys]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[UserPasskeys] (
            [UserPasskeyId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_UserPasskeys_PublicId DEFAULT (NEWSEQUENTIALID()),
            [UserId] BIGINT NOT NULL,
            [CredentialId] NVARCHAR(1024) NOT NULL,
            [PublicKey] NVARCHAR(MAX) NOT NULL,
            [SignCount] BIGINT NOT NULL CONSTRAINT DF_identity_UserPasskeys_SignCount DEFAULT (0),
            [Transports] NVARCHAR(200) NULL,
            [Aaguid] NVARCHAR(64) NULL,
            [DeviceLabel] NVARCHAR(120) NOT NULL CONSTRAINT DF_identity_UserPasskeys_DeviceLabel DEFAULT (N'Passkey'),
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_UserPasskeys_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [LastUsedAtUtc] DATETIME2(7) NULL,
            [IsActive] BIT NOT NULL CONSTRAINT DF_identity_UserPasskeys_IsActive DEFAULT (1),
            CONSTRAINT PK_identity_UserPasskeys PRIMARY KEY CLUSTERED ([UserPasskeyId]),
            CONSTRAINT UQ_identity_UserPasskeys_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_identity_UserPasskeys_CredentialId UNIQUE ([CredentialId]),
            CONSTRAINT FK_identity_UserPasskeys_User FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId])
        );
        CREATE INDEX IX_identity_UserPasskeys_UserId ON [identity].[UserPasskeys] ([UserId]) WHERE [IsActive] = 1;
    END;

    IF OBJECT_ID(N'[identity].[GuestPasskeySetupTokens]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[GuestPasskeySetupTokens] (
            [GuestPasskeySetupTokenId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_GuestPasskeySetupTokens_PublicId DEFAULT (NEWSEQUENTIALID()),
            [UserId] BIGINT NOT NULL,
            [TokenHash] VARBINARY(32) NOT NULL,
            [ExpiresAtUtc] DATETIME2(7) NOT NULL,
            [ConsumedAtUtc] DATETIME2(7) NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_GuestPasskeySetupTokens_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [RequestedIpHash] VARBINARY(32) NULL,
            CONSTRAINT PK_identity_GuestPasskeySetupTokens PRIMARY KEY CLUSTERED ([GuestPasskeySetupTokenId]),
            CONSTRAINT UQ_identity_GuestPasskeySetupTokens_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_identity_GuestPasskeySetupTokens_TokenHash UNIQUE ([TokenHash]),
            CONSTRAINT FK_identity_GuestPasskeySetupTokens_User FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId])
        );
        CREATE INDEX IX_identity_GuestPasskeySetupTokens_UserId ON [identity].[GuestPasskeySetupTokens] ([UserId]);
    END;

    IF OBJECT_ID(N'[identity].[PasskeyChallenges]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[PasskeyChallenges] (
            [PasskeyChallengeId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_PasskeyChallenges_PublicId DEFAULT (NEWSEQUENTIALID()),
            [UserId] BIGINT NULL,
            [RegistrationSessionPublicId] UNIQUEIDENTIFIER NULL,
            [PurposeCode] NVARCHAR(40) NOT NULL,
            [Challenge] VARBINARY(64) NOT NULL,
            [ExpiresAtUtc] DATETIME2(7) NOT NULL,
            [ConsumedAtUtc] DATETIME2(7) NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_PasskeyChallenges_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT PK_identity_PasskeyChallenges PRIMARY KEY CLUSTERED ([PasskeyChallengeId]),
            CONSTRAINT UQ_identity_PasskeyChallenges_PublicId UNIQUE ([PublicId]),
            CONSTRAINT CK_identity_PasskeyChallenges_Purpose CHECK ([PurposeCode] IN (N'registration', N'authentication'))
        );
        CREATE INDEX IX_identity_PasskeyChallenges_Session ON [identity].[PasskeyChallenges] ([RegistrationSessionPublicId]);
    END;

    IF OBJECT_ID(N'[identity].[PasskeyRegistrationSessions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[PasskeyRegistrationSessions] (
            [PasskeyRegistrationSessionId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_PasskeyRegistrationSessions_PublicId DEFAULT (NEWSEQUENTIALID()),
            [UserId] BIGINT NOT NULL,
            [SetupTokenPublicId] UNIQUEIDENTIFIER NOT NULL,
            [SessionTokenHash] VARBINARY(32) NOT NULL,
            [ExpiresAtUtc] DATETIME2(7) NOT NULL,
            [ConsumedAtUtc] DATETIME2(7) NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_PasskeyRegistrationSessions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT PK_identity_PasskeyRegistrationSessions PRIMARY KEY CLUSTERED ([PasskeyRegistrationSessionId]),
            CONSTRAINT UQ_identity_PasskeyRegistrationSessions_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_identity_PasskeyRegistrationSessions_SessionTokenHash UNIQUE ([SessionTokenHash]),
            CONSTRAINT FK_identity_PasskeyRegistrationSessions_User FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId])
        );
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
