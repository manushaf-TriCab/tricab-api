/*
  Tri Expense Phase 3 — additive non-production schema.
  Target: tricab-mel / tc / development only.
  Idempotent. Does not drop data. Does not touch legacy MySQL.
*/

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'expense')
BEGIN
    EXEC(N'CREATE SCHEMA [expense]');
END
GO

/* ------------------------------------------------------------------ Receipt finance-resolution fields */
IF COL_LENGTH(N'expense.ExpenseReceipts', N'Resolution') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpenseReceipts] ADD
        [Resolution] NVARCHAR(64) NULL,
        [ResolutionNote] NVARCHAR(MAX) NULL,
        [ResolvedByUserId] BIGINT NULL,
        [LegacyResolvedBy] NVARCHAR(320) NULL,
        [ResolvedAtUtc] DATETIME2(7) NULL;
END
GO

IF OBJECT_ID(N'[expense].[FK_expense_ExpenseReceipts_ResolvedByUserId]', N'F') IS NULL
   AND COL_LENGTH(N'expense.ExpenseReceipts', N'ResolvedByUserId') IS NOT NULL
BEGIN
    ALTER TABLE [expense].[ExpenseReceipts] WITH CHECK
    ADD CONSTRAINT [FK_expense_ExpenseReceipts_ResolvedByUserId]
        FOREIGN KEY ([ResolvedByUserId]) REFERENCES [identity].[Users]([UserId]);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_expense_ExpenseReceipts_Resolution'
      AND object_id = OBJECT_ID(N'[expense].[ExpenseReceipts]')
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReceipts_Resolution]
        ON [expense].[ExpenseReceipts]([Resolution])
        WHERE [Resolution] IS NOT NULL;
END
GO

/* ------------------------------------------------------------------ Period transaction windows (distinct from statement dates) */
IF COL_LENGTH(N'expense.ExpensePeriods', N'TransactionStartDate') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpensePeriods] ADD
        [TransactionStartDate] DATE NULL,
        [TransactionEndDate] DATE NULL;
END
GO

/* ------------------------------------------------------------------ Bank feed fidelity + offsets */
IF COL_LENGTH(N'expense.ExpenseBankFeedEntries', N'OffsetBankFeedEntryId') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpenseBankFeedEntries] ADD
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
        [LegacyUploadedBy] NVARCHAR(320) NULL;
END
GO

IF OBJECT_ID(N'[expense].[FK_expense_ExpenseBankFeedEntries_Offset]', N'F') IS NULL
   AND COL_LENGTH(N'expense.ExpenseBankFeedEntries', N'OffsetBankFeedEntryId') IS NOT NULL
BEGIN
    ALTER TABLE [expense].[ExpenseBankFeedEntries] WITH CHECK
    ADD CONSTRAINT [FK_expense_ExpenseBankFeedEntries_Offset]
        FOREIGN KEY ([OffsetBankFeedEntryId]) REFERENCES [expense].[ExpenseBankFeedEntries]([ExpenseBankFeedEntryId]);
END
GO

IF OBJECT_ID(N'[expense].[FK_expense_ExpenseBankFeedEntries_ResolvedByUserId]', N'F') IS NULL
   AND COL_LENGTH(N'expense.ExpenseBankFeedEntries', N'ResolvedByUserId') IS NOT NULL
BEGIN
    ALTER TABLE [expense].[ExpenseBankFeedEntries] WITH CHECK
    ADD CONSTRAINT [FK_expense_ExpenseBankFeedEntries_ResolvedByUserId]
        FOREIGN KEY ([ResolvedByUserId]) REFERENCES [identity].[Users]([UserId]);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_expense_ExpenseBankFeedEntries_Offset'
      AND object_id = OBJECT_ID(N'[expense].[ExpenseBankFeedEntries]')
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseBankFeedEntries_Offset]
        ON [expense].[ExpenseBankFeedEntries]([OffsetBankFeedEntryId])
        WHERE [OffsetBankFeedEntryId] IS NOT NULL;
END
GO

/* ------------------------------------------------------------------ Reminder log — no uniqueness that would change legacy last-sent semantics */
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
        CONSTRAINT [PK_expense_ExpenseReminderLog] PRIMARY KEY CLUSTERED ([ExpenseReminderLogId]),
        CONSTRAINT [UQ_expense_ExpenseReminderLog_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [FK_expense_ExpenseReminderLog_RecipientUserId] FOREIGN KEY ([RecipientUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_expense_ExpenseReminderLog_SentByUserId] FOREIGN KEY ([SentByUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReminderLog_RecipientEmail]
        ON [expense].[ExpenseReminderLog]([RecipientEmail], [SentAtUtc] DESC);
    CREATE UNIQUE NONCLUSTERED INDEX [UX_expense_ExpenseReminderLog_LegacyReminderLogId]
        ON [expense].[ExpenseReminderLog]([LegacyReminderLogId]) WHERE [LegacyReminderLogId] IS NOT NULL;
END
GO
