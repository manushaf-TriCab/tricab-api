/*
================================================================================
  Quotegen AI workspace (Phase 1): the assistant chat bound to one RFQ draft.

  STATUS: NOT APPLIED to any database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Tables, all in [rfq]:
    QuotegenConversations  one shared conversation per RFQ draft
    QuotegenMessages       attributed messages (user / assistant / event)
    QuotegenToolEvents     one row per completed write tool call, keyed by an
                           operation id, so a repeated call is never written twice
    QuotegenBudgetLedger   one row per model call: a reservation made before the
                           call, settled to the actual cost afterwards

  Ids are the same lower-case UUID text the RFQ routes use (NVARCHAR(36)).
  Money is in millionths of a USD (BIGINT). Guarded and rerunnable: each object
  is created only if missing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF SCHEMA_ID(N'rfq') IS NULL
    EXEC(N'CREATE SCHEMA [rfq]');
GO

IF OBJECT_ID(N'[rfq].[QuotegenConversations]', N'U') IS NULL
BEGIN
    CREATE TABLE [rfq].[QuotegenConversations] (
        [ConversationId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] NVARCHAR(36) NOT NULL,
        [RfqPublicId] NVARCHAR(36) NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_rfq_QuotegenConversations_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_rfq_QuotegenConversations] PRIMARY KEY CLUSTERED ([ConversationId]),
        CONSTRAINT [UQ_rfq_QuotegenConversations_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_rfq_QuotegenConversations_RfqPublicId] UNIQUE ([RfqPublicId])
    );
END;
GO

IF OBJECT_ID(N'[rfq].[QuotegenMessages]', N'U') IS NULL
BEGIN
    CREATE TABLE [rfq].[QuotegenMessages] (
        [MessageId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] NVARCHAR(36) NOT NULL,
        [ConversationId] BIGINT NOT NULL,
        [Role] NVARCHAR(10) NOT NULL,
        [AuthorUserPublicId] NVARCHAR(36) NULL,
        [AuthorName] NVARCHAR(200) NULL,
        [MessageText] NVARCHAR(MAX) NOT NULL,
        [EventsJson] NVARCHAR(MAX) NOT NULL
            CONSTRAINT [DF_rfq_QuotegenMessages_EventsJson] DEFAULT (N'[]'),
        [ProposedSyncRevision] INT NULL,
        [ContainsPricing] BIT NOT NULL
            CONSTRAINT [DF_rfq_QuotegenMessages_ContainsPricing] DEFAULT (0),
        [CreatedAtUtc] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_rfq_QuotegenMessages_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_rfq_QuotegenMessages] PRIMARY KEY CLUSTERED ([MessageId]),
        CONSTRAINT [UQ_rfq_QuotegenMessages_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_rfq_QuotegenMessages_Role] CHECK ([Role] IN (N'user', N'assistant', N'event')),
        CONSTRAINT [FK_rfq_QuotegenMessages_Conversation]
            FOREIGN KEY ([ConversationId]) REFERENCES [rfq].[QuotegenConversations] ([ConversationId])
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'[rfq].[QuotegenMessages]')
      AND [name] = N'IX_rfq_QuotegenMessages_Conversation'
)
    CREATE NONCLUSTERED INDEX [IX_rfq_QuotegenMessages_Conversation]
        ON [rfq].[QuotegenMessages] ([ConversationId], [MessageId]);
GO

IF OBJECT_ID(N'[rfq].[QuotegenToolEvents]', N'U') IS NULL
BEGIN
    CREATE TABLE [rfq].[QuotegenToolEvents] (
        [ToolEventId] BIGINT IDENTITY(1,1) NOT NULL,
        [ConversationId] BIGINT NOT NULL,
        [OperationId] CHAR(64) NOT NULL,
        [ToolName] NVARCHAR(60) NOT NULL,
        [Status] NVARCHAR(10) NOT NULL,
        [Summary] NVARCHAR(500) NOT NULL,
        [ResultJson] NVARCHAR(MAX) NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_rfq_QuotegenToolEvents_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_rfq_QuotegenToolEvents] PRIMARY KEY CLUSTERED ([ToolEventId]),
        CONSTRAINT [UQ_rfq_QuotegenToolEvents_Operation] UNIQUE ([ConversationId], [OperationId]),
        CONSTRAINT [FK_rfq_QuotegenToolEvents_Conversation]
            FOREIGN KEY ([ConversationId]) REFERENCES [rfq].[QuotegenConversations] ([ConversationId])
    );
END;
GO

IF OBJECT_ID(N'[rfq].[QuotegenBudgetLedger]', N'U') IS NULL
BEGIN
    CREATE TABLE [rfq].[QuotegenBudgetLedger] (
        [LedgerId] BIGINT IDENTITY(1,1) NOT NULL,
        [RfqPublicId] NVARCHAR(36) NOT NULL,
        [ReservationId] NVARCHAR(36) NOT NULL,
        [Kind] NVARCHAR(12) NOT NULL,
        [Micros] BIGINT NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_rfq_QuotegenBudgetLedger_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [SettledAtUtc] DATETIME2(7) NULL,
        CONSTRAINT [PK_rfq_QuotegenBudgetLedger] PRIMARY KEY CLUSTERED ([LedgerId]),
        CONSTRAINT [UQ_rfq_QuotegenBudgetLedger_ReservationId] UNIQUE ([ReservationId]),
        CONSTRAINT [CK_rfq_QuotegenBudgetLedger_Kind] CHECK ([Kind] IN (N'reservation', N'actual')),
        CONSTRAINT [CK_rfq_QuotegenBudgetLedger_Micros] CHECK ([Micros] >= 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'[rfq].[QuotegenBudgetLedger]')
      AND [name] = N'IX_rfq_QuotegenBudgetLedger_Rfq'
)
    CREATE NONCLUSTERED INDEX [IX_rfq_QuotegenBudgetLedger_Rfq]
        ON [rfq].[QuotegenBudgetLedger] ([RfqPublicId], [Kind]) INCLUDE ([Micros]);
GO
