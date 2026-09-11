/*
  Additive TrustedDevices schema for biometric return login.
  ZERO destructive changes — creates table/indexes only when missing.

  Target: tricab-mel / tc (IP-restricted tricab.ai).
  Stores CredentialHash only — never raw device credentials.
*/

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'identity')
BEGIN
    EXEC(N'CREATE SCHEMA [identity]');
END
GO

IF OBJECT_ID(N'[identity].[TrustedDevices]', N'U') IS NULL
BEGIN
    CREATE TABLE [identity].[TrustedDevices] (
        [TrustedDeviceId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_TrustedDevices_PublicId DEFAULT (NEWSEQUENTIALID()),
        [UserId] BIGINT NOT NULL,
        [CredentialHash] BINARY(32) NOT NULL,
        [PreviousCredentialHash] BINARY(32) NULL,
        [PreviousCredentialValidUntilUtc] DATETIME2(7) NULL,
        [InstallationId] UNIQUEIDENTIFIER NOT NULL,
        [Platform] NVARCHAR(20) NOT NULL,
        [DeviceName] NVARCHAR(120) NULL,
        [CredentialVersion] INT NOT NULL CONSTRAINT DF_identity_TrustedDevices_CredentialVersion DEFAULT (1),
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_TrustedDevices_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [LastUsedAtUtc] DATETIME2(7) NULL,
        [ExpiresAtUtc] DATETIME2(7) NOT NULL,
        [RevokedAtUtc] DATETIME2(7) NULL,
        [CreatedIp] NVARCHAR(45) NULL,
        [LastUsedIp] NVARCHAR(45) NULL,
        CONSTRAINT PK_identity_TrustedDevices PRIMARY KEY CLUSTERED ([TrustedDeviceId]),
        CONSTRAINT UQ_identity_TrustedDevices_PublicId UNIQUE ([PublicId]),
        CONSTRAINT UQ_identity_TrustedDevices_CredentialHash UNIQUE ([CredentialHash]),
        CONSTRAINT FK_identity_TrustedDevices_Users FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
        CONSTRAINT CK_identity_TrustedDevices_Platform CHECK ([Platform] IN (N'ios', N'android')),
        CONSTRAINT CK_identity_TrustedDevices_CreatedBeforeExpires CHECK ([ExpiresAtUtc] > [CreatedAtUtc]),
        CONSTRAINT CK_identity_TrustedDevices_RevokedAfterCreated CHECK ([RevokedAtUtc] IS NULL OR [RevokedAtUtc] >= [CreatedAtUtc]),
        CONSTRAINT CK_identity_TrustedDevices_CredentialVersion CHECK ([CredentialVersion] >= 1)
    );
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[identity].[TrustedDevices]')
      AND name = N'IX_identity_TrustedDevices_UserId_ExpiresAtUtc'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_identity_TrustedDevices_UserId_ExpiresAtUtc
        ON [identity].[TrustedDevices] ([UserId], [ExpiresAtUtc])
        WHERE [RevokedAtUtc] IS NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[identity].[TrustedDevices]')
      AND name = N'IX_identity_TrustedDevices_CredentialHash'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_identity_TrustedDevices_CredentialHash
        ON [identity].[TrustedDevices] ([CredentialHash]);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[identity].[TrustedDevices]')
      AND name = N'IX_identity_TrustedDevices_InstallationId_Active'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_identity_TrustedDevices_InstallationId_Active
        ON [identity].[TrustedDevices] ([InstallationId], [UserId])
        WHERE [RevokedAtUtc] IS NULL;
END
GO
