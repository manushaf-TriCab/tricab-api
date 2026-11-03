/*
================================================================================
  Platform groups (Tri-Task company plan, Phase 1) — [identity].[Groups] and
  [identity].[GroupMembers].

  Groups live in [identity], not in any one module, so Tri-Task workspaces, RFQ,
  Onboarding and later modules can reuse them.

    SourceCode 'platform'  Created by an Administrator; members added by hand.
    SourceCode 'entra'     An Entra mail group an Administrator linked; members
                           follow Entra on each sync and are read-only here.

  GroupMembers.SourceCode says how a person got in ('platform' or 'entra'); the
  sync only ever adds or removes 'entra' rows.

  IsUnlinked: an Entra group that was unlinked (by an Administrator, or because
  Entra no longer has it). It is kept, with its members, so access granted through
  it is not silently lost. Linking it again clears the flag.

  - Idempotent / safe to re-run. Additive only. Creates no users, grants nothing.
  - Run after create-identity-schema.sql. Run BEFORE the API that uses it, and
    seed-identity-groups-permissions.sql for the Administrator permission.
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

    IF OBJECT_ID(N'[identity].[Groups]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[Groups] (
            [GroupId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_Groups_PublicId DEFAULT (NEWSEQUENTIALID()),
            [Name] NVARCHAR(200) NOT NULL,
            [NormalizedName] NVARCHAR(200) NOT NULL,
            [Description] NVARCHAR(500) NULL,
            [SourceCode] NVARCHAR(20) NOT NULL,
            [EntraGroupId] NVARCHAR(64) NULL,
            [EntraMail] NVARCHAR(256) NULL,
            [IsUnlinked] BIT NOT NULL CONSTRAINT DF_identity_Groups_IsUnlinked DEFAULT (0),
            [UnlinkedAt] DATETIMEOFFSET(7) NULL,
            [LastSyncedAt] DATETIMEOFFSET(7) NULL,
            [LastSyncOutcome] NVARCHAR(40) NULL,
            [LastSyncMessage] NVARCHAR(400) NULL,
            [CreatedByUserId] BIGINT NULL,
            [CreatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_Groups_CreatedAt DEFAULT (SYSDATETIMEOFFSET()),
            [UpdatedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_Groups_UpdatedAt DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT PK_identity_Groups PRIMARY KEY CLUSTERED ([GroupId]),
            CONSTRAINT UQ_identity_Groups_PublicId UNIQUE ([PublicId]),
            CONSTRAINT CK_identity_Groups_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([Name]))) > 0),
            CONSTRAINT CK_identity_Groups_SourceCode CHECK ([SourceCode] IN (N'entra', N'platform')),
            CONSTRAINT CK_identity_Groups_Entra_HasGroupId CHECK (
                ([SourceCode] = N'entra' AND [EntraGroupId] IS NOT NULL)
                OR ([SourceCode] = N'platform' AND [EntraGroupId] IS NULL)),
            CONSTRAINT FK_identity_Groups_CreatedBy FOREIGN KEY ([CreatedByUserId])
                REFERENCES [identity].[Users] ([UserId])
        );
    END;

    /* One Entra group is linked once (re-linking reuses the row). */
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[identity].[Groups]') AND name = N'UX_identity_Groups_EntraGroupId')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_identity_Groups_EntraGroupId
            ON [identity].[Groups] ([EntraGroupId])
            WHERE [EntraGroupId] IS NOT NULL;
    END;

    /* Platform group names are unique (case-insensitive via NormalizedName); Entra names can repeat. */
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[identity].[Groups]') AND name = N'UX_identity_Groups_PlatformName')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_identity_Groups_PlatformName
            ON [identity].[Groups] ([NormalizedName])
            WHERE [SourceCode] = N'platform';
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[identity].[Groups]') AND name = N'IX_identity_Groups_Source_Unlinked')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_identity_Groups_Source_Unlinked
            ON [identity].[Groups] ([SourceCode], [IsUnlinked]);
    END;

    IF OBJECT_ID(N'[identity].[GroupMembers]', N'U') IS NULL
    BEGIN
        CREATE TABLE [identity].[GroupMembers] (
            [GroupMemberId] BIGINT IDENTITY(1,1) NOT NULL,
            [GroupId] BIGINT NOT NULL,
            [UserId] BIGINT NOT NULL,
            [SourceCode] NVARCHAR(20) NOT NULL,
            [AddedByUserId] BIGINT NULL,
            [AddedAt] DATETIMEOFFSET(7) NOT NULL CONSTRAINT DF_identity_GroupMembers_AddedAt DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT PK_identity_GroupMembers PRIMARY KEY CLUSTERED ([GroupMemberId]),
            CONSTRAINT UQ_identity_GroupMembers_Group_User UNIQUE ([GroupId], [UserId]),
            CONSTRAINT CK_identity_GroupMembers_SourceCode CHECK ([SourceCode] IN (N'entra', N'platform')),
            CONSTRAINT FK_identity_GroupMembers_Group FOREIGN KEY ([GroupId])
                REFERENCES [identity].[Groups] ([GroupId]),
            CONSTRAINT FK_identity_GroupMembers_User FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_identity_GroupMembers_AddedBy FOREIGN KEY ([AddedByUserId])
                REFERENCES [identity].[Users] ([UserId])
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[identity].[GroupMembers]') AND name = N'IX_identity_GroupMembers_User')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_identity_GroupMembers_User
            ON [identity].[GroupMembers] ([UserId]);
    END;

    COMMIT TRANSACTION;
    PRINT N'identity.Groups and identity.GroupMembers created/verified.';
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;
    THROW;
END CATCH;
