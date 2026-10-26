/*
================================================================================
  HR Employees — [hr] schema: a READ-ONLY mirror of the legacy payroll table
  tc.employee (MySQL).

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Run AFTER create-identity-schema.sql: [hr].[Employees].[UserId] has a foreign
  key into [identity].[Users]. Nothing in [identity] points back here.

  ------------------------------------------------------------------------------
  Payroll stays the master. These tables are written by one thing only — the
  scheduled sync (bin/sync-hr-employees.php) — which never writes back to
  tc.employee and never deletes a row here: an employee missing from the source
  is flagged (MissingFromSourceAt), not removed.

  Plan: platform/plans/HR-EMPLOYEES-PLAN.md (decisions 1-5).

  The two employee tables are split on purpose, the same pattern as
  [onboarding_hr]:

    [hr].[Employees]                  Directory data. Name, code, department,
                                      factory, manager, start date, work email
                                      (only @tricab.com addresses are kept).
                                      Readable with HR.EMPLOYEES.READ.

    [hr].[EmployeeRestrictedDetails]  SENSITIVE PERSONAL DATA. Date of birth,
                                      home address, personal mobile, personal
                                      email, leave balances. Readable ONLY with
                                      HR.EMPLOYEES.RESTRICTED.READ. A query
                                      over [hr].[Employees] cannot return any
                                      of it by accident.

  Be honest about what the split buys: the API connects as one database
  principal, so this is not a database permission boundary today. It buys an
  accidental SELECT * that cannot leak a birth date, a clear list of the reads
  worth auditing, and a later GRANT-per-table without moving data.

  Idempotent / safe to re-run. Creates no users and links no one: UserId is
  filled by the sync, case-insensitively by login, and never creates a user.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'hr')
BEGIN
    EXEC(N'CREATE SCHEMA [hr]');
END
GO

/* ============================================================================
   [hr].[Employees] — one row per tc.employee row, keyed by EmployeeCode.

   NormalizedUserLogin is LOWER(UserLogin). It is indexed but NOT unique: the
   source holds case-variant duplicates (e.g. ELOISED), and the mirror records
   what payroll says rather than refusing it.

   SourceHash is the sha256 (hex) of the full source row, including the
   restricted columns, so the sync can skip an unchanged row without comparing
   fields. It is not reversible and holds no data of its own.

   IsCurrent: present in the newest payroll refresh (plan decision 3).
   ============================================================================ */
IF OBJECT_ID(N'[hr].[Employees]', N'U') IS NULL
BEGIN
    BEGIN TRY
        BEGIN TRANSACTION;

        CREATE TABLE [hr].[Employees] (
            [EmployeeId]          BIGINT IDENTITY(1,1) NOT NULL,
            [EmployeeCode]        NVARCHAR(20)  NOT NULL,
            [UserLogin]           NVARCHAR(60)  NULL,
            [NormalizedUserLogin] NVARCHAR(60)  NULL,
            [FirstName]           NVARCHAR(50)  NULL,
            [LastName]            NVARCHAR(50)  NULL,
            [DisplayName]         NVARCHAR(100) NULL,
            [DepartmentRaw]       NVARCHAR(100) NULL,
            [FactoryRaw]          NVARCHAR(20)  NULL,
            /* Only @tricab.com addresses. A personal address is sensitive and
               lives in [hr].[EmployeeRestrictedDetails]. */
            [WorkEmail]           NVARCHAR(256) NULL,
            [ManagerLogin]        NVARCHAR(60)  NULL,
            [DateHired]           DATE NULL,
            [LegacyUpdateDate]    DATE NULL,
            [IsCurrent]           BIT NOT NULL
                CONSTRAINT [DF_hr_Employees_IsCurrent] DEFAULT (0),
            [UserId]              BIGINT NULL,
            [SourceHash]          CHAR(64) NOT NULL,
            [FirstSeenAt]         DATETIMEOFFSET(7) NOT NULL
                CONSTRAINT [DF_hr_Employees_FirstSeenAt] DEFAULT (SYSDATETIMEOFFSET()),
            [LastSyncedAt]        DATETIMEOFFSET(7) NOT NULL
                CONSTRAINT [DF_hr_Employees_LastSyncedAt] DEFAULT (SYSDATETIMEOFFSET()),
            [MissingFromSourceAt] DATETIMEOFFSET(7) NULL,
            [CreatedAt]           DATETIMEOFFSET(7) NOT NULL
                CONSTRAINT [DF_hr_Employees_CreatedAt] DEFAULT (SYSDATETIMEOFFSET()),
            [UpdatedAt]           DATETIMEOFFSET(7) NOT NULL
                CONSTRAINT [DF_hr_Employees_UpdatedAt] DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT [PK_hr_Employees] PRIMARY KEY CLUSTERED ([EmployeeId]),
            CONSTRAINT [UQ_hr_Employees_EmployeeCode] UNIQUE ([EmployeeCode]),
            CONSTRAINT [CK_hr_Employees_EmployeeCode_NotEmpty]
                CHECK (LEN(LTRIM(RTRIM([EmployeeCode]))) > 0),
            CONSTRAINT [FK_hr_Employees_User] FOREIGN KEY ([UserId])
                REFERENCES [identity].[Users] ([UserId])
        );

        CREATE NONCLUSTERED INDEX [IX_hr_Employees_IsCurrent]
            ON [hr].[Employees] ([IsCurrent]);

        /* Not unique — see the header note on case-variant duplicates. */
        CREATE NONCLUSTERED INDEX [IX_hr_Employees_NormalizedUserLogin]
            ON [hr].[Employees] ([NormalizedUserLogin])
            WHERE [NormalizedUserLogin] IS NOT NULL;

        CREATE NONCLUSTERED INDEX [IX_hr_Employees_ManagerLogin]
            ON [hr].[Employees] ([ManagerLogin]);

        CREATE NONCLUSTERED INDEX [IX_hr_Employees_UserId]
            ON [hr].[Employees] ([UserId]);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END
GO

/* ============================================================================
   [hr].[EmployeeRestrictedDetails] — SENSITIVE PERSONAL DATA.

   Holds date of birth, home address, personal mobile, personal email and leave
   balances. Readable ONLY with HR.EMPLOYEES.RESTRICTED.READ. Every API read of
   this table must be gated on that permission, and the field must be absent
   (not null-filled) from a response for a caller who lacks it. Never join it
   into a directory query, never log it, never copy it elsewhere.

   1:1 with [hr].[Employees] by EmployeeCode. LeaveAsOf is the payroll refresh
   date the balances were true on, and must be shown with them.
   ============================================================================ */
IF OBJECT_ID(N'[hr].[EmployeeRestrictedDetails]', N'U') IS NULL
BEGIN
    BEGIN TRY
        BEGIN TRANSACTION;

        CREATE TABLE [hr].[EmployeeRestrictedDetails] (
            [EmployeeCode]              NVARCHAR(20)  NOT NULL,
            [PersonalEmail]             NVARCHAR(256) NULL,
            [Mobile]                    NVARCHAR(30)  NULL,
            [Street]                    NVARCHAR(100) NULL,
            [Suburb]                    NVARCHAR(100) NULL,
            [State]                     NVARCHAR(50)  NULL,
            [Postcode]                  NVARCHAR(10)  NULL,
            [DateOfBirth]               DATE NULL,
            [AnnualLeaveRemaining]      DECIMAL(9,2) NULL,
            [LongServiceLeaveRemaining] DECIMAL(9,2) NULL,
            [SickLeaveRemaining]        DECIMAL(9,2) NULL,
            [LeaveAsOf]                 DATE NULL,
            [UpdatedAt]                 DATETIMEOFFSET(7) NOT NULL
                CONSTRAINT [DF_hr_EmployeeRestrictedDetails_UpdatedAt] DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT [PK_hr_EmployeeRestrictedDetails] PRIMARY KEY CLUSTERED ([EmployeeCode]),
            CONSTRAINT [FK_hr_EmployeeRestrictedDetails_Employee] FOREIGN KEY ([EmployeeCode])
                REFERENCES [hr].[Employees] ([EmployeeCode])
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END
GO

/* ============================================================================
   [hr].[DepartmentManagers] — mirror of tc.dept_manager (29 rows, old codes
   only; AltManagerLogin has never been set in the source). Reference data.
   ============================================================================ */
IF OBJECT_ID(N'[hr].[DepartmentManagers]', N'U') IS NULL
BEGIN
    BEGIN TRY
        BEGIN TRANSACTION;

        CREATE TABLE [hr].[DepartmentManagers] (
            [Department]      NVARCHAR(255) NOT NULL,
            [ManagerLogin]    NVARCHAR(255) NULL,
            [AltManagerLogin] NVARCHAR(50)  NULL,
            [LastSyncedAt]    DATETIMEOFFSET(7) NOT NULL
                CONSTRAINT [DF_hr_DepartmentManagers_LastSyncedAt] DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT [PK_hr_DepartmentManagers] PRIMARY KEY CLUSTERED ([Department])
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END
GO

/* ============================================================================
   [hr].[SyncRuns] — one row per sync run, dry-run or apply.

   A 'guarded' run is one the short-refresh guard stopped from changing the
   IsCurrent flags (plan decision 3); Warning says why. Error never holds row
   data, only the failure message.
   ============================================================================ */
IF OBJECT_ID(N'[hr].[SyncRuns]', N'U') IS NULL
BEGIN
    BEGIN TRY
        BEGIN TRANSACTION;

        CREATE TABLE [hr].[SyncRuns] (
            [SyncRunId]         BIGINT IDENTITY(1,1) NOT NULL,
            [StartedAt]         DATETIMEOFFSET(7) NOT NULL
                CONSTRAINT [DF_hr_SyncRuns_StartedAt] DEFAULT (SYSDATETIMEOFFSET()),
            [FinishedAt]        DATETIMEOFFSET(7) NULL,
            [Mode]              NVARCHAR(20) NOT NULL,
            [Status]            NVARCHAR(20) NOT NULL,
            [RowsRead]          INT NOT NULL CONSTRAINT [DF_hr_SyncRuns_RowsRead] DEFAULT (0),
            [RowsInserted]      INT NOT NULL CONSTRAINT [DF_hr_SyncRuns_RowsInserted] DEFAULT (0),
            [RowsUpdated]       INT NOT NULL CONSTRAINT [DF_hr_SyncRuns_RowsUpdated] DEFAULT (0),
            [RowsUnchanged]     INT NOT NULL CONSTRAINT [DF_hr_SyncRuns_RowsUnchanged] DEFAULT (0),
            [RowsMissing]       INT NOT NULL CONSTRAINT [DF_hr_SyncRuns_RowsMissing] DEFAULT (0),
            [CurrentCount]      INT NULL,
            [LatestPayrollDate] DATE NULL,
            [UnmatchedLogins]   INT NULL,
            [Warning]           NVARCHAR(1000) NULL,
            [Error]             NVARCHAR(2000) NULL,
            CONSTRAINT [PK_hr_SyncRuns] PRIMARY KEY CLUSTERED ([SyncRunId]),
            CONSTRAINT [CK_hr_SyncRuns_Mode] CHECK ([Mode] IN (N'dry-run', N'apply')),
            CONSTRAINT [CK_hr_SyncRuns_Status]
                CHECK ([Status] IN (N'running', N'succeeded', N'failed', N'guarded'))
        );

        CREATE NONCLUSTERED INDEX [IX_hr_SyncRuns_StartedAt]
            ON [hr].[SyncRuns] ([StartedAt] DESC);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END
GO
