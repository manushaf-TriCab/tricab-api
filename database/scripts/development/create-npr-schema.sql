/*
================================================================================
  [npr] schema: New Product Requests (plan NPR-TSD-MIGRATION-PLAN, N1.1).

  Converted from the npr-portal SQLite schema (api/db/schema.sql). The
  npr_cablefit_* tables are NOT here; they belong to the CableFit work.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's approval. Idempotent / safe to re-run.

  Conventions (as create-onboarding-schema.sql): PascalCase columns, BIGINT
  IDENTITY keys, UNIQUEIDENTIFIER PublicId for anything a URL names, DATETIME2(3)
  UTC, ROWVERSION on requests, people held as [identity].[Users].[UserId]
  (BIGINT, FK) plus a snapshot of name and email taken at the time, JSON in
  NVARCHAR(MAX) with ISJSON checks. Attachments hold a Blob storage key, never a
  disk path. All foreign keys stay inside [npr] except to [identity].[Users].

  Status: draft, awaiting_manager, returned_to_sales, with_tsd,
  awaiting_screening, screening_completed, cancelled.
  ScreeningOutcome (independent of status): approved, deferred, rejected.

  ManagerRoutes: one row per branch route or delegate override. Overlapping
  validity windows for the same branch are rejected in application code, not by
  a constraint.
================================================================================
*/

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'npr')
BEGIN
    EXEC(N'CREATE SCHEMA [npr]');
END
GO

IF OBJECT_ID(N'[npr].[Requests]', N'U') IS NULL
BEGIN
    CREATE TABLE [npr].[Requests] (
        [RequestId]                  BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                   UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_npr_Requests_PublicId] DEFAULT (NEWID()),
        [RequestNumber]              NVARCHAR(32) NOT NULL,
        [CurrentRevision]            INT NOT NULL CONSTRAINT [DF_npr_Requests_CurrentRevision] DEFAULT (1),
        [Status]                     NVARCHAR(32) NOT NULL CONSTRAINT [DF_npr_Requests_Status] DEFAULT (N'draft'),
        [ScreeningOutcome]           NVARCHAR(32) NULL,
        [Title]                      NVARCHAR(255) NOT NULL CONSTRAINT [DF_npr_Requests_Title] DEFAULT (N''),
        [CustomerName]               NVARCHAR(255) NOT NULL CONSTRAINT [DF_npr_Requests_CustomerName] DEFAULT (N''),
        [RequesterUserId]            BIGINT NOT NULL,
        [RequesterEmail]             NVARCHAR(255) NOT NULL,
        [RequesterName]              NVARCHAR(255) NOT NULL,
        [RequesterBranch]            NVARCHAR(64) NOT NULL,
        [AssignedManagerUserId]      BIGINT NULL,
        [AssignedManagerEmail]       NVARCHAR(255) NULL,
        [AssignedManagerName]        NVARCHAR(255) NULL,
        [TsdOwnerUserId]             BIGINT NULL,
        [TsdOwnerEmail]              NVARCHAR(255) NULL,
        [TsdOwnerName]               NVARCHAR(255) NULL,
        [ScreeningSignatoryUserId]   BIGINT NULL,
        [ScreeningSignatoryEmail]    NVARCHAR(255) NULL,
        [ScreeningSignatoryName]     NVARCHAR(255) NULL,
        [UrgencyLevel]               NVARCHAR(32) NOT NULL CONSTRAINT [DF_npr_Requests_UrgencyLevel] DEFAULT (N'standard'),
        [CreatedAt]                  DATETIME2(3) NOT NULL CONSTRAINT [DF_npr_Requests_CreatedAt] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAt]                  DATETIME2(3) NOT NULL CONSTRAINT [DF_npr_Requests_UpdatedAt] DEFAULT (SYSUTCDATETIME()),
        [SubmittedAt]                DATETIME2(3) NULL,
        [EndorsedAt]                 DATETIME2(3) NULL,
        [TsdReceivedAt]              DATETIME2(3) NULL,
        [TsdAcknowledgedAt]          DATETIME2(3) NULL,
        [TsdAcknowledgedByName]      NVARCHAR(255) NULL,
        [TsdAcknowledgedByUserId]    BIGINT NULL,
        [ScreeningDecidedAt]         DATETIME2(3) NULL,
        [ScreeningRationale]         NVARCHAR(MAX) NULL,
        [ScreeningConditions]        NVARCHAR(MAX) NULL,
        [RowVersion]                 ROWVERSION NOT NULL,
        CONSTRAINT [PK_npr_Requests] PRIMARY KEY CLUSTERED ([RequestId]),
        CONSTRAINT [UQ_npr_Requests_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_npr_Requests_RequestNumber] UNIQUE ([RequestNumber]),
        CONSTRAINT [CK_npr_Requests_Status] CHECK ([Status] IN
            (N'draft', N'awaiting_manager', N'returned_to_sales', N'with_tsd',
             N'awaiting_screening', N'screening_completed', N'cancelled')),
        CONSTRAINT [CK_npr_Requests_ScreeningOutcome] CHECK
            ([ScreeningOutcome] IS NULL OR [ScreeningOutcome] IN (N'approved', N'deferred', N'rejected')),
        CONSTRAINT [FK_npr_Requests_Requester] FOREIGN KEY ([RequesterUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_npr_Requests_AssignedManager] FOREIGN KEY ([AssignedManagerUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_npr_Requests_TsdOwner] FOREIGN KEY ([TsdOwnerUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_npr_Requests_ScreeningSignatory] FOREIGN KEY ([ScreeningSignatoryUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_npr_Requests_TsdAcknowledgedBy] FOREIGN KEY ([TsdAcknowledgedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_Requests_Requester' AND object_id = OBJECT_ID(N'[npr].[Requests]'))
    CREATE INDEX [IX_npr_Requests_Requester] ON [npr].[Requests] ([RequesterUserId]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_Requests_Manager' AND object_id = OBJECT_ID(N'[npr].[Requests]'))
    CREATE INDEX [IX_npr_Requests_Manager] ON [npr].[Requests] ([AssignedManagerUserId]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_Requests_Status' AND object_id = OBJECT_ID(N'[npr].[Requests]'))
    CREATE INDEX [IX_npr_Requests_Status] ON [npr].[Requests] ([Status]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_Requests_Branch' AND object_id = OBJECT_ID(N'[npr].[Requests]'))
    CREATE INDEX [IX_npr_Requests_Branch] ON [npr].[Requests] ([RequesterBranch]);
GO

IF OBJECT_ID(N'[npr].[RequestRevisions]', N'U') IS NULL
BEGIN
    CREATE TABLE [npr].[RequestRevisions] (
        [RevisionId]       BIGINT IDENTITY(1,1) NOT NULL,
        [RequestId]        BIGINT NOT NULL,
        [RevisionNumber]   INT NOT NULL,
        [AnswersJson]      NVARCHAR(MAX) NOT NULL,
        [VariantsJson]     NVARCHAR(MAX) NOT NULL,
        [CreatedByUserId]  BIGINT NOT NULL,
        [CreatedByEmail]   NVARCHAR(255) NOT NULL,
        [CreatedByName]    NVARCHAR(255) NOT NULL,
        [CreatedAt]        DATETIME2(3) NOT NULL CONSTRAINT [DF_npr_RequestRevisions_CreatedAt] DEFAULT (SYSUTCDATETIME()),
        [ChangeSummary]    NVARCHAR(MAX) NULL,
        [IsSigned]         BIT NOT NULL CONSTRAINT [DF_npr_RequestRevisions_IsSigned] DEFAULT (0),
        [IsScreened]       BIT NOT NULL CONSTRAINT [DF_npr_RequestRevisions_IsScreened] DEFAULT (0),
        CONSTRAINT [PK_npr_RequestRevisions] PRIMARY KEY CLUSTERED ([RevisionId]),
        CONSTRAINT [UQ_npr_RequestRevisions_Number] UNIQUE ([RequestId], [RevisionNumber]),
        CONSTRAINT [CK_npr_RequestRevisions_AnswersJson] CHECK (ISJSON([AnswersJson]) = 1),
        CONSTRAINT [CK_npr_RequestRevisions_VariantsJson] CHECK (ISJSON([VariantsJson]) = 1),
        CONSTRAINT [FK_npr_RequestRevisions_Request] FOREIGN KEY ([RequestId]) REFERENCES [npr].[Requests]([RequestId]),
        CONSTRAINT [FK_npr_RequestRevisions_CreatedBy] FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF OBJECT_ID(N'[npr].[Endorsements]', N'U') IS NULL
BEGIN
    CREATE TABLE [npr].[Endorsements] (
        [EndorsementId]      BIGINT IDENTITY(1,1) NOT NULL,
        [RequestId]          BIGINT NOT NULL,
        [RevisionNumber]     INT NOT NULL,
        [Action]             NVARCHAR(32) NOT NULL,
        [ManagerUserId]      BIGINT NOT NULL,
        [ManagerEmail]       NVARCHAR(255) NOT NULL,
        [ManagerName]        NVARCHAR(255) NOT NULL,
        [DeclarationAgreed]  BIT NOT NULL CONSTRAINT [DF_npr_Endorsements_DeclarationAgreed] DEFAULT (0),
        [Comment]            NVARCHAR(MAX) NULL,
        [CreatedAt]          DATETIME2(3) NOT NULL CONSTRAINT [DF_npr_Endorsements_CreatedAt] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_npr_Endorsements] PRIMARY KEY CLUSTERED ([EndorsementId]),
        CONSTRAINT [FK_npr_Endorsements_Request] FOREIGN KEY ([RequestId]) REFERENCES [npr].[Requests]([RequestId]),
        CONSTRAINT [FK_npr_Endorsements_Manager] FOREIGN KEY ([ManagerUserId]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_Endorsements_Request' AND object_id = OBJECT_ID(N'[npr].[Endorsements]'))
    CREATE INDEX [IX_npr_Endorsements_Request] ON [npr].[Endorsements] ([RequestId], [RevisionNumber]);
GO

IF OBJECT_ID(N'[npr].[Attachments]', N'U') IS NULL
BEGIN
    CREATE TABLE [npr].[Attachments] (
        [AttachmentId]      BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]          UNIQUEIDENTIFIER NOT NULL CONSTRAINT [DF_npr_Attachments_PublicId] DEFAULT (NEWID()),
        [RequestId]         BIGINT NOT NULL,
        [OriginalFilename]  NVARCHAR(255) NOT NULL,
        /* Blob name inside the NPR container. Never a disk path. */
        [BlobKey]           NVARCHAR(400) NOT NULL,
        [FileSizeBytes]     BIGINT NOT NULL,
        [MimeType]          NVARCHAR(128) NOT NULL,
        [DocumentType]      NVARCHAR(64) NOT NULL,
        [Title]             NVARCHAR(255) NOT NULL CONSTRAINT [DF_npr_Attachments_Title] DEFAULT (N''),
        [Reference]         NVARCHAR(255) NULL,
        [ReferenceState]    NVARCHAR(32) NOT NULL CONSTRAINT [DF_npr_Attachments_ReferenceState] DEFAULT (N'provided'),
        [Revision]          NVARCHAR(64) NULL,
        [RevisionState]     NVARCHAR(32) NOT NULL CONSTRAINT [DF_npr_Attachments_RevisionState] DEFAULT (N'provided'),
        [UploadedByUserId]  BIGINT NOT NULL,
        [UploadedByEmail]   NVARCHAR(255) NOT NULL,
        [UploadedAt]        DATETIME2(3) NOT NULL CONSTRAINT [DF_npr_Attachments_UploadedAt] DEFAULT (SYSUTCDATETIME()),
        [IsDeleted]         BIT NOT NULL CONSTRAINT [DF_npr_Attachments_IsDeleted] DEFAULT (0),
        [DeletedAt]         DATETIME2(3) NULL,
        [DeletedByUserId]   BIGINT NULL,
        [DeletedByEmail]    NVARCHAR(255) NULL,
        CONSTRAINT [PK_npr_Attachments] PRIMARY KEY CLUSTERED ([AttachmentId]),
        CONSTRAINT [UQ_npr_Attachments_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [FK_npr_Attachments_Request] FOREIGN KEY ([RequestId]) REFERENCES [npr].[Requests]([RequestId]),
        CONSTRAINT [FK_npr_Attachments_UploadedBy] FOREIGN KEY ([UploadedByUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_npr_Attachments_DeletedBy] FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_Attachments_Request' AND object_id = OBJECT_ID(N'[npr].[Attachments]'))
    CREATE INDEX [IX_npr_Attachments_Request] ON [npr].[Attachments] ([RequestId]);
GO

IF OBJECT_ID(N'[npr].[Activity]', N'U') IS NULL
BEGIN
    CREATE TABLE [npr].[Activity] (
        [ActivityId]      BIGINT IDENTITY(1,1) NOT NULL,
        [RequestId]       BIGINT NOT NULL,
        [RevisionNumber]  INT NOT NULL,
        [ActorUserId]     BIGINT NULL,
        [ActorEmail]      NVARCHAR(255) NOT NULL,
        [ActorName]       NVARCHAR(255) NOT NULL,
        [ActorRole]       NVARCHAR(64) NOT NULL,
        [Action]          NVARCHAR(64) NOT NULL,
        [BeforeStatus]    NVARCHAR(32) NULL,
        [AfterStatus]     NVARCHAR(32) NULL,
        [ChangeSummary]   NVARCHAR(MAX) NULL,
        [PayloadJson]     NVARCHAR(MAX) NULL,
        [CreatedAt]       DATETIME2(3) NOT NULL CONSTRAINT [DF_npr_Activity_CreatedAt] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_npr_Activity] PRIMARY KEY CLUSTERED ([ActivityId]),
        CONSTRAINT [CK_npr_Activity_PayloadJson] CHECK ([PayloadJson] IS NULL OR ISJSON([PayloadJson]) = 1),
        CONSTRAINT [FK_npr_Activity_Request] FOREIGN KEY ([RequestId]) REFERENCES [npr].[Requests]([RequestId]),
        CONSTRAINT [FK_npr_Activity_Actor] FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_Activity_Request' AND object_id = OBJECT_ID(N'[npr].[Activity]'))
    CREATE INDEX [IX_npr_Activity_Request] ON [npr].[Activity] ([RequestId]);
GO

IF OBJECT_ID(N'[npr].[NotificationOutbox]', N'U') IS NULL
BEGIN
    CREATE TABLE [npr].[NotificationOutbox] (
        [OutboxId]         BIGINT IDENTITY(1,1) NOT NULL,
        [ActivityId]       BIGINT NOT NULL,
        [RequestId]        BIGINT NOT NULL,
        [Event]            NVARCHAR(64) NOT NULL,
        [ToSnapshotJson]   NVARCHAR(MAX) NOT NULL,
        [CcSnapshotJson]   NVARCHAR(MAX) NOT NULL,
        [PayloadSummary]   NVARCHAR(MAX) NOT NULL,
        [Status]           NVARCHAR(32) NOT NULL CONSTRAINT [DF_npr_NotificationOutbox_Status] DEFAULT (N'pending'),
        [Attempts]         INT NOT NULL CONSTRAINT [DF_npr_NotificationOutbox_Attempts] DEFAULT (0),
        [LastAttemptAt]    DATETIME2(3) NULL,
        [ErrorMessage]     NVARCHAR(MAX) NULL,
        [CreatedAt]        DATETIME2(3) NOT NULL CONSTRAINT [DF_npr_NotificationOutbox_CreatedAt] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_npr_NotificationOutbox] PRIMARY KEY CLUSTERED ([OutboxId]),
        CONSTRAINT [CK_npr_NotificationOutbox_ToJson] CHECK (ISJSON([ToSnapshotJson]) = 1),
        CONSTRAINT [CK_npr_NotificationOutbox_CcJson] CHECK (ISJSON([CcSnapshotJson]) = 1),
        CONSTRAINT [FK_npr_NotificationOutbox_Activity] FOREIGN KEY ([ActivityId]) REFERENCES [npr].[Activity]([ActivityId]),
        CONSTRAINT [FK_npr_NotificationOutbox_Request] FOREIGN KEY ([RequestId]) REFERENCES [npr].[Requests]([RequestId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_NotificationOutbox_Status' AND object_id = OBJECT_ID(N'[npr].[NotificationOutbox]'))
    CREATE INDEX [IX_npr_NotificationOutbox_Status] ON [npr].[NotificationOutbox] ([Status]);
GO

IF OBJECT_ID(N'[npr].[NotificationDeliveries]', N'U') IS NULL
BEGIN
    CREATE TABLE [npr].[NotificationDeliveries] (
        [DeliveryId]      BIGINT IDENTITY(1,1) NOT NULL,
        [OutboxId]        BIGINT NOT NULL,
        [RecipientEmail]  NVARCHAR(255) NOT NULL,
        [RecipientType]   NVARCHAR(16) NOT NULL,
        [Status]          NVARCHAR(32) NOT NULL CONSTRAINT [DF_npr_NotificationDeliveries_Status] DEFAULT (N'pending'),
        [Attempts]        INT NOT NULL CONSTRAINT [DF_npr_NotificationDeliveries_Attempts] DEFAULT (0),
        [SentAt]          DATETIME2(3) NULL,
        [ErrorMessage]    NVARCHAR(MAX) NULL,
        CONSTRAINT [PK_npr_NotificationDeliveries] PRIMARY KEY CLUSTERED ([DeliveryId]),
        CONSTRAINT [FK_npr_NotificationDeliveries_Outbox] FOREIGN KEY ([OutboxId]) REFERENCES [npr].[NotificationOutbox]([OutboxId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_NotificationDeliveries_Outbox' AND object_id = OBJECT_ID(N'[npr].[NotificationDeliveries]'))
    CREATE INDEX [IX_npr_NotificationDeliveries_Outbox] ON [npr].[NotificationDeliveries] ([OutboxId]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_NotificationDeliveries_Status' AND object_id = OBJECT_ID(N'[npr].[NotificationDeliveries]'))
    CREATE INDEX [IX_npr_NotificationDeliveries_Status] ON [npr].[NotificationDeliveries] ([Status]);
GO

IF OBJECT_ID(N'[npr].[ManagerRoutes]', N'U') IS NULL
BEGIN
    CREATE TABLE [npr].[ManagerRoutes] (
        [ManagerRouteId]  BIGINT IDENTITY(1,1) NOT NULL,
        [Branch]          NVARCHAR(64) NOT NULL,
        [ManagerUserId]   BIGINT NOT NULL,
        [ManagerEmail]    NVARCHAR(255) NOT NULL,
        [ManagerName]     NVARCHAR(255) NOT NULL,
        [IsDelegate]      BIT NOT NULL CONSTRAINT [DF_npr_ManagerRoutes_IsDelegate] DEFAULT (0),
        [ValidFrom]       DATE NOT NULL,
        [ValidTo]         DATE NULL,
        [ApprovedByUserId] BIGINT NULL,
        [CreatedAt]       DATETIME2(3) NOT NULL CONSTRAINT [DF_npr_ManagerRoutes_CreatedAt] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_npr_ManagerRoutes] PRIMARY KEY CLUSTERED ([ManagerRouteId]),
        CONSTRAINT [CK_npr_ManagerRoutes_Window] CHECK ([ValidTo] IS NULL OR [ValidTo] >= [ValidFrom]),
        CONSTRAINT [FK_npr_ManagerRoutes_Manager] FOREIGN KEY ([ManagerUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_npr_ManagerRoutes_ApprovedBy] FOREIGN KEY ([ApprovedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_npr_ManagerRoutes_Branch' AND object_id = OBJECT_ID(N'[npr].[ManagerRoutes]'))
    CREATE INDEX [IX_npr_ManagerRoutes_Branch] ON [npr].[ManagerRoutes] ([Branch], [ValidFrom], [ValidTo]);
GO
