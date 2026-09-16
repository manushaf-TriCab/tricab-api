/*
  Tri Expense Phase 5 — additive non-production schema.
  Target: tricab-mel / tc / development only.
  Idempotent. Does not drop data. Does not touch legacy MySQL.

  Adds reminder-log classification and a dedupe key so the scheduled monthly
  manager digest cannot double-send when a cron is retried or re-run by hand.
*/

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'expense')
BEGIN
    EXEC(N'CREATE SCHEMA [expense]');
END
GO

/* ------------------------------------------------------------------ Reminder log type + idempotency key */
IF COL_LENGTH(N'expense.ExpenseReminderLog', N'ReminderType') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpenseReminderLog] ADD
        [ReminderType] NVARCHAR(64) NULL;
END
GO

IF COL_LENGTH(N'expense.ExpenseReminderLog', N'IdempotencyKey') IS NULL
BEGIN
    ALTER TABLE [expense].[ExpenseReminderLog] ADD
        [IdempotencyKey] NVARCHAR(200) NULL;
END
GO

/*
  Filtered unique index: only scheduled sends carry a key, so manual Finance
  reminders (which may legitimately repeat) stay unconstrained while the monthly
  digest is protected at the database level rather than only in application code.
*/
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UX_expense_ExpenseReminderLog_IdempotencyKey'
      AND object_id = OBJECT_ID(N'[expense].[ExpenseReminderLog]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX [UX_expense_ExpenseReminderLog_IdempotencyKey]
        ON [expense].[ExpenseReminderLog]([IdempotencyKey])
        WHERE [IdempotencyKey] IS NOT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_expense_ExpenseReminderLog_ReminderType'
      AND object_id = OBJECT_ID(N'[expense].[ExpenseReminderLog]')
)
BEGIN
    CREATE NONCLUSTERED INDEX [IX_expense_ExpenseReminderLog_ReminderType]
        ON [expense].[ExpenseReminderLog]([ReminderType], [SentAtUtc] DESC);
END
GO
