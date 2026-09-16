/*
================================================================================
  Additive development migration: Log Notes / Customer Notes (Phase 1).

  Creates:
    [customer].[LogNotes]
    [customer].[LogNoteAuditEvents]

  Azure SQL-compatible, idempotent guards.
  Does NOT modify legacy MySQL tc.cust_call, WordPress, 4JS, or NetSuite.
  DEVELOPMENT / additive only — do not apply to production.
  4JS sync intentionally disabled pending explicit integration approval.
  Legacy data migration is NOT run by this script.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'customer')
BEGIN
    EXEC(N'CREATE SCHEMA [customer]');
END
GO

IF OBJECT_ID(N'[customer].[LogNotes]', N'U') IS NULL
BEGIN
    CREATE TABLE [customer].[LogNotes] (
        [LogNoteId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_customer_LogNotes_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [CustomerId] BIGINT NOT NULL,
        [CustomerContactId] BIGINT NULL,
        [ContactNameSnapshot] NVARCHAR(255) NULL,
        [ContactPositionSnapshot] NVARCHAR(255) NULL,
        [InteractionTypeCode] NVARCHAR(40) NOT NULL,
        [CategoryCode] NVARCHAR(20) NOT NULL,
        [Body] NVARCHAR(MAX) NOT NULL,
        [CallResultCode] NVARCHAR(50) NULL,
        [FollowUpAt] DATETIME2(7) NULL,
        [IsAiGenerated] BIT NOT NULL
            CONSTRAINT [DF_customer_LogNotes_IsAiGenerated] DEFAULT (0),
        [CreatedByUserId] BIGINT NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_customer_LogNotes_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion] ROWVERSION NOT NULL,
        [LegacyCustCallId] INT NULL,
        [LegacySalesRepLogin] NVARCHAR(100) NULL,
        CONSTRAINT [PK_customer_LogNotes] PRIMARY KEY CLUSTERED ([LogNoteId]),
        CONSTRAINT [UQ_customer_LogNotes_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [FK_customer_LogNotes_Customers]
            FOREIGN KEY ([CustomerId]) REFERENCES [customer].[Customers] ([CustomerId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_customer_LogNotes_CustomerContacts]
            FOREIGN KEY ([CustomerContactId]) REFERENCES [customer].[CustomerContacts] ([CustomerContactId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_customer_LogNotes_CreatedByUser]
            FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [CK_customer_LogNotes_InteractionTypeCode] CHECK (
            [InteractionTypeCode] IN (N'Conference', N'Customer', N'Online Meeting', N'Phone Call')
        ),
        CONSTRAINT [CK_customer_LogNotes_CategoryCode] CHECK (
            [CategoryCode] IN (N'standard', N'niche', N'accounts')
        ),
        CONSTRAINT [CK_customer_LogNotes_Body_NotEmpty] CHECK (LEN(LTRIM(RTRIM([Body]))) > 0)
    );

    CREATE UNIQUE NONCLUSTERED INDEX [UX_customer_LogNotes_LegacyCustCallId_NotNull]
        ON [customer].[LogNotes] ([LegacyCustCallId])
        WHERE [LegacyCustCallId] IS NOT NULL;

    CREATE NONCLUSTERED INDEX [IX_customer_LogNotes_CustomerId_CreatedAtUtc]
        ON [customer].[LogNotes] ([CustomerId], [CreatedAtUtc] DESC);

    CREATE NONCLUSTERED INDEX [IX_customer_LogNotes_CreatedByUserId]
        ON [customer].[LogNotes] ([CreatedByUserId]);

    CREATE NONCLUSTERED INDEX [IX_customer_LogNotes_CreatedAtUtc]
        ON [customer].[LogNotes] ([CreatedAtUtc] DESC);

    CREATE NONCLUSTERED INDEX [IX_customer_LogNotes_InteractionTypeCode]
        ON [customer].[LogNotes] ([InteractionTypeCode]);

    CREATE NONCLUSTERED INDEX [IX_customer_LogNotes_CategoryCode]
        ON [customer].[LogNotes] ([CategoryCode]);

    CREATE NONCLUSTERED INDEX [IX_customer_LogNotes_CustomerContactId]
        ON [customer].[LogNotes] ([CustomerContactId])
        WHERE [CustomerContactId] IS NOT NULL;
END
GO

IF OBJECT_ID(N'[customer].[LogNoteAuditEvents]', N'U') IS NULL
BEGIN
    CREATE TABLE [customer].[LogNoteAuditEvents] (
        [LogNoteAuditEventId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_customer_LogNoteAuditEvents_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [ActorUserId] BIGINT NOT NULL,
        [ActionCode] NVARCHAR(80) NOT NULL,
        [LogNotePublicId] UNIQUEIDENTIFIER NOT NULL,
        [CustomerPublicId] UNIQUEIDENTIFIER NOT NULL,
        [Detail] NVARCHAR(MAX) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_customer_LogNoteAuditEvents_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_customer_LogNoteAuditEvents] PRIMARY KEY CLUSTERED ([LogNoteAuditEventId]),
        CONSTRAINT [UQ_customer_LogNoteAuditEvents_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [FK_customer_LogNoteAuditEvents_ActorUserId]
            FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [CK_customer_LogNoteAuditEvents_ActionCode_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([ActionCode]))) > 0)
    );

    CREATE NONCLUSTERED INDEX [IX_customer_LogNoteAuditEvents_CreatedAtUtc]
        ON [customer].[LogNoteAuditEvents] ([CreatedAtUtc] DESC);

    CREATE NONCLUSTERED INDEX [IX_customer_LogNoteAuditEvents_LogNotePublicId]
        ON [customer].[LogNoteAuditEvents] ([LogNotePublicId]);

    CREATE NONCLUSTERED INDEX [IX_customer_LogNoteAuditEvents_CustomerPublicId]
        ON [customer].[LogNoteAuditEvents] ([CustomerPublicId]);

    CREATE NONCLUSTERED INDEX [IX_customer_LogNoteAuditEvents_ActorUserId]
        ON [customer].[LogNoteAuditEvents] ([ActorUserId]);
END
GO
