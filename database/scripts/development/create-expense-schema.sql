/*
  Tri Expense Stage 3 — NON-PRODUCTION Azure SQL schema.
  Target: tricab-mel / tc / development only.
  DO NOT apply to production.
*/

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'expense')
BEGIN
    EXEC(N'CREATE SCHEMA [expense]');
END
GO

IF OBJECT_ID(N'[expense].[ExpenseProfiles]', N'U') IS NULL
BEGIN
    CREATE TABLE [expense].[ExpenseProfiles] (
        [ExpenseProfileId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT [DF_expense_ExpenseProfiles_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [UserId] BIGINT NOT NULL,
        [LegacyLogin] NVARCHAR(190) NOT NULL,
        [LegacyEmail] NVARCHAR(320) NOT NULL,
        [IsActive] BIT NOT NULL CONSTRAINT [DF_expense_ExpenseProfiles_IsActive] DEFAULT (1),
        [IsFinance] BIT NOT NULL CONSTRAINT [DF_expense_ExpenseProfiles_IsFinance] DEFAULT (0),
        [IsExpenseAdmin] BIT NOT NULL CONSTRAINT [DF_expense_ExpenseProfiles_IsExpenseAdmin] DEFAULT (0),
        [ManagerUserId] BIGINT NULL,
        [LegacyManagerLogin] NVARCHAR(190) NULL,
        [OfficeLocation] NVARCHAR(190) NULL,
        [DepartmentOverride] NVARCHAR(190) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpenseProfiles_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpenseProfiles_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_expense_ExpenseProfiles] PRIMARY KEY CLUSTERED ([ExpenseProfileId]),
        CONSTRAINT [UQ_expense_ExpenseProfiles_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_expense_ExpenseProfiles_UserId] UNIQUE ([UserId]),
        CONSTRAINT [UQ_expense_ExpenseProfiles_LegacyLogin] UNIQUE ([LegacyLogin]),
        CONSTRAINT [FK_expense_ExpenseProfiles_UserId] FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_expense_ExpenseProfiles_ManagerUserId] FOREIGN KEY ([ManagerUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseProfiles_LegacyEmail] ON [expense].[ExpenseProfiles]([LegacyEmail]);
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseProfiles_ManagerUserId] ON [expense].[ExpenseProfiles]([ManagerUserId]) WHERE [ManagerUserId] IS NOT NULL;
END
GO

IF OBJECT_ID(N'[expense].[ExpenseReceipts]', N'U') IS NULL
BEGIN
    CREATE TABLE [expense].[ExpenseReceipts] (
        [ExpenseReceiptId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT [DF_expense_ExpenseReceipts_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [OwnerUserId] BIGINT NOT NULL,
        [SubmittedByUserId] BIGINT NULL,
        [ManagerUserId] BIGINT NULL,
        [Status] NVARCHAR(32) NOT NULL,
        [ManagerStatus] NVARCHAR(32) NOT NULL CONSTRAINT [DF_expense_ExpenseReceipts_ManagerStatus] DEFAULT (N''),
        [ManagerReason] NVARCHAR(MAX) NULL,
        [VendorName] NVARCHAR(255) NOT NULL,
        [TotalAmount] DECIMAL(18,6) NOT NULL,
        [GstAmount] DECIMAL(18,6) NOT NULL,
        [Currency] NVARCHAR(16) NOT NULL,
        [GstType] NVARCHAR(64) NOT NULL,
        [ReceiptDate] DATE NULL,
        [Category] NVARCHAR(255) NOT NULL CONSTRAINT [DF_expense_ExpenseReceipts_Category] DEFAULT (N''),
        [OtherCategory] NVARCHAR(255) NULL,
        [CostCentre] NVARCHAR(255) NOT NULL CONSTRAINT [DF_expense_ExpenseReceipts_CostCentre] DEFAULT (N''),
        [Description] NVARCHAR(MAX) NULL,
        [StatementPeriod] NVARCHAR(64) NULL,
        [CustomerCode] NVARCHAR(64) NULL,
        [CustomerName] NVARCHAR(255) NULL,
        [CustomerState] NVARCHAR(64) NULL,
        [ContactName] NVARCHAR(255) NULL,
        [ContactEmail] NVARCHAR(320) NULL,
        [ContactPhone] NVARCHAR(64) NULL,
        [AttachmentSource] NVARCHAR(32) NULL,
        [AttachmentReference] NVARCHAR(1024) NULL,
        [AttachmentFileName] NVARCHAR(255) NULL,
        [BankFeedEntryId] BIGINT NULL,
        [PeriodId] BIGINT NULL,
        [LegacyReceiptId] INT NULL,
        [LegacyCreatedBy] NVARCHAR(320) NULL,
        [LegacySubmittedBy] NVARCHAR(320) NULL,
        [LegacyManagerLogin] NVARCHAR(190) NULL,
        [LegacyFileUrl] NVARCHAR(1024) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpenseReceipts_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [SubmittedAtUtc] DATETIME2(7) NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpenseReceipts_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedBy] NVARCHAR(320) NULL,
        [Resolution] NVARCHAR(64) NULL,
        [ResolutionNote] NVARCHAR(MAX) NULL,
        [ResolvedByUserId] BIGINT NULL,
        [LegacyResolvedBy] NVARCHAR(320) NULL,
        [ResolvedAtUtc] DATETIME2(7) NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_expense_ExpenseReceipts] PRIMARY KEY CLUSTERED ([ExpenseReceiptId]),
        CONSTRAINT [UQ_expense_ExpenseReceipts_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_expense_ExpenseReceipts_Status] CHECK ([Status] IN (N'draft', N'pending', N'approved', N'rejected')),
        CONSTRAINT [FK_expense_ExpenseReceipts_OwnerUserId] FOREIGN KEY ([OwnerUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_expense_ExpenseReceipts_SubmittedByUserId] FOREIGN KEY ([SubmittedByUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_expense_ExpenseReceipts_ManagerUserId] FOREIGN KEY ([ManagerUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_expense_ExpenseReceipts_ResolvedByUserId] FOREIGN KEY ([ResolvedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE UNIQUE NONCLUSTERED INDEX [UX_expense_ExpenseReceipts_LegacyReceiptId] ON [expense].[ExpenseReceipts]([LegacyReceiptId]) WHERE [LegacyReceiptId] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReceipts_OwnerUserId] ON [expense].[ExpenseReceipts]([OwnerUserId]) WHERE [DeletedAtUtc] IS NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReceipts_ManagerUserId_Status] ON [expense].[ExpenseReceipts]([ManagerUserId], [Status]) WHERE [DeletedAtUtc] IS NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReceipts_Status_ManagerStatus] ON [expense].[ExpenseReceipts]([Status], [ManagerStatus]) WHERE [DeletedAtUtc] IS NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReceipts_ReceiptDate] ON [expense].[ExpenseReceipts]([ReceiptDate]) WHERE [DeletedAtUtc] IS NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReceipts_PeriodId] ON [expense].[ExpenseReceipts]([PeriodId]) WHERE [PeriodId] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReceipts_BankFeedEntryId] ON [expense].[ExpenseReceipts]([BankFeedEntryId]) WHERE [BankFeedEntryId] IS NOT NULL;
END
GO

IF OBJECT_ID(N'[expense].[ExpensePeriods]', N'U') IS NULL
BEGIN
    CREATE TABLE [expense].[ExpensePeriods] (
        [ExpensePeriodId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT [DF_expense_ExpensePeriods_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [Country] CHAR(2) NOT NULL,
        [PeriodStart] DATE NOT NULL,
        [PeriodEnd] DATE NOT NULL,
        [TransactionStartDate] DATE NULL,
        [TransactionEndDate] DATE NULL,
        [Status] NVARCHAR(16) NOT NULL,
        [ClosedAtUtc] DATETIME2(7) NULL,
        [ClosedByUserId] BIGINT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpensePeriods_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [LegacyPeriodId] INT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_expense_ExpensePeriods] PRIMARY KEY CLUSTERED ([ExpensePeriodId]),
        CONSTRAINT [UQ_expense_ExpensePeriods_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_expense_ExpensePeriods_Status] CHECK ([Status] IN (N'open', N'closed')),
        CONSTRAINT [FK_expense_ExpensePeriods_ClosedByUserId] FOREIGN KEY ([ClosedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE UNIQUE NONCLUSTERED INDEX [UX_expense_ExpensePeriods_LegacyPeriodId] ON [expense].[ExpensePeriods]([LegacyPeriodId]) WHERE [LegacyPeriodId] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpensePeriods_CountryStatus]
        ON [expense].[ExpensePeriods]([Country], [Status], [PeriodEnd] DESC);
END
GO

IF OBJECT_ID(N'[expense].[ExpenseBankFeedEntries]', N'U') IS NULL
BEGIN
    CREATE TABLE [expense].[ExpenseBankFeedEntries] (
        [ExpenseBankFeedEntryId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT [DF_expense_ExpenseBankFeedEntries_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [BankReference] NVARCHAR(190) NOT NULL,
        [CardholderEmail] NVARCHAR(320) NOT NULL,
        [CardholderUserId] BIGINT NULL,
        [FirstName] NVARCHAR(100) NULL,
        [LastName] NVARCHAR(100) NULL,
        [Country] CHAR(2) NOT NULL,
        [TransactionDate] DATE NULL,
        [MerchantName] NVARCHAR(255) NOT NULL,
        [Amount] DECIMAL(18,6) NOT NULL,
        [Currency] NVARCHAR(16) NOT NULL,
        [MatchStatus] NVARCHAR(32) NOT NULL,
        [ExpenseReceiptId] BIGINT NULL,
        [ExpensePeriodId] BIGINT NULL,
        [Resolution] NVARCHAR(64) NULL,
        [OffsetBankFeedEntryId] BIGINT NULL,
        [ResolutionNote] NVARCHAR(MAX) NULL,
        [ResolvedByUserId] BIGINT NULL,
        [LegacyResolvedBy] NVARCHAR(320) NULL,
        [ResolvedAtUtc] DATETIME2(7) NULL,
        [PostingDate] DATE NULL,
        [StatementDate] DATE NULL,
        [MerchantCity] NVARCHAR(100) NULL,
        [MerchantCountry] NVARCHAR(16) NULL,
        [MerchantPostcode] NVARCHAR(20) NULL,
        [ForeignAmount] DECIMAL(18,6) NULL,
        [ForeignCurrency] NVARCHAR(16) NULL,
        [NetAmount] DECIMAL(18,6) NULL,
        [TaxAmount] DECIMAL(18,6) NULL,
        [TransactionType] NVARCHAR(64) NULL,
        [MerchantCategoryGroup] NVARCHAR(100) NULL,
        [LegacyUploadedBy] NVARCHAR(320) NULL,
        [LegacyBankFeedId] INT NULL,
        [UploadedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpenseBankFeedEntries_UploadedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_expense_ExpenseBankFeedEntries] PRIMARY KEY CLUSTERED ([ExpenseBankFeedEntryId]),
        CONSTRAINT [UQ_expense_ExpenseBankFeedEntries_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_expense_ExpenseBankFeedEntries_BankReference] UNIQUE ([BankReference]),
        CONSTRAINT [CK_expense_ExpenseBankFeedEntries_MatchStatus] CHECK ([MatchStatus] IN (N'unmatched', N'matched', N'ambiguous')),
        CONSTRAINT [FK_expense_ExpenseBankFeedEntries_Receipt] FOREIGN KEY ([ExpenseReceiptId]) REFERENCES [expense].[ExpenseReceipts]([ExpenseReceiptId]),
        CONSTRAINT [FK_expense_ExpenseBankFeedEntries_Period] FOREIGN KEY ([ExpensePeriodId]) REFERENCES [expense].[ExpensePeriods]([ExpensePeriodId]),
        CONSTRAINT [FK_expense_ExpenseBankFeedEntries_Offset] FOREIGN KEY ([OffsetBankFeedEntryId]) REFERENCES [expense].[ExpenseBankFeedEntries]([ExpenseBankFeedEntryId]),
        CONSTRAINT [FK_expense_ExpenseBankFeedEntries_ResolvedByUserId] FOREIGN KEY ([ResolvedByUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_expense_ExpenseBankFeedEntries_CardholderUserId] FOREIGN KEY ([CardholderUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE UNIQUE NONCLUSTERED INDEX [UX_expense_ExpenseBankFeedEntries_LegacyBankFeedId] ON [expense].[ExpenseBankFeedEntries]([LegacyBankFeedId]) WHERE [LegacyBankFeedId] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseBankFeedEntries_MatchStatus] ON [expense].[ExpenseBankFeedEntries]([MatchStatus]);
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseBankFeedEntries_CardholderEmail] ON [expense].[ExpenseBankFeedEntries]([CardholderEmail]);
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseBankFeedEntries_PeriodMatch]
        ON [expense].[ExpenseBankFeedEntries]([ExpensePeriodId], [MatchStatus], [Resolution])
        WHERE [ExpensePeriodId] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseBankFeedEntries_CountryTxDate]
        ON [expense].[ExpenseBankFeedEntries]([Country], [TransactionDate]);
END
GO

IF OBJECT_ID(N'[expense].[ExpenseAuditEvents]', N'U') IS NULL
BEGIN
    CREATE TABLE [expense].[ExpenseAuditEvents] (
        [ExpenseAuditEventId] BIGINT IDENTITY(1,1) NOT NULL,
        [ActorUserId] BIGINT NOT NULL,
        [ActionCode] NVARCHAR(64) NOT NULL,
        [ExpenseReceiptPublicId] UNIQUEIDENTIFIER NULL,
        [Detail] NVARCHAR(MAX) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpenseAuditEvents_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_expense_ExpenseAuditEvents] PRIMARY KEY CLUSTERED ([ExpenseAuditEventId]),
        CONSTRAINT [FK_expense_ExpenseAuditEvents_ActorUserId] FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseAuditEvents_CreatedAtUtc] ON [expense].[ExpenseAuditEvents]([CreatedAtUtc] DESC);
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseAuditEvents_ReceiptPublicId] ON [expense].[ExpenseAuditEvents]([ExpenseReceiptPublicId]) WHERE [ExpenseReceiptPublicId] IS NOT NULL;
END
GO

IF OBJECT_ID(N'[expense].[ExpenseReminderLog]', N'U') IS NULL
BEGIN
    CREATE TABLE [expense].[ExpenseReminderLog] (
        [ExpenseReminderLogId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT [DF_expense_ExpenseReminderLog_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [RecipientUserId] BIGINT NULL,
        [RecipientEmail] NVARCHAR(320) NOT NULL,
        [SentByUserId] BIGINT NULL,
        [LegacySentBy] NVARCHAR(320) NULL,
        [SentAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpenseReminderLog_SentAtUtc] DEFAULT (SYSUTCDATETIME()),
        [LegacyReminderLogId] INT NULL,
        [ReminderType] NVARCHAR(64) NULL,
        [IdempotencyKey] NVARCHAR(200) NULL,
        CONSTRAINT [PK_expense_ExpenseReminderLog] PRIMARY KEY CLUSTERED ([ExpenseReminderLogId]),
        CONSTRAINT [UQ_expense_ExpenseReminderLog_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [FK_expense_ExpenseReminderLog_RecipientUserId] FOREIGN KEY ([RecipientUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_expense_ExpenseReminderLog_SentByUserId] FOREIGN KEY ([SentByUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReminderLog_RecipientEmail]
        ON [expense].[ExpenseReminderLog]([RecipientEmail], [SentAtUtc] DESC);
    CREATE UNIQUE NONCLUSTERED INDEX [UX_expense_ExpenseReminderLog_LegacyReminderLogId]
        ON [expense].[ExpenseReminderLog]([LegacyReminderLogId]) WHERE [LegacyReminderLogId] IS NOT NULL;
    CREATE UNIQUE NONCLUSTERED INDEX [UX_expense_ExpenseReminderLog_IdempotencyKey]
        ON [expense].[ExpenseReminderLog]([IdempotencyKey]) WHERE [IdempotencyKey] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReminderLog_ReminderType]
        ON [expense].[ExpenseReminderLog]([ReminderType], [SentAtUtc] DESC);
END
GO

