/*
================================================================================
  RFQ number: RFQ-000001, RFQ-000002, ... (the number zero-padded to 6 digits) replaces the hex RFQ reference
  (RFQ-02742DBD, the first eight hex digits of the draft id).

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's call. Apply it BEFORE deploying the API that reads rfq_number.

  What it does, in one transaction that holds a table lock on the drafts:
    1. [rfq].[rfq_draft_number_seq]: a SEQUENCE starting at 1, NO CACHE
       (a cache would leave gaps after a restart).
    2. [rfq].[rfq_draft_quotes].[rfq_number] INT NULL, DEFAULT (NEXT VALUE FOR
       the sequence): every new draft takes the next number on insert.
       Existing rows stay NULL until step 3.
    3. Numbers every draft that has none, in created_at then id order, from
       the highest number already given (1 on the first run).
    4. A unique index on rfq_number (filtered to non-NULL rows).
    5. Restarts the sequence past the highest number, if it is not already.
  Then, outside the transaction:
    6. [mail].[message_links].[reference] is rewritten to RFQ-<number, 6 digits> for every
       link whose draft has a number. (Old "Created RFQ draft RFQ-02742DBD."
       activity lines are history and stay as written.)

  The old hex reference still finds a draft in search. The legacy refresh
  ignores rfq_number (it is platform-only).

  Safe to re-run: each step checks before it acts, and a second run changes
  nothing. A fresh database gets the same shape from create-rfq-schema.sql.
================================================================================
*/

SET NOCOUNT ON;
SET QUOTED_IDENTIFIER ON; -- the filtered unique index needs it (sqlcmd starts with it off)
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'[rfq].[rfq_draft_quotes]', N'U') IS NULL
    THROW 50001, N'[rfq].[rfq_draft_quotes] is missing. Run create-rfq-schema.sql first.', 1;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    -- Lock the drafts first so no insert takes a sequence value between the backfill and the restart.
    DECLARE @lock INT;
    SELECT @lock = COUNT_BIG(*) FROM [rfq].[rfq_draft_quotes] WITH (TABLOCKX, HOLDLOCK);

    IF NOT EXISTS (SELECT 1 FROM sys.sequences WHERE [object_id] = OBJECT_ID(N'[rfq].[rfq_draft_number_seq]'))
        EXEC (N'CREATE SEQUENCE [rfq].[rfq_draft_number_seq] AS INT START WITH 1 INCREMENT BY 1 NO CACHE');

    IF COL_LENGTH(N'[rfq].[rfq_draft_quotes]', N'rfq_number') IS NULL
        EXEC (N'ALTER TABLE [rfq].[rfq_draft_quotes] ADD [rfq_number] INT NULL
                    CONSTRAINT [DF_rfq_draft_quotes_rfq_number] DEFAULT (NEXT VALUE FOR [rfq].[rfq_draft_number_seq])');

    -- Dynamic SQL from here: the column did not exist when this batch was compiled.
    EXEC (N'
        DECLARE @base INT = ISNULL((SELECT MAX([rfq_number]) FROM [rfq].[rfq_draft_quotes]), 0);

        WITH numbered AS (
            SELECT [rfq_number],
                   @base + ROW_NUMBER() OVER (ORDER BY [created_at], [id]) AS [n]
            FROM [rfq].[rfq_draft_quotes]
            WHERE [rfq_number] IS NULL
        )
        UPDATE numbered SET [rfq_number] = [n];

        IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [name] = N''UX_rfq_draft_quotes_rfq_number''
                         AND [object_id] = OBJECT_ID(N''[rfq].[rfq_draft_quotes]''))
            CREATE UNIQUE NONCLUSTERED INDEX [UX_rfq_draft_quotes_rfq_number]
                ON [rfq].[rfq_draft_quotes]([rfq_number]) WHERE [rfq_number] IS NOT NULL;

        DECLARE @max INT = (SELECT MAX([rfq_number]) FROM [rfq].[rfq_draft_quotes]);
        DECLARE @current BIGINT = (SELECT CAST([current_value] AS BIGINT) FROM sys.sequences
                                   WHERE [object_id] = OBJECT_ID(N''[rfq].[rfq_draft_number_seq]''));
        IF @max IS NOT NULL AND @max >= @current
        BEGIN
            DECLARE @restart NVARCHAR(200) = N''ALTER SEQUENCE [rfq].[rfq_draft_number_seq] RESTART WITH ''
                + CAST(@max + 1 AS NVARCHAR(20));
            EXEC (@restart);
        END;
    ');

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

-- The mail inbox keeps a text copy of the reference on each draft link.
IF OBJECT_ID(N'[mail].[message_links]', N'U') IS NOT NULL
BEGIN
    EXEC (N'
        UPDATE l
        SET l.[reference] = N''RFQ-'' + CASE WHEN d.[rfq_number] > 999999 THEN CAST(d.[rfq_number] AS NVARCHAR(20))
                                             ELSE RIGHT(N''000000'' + CAST(d.[rfq_number] AS NVARCHAR(20)), 6) END
        FROM [mail].[message_links] l
        JOIN [rfq].[rfq_draft_quotes] d ON d.[id] = LOWER(REPLACE(l.[rfq_public_id], N''-'', N''''))
        WHERE d.[rfq_number] IS NOT NULL
          AND l.[reference] <> N''RFQ-'' + CASE WHEN d.[rfq_number] > 999999 THEN CAST(d.[rfq_number] AS NVARCHAR(20))
                                                 ELSE RIGHT(N''000000'' + CAST(d.[rfq_number] AS NVARCHAR(20)), 6) END;
    ');
END;
GO
