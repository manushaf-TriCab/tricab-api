/*
================================================================================
  LOCAL ONLY. NOT FOR ANY SHARED DATABASE. Tri-Task sample data (Phase 2, T2.10).
  Refuses to run against Azure SQL (see the guard below); run against the local
  Docker database only, after create-finance-task-schema.sql and the Tri-Task
  seeds. Applying it to tricab-mel is forbidden.

  The dataset behind the 100 reference screenshots (finance-reference/screenshots/
  tri-task, 7 Oct 2026), so a page can be compared with its reference:

      3 entities, 8 statement areas, 5 projects (one on hold, one completed, one
      in the Bin), 12 tasks (11 live and one in the Bin: in progress, completed,
      two overdue, one urgent, one with a due date requested, one blocked by a
      dependency, one unassigned), 2 templates, 2 daily-summary recipients, and
      two mail rows on one task (one sent, one failed) so the reminder dialog has
      a "Previous correspondence" list. Nothing is sent: the rows are ledger
      history only.

  Invented throughout: the entities, projects and tasks are the references' own
  sample data; the PEOPLE are nine local test users with invented names and
  clearly local addresses (@sample.local). Never a real person or address. Their
  roles: one FINANCE_TASK_ADMIN, two FINANCE_TASK_MANAGER, six FINANCE_TASK_USER.

  Dates are relative to the run, in Australia/Melbourne, so "overdue" stays
  overdue and "due this week" stays this week: two tasks are overdue on any day
  this is run.

  Idempotent: every row has a fixed id and is inserted only if missing, so running
  it twice adds nothing. It never changes a row that exists.

  Also grants Tri-Task a send on a sample mailbox (tri-task@sample.local), beside
  the one seed-finance-mailboxes-local.sql already grants.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

/* ---------------------------------------------------------------------------
   Guard: local SQL Server only. Azure SQL Database, Managed Instance and Azure
   Synapse report these engine editions; the local Docker server is 3 (Enterprise
   and Developer), 2 (Standard) or 4 (Express).
   --------------------------------------------------------------------------- */
IF CAST(SERVERPROPERTY('EngineEdition') AS INT) NOT IN (2, 3, 4)
   OR @@SERVERNAME LIKE N'%database.windows.net%'
   OR @@SERVERNAME LIKE N'%tricab-mel%'
BEGIN
    THROW 54120, N'The Tri-Task sample seed is for the local Docker database only. Refusing to run against Azure SQL.', 1;
END;
IF OBJECT_ID(N'[finance].[task_tasks]', N'U') IS NULL OR OBJECT_ID(N'[identity].[Users]', N'U') IS NULL
BEGIN
    THROW 54121, N'Run create-finance-task-schema.sql and the identity schema first.', 1;
END;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Today DATE = CAST(SYSDATETIMEOFFSET() AT TIME ZONE N'AUS Eastern Standard Time' AS DATE);
    DECLARE @Base DATETIME2(6) = DATEADD(HOUR, -2, SYSUTCDATETIME());

    /* ------------------------------------------------------------------
       People: nine local test users, and their Tri-Task roles.
       ------------------------------------------------------------------ */
    DECLARE @People TABLE (
        [Key]       NVARCHAR(20)  NOT NULL PRIMARY KEY,
        [Email]     NVARCHAR(256) NOT NULL,
        [Name]      NVARCHAR(200) NOT NULL,
        [RoleCode]  NVARCHAR(100) NOT NULL
    );
    INSERT INTO @People ([Key], [Email], [Name], [RoleCode]) VALUES
        (N'admin', N'sample.admin@sample.local', N'Sample Administrator', N'FINANCE_TASK_ADMIN'),
        (N'lena',  N'lena.marsh@sample.local',   N'Lena Marsh',           N'FINANCE_TASK_MANAGER'),
        (N'noah',  N'noah.hale@sample.local',    N'Noah Hale',            N'FINANCE_TASK_MANAGER'),
        (N'owen',  N'owen.reed@sample.local',    N'Owen Reed',            N'FINANCE_TASK_USER'),
        (N'mia',   N'mia.ford@sample.local',     N'Mia Ford',             N'FINANCE_TASK_USER'),
        (N'ivan',  N'ivan.cole@sample.local',    N'Ivan Cole',            N'FINANCE_TASK_USER'),
        (N'ada',   N'ada.quinn@sample.local',    N'Ada Quinn',            N'FINANCE_TASK_USER'),
        (N'theo',  N'theo.park@sample.local',    N'Theo Park',            N'FINANCE_TASK_USER'),
        (N'rosa',  N'rosa.wynn@sample.local',    N'Rosa Wynn',            N'FINANCE_TASK_USER');

    INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
    SELECT p.[Email], LOWER(p.[Email]), p.[Name], s.[UserStatusId]
    FROM @People p
    CROSS JOIN [identity].[UserStatuses] s
    WHERE s.[StatusCode] = N'active'
      AND NOT EXISTS (SELECT 1 FROM [identity].[Users] u WHERE u.[NormalizedEmailAddress] = LOWER(p.[Email]));

    INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [IsActive])
    SELECT u.[UserId], r.[RoleId], 1
    FROM @People p
    JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(p.[Email])
    JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = p.[RoleCode]
    WHERE NOT EXISTS (SELECT 1 FROM [identity].[UserRoles] ur WHERE ur.[UserId] = u.[UserId] AND ur.[RoleId] = r.[RoleId]);

    IF (SELECT COUNT(*) FROM @People p
         JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(p.[Email])
         JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[IsActive] = 1) < 9
    BEGIN
        THROW 54122, N'The Tri-Task roles are missing. Run seed-finance-task-roles.sql first.', 1;
    END;

    DECLARE @Ids TABLE ([Key] NVARCHAR(20) NOT NULL PRIMARY KEY, [UserId] BIGINT NOT NULL, [Name] NVARCHAR(200) NOT NULL);
    INSERT INTO @Ids ([Key], [UserId], [Name])
    SELECT p.[Key], u.[UserId], p.[Name] FROM @People p JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(p.[Email]);

    /* ------------------------------------------------------------------
       Entities, statement areas, projects, templates
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[task_entities] ([id], [name], [code], [description], [color], [is_active], [created_date], [updated_date])
    SELECT v.[id], v.[name], v.[code], v.[description], v.[color], 1, @Base, @Base
    FROM (VALUES
        ('5a1e0000000000000000000000000001', N'Demo Holdings Pty Ltd', N'DHP', N'Parent entity (sample)',          N'#6366F1'),
        ('5a1e0000000000000000000000000002', N'Demo Trading Pty Ltd',  N'DTP', N'Trading entity (sample)',         N'#10B981'),
        ('5a1e0000000000000000000000000003', N'Demo NZ Ltd',           N'DNZ', N'New Zealand entity (sample)',     N'#F59E0B')
    ) v ([id], [name], [code], [description], [color])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_entities] e WHERE e.[id] = v.[id] OR e.[name] = v.[name]);

    INSERT INTO [finance].[task_statement_areas] ([id], [name], [category], [description], [sort_order], [created_date], [updated_date])
    SELECT v.[id], v.[name], v.[category], N'', v.[sort], @Base, @Base
    FROM (VALUES
        ('5a1a0000000000000000000000000001', N'Cash and cash equivalents',         N'balance_sheet',    0),
        ('5a1a0000000000000000000000000002', N'Trade receivables',                 N'balance_sheet',    1),
        ('5a1a0000000000000000000000000003', N'Trade payables',                    N'balance_sheet',    2),
        ('5a1a0000000000000000000000000004', N'Revenue',                           N'income_statement', 3),
        ('5a1a0000000000000000000000000005', N'Cost of sales',                     N'income_statement', 4),
        ('5a1a0000000000000000000000000006', N'Net cash from operating activities', N'cash_flow',       5),
        ('5a1a0000000000000000000000000007', N'Share capital',                     N'equity',           6),
        ('5a1a0000000000000000000000000008', N'Related party disclosures',         N'notes',            7)
    ) v ([id], [name], [category], [sort])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_statement_areas] a WHERE a.[id] = v.[id] OR a.[name] = v.[name]);

    INSERT INTO [finance].[task_projects] ([id], [name], [description], [color], [status], [target_date], [deleted_at], [deleted_by], [created_date], [updated_date])
    SELECT v.[id], v.[name], v.[description], v.[color], v.[status],
           CASE WHEN v.[target] IS NULL THEN NULL ELSE CONVERT(CHAR(10), DATEADD(DAY, v.[target], @Today), 23) END,
           CASE WHEN v.[binned] = 1 THEN DATEADD(MINUTE, -30, SYSUTCDATETIME()) END,
           CASE WHEN v.[binned] = 1 THEN (SELECT [UserId] FROM @Ids WHERE [Key] = N'admin') END,
           @Base, @Base
    FROM (VALUES
        ('5a1b0000000000000000000000000001', N'September month-end close',   N'Month-end close for September 2026', N'#6366F1', N'active',    3,    0),
        ('5a1b0000000000000000000000000002', N'FY26 audit preparation',      N'Audit schedules and support',        N'#10B981', N'active',    54,   0),
        ('5a1b0000000000000000000000000003', N'Bank reconciliation clean-up', N'Clear aged reconciling items',      N'#F59E0B', N'on_hold',   NULL, 0),
        ('5a1b0000000000000000000000000004', N'Tax compliance FY25',         N'Lodged and finished',                N'#64748B', N'completed', -99,  0),
        ('5a1b0000000000000000000000000005', N'Retired pilot project',       N'Moved to the Bin',                   N'#EF4444', N'active',    NULL, 1)
    ) v ([id], [name], [description], [color], [status], [target], [binned])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_projects] p WHERE p.[id] = v.[id]);

    INSERT INTO [finance].[task_templates]
        ([id], [name], [description], [requirements], [category], [finance_statement_area], [preparer_user_id], [reviewer_user_id], [default_progress], [due_date], [due_date_requested], [created_date], [updated_date])
    SELECT v.[id], v.[name], v.[description], v.[requirements], v.[category], v.[area],
           (SELECT [UserId] FROM @Ids WHERE [Key] = v.[prep]), (SELECT [UserId] FROM @Ids WHERE [Key] = v.[rev]),
           0, NULL, v.[requested], @Base, @Base
    FROM (VALUES
        ('5a1c0000000000000000000000000001', N'Monthly bank reconciliation', N'Standard month-end bank rec.',  N'- Bank statements' + NCHAR(10) + N'- Cash book', N'routine', N'Cash and cash equivalents', N'mia', N'lena', 0),
        ('5a1c0000000000000000000000000002', N'Audit request response',      N'Respond to an auditor request.', N'',                                              N'ad_hoc', N'',                          NULL,   NULL,   1)
    ) v ([id], [name], [description], [requirements], [category], [area], [prep], [rev], [requested])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_templates] t WHERE t.[id] = v.[id]);

    /* ------------------------------------------------------------------
       Tasks: 12, in the references' order of creation (the register shows the
       newest change first, so the order is the reference's).
       ------------------------------------------------------------------ */
    DECLARE @Tasks TABLE (
        [Seq] INT NOT NULL PRIMARY KEY, [id] CHAR(32) NOT NULL, [name] NVARCHAR(300) NOT NULL, [description] NVARCHAR(MAX) NULL,
        [requirements] NVARCHAR(MAX) NULL, [notes] NVARCHAR(MAX) NULL, [category] NVARCHAR(20) NOT NULL, [area] NVARCHAR(300) NULL,
        [entity] CHAR(32) NULL, [project] CHAR(32) NULL, [prep] NVARCHAR(20) NULL, [rev] NVARCHAR(20) NULL, [status] NVARCHAR(20) NOT NULL,
        [progress] INT NOT NULL, [due] INT NULL, [requested] BIT NOT NULL, [urgent] BIT NOT NULL, [binned] BIT NOT NULL
    );
    DECLARE @DHP CHAR(32) = '5a1e0000000000000000000000000001', @DTP CHAR(32) = '5a1e0000000000000000000000000002', @DNZ CHAR(32) = '5a1e0000000000000000000000000003';
    DECLARE @Sept CHAR(32) = '5a1b0000000000000000000000000001', @Audit CHAR(32) = '5a1b0000000000000000000000000002', @BankCl CHAR(32) = '5a1b0000000000000000000000000003',
            @Tax CHAR(32) = '5a1b0000000000000000000000000004', @Pilot CHAR(32) = '5a1b0000000000000000000000000005';
    INSERT INTO @Tasks VALUES
     (1,  '5a1d0000000000000000000000000001', N'Reconcile operating bank accounts', N'Reconcile all operating accounts to the bank statements.', N'- September bank statements' + NCHAR(10) + N'- Cash book', N'Waiting on one statement from the bank.', N'routine', N'Cash and cash equivalents', @DHP, @Sept,   N'mia',  N'lena', N'in_progress', 60, -6, 0, 1, 0),
     (2,  '5a1d0000000000000000000000000002', N'Accounts receivable ageing review', N'Review the ageing and chase balances over 60 days.', NULL, NULL, N'routine', N'Trade receivables',  @DTP, @Sept,  N'ada',  N'owen', N'in_progress', 30, 2,  0, 0, 0),
     (3,  '5a1d0000000000000000000000000003', N'Accounts payable accruals',          NULL, N'- GRNI listing' + NCHAR(10) + N'- Unprocessed invoices', NULL, N'routine', N'Trade payables',     @DTP, @Sept,  N'ivan', N'lena', N'in_progress', 80, 1,  0, 0, 0),
     (4,  '5a1d0000000000000000000000000004', N'Revenue cut-off testing',            NULL, NULL, NULL, N'routine', N'Revenue',                              @DHP, @Sept,  N'rosa', N'noah', N'completed',   100, -2, 0, 0, 0),
     (5,  '5a1d0000000000000000000000000005', N'Intercompany reconciliation',        NULL, NULL, NULL, N'ad_hoc',  N'Related party disclosures',            @DNZ, @Sept,  N'theo', N'owen', N'in_progress', 10, 8,  0, 0, 0),
     (6,  '5a1d0000000000000000000000000006', N'Audit PBC list: fixed assets',       NULL, NULL, NULL, N'ad_hoc',  N'Share capital',                        @DHP, @Audit, N'mia',  N'noah', N'in_progress', 0,  44, 0, 0, 0),
     (7,  '5a1d0000000000000000000000000007', N'Audit PBC list: debtors confirmations', NULL, NULL, NULL, N'ad_hoc', NULL,                                 @DTP, @Audit, N'ada',  N'lena', N'in_progress', 0,  NULL, 1, 0, 0),
     (8,  '5a1d0000000000000000000000000008', N'Clear aged unreconciled items',      NULL, NULL, NULL, N'ad_hoc',  N'Cash and cash equivalents',            @DNZ, @BankCl, N'ivan', N'owen', N'in_progress', 40, -12, 0, 0, 0),
     (9,  '5a1d0000000000000000000000000009', N'Lodge FY25 income tax return',       NULL, NULL, NULL, N'routine', NULL,                                    @DHP, @Tax,   N'rosa', N'noah', N'completed',   100, -99, 0, 0, 0),
     (10, '5a1d000000000000000000000000000a', N'Prepare cash flow statement',        NULL, NULL, NULL, N'routine', N'Net cash from operating activities', @DHP, @Sept,  N'mia',  N'lena', N'in_progress', 0,  5,  0, 0, 0),
     (11, '5a1d000000000000000000000000000b', N'Pilot task to be binned',            NULL, NULL, NULL, N'routine', NULL,                                    @DTP, @Pilot, N'theo', N'noah', N'in_progress', 0,  13, 0, 0, 1),
     (12, '5a1d000000000000000000000000000c', N'Unassigned housekeeping task',       NULL, NULL, NULL, N'ad_hoc',  NULL,                                    NULL, NULL,   NULL,    NULL,   N'in_progress', 0,  23, 0, 0, 0);

    INSERT INTO [finance].[task_tasks]
        ([id], [name], [description], [requirements], [notes], [category], [finance_statement_area], [entity_id], [project_id], [preparer_user_id], [reviewer_user_id],
         [status], [progress], [due_date], [due_date_requested], [urgent], [completed_at], [created_by_user_id], [deleted_at], [deleted_by], [created_date], [updated_date])
    SELECT t.[id], t.[name], t.[description], t.[requirements], t.[notes], t.[category], t.[area], t.[entity], t.[project],
           (SELECT [UserId] FROM @Ids WHERE [Key] = t.[prep]), (SELECT [UserId] FROM @Ids WHERE [Key] = t.[rev]),
           t.[status], t.[progress], CASE WHEN t.[due] IS NULL THEN NULL ELSE CONVERT(CHAR(10), DATEADD(DAY, t.[due], @Today), 23) END,
           t.[requested], t.[urgent],
           CASE WHEN t.[status] = N'completed' THEN DATEADD(SECOND, t.[Seq], @Base) END,
           (SELECT [UserId] FROM @Ids WHERE [Key] = N'admin'),
           CASE WHEN t.[binned] = 1 THEN DATEADD(MINUTE, -20, SYSUTCDATETIME()) END,
           CASE WHEN t.[binned] = 1 THEN (SELECT [UserId] FROM @Ids WHERE [Key] = N'admin') END,
           DATEADD(SECOND, t.[Seq], @Base), DATEADD(SECOND, t.[Seq], @Base)
    FROM @Tasks t
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_tasks] e WHERE e.[id] = t.[id]);

    /* The blocked one: the cash flow statement waits for the bank reconciliation. */
    INSERT INTO [finance].[task_dependencies] ([id], [task_id], [depends_on_task_id], [created_date])
    SELECT '5a1f0000000000000000000000000001', '5a1d000000000000000000000000000a', '5a1d0000000000000000000000000001', @Base
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_dependencies] d WHERE d.[id] = '5a1f0000000000000000000000000001');
    UPDATE [finance].[task_tasks] SET [updated_date] = DATEADD(SECOND, 13, @Base)
    WHERE [id] = '5a1d000000000000000000000000000a' AND [updated_date] = DATEADD(SECOND, 10, @Base);

    /* One creation row of history per task, as the app writes it. */
    INSERT INTO [finance].[task_history] ([id], [task_id], [from_status], [to_status], [changed_by_user_id], [changed_by_name], [changed_at], [created_date], [updated_date])
    SELECT '5a200000000000000000000000' + RIGHT(t.[id], 6), t.[id], NULL, t.[status], (SELECT [UserId] FROM @Ids WHERE [Key] = N'admin'),
           (SELECT [Name] FROM @Ids WHERE [Key] = N'admin'), DATEADD(SECOND, t.[Seq], @Base), DATEADD(SECOND, t.[Seq], @Base), DATEADD(SECOND, t.[Seq], @Base)
    FROM @Tasks t
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_history] h WHERE h.[id] = '5a200000000000000000000000' + RIGHT(t.[id], 6));

    /* ------------------------------------------------------------------
       The daily-summary recipients, and the profiles the Team page shows
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[task_report_recipients] ([id], [email], [name], [user_id], [created_date], [updated_date])
    SELECT v.[id], p.[Email], p.[Name], (SELECT [UserId] FROM @Ids WHERE [Key] = v.[key]), @Base, @Base
    FROM (VALUES ('5a210000000000000000000000000001', N'noah'), ('5a210000000000000000000000000002', N'lena')) v ([id], [key])
    JOIN @People p ON p.[Key] = v.[key]
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_report_recipients] r WHERE r.[id] = v.[id] OR r.[email] = p.[Email]);

    /* ------------------------------------------------------------------
       Mail: a sample mailbox Tri-Task may send from, and two ledger rows on the
       bank reconciliation (one sent, one failed). Nothing is ever sent.
       ------------------------------------------------------------------ */
    INSERT INTO [finance].[mailboxes] ([id], [email], [display_name], [purpose], [is_active])
    SELECT '5a220000000000000000000000000001', N'tri-task@sample.local', N'Tri-Task (sample)', N'Sample sending mailbox (local development row)', 1
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[mailboxes] m WHERE m.[email] = N'tri-task@sample.local');

    INSERT INTO [finance].[mailbox_grants] ([id], [application_key], [mailbox_id], [can_read], [can_send])
    SELECT '5a230000000000000000000000000001', N'TASK', m.[id], 0, 1
    FROM [finance].[mailboxes] m
    WHERE m.[email] = N'tri-task@sample.local'
      AND NOT EXISTS (SELECT 1 FROM [finance].[mailbox_grants] g WHERE g.[application_key] = N'TASK' AND g.[mailbox_id] = m.[id]);

    INSERT INTO [finance].[mail_deliveries]
        ([id], [application_key], [actor_user_id], [mailbox_email], [to_addresses_json], [cc_addresses_json], [subject], [body_sha256],
         [attachment_names_json], [idempotency_key], [status], [error_message], [created_at], [sent_at])
    SELECT v.[id], N'TASK', (SELECT [UserId] FROM @Ids WHERE [Key] = N'lena'), N'tri-task@sample.local', N'["mia.ford@sample.local"]', N'[]', v.[subject],
           REPLICATE(N'0', 64), N'[]', NULL, v.[status], v.[error], DATEADD(DAY, v.[daysAgo], SYSUTCDATETIME()), v.[sentAt]
    FROM (VALUES
        ('5a240000000000000000000000000001', N'Reminder: Reconcile operating bank accounts', N'sent',   CAST(NULL AS NVARCHAR(500)), -5, DATEADD(DAY, -5, SYSUTCDATETIME())),
        ('5a240000000000000000000000000002', N'Task assigned: Reconcile operating bank accounts', N'failed', N'Sample failure',               -6, CAST(NULL AS DATETIME2(6)))
    ) v ([id], [subject], [status], [error], [daysAgo], [sentAt])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[mail_deliveries] d WHERE d.[id] = v.[id]);

    INSERT INTO [finance].[task_mail_links] ([id], [kind], [task_id], [delivery_id], [created_at])
    SELECT v.[id], v.[kind], '5a1d0000000000000000000000000001', v.[delivery], DATEADD(DAY, v.[daysAgo], SYSUTCDATETIME())
    FROM (VALUES
        ('5a250000000000000000000000000001', N'reminder',   '5a240000000000000000000000000001', -5),
        ('5a250000000000000000000000000002', N'task_email', '5a240000000000000000000000000002', -6)
    ) v ([id], [kind], [delivery], [daysAgo])
    WHERE NOT EXISTS (SELECT 1 FROM [finance].[task_mail_links] l WHERE l.[id] = v.[id]);

    /* ------------------------------------------------------------------
       Guard: the dataset is whole. 11 live tasks and 1 in the Bin, 4 live
       projects and 1 in the Bin.
       ------------------------------------------------------------------ */
    IF (SELECT COUNT(*) FROM [finance].[task_tasks] WHERE [id] LIKE '5a1d%' AND [deleted_at] IS NULL) <> 11
       OR (SELECT COUNT(*) FROM [finance].[task_tasks] WHERE [id] LIKE '5a1d%' AND [deleted_at] IS NOT NULL) <> 1
       OR (SELECT COUNT(*) FROM [finance].[task_projects] WHERE [id] LIKE '5a1b%' AND [deleted_at] IS NULL) <> 4
    BEGIN
        THROW 54123, N'The Tri-Task sample data is not whole (expected 11 live tasks, 1 binned, 4 live projects).', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [finance].[task_entities])         AS [Entities],
        (SELECT COUNT(*) FROM [finance].[task_statement_areas])  AS [Areas],
        (SELECT COUNT(*) FROM [finance].[task_projects])         AS [Projects],
        (SELECT COUNT(*) FROM [finance].[task_tasks])            AS [Tasks],
        (SELECT COUNT(*) FROM [finance].[task_templates])        AS [Templates],
        (SELECT COUNT(*) FROM [finance].[task_report_recipients]) AS [Recipients];

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();

    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
