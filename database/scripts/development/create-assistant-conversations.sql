SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'[product].[AssistantConversations]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[AssistantConversations] (
        [ConversationId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL,
        [RegionCode] NVARCHAR(32) NULL,
        [CatalogueVisibility] NVARCHAR(10) NOT NULL
            CONSTRAINT [DF_product_AssistantConversations_CatalogueVisibility] DEFAULT (N'public'),
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_product_AssistantConversations_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_product_AssistantConversations_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_product_AssistantConversations] PRIMARY KEY CLUSTERED ([ConversationId]),
        CONSTRAINT [UQ_product_AssistantConversations_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_product_AssistantConversations_CatalogueVisibility]
            CHECK ([CatalogueVisibility] IN (N'public', N'staff'))
    );
END;

IF COL_LENGTH(N'[product].[AssistantConversations]', N'RegionCode') IS NULL
    ALTER TABLE [product].[AssistantConversations] ADD [RegionCode] NVARCHAR(32) NULL;

IF COL_LENGTH(N'[product].[AssistantConversations]', N'CatalogueVisibility') IS NULL
BEGIN
    ALTER TABLE [product].[AssistantConversations] ADD [CatalogueVisibility] NVARCHAR(10) NULL;
    UPDATE [product].[AssistantConversations] SET [CatalogueVisibility] = N'public' WHERE [CatalogueVisibility] IS NULL;
    ALTER TABLE [product].[AssistantConversations] ALTER COLUMN [CatalogueVisibility] NVARCHAR(10) NOT NULL;
END;

IF OBJECT_ID(N'[product].[DF_product_AssistantConversations_CatalogueVisibility]', N'D') IS NULL
    ALTER TABLE [product].[AssistantConversations]
        ADD CONSTRAINT [DF_product_AssistantConversations_CatalogueVisibility]
        DEFAULT (N'public') FOR [CatalogueVisibility];

IF OBJECT_ID(N'[product].[CK_product_AssistantConversations_CatalogueVisibility]', N'C') IS NULL
    ALTER TABLE [product].[AssistantConversations]
        ADD CONSTRAINT [CK_product_AssistantConversations_CatalogueVisibility]
        CHECK ([CatalogueVisibility] IN (N'public', N'staff'));

IF OBJECT_ID(N'[product].[AssistantConversationTurns]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[AssistantConversationTurns] (
        [ConversationTurnId] BIGINT IDENTITY(1,1) NOT NULL,
        [ConversationId] BIGINT NOT NULL,
        [UserMessage] NVARCHAR(2000) NOT NULL,
        [AssistantMessage] NVARCHAR(MAX) NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_product_AssistantConversationTurns_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_product_AssistantConversationTurns] PRIMARY KEY CLUSTERED ([ConversationTurnId]),
        CONSTRAINT [FK_product_AssistantConversationTurns_Conversation]
            FOREIGN KEY ([ConversationId]) REFERENCES [product].[AssistantConversations] ([ConversationId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'[product].[AssistantConversationTurns]')
      AND [name] = N'IX_product_AssistantConversationTurns_ConversationId'
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_product_AssistantConversationTurns_ConversationId]
        ON [product].[AssistantConversationTurns] ([ConversationId], [ConversationTurnId]);
END;
