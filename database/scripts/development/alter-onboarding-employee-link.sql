/*
================================================================================
  HR Employees phase 5 — link an onboarding person to their payroll employee.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Run AFTER create-onboarding-schema.sql and create-hr-employees-schema.sql
  (it points at both). Idempotent / safe to re-run. Creates no users, links no
  one, writes no row: every link is made later by an HR person in the console.

  What it adds
  ------------
  [onboarding].[TraineeProfiles]  (5.1)
    EmployeeCode          NULL until HR confirms a match. A payroll code, not a
                          copy of anything in payroll. FK to
                          [hr].[Employees].[EmployeeCode] (the mirror never
                          deletes rows, so the link cannot dangle).
    EmployeeLinkedAtUtc / EmployeeLinkedByUserId   who confirmed it, and when.
    Filtered unique index: one employee code belongs to one onboarding record.

  [hr].[OnboardingLinkProposals]  (5.2)
    One row per (onboarding person, employee) pair the sync suggested, with the
    reason (`login` or `name_start_date`) and what HR did with it. A proposal is
    never a link. Status: pending, confirmed, dismissed, superseded. A pair that
    has any row is never proposed again, so a dismissed match stays dismissed.

  Matching rules live in TriCab\Api\Hr\OnboardingLink\OnboardingLinkMatcher:
  login first (the employee's platform user is the onboarding person's user);
  otherwise the same name and a start date within 7 days of the payroll hire
  date. Never a link on name alone.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'[onboarding].[TraineeProfiles]', N'U') IS NULL
    THROW 50001, N'[onboarding].[TraineeProfiles] is missing: run create-onboarding-schema.sql first.', 1;
GO
IF OBJECT_ID(N'[hr].[Employees]', N'U') IS NULL
    THROW 50001, N'[hr].[Employees] is missing: run create-hr-employees-schema.sql first.', 1;
GO

/* ---- 5.1 columns -------------------------------------------------------- */
IF COL_LENGTH(N'[onboarding].[TraineeProfiles]', N'EmployeeCode') IS NULL
    ALTER TABLE [onboarding].[TraineeProfiles] ADD [EmployeeCode] NVARCHAR(20) NULL;
GO
IF COL_LENGTH(N'[onboarding].[TraineeProfiles]', N'EmployeeLinkedAtUtc') IS NULL
    ALTER TABLE [onboarding].[TraineeProfiles] ADD [EmployeeLinkedAtUtc] DATETIME2(7) NULL;
GO
IF COL_LENGTH(N'[onboarding].[TraineeProfiles]', N'EmployeeLinkedByUserId') IS NULL
    ALTER TABLE [onboarding].[TraineeProfiles] ADD [EmployeeLinkedByUserId] BIGINT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_onboarding_TraineeProfiles_EmployeeCode')
    ALTER TABLE [onboarding].[TraineeProfiles] ADD CONSTRAINT [FK_onboarding_TraineeProfiles_EmployeeCode]
        FOREIGN KEY ([EmployeeCode]) REFERENCES [hr].[Employees] ([EmployeeCode]);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_onboarding_TraineeProfiles_EmployeeLinkedByUserId')
    ALTER TABLE [onboarding].[TraineeProfiles] ADD CONSTRAINT [FK_onboarding_TraineeProfiles_EmployeeLinkedByUserId]
        FOREIGN KEY ([EmployeeLinkedByUserId]) REFERENCES [identity].[Users] ([UserId]);
GO
/* A code, when set, always says who linked it and when. */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_onboarding_TraineeProfiles_EmployeeLink')
    ALTER TABLE [onboarding].[TraineeProfiles] ADD CONSTRAINT [CK_onboarding_TraineeProfiles_EmployeeLink]
        CHECK (([EmployeeCode] IS NULL AND [EmployeeLinkedAtUtc] IS NULL AND [EmployeeLinkedByUserId] IS NULL)
            OR ([EmployeeCode] IS NOT NULL AND [EmployeeLinkedAtUtc] IS NOT NULL AND [EmployeeLinkedByUserId] IS NOT NULL));
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_onboarding_TraineeProfiles_EmployeeCode'
               AND object_id = OBJECT_ID(N'[onboarding].[TraineeProfiles]'))
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_TraineeProfiles_EmployeeCode]
        ON [onboarding].[TraineeProfiles] ([EmployeeCode]) WHERE [EmployeeCode] IS NOT NULL;
GO

/* ---- 5.2 proposals ------------------------------------------------------ */
IF OBJECT_ID(N'[hr].[OnboardingLinkProposals]', N'U') IS NULL
BEGIN
    CREATE TABLE [hr].[OnboardingLinkProposals] (
        [ProposalId]       BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]         UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_hr_OnboardingLinkProposals_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [TraineeProfileId] BIGINT NOT NULL,
        [EmployeeCode]     NVARCHAR(20) NOT NULL,
        [MatchReason]      NVARCHAR(30) NOT NULL,
        [Status]           NVARCHAR(20) NOT NULL
            CONSTRAINT [DF_hr_OnboardingLinkProposals_Status] DEFAULT (N'pending'),
        [ProposedAtUtc]    DATETIME2(7) NOT NULL
            CONSTRAINT [DF_hr_OnboardingLinkProposals_ProposedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [DecidedAtUtc]     DATETIME2(7) NULL,
        [DecidedByUserId]  BIGINT NULL,
        CONSTRAINT [PK_hr_OnboardingLinkProposals] PRIMARY KEY CLUSTERED ([ProposalId]),
        CONSTRAINT [UQ_hr_OnboardingLinkProposals_PublicId] UNIQUE ([PublicId]),
        /* A pair is proposed once, whatever became of it. */
        CONSTRAINT [UQ_hr_OnboardingLinkProposals_Pair] UNIQUE ([TraineeProfileId], [EmployeeCode]),
        CONSTRAINT [CK_hr_OnboardingLinkProposals_MatchReason]
            CHECK ([MatchReason] IN (N'login', N'name_start_date')),
        CONSTRAINT [CK_hr_OnboardingLinkProposals_Status]
            CHECK ([Status] IN (N'pending', N'confirmed', N'dismissed', N'superseded')),
        CONSTRAINT [CK_hr_OnboardingLinkProposals_Decided]
            CHECK (([Status] = N'pending' AND [DecidedAtUtc] IS NULL)
                OR ([Status] <> N'pending' AND [DecidedAtUtc] IS NOT NULL)),
        CONSTRAINT [FK_hr_OnboardingLinkProposals_Profile]
            FOREIGN KEY ([TraineeProfileId]) REFERENCES [onboarding].[TraineeProfiles] ([TraineeProfileId])
            /* Deleting an unused onboarding record (it is only allowed while nothing is recorded
               against it) must not be blocked by a suggestion about it. */
            ON DELETE CASCADE,
        CONSTRAINT [FK_hr_OnboardingLinkProposals_Employee]
            FOREIGN KEY ([EmployeeCode]) REFERENCES [hr].[Employees] ([EmployeeCode]),
        CONSTRAINT [FK_hr_OnboardingLinkProposals_DecidedBy]
            FOREIGN KEY ([DecidedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );

    CREATE NONCLUSTERED INDEX [IX_hr_OnboardingLinkProposals_Profile]
        ON [hr].[OnboardingLinkProposals] ([TraineeProfileId], [Status]);
    CREATE NONCLUSTERED INDEX [IX_hr_OnboardingLinkProposals_Employee]
        ON [hr].[OnboardingLinkProposals] ([EmployeeCode], [Status]);
END
GO

PRINT N'HR onboarding link columns and proposals created/verified.';
GO
