/*
  Tri Expense Phase 4 — additive non-production schema.
  Target: tricab-mel / tc / development only.
  Idempotent. Does not drop data. Does not touch legacy MySQL.
*/

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'expense')
BEGIN
    EXEC(N'CREATE SCHEMA [expense]');
END
GO

/* ------------------------------------------------------------------ Bank feed PublicId + cardholder identity */
IF COL_LENGTH(N'expense.ExpenseBankFeedEntries', N'PublicId') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpenseBankFeedEntries] ADD
        [PublicId] UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_expense_ExpenseBankFeedEntries_PublicId] DEFAULT (NEWSEQUENTIALID());
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_expense_ExpenseBankFeedEntries_PublicId'
      AND object_id = OBJECT_ID(N'[expense].[ExpenseBankFeedEntries]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX [UQ_expense_ExpenseBankFeedEntries_PublicId]
        ON [expense].[ExpenseBankFeedEntries]([PublicId]);
END
GO

IF COL_LENGTH(N'expense.ExpenseBankFeedEntries', N'CardholderUserId') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpenseBankFeedEntries] ADD
        [CardholderUserId] BIGINT NULL;
END
GO

IF OBJECT_ID(N'[expense].[FK_expense_ExpenseBankFeedEntries_CardholderUserId]', N'F') IS NULL
   AND COL_LENGTH(N'expense.ExpenseBankFeedEntries', N'CardholderUserId') IS NOT NULL
BEGIN
    ALTER TABLE [expense].[ExpenseBankFeedEntries] WITH CHECK
    ADD CONSTRAINT [FK_expense_ExpenseBankFeedEntries_CardholderUserId]
        FOREIGN KEY ([CardholderUserId]) REFERENCES [identity].[Users]([UserId]);
END
GO

IF COL_LENGTH(N'expense.ExpenseBankFeedEntries', N'FirstName') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpenseBankFeedEntries] ADD
        [FirstName] NVARCHAR(100) NULL,
        [LastName] NVARCHAR(100) NULL;
END
GO

IF COL_LENGTH(N'expense.ExpenseBankFeedEntries', N'UploadedAtUtc') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpenseBankFeedEntries] ADD
        [UploadedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_expense_ExpenseBankFeedEntries_UploadedAtUtc_P4] DEFAULT (SYSUTCDATETIME());
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_expense_ExpenseBankFeedEntries_PeriodMatch'
      AND object_id = OBJECT_ID(N'[expense].[ExpenseBankFeedEntries]')
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseBankFeedEntries_PeriodMatch]
        ON [expense].[ExpenseBankFeedEntries]([ExpensePeriodId], [MatchStatus], [Resolution])
        WHERE [ExpensePeriodId] IS NOT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_expense_ExpenseBankFeedEntries_CountryTxDate'
      AND object_id = OBJECT_ID(N'[expense].[ExpenseBankFeedEntries]')
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseBankFeedEntries_CountryTxDate]
        ON [expense].[ExpenseBankFeedEntries]([Country], [TransactionDate]);
END
GO

/* ------------------------------------------------------------------ Period PublicId */
IF COL_LENGTH(N'expense.ExpensePeriods', N'PublicId') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpensePeriods] ADD
        [PublicId] UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_expense_ExpensePeriods_PublicId] DEFAULT (NEWSEQUENTIALID());
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_expense_ExpensePeriods_PublicId'
      AND object_id = OBJECT_ID(N'[expense].[ExpensePeriods]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX [UQ_expense_ExpensePeriods_PublicId]
        ON [expense].[ExpensePeriods]([PublicId]);
END
GO

IF COL_LENGTH(N'expense.ExpensePeriods', N'ClosedByUserId') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpensePeriods] ADD
        [ClosedByUserId] BIGINT NULL;
END
GO

IF OBJECT_ID(N'[expense].[FK_expense_ExpensePeriods_ClosedByUserId]', N'F') IS NULL
   AND COL_LENGTH(N'expense.ExpensePeriods', N'ClosedByUserId') IS NOT NULL
BEGIN
    ALTER TABLE [expense].[ExpensePeriods] WITH CHECK
    ADD CONSTRAINT [FK_expense_ExpensePeriods_ClosedByUserId]
        FOREIGN KEY ([ClosedByUserId]) REFERENCES [identity].[Users]([UserId]);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_expense_ExpensePeriods_CountryStatus'
      AND object_id = OBJECT_ID(N'[expense].[ExpensePeriods]')
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_expense_ExpensePeriods_CountryStatus]
        ON [expense].[ExpensePeriods]([Country], [Status], [PeriodEnd] DESC);
END
GO

/* ------------------------------------------------------------------ Reminder log already created in Phase 3 — ensure PublicId index */
IF OBJECT_ID(N'[expense].[ExpenseReminderLog]', N'U') IS NULL
BEGIN
    CREATE TABLE [expense].[ExpenseReminderLog] (
        [ExpenseReminderLogId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT [DF_expense_ExpenseReminderLog_PublicId_P4] DEFAULT (NEWSEQUENTIALID()),
        [RecipientUserId] BIGINT NULL,
        [RecipientEmail] NVARCHAR(320) NOT NULL,
        [SentByUserId] BIGINT NULL,
        [LegacySentBy] NVARCHAR(320) NULL,
        [SentAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_expense_ExpenseReminderLog_SentAtUtc_P4] DEFAULT (SYSUTCDATETIME()),
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
