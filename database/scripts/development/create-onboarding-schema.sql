/*
================================================================================
  Phase 2.2 — [onboarding] schema (classes C0, C1, C2) and its audit tables.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Companion: create-onboarding-hr-schema.sql, which holds C3 (payroll-sensitive)
  and C4 (restricted HR). The split is documented in
  docs/onboarding-architecture.md §5. Nothing in this file references a table in
  [onboarding_hr]: the boundary is only worth having if no query crosses it.

  What lives here:
    C0 Content   — training modules, questions, policy wording, form definitions
    C1 Workflow  — assignments, status, progress, attempts, acknowledgements
    C2 Ordinary HR — position, department, start date, manager, cohort

  Written in GO-separated batches rather than one transaction because this file
  creates triggers, and CREATE TRIGGER must begin its batch. Every object is
  guarded, so the file is idempotent and safe to re-run.

  Conventions followed from create-expense-schema.sql and create-identity-schema.sql:
  BIGINT IDENTITY internal keys, UNIQUEIDENTIFIER PublicId for anything a URL or
  another system names, DATETIME2(7) UTC, ROWVERSION where two administrators can
  edit the same record, FK to [identity].[Users]([UserId]) and never to an email
  address or a name.
================================================================================
*/

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'onboarding')
BEGIN
    EXEC(N'CREATE SCHEMA [onboarding]');
END
GO

/* ============================================================================
   Reference — retention classes

   Work order §6 asks for retention classifications on sensitive fields and
   generated documents. This table is that classification, and it is the only
   place a retention period is stated.

   [RetentionDays] is NULL for every class today, and NULL means "no figure has
   been decided; keep". That is deliberate, not an oversight:

     - The owner's leaver decision of 21 Sep 2026 sets a FOURTEEN DAY window for
       a departed person's READ ACCESS to their own records. It says nothing
       about how long the records are kept, and the two must not be conflated.
     - These are employee records. Fair Work Act 2009 (Cth) s.535 requires an
       employer to keep them for seven years. A retention job that deleted an
       induction a fortnight after somebody left would destroy evidence the
       business is obliged to hold.

   So the retention job (Phase 6, following bin/tri-expense-purge-expired-receipts.php)
   reads this table, finds NULL everywhere, and deletes nothing. Setting a figure
   is a deliberate act by an owner who has taken advice, recorded in one row.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[RetentionClasses]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[RetentionClasses] (
        [RetentionClassId]  TINYINT NOT NULL,
        [ClassCode]         NVARCHAR(8) NOT NULL,
        [ClassName]         NVARCHAR(64) NOT NULL,
        [Description]       NVARCHAR(400) NOT NULL,
        /* NULL = no retention figure decided. The purge job keeps the record. */
        [RetentionDays]     INT NULL,
        [DecidedByUserId]   BIGINT NULL,
        [DecidedAtUtc]      DATETIME2(7) NULL,
        [UpdatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_RetentionClasses_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_onboarding_RetentionClasses] PRIMARY KEY CLUSTERED ([RetentionClassId]),
        CONSTRAINT [UQ_onboarding_RetentionClasses_ClassCode] UNIQUE ([ClassCode]),
        CONSTRAINT [CK_onboarding_RetentionClasses_RetentionDays] CHECK ([RetentionDays] IS NULL OR [RetentionDays] > 0),
        /* A figure without an owner behind it is an accident waiting to be cited. */
        CONSTRAINT [CK_onboarding_RetentionClasses_Decided]
            CHECK ([RetentionDays] IS NULL OR ([DecidedByUserId] IS NOT NULL AND [DecidedAtUtc] IS NOT NULL)),
        CONSTRAINT [FK_onboarding_RetentionClasses_DecidedBy]
            FOREIGN KEY ([DecidedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

MERGE [onboarding].[RetentionClasses] AS target
USING (VALUES
    (0, N'C0', N'Content',            N'Training modules, questions, policy wording, form definitions. Not personal data.'),
    (1, N'C1', N'Workflow',           N'Assignments, status, progress, attempt answers, acknowledgement records, notifications.'),
    (2, N'C2', N'Ordinary HR',        N'Position, department, branch, start date, manager, induction checklist, confidentiality deed.'),
    (3, N'C3', N'Payroll-sensitive',  N'Bank details, superannuation, tax file declaration, date of birth, home address. Held in the restricted onboarding_hr schema.'),
    (4, N'C4', N'Restricted HR',      N'Health, immigration and identity documents, cultural identity, emergency contacts. Special-category personal information. Held in the restricted onboarding_hr schema.'),
    (5, N'C5', N'Generated documents',N'Completed form PDFs and candidate photographs. Bytes in a private blob container; metadata inherits its content class.')
) AS source ([RetentionClassId], [ClassCode], [ClassName], [Description])
    ON target.[RetentionClassId] = source.[RetentionClassId]
WHEN NOT MATCHED BY TARGET THEN
    INSERT ([RetentionClassId], [ClassCode], [ClassName], [Description], [RetentionDays])
    VALUES (source.[RetentionClassId], source.[ClassCode], source.[ClassName], source.[Description], NULL);
GO

/* ============================================================================
   C2 — Cohorts

   A LABEL, and nothing more. Work order invariant 8: cohorts allocate nothing.

   The source schema comments suggest changing `trainees.cohort_id` re-derives a
   person's assignments, which is the opposite rule and is open question 4. Until
   that is settled the target refuses to have the argument: there is no foreign
   key from [Assignments] to [Cohorts], so no allocation can be derived from a
   cohort even by accident. Moving a person between cohorts changes what they are
   called in a report and nothing they have to do.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[Cohorts]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[Cohorts] (
        [CohortId]      BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]      UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_Cohorts_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [Name]          NVARCHAR(160) NOT NULL,
        [StartDate]     DATE NULL,
        [IsActive]      BIT NOT NULL CONSTRAINT [DF_onboarding_Cohorts_IsActive] DEFAULT (1),
        [CreatedAtUtc]  DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Cohorts_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]  DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Cohorts_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]    ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_Cohorts] PRIMARY KEY CLUSTERED ([CohortId]),
        CONSTRAINT [UQ_onboarding_Cohorts_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_onboarding_Cohorts_Name] CHECK (LEN(LTRIM(RTRIM([Name]))) > 0)
    );
END
GO

/* ============================================================================
   C2 — Trainee profiles

   One row per person being onboarded. The person is [identity].[Users].[UserId]
   and nothing else: no email address, no name, no provider subject. Work order
   §5 and §6 both insist on that, and it is what makes ownership survive a person
   changing role, address or authentication method on their start date.

   [DepartedAtUtc] and [AccessEndsAtUtc] carry the leaver policy — see
   TriCab\Core\Onboarding\Domain\Service\LeaverPolicy, which is the authority on
   what they mean. In short: fourteen days of read access after departure, no
   submitting from the departure instant, and nothing at all to do with how long
   the records are kept.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[TraineeProfiles]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[TraineeProfiles] (
        [TraineeProfileId]   BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]           UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_TraineeProfiles_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [UserId]             BIGINT NOT NULL,
        [CohortId]           BIGINT NULL,
        [Position]           NVARCHAR(200) NULL,
        [Department]         NVARCHAR(200) NULL,
        [Branch]             NVARCHAR(200) NULL,
        [StartDate]          DATE NULL,
        [ManagerUserId]      BIGINT NULL,
        [InvitedAtUtc]       DATETIME2(7) NULL,
        [InvitedByUserId]    BIGINT NULL,
        /* The last day, as recorded by HR. Often set weeks ahead of time, so a
           future value means "serving out notice", not "gone" — LeaverPolicy
           treats those differently and so must every query here. */
        [DepartedAtUtc]      DATETIME2(7) NULL,
        [DepartureRecordedByUserId] BIGINT NULL,
        /* Persisted so "whose read window has closed" is an index seek rather
           than a table scan through PHP. The 14 must equal
           LeaverPolicy::ACCESS_WINDOW_DAYS; LeaverPolicyMatchesSchemaTest reads
           this file and fails if the two ever drift, because a grace period
           changed in one place and not the other is a silent authorization
           change that no ordinary test would catch. */
        [AccessEndsAtUtc]    AS DATEADD(DAY, 14, [DepartedAtUtc]) PERSISTED,
        [CreatedAtUtc]       DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_TraineeProfiles_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]       DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_TraineeProfiles_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]         ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_TraineeProfiles] PRIMARY KEY CLUSTERED ([TraineeProfileId]),
        CONSTRAINT [UQ_onboarding_TraineeProfiles_PublicId] UNIQUE ([PublicId]),
        /* One induction record per person. Two would split their evidence. */
        CONSTRAINT [UQ_onboarding_TraineeProfiles_UserId] UNIQUE ([UserId]),
        CONSTRAINT [CK_onboarding_TraineeProfiles_Departure]
            CHECK ([DepartedAtUtc] IS NULL OR [DepartureRecordedByUserId] IS NOT NULL),
        CONSTRAINT [FK_onboarding_TraineeProfiles_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_TraineeProfiles_ManagerUserId]
            FOREIGN KEY ([ManagerUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_TraineeProfiles_InvitedByUserId]
            FOREIGN KEY ([InvitedByUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_TraineeProfiles_DepartureRecordedByUserId]
            FOREIGN KEY ([DepartureRecordedByUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_TraineeProfiles_CohortId]
            FOREIGN KEY ([CohortId]) REFERENCES [onboarding].[Cohorts]([CohortId])
    );
    CREATE NONCLUSTERED INDEX [IX_onboarding_TraineeProfiles_CohortId]
        ON [onboarding].[TraineeProfiles]([CohortId]) WHERE [CohortId] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_onboarding_TraineeProfiles_ManagerUserId]
        ON [onboarding].[TraineeProfiles]([ManagerUserId]) WHERE [ManagerUserId] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_onboarding_TraineeProfiles_StartDate]
        ON [onboarding].[TraineeProfiles]([StartDate]) WHERE [StartDate] IS NOT NULL;
    /* Leavers whose read window is running or has run out. */
    CREATE NONCLUSTERED INDEX [IX_onboarding_TraineeProfiles_AccessEndsAtUtc]
        ON [onboarding].[TraineeProfiles]([AccessEndsAtUtc])
        INCLUDE ([UserId], [DepartedAtUtc])
        WHERE [DepartedAtUtc] IS NOT NULL;
END
GO

/* ============================================================================
   C0 — Training modules and their versions

   Content is versioned because evidence is: an attempt has to be readable
   against the questions as they were asked, not as they were later reworded.
   A published version is frozen — see the trigger below.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[TrainingModules]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[TrainingModules] (
        [TrainingModuleId]  BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]          UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_TrainingModules_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [Slug]              NVARCHAR(120) NOT NULL,
        [Title]             NVARCHAR(240) NOT NULL,
        [Summary]           NVARCHAR(1000) NULL,
        [IsActive]          BIT NOT NULL CONSTRAINT [DF_onboarding_TrainingModules_IsActive] DEFAULT (1),
        [CreatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_TrainingModules_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_TrainingModules_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]        ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_TrainingModules] PRIMARY KEY CLUSTERED ([TrainingModuleId]),
        CONSTRAINT [UQ_onboarding_TrainingModules_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_TrainingModules_Slug] UNIQUE ([Slug])
    );
END
GO

IF OBJECT_ID(N'[onboarding].[TrainingModuleVersions]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[TrainingModuleVersions] (
        [TrainingModuleVersionId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_TrainingModuleVersions_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [TrainingModuleId]        BIGINT NOT NULL,
        [VersionNumber]           INT NOT NULL,
        /* Ordered slide manifest. Presentation progress is validated against
           this and rejected if it names a slide the manifest does not, or if it
           arrives against a superseded version. */
        [PresentationManifestJson] NVARCHAR(MAX) NOT NULL,
        [RequiredSlideCount]      INT NOT NULL
            CONSTRAINT [DF_onboarding_TrainingModuleVersions_RequiredSlideCount] DEFAULT (0),
        [PassMarkPercent]         TINYINT NULL,
        [IsPublished]             BIT NOT NULL
            CONSTRAINT [DF_onboarding_TrainingModuleVersions_IsPublished] DEFAULT (0),
        [PublishedAtUtc]          DATETIME2(7) NULL,
        [PublishedByUserId]       BIGINT NULL,
        [RetiredAtUtc]            DATETIME2(7) NULL,
        [CreatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_TrainingModuleVersions_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_TrainingModuleVersions_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]              ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_TrainingModuleVersions] PRIMARY KEY CLUSTERED ([TrainingModuleVersionId]),
        CONSTRAINT [UQ_onboarding_TrainingModuleVersions_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_TrainingModuleVersions_Number] UNIQUE ([TrainingModuleId], [VersionNumber]),
        CONSTRAINT [CK_onboarding_TrainingModuleVersions_PassMark]
            CHECK ([PassMarkPercent] IS NULL OR [PassMarkPercent] BETWEEN 1 AND 100),
        CONSTRAINT [CK_onboarding_TrainingModuleVersions_Published]
            CHECK ([IsPublished] = 0 OR ([PublishedAtUtc] IS NOT NULL AND [PublishedByUserId] IS NOT NULL)),
        CONSTRAINT [FK_onboarding_TrainingModuleVersions_Module]
            FOREIGN KEY ([TrainingModuleId]) REFERENCES [onboarding].[TrainingModules]([TrainingModuleId]),
        CONSTRAINT [FK_onboarding_TrainingModuleVersions_PublishedBy]
            FOREIGN KEY ([PublishedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
    /* A7, for training: exactly one live version of a module is allocatable. */
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_TrainingModuleVersions_OneLive]
        ON [onboarding].[TrainingModuleVersions]([TrainingModuleId])
        WHERE [IsPublished] = 1 AND [RetiredAtUtc] IS NULL;
END
GO

IF OBJECT_ID(N'[onboarding].[Questions]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[Questions] (
        [QuestionId]              BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_Questions_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [TrainingModuleVersionId] BIGINT NOT NULL,
        [Ordinal]                 INT NOT NULL,
        [Prompt]                  NVARCHAR(MAX) NOT NULL,
        /* [{"key":"a","label":"…"}, …]. The projection served to a trainee is
           built by the domain's redaction, which strips the two columns below;
           they are never selected by a self-service repository. */
        [OptionsJson]             NVARCHAR(MAX) NOT NULL,
        [CorrectOptionKey]        NVARCHAR(40) NOT NULL,
        [CorrectReason]           NVARCHAR(MAX) NULL,
        [CreatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Questions_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Questions_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]              ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_Questions] PRIMARY KEY CLUSTERED ([QuestionId]),
        CONSTRAINT [UQ_onboarding_Questions_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_Questions_Ordinal] UNIQUE ([TrainingModuleVersionId], [Ordinal]),
        CONSTRAINT [CK_onboarding_Questions_CorrectOptionKey] CHECK (LEN(LTRIM(RTRIM([CorrectOptionKey]))) > 0),
        CONSTRAINT [FK_onboarding_Questions_Version]
            FOREIGN KEY ([TrainingModuleVersionId]) REFERENCES [onboarding].[TrainingModuleVersions]([TrainingModuleVersionId])
    );
END
GO

/* ============================================================================
   C0 — Policies and their versions

   A6: an acknowledgement stays bound to the version acknowledged. Superseding a
   policy creates a new row here and moves no acknowledgement — which is the
   whole reason wording lives on the version and not on the policy.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[Policies]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[Policies] (
        [PolicyId]      BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]      UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_Policies_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [Slug]          NVARCHAR(120) NOT NULL,
        [Title]         NVARCHAR(240) NOT NULL,
        [IsActive]      BIT NOT NULL CONSTRAINT [DF_onboarding_Policies_IsActive] DEFAULT (1),
        [CreatedAtUtc]  DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Policies_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]  DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Policies_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]    ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_Policies] PRIMARY KEY CLUSTERED ([PolicyId]),
        CONSTRAINT [UQ_onboarding_Policies_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_Policies_Slug] UNIQUE ([Slug])
    );
END
GO

IF OBJECT_ID(N'[onboarding].[PolicyVersions]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[PolicyVersions] (
        [PolicyVersionId]   BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]          UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_PolicyVersions_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [PolicyId]          BIGINT NOT NULL,
        [VersionNumber]     INT NOT NULL,
        /* Structured content, not HTML. The source's renderer rejects raw HTML
           in policy wording and so must this one: policy text is authored by HR,
           displayed to every new starter, and is therefore an injection path. */
        [ContentJson]       NVARCHAR(MAX) NOT NULL,
        [EffectiveFrom]     DATE NULL,
        [IsPublished]       BIT NOT NULL
            CONSTRAINT [DF_onboarding_PolicyVersions_IsPublished] DEFAULT (0),
        [PublishedAtUtc]    DATETIME2(7) NULL,
        [PublishedByUserId] BIGINT NULL,
        [SupersededAtUtc]   DATETIME2(7) NULL,
        [CreatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_PolicyVersions_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_PolicyVersions_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]        ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_PolicyVersions] PRIMARY KEY CLUSTERED ([PolicyVersionId]),
        CONSTRAINT [UQ_onboarding_PolicyVersions_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_PolicyVersions_Number] UNIQUE ([PolicyId], [VersionNumber]),
        CONSTRAINT [CK_onboarding_PolicyVersions_Published]
            CHECK ([IsPublished] = 0 OR ([PublishedAtUtc] IS NOT NULL AND [PublishedByUserId] IS NOT NULL)),
        CONSTRAINT [FK_onboarding_PolicyVersions_Policy]
            FOREIGN KEY ([PolicyId]) REFERENCES [onboarding].[Policies]([PolicyId]),
        CONSTRAINT [FK_onboarding_PolicyVersions_PublishedBy]
            FOREIGN KEY ([PublishedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_PolicyVersions_OneLive]
        ON [onboarding].[PolicyVersions]([PolicyId])
        WHERE [IsPublished] = 1 AND [SupersededAtUtc] IS NULL;
END
GO

/* ============================================================================
   C0 — Form definitions and their versions

   [AccessGroup] is the source's own classification (`hr`, `payroll`,
   `restricted_hr`) and it survives the port, because it is what decides where a
   submitted field is written. It classifies the form as a whole; the per-FIELD
   map that actually drives the split lives in
   [onboarding_hr].[FieldClassifications], because `employee-details` mixes
   ordinary HR, payroll and restricted fields in one payload and cannot be
   classified whole (architecture doc §5).
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[FormDefinitions]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[FormDefinitions] (
        [FormDefinitionId]  BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]          UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_FormDefinitions_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [Slug]              NVARCHAR(120) NOT NULL,
        [Title]             NVARCHAR(240) NOT NULL,
        [AccessGroup]       NVARCHAR(20) NOT NULL,
        [IsActive]          BIT NOT NULL CONSTRAINT [DF_onboarding_FormDefinitions_IsActive] DEFAULT (1),
        [CreatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_FormDefinitions_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_FormDefinitions_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]        ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_FormDefinitions] PRIMARY KEY CLUSTERED ([FormDefinitionId]),
        CONSTRAINT [UQ_onboarding_FormDefinitions_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_FormDefinitions_Slug] UNIQUE ([Slug]),
        CONSTRAINT [CK_onboarding_FormDefinitions_AccessGroup]
            CHECK ([AccessGroup] IN (N'hr', N'payroll', N'restricted_hr'))
    );
END
GO

IF OBJECT_ID(N'[onboarding].[FormDefinitionVersions]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[FormDefinitionVersions] (
        [FormDefinitionVersionId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_FormDefinitionVersions_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [FormDefinitionId]        BIGINT NOT NULL,
        [VersionNumber]           INT NOT NULL,
        [SchemaJson]              NVARCHAR(MAX) NOT NULL,
        [IsPublished]             BIT NOT NULL
            CONSTRAINT [DF_onboarding_FormDefinitionVersions_IsPublished] DEFAULT (0),
        [PublishedAtUtc]          DATETIME2(7) NULL,
        [PublishedByUserId]       BIGINT NULL,
        [RetiredAtUtc]            DATETIME2(7) NULL,
        [CreatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_FormDefinitionVersions_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_FormDefinitionVersions_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]              ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_FormDefinitionVersions] PRIMARY KEY CLUSTERED ([FormDefinitionVersionId]),
        CONSTRAINT [UQ_onboarding_FormDefinitionVersions_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_FormDefinitionVersions_Number] UNIQUE ([FormDefinitionId], [VersionNumber]),
        CONSTRAINT [CK_onboarding_FormDefinitionVersions_Published]
            CHECK ([IsPublished] = 0 OR ([PublishedAtUtc] IS NOT NULL AND [PublishedByUserId] IS NOT NULL)),
        CONSTRAINT [FK_onboarding_FormDefinitionVersions_Definition]
            FOREIGN KEY ([FormDefinitionId]) REFERENCES [onboarding].[FormDefinitions]([FormDefinitionId]),
        CONSTRAINT [FK_onboarding_FormDefinitionVersions_PublishedBy]
            FOREIGN KEY ([PublishedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
    /* A7: only one version of a named form is allocatable to new starters.
       The source's `form_definitions_one_active_version_idx`, restated. */
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_FormDefinitionVersions_OneLive]
        ON [onboarding].[FormDefinitionVersions]([FormDefinitionId])
        WHERE [IsPublished] = 1 AND [RetiredAtUtc] IS NULL;
END
GO

/* ============================================================================
   C1 — Assignments

   The allocation. A9 and work order invariant 8: allocation is EXPLICIT.
   Nothing is granted by cohort, role, title or department, and the schema is
   built so that it cannot be:

     - [AllocationSource] admits one value, N'explicit'. Adding N'cohort' later
       is a migration somebody has to write and defend, not a line of PHP.
     - There is no foreign key to [Cohorts] anywhere in this table.
     - A person with no row here sees nothing. That is the correct empty state,
       not a fault to be worked around by deriving something.

   Polymorphic across the three allocatable things, with one nullable FK each and
   a check that exactly one is set and matches [ItemType]. A single nullable
   "ItemId" with no FK would have been shorter and would have let a form
   assignment point at a deleted policy.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[Assignments]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[Assignments] (
        [AssignmentId]            BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_Assignments_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [UserId]                  BIGINT NOT NULL,
        [ItemType]                NVARCHAR(16) NOT NULL,
        [TrainingModuleVersionId] BIGINT NULL,
        [PolicyVersionId]         BIGINT NULL,
        [FormDefinitionVersionId] BIGINT NULL,
        [Status]                  NVARCHAR(24) NOT NULL
            CONSTRAINT [DF_onboarding_Assignments_Status] DEFAULT (N'not_started'),
        [AllocationSource]        NVARCHAR(16) NOT NULL
            CONSTRAINT [DF_onboarding_Assignments_AllocationSource] DEFAULT (N'explicit'),
        [AllocatedByUserId]       BIGINT NOT NULL,
        [AllocatedAtUtc]          DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Assignments_AllocatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [DueDate]                 DATE NULL,
        [StartedAtUtc]            DATETIME2(7) NULL,
        [SubmittedAtUtc]          DATETIME2(7) NULL,
        [CompletedAtUtc]          DATETIME2(7) NULL,
        [ReviewedByUserId]        BIGINT NULL,
        [ReviewedAtUtc]           DATETIME2(7) NULL,
        /* Evidence-preserving withdrawal (2.3): HR takes the item off somebody's
           plate, and every attempt, acknowledgement and submission already made
           against it stays exactly where it is. Withdrawal is a date, never a
           DELETE. */
        [WithdrawnAtUtc]          DATETIME2(7) NULL,
        [WithdrawnByUserId]       BIGINT NULL,
        [WithdrawalReason]        NVARCHAR(500) NULL,
        [CreatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Assignments_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Assignments_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        /* D2: two HR users can open the same person's allocation. */
        [RowVersion]              ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_Assignments] PRIMARY KEY CLUSTERED ([AssignmentId]),
        CONSTRAINT [UQ_onboarding_Assignments_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_onboarding_Assignments_ItemType]
            CHECK ([ItemType] IN (N'training', N'policy', N'form')),
        /* Codes must match TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus. */
        CONSTRAINT [CK_onboarding_Assignments_Status]
            CHECK ([Status] IN (N'not_started', N'in_progress', N'need_help', N'submitted', N'fix_and_resubmit', N'completed')),
        CONSTRAINT [CK_onboarding_Assignments_AllocationSource]
            CHECK ([AllocationSource] = N'explicit'),
        CONSTRAINT [CK_onboarding_Assignments_ExactlyOneItem] CHECK (
            (CASE WHEN [TrainingModuleVersionId] IS NOT NULL THEN 1 ELSE 0 END
           + CASE WHEN [PolicyVersionId]         IS NOT NULL THEN 1 ELSE 0 END
           + CASE WHEN [FormDefinitionVersionId] IS NOT NULL THEN 1 ELSE 0 END) = 1
        ),
        CONSTRAINT [CK_onboarding_Assignments_ItemMatchesType] CHECK (
            ([ItemType] = N'training' AND [TrainingModuleVersionId] IS NOT NULL) OR
            ([ItemType] = N'policy'   AND [PolicyVersionId]         IS NOT NULL) OR
            ([ItemType] = N'form'     AND [FormDefinitionVersionId] IS NOT NULL)
        ),
        CONSTRAINT [CK_onboarding_Assignments_Withdrawal]
            CHECK ([WithdrawnAtUtc] IS NULL OR [WithdrawnByUserId] IS NOT NULL),
        CONSTRAINT [FK_onboarding_Assignments_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_Assignments_AllocatedBy]
            FOREIGN KEY ([AllocatedByUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_Assignments_ReviewedBy]
            FOREIGN KEY ([ReviewedByUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_Assignments_WithdrawnBy]
            FOREIGN KEY ([WithdrawnByUserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_Assignments_TrainingModuleVersion]
            FOREIGN KEY ([TrainingModuleVersionId]) REFERENCES [onboarding].[TrainingModuleVersions]([TrainingModuleVersionId]),
        CONSTRAINT [FK_onboarding_Assignments_PolicyVersion]
            FOREIGN KEY ([PolicyVersionId]) REFERENCES [onboarding].[PolicyVersions]([PolicyVersionId]),
        CONSTRAINT [FK_onboarding_Assignments_FormDefinitionVersion]
            FOREIGN KEY ([FormDefinitionVersionId]) REFERENCES [onboarding].[FormDefinitionVersions]([FormDefinitionVersionId])
    );

    /* One live allocation of a given item per person. Withdrawn rows are excluded
       so the same item can be allocated again later without disturbing the
       evidence of the first time. */
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_Assignments_LiveTraining]
        ON [onboarding].[Assignments]([UserId], [TrainingModuleVersionId])
        WHERE [TrainingModuleVersionId] IS NOT NULL AND [WithdrawnAtUtc] IS NULL;
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_Assignments_LivePolicy]
        ON [onboarding].[Assignments]([UserId], [PolicyVersionId])
        WHERE [PolicyVersionId] IS NOT NULL AND [WithdrawnAtUtc] IS NULL;
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_Assignments_LiveForm]
        ON [onboarding].[Assignments]([UserId], [FormDefinitionVersionId])
        WHERE [FormDefinitionVersionId] IS NOT NULL AND [WithdrawnAtUtc] IS NULL;

    /* The trainee's own worklist — the single hottest query in the module. */
    CREATE NONCLUSTERED INDEX [IX_onboarding_Assignments_UserWorklist]
        ON [onboarding].[Assignments]([UserId], [Status])
        INCLUDE ([ItemType], [DueDate], [PublicId])
        WHERE [WithdrawnAtUtc] IS NULL;
    /* The reviewer queue: submitted and need_help, oldest first. */
    CREATE NONCLUSTERED INDEX [IX_onboarding_Assignments_ReviewQueue]
        ON [onboarding].[Assignments]([Status], [SubmittedAtUtc])
        INCLUDE ([UserId], [ItemType], [PublicId])
        WHERE [WithdrawnAtUtc] IS NULL;
    CREATE NONCLUSTERED INDEX [IX_onboarding_Assignments_DueDate]
        ON [onboarding].[Assignments]([DueDate])
        WHERE [DueDate] IS NOT NULL AND [WithdrawnAtUtc] IS NULL;
END
GO

/* ============================================================================
   C1 — Presentation progress

   One row per assignment, merged idempotently. [SlideIdsJson] holds the reached
   slides in manifest order, deduplicated; progress naming a slide outside the
   active manifest, or arriving against a superseded content version, is rejected
   by the domain before it reaches here. [TrainingModuleVersionId] is stored so
   that rejection has something to compare against.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[PresentationProgress]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[PresentationProgress] (
        [PresentationProgressId]  BIGINT IDENTITY(1,1) NOT NULL,
        [AssignmentId]            BIGINT NOT NULL,
        [TrainingModuleVersionId] BIGINT NOT NULL,
        [SlideIdsJson]            NVARCHAR(MAX) NOT NULL,
        [ReachedSlideCount]       INT NOT NULL
            CONSTRAINT [DF_onboarding_PresentationProgress_ReachedSlideCount] DEFAULT (0),
        [ReachedFinalSlide]       BIT NOT NULL
            CONSTRAINT [DF_onboarding_PresentationProgress_ReachedFinalSlide] DEFAULT (0),
        [LastSeenAtUtc]           DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_PresentationProgress_LastSeenAtUtc] DEFAULT (SYSUTCDATETIME()),
        [CreatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_PresentationProgress_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_PresentationProgress_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]              ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_PresentationProgress] PRIMARY KEY CLUSTERED ([PresentationProgressId]),
        CONSTRAINT [UQ_onboarding_PresentationProgress_Assignment] UNIQUE ([AssignmentId]),
        CONSTRAINT [CK_onboarding_PresentationProgress_ReachedSlideCount] CHECK ([ReachedSlideCount] >= 0),
        CONSTRAINT [FK_onboarding_PresentationProgress_Assignment]
            FOREIGN KEY ([AssignmentId]) REFERENCES [onboarding].[Assignments]([AssignmentId]),
        CONSTRAINT [FK_onboarding_PresentationProgress_Version]
            FOREIGN KEY ([TrainingModuleVersionId]) REFERENCES [onboarding].[TrainingModuleVersions]([TrainingModuleVersionId])
    );
END
GO

/* ============================================================================
   C1 — Attempts and their answers (IMMUTABLE)

   [QuestionSnapshotJson] is the assessment as it was put to this person, correct
   answers included. Grading reads it and never the request: a client that sends
   its own answer key is answering a question nobody asked it.

   Answers are insert-only, enforced by trigger below, not by convention.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[Attempts]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[Attempts] (
        [AttemptId]            BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]             UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_Attempts_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [AssignmentId]         BIGINT NOT NULL,
        [AttemptNumber]        INT NOT NULL,
        [QuestionSnapshotJson] NVARCHAR(MAX) NOT NULL,
        [StartedAtUtc]         DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_Attempts_StartedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [SubmittedAtUtc]       DATETIME2(7) NULL,
        [ScorePercent]         TINYINT NULL,
        [IsPass]               BIT NULL,
        [IdempotencyKey]       NVARCHAR(128) NULL,
        CONSTRAINT [PK_onboarding_Attempts] PRIMARY KEY CLUSTERED ([AttemptId]),
        CONSTRAINT [UQ_onboarding_Attempts_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_Attempts_Number] UNIQUE ([AssignmentId], [AttemptNumber]),
        CONSTRAINT [CK_onboarding_Attempts_ScorePercent]
            CHECK ([ScorePercent] IS NULL OR [ScorePercent] BETWEEN 0 AND 100),
        CONSTRAINT [CK_onboarding_Attempts_Submitted]
            CHECK ([SubmittedAtUtc] IS NULL OR ([ScorePercent] IS NOT NULL AND [IsPass] IS NOT NULL)),
        CONSTRAINT [FK_onboarding_Attempts_Assignment]
            FOREIGN KEY ([AssignmentId]) REFERENCES [onboarding].[Assignments]([AssignmentId])
    );
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_Attempts_IdempotencyKey]
        ON [onboarding].[Attempts]([AssignmentId], [IdempotencyKey])
        WHERE [IdempotencyKey] IS NOT NULL;
END
GO

IF OBJECT_ID(N'[onboarding].[AttemptAnswers]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[AttemptAnswers] (
        [AttemptAnswerId]   BIGINT IDENTITY(1,1) NOT NULL,
        [AttemptId]         BIGINT NOT NULL,
        [QuestionId]        BIGINT NOT NULL,
        [Ordinal]           INT NOT NULL,
        [SelectedOptionKey] NVARCHAR(40) NULL,
        [IsCorrect]         BIT NULL,
        [AnsweredAtUtc]     DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_AttemptAnswers_AnsweredAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_onboarding_AttemptAnswers] PRIMARY KEY CLUSTERED ([AttemptAnswerId]),
        CONSTRAINT [UQ_onboarding_AttemptAnswers_Question] UNIQUE ([AttemptId], [QuestionId]),
        CONSTRAINT [FK_onboarding_AttemptAnswers_Attempt]
            FOREIGN KEY ([AttemptId]) REFERENCES [onboarding].[Attempts]([AttemptId]),
        CONSTRAINT [FK_onboarding_AttemptAnswers_Question]
            FOREIGN KEY ([QuestionId]) REFERENCES [onboarding].[Questions]([QuestionId])
    );
END
GO

/* ============================================================================
   C1 — Policy acknowledgements (IMMUTABLE)

   A6: bound to [PolicyVersionId], never to [PolicyId]. Superseding a policy
   leaves every acknowledgement pointing at the wording that was actually read,
   which is the only version anybody agreed to.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[PolicyAcknowledgements]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[PolicyAcknowledgements] (
        [PolicyAcknowledgementId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_PolicyAcknowledgements_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [AssignmentId]            BIGINT NOT NULL,
        [UserId]                  BIGINT NOT NULL,
        [PolicyVersionId]         BIGINT NOT NULL,
        [AcknowledgedAtUtc]       DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_PolicyAcknowledgements_AcknowledgedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [DeclarationText]         NVARCHAR(MAX) NOT NULL,
        [IdempotencyKey]          NVARCHAR(128) NULL,
        CONSTRAINT [PK_onboarding_PolicyAcknowledgements] PRIMARY KEY CLUSTERED ([PolicyAcknowledgementId]),
        CONSTRAINT [UQ_onboarding_PolicyAcknowledgements_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_PolicyAcknowledgements_UserVersion] UNIQUE ([UserId], [PolicyVersionId]),
        CONSTRAINT [FK_onboarding_PolicyAcknowledgements_Assignment]
            FOREIGN KEY ([AssignmentId]) REFERENCES [onboarding].[Assignments]([AssignmentId]),
        CONSTRAINT [FK_onboarding_PolicyAcknowledgements_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_PolicyAcknowledgements_PolicyVersion]
            FOREIGN KEY ([PolicyVersionId]) REFERENCES [onboarding].[PolicyVersions]([PolicyVersionId])
    );
END
GO

/* ============================================================================
   C1/C2 — Form drafts (MUTABLE) and submissions (IMMUTABLE)

   A5, as two tables rather than two columns. The source keeps a mutable
   `draft_data` beside an immutable `submitted_data` in one row, which works but
   puts the two lifetimes one UPDATE apart. Here a draft is scratch, is deleted
   when it is handed in, and a submission is a revision that is never rewritten:
   a correction is revision 2.

   Only the ORDINARY-HR part of the payload is in [SubmittedDataJson]. Payroll
   and restricted fields are split out on the way in and written to
   [onboarding_hr].[RestrictedSubmissionData], keyed by [PublicId] here. That is
   what stops an accidental SELECT * over this schema returning a bank account.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[FormDrafts]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[FormDrafts] (
        [FormDraftId]   BIGINT IDENTITY(1,1) NOT NULL,
        [AssignmentId]  BIGINT NOT NULL,
        [DraftDataJson] NVARCHAR(MAX) NOT NULL,
        [UpdatedAtUtc]  DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_FormDrafts_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]    ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_FormDrafts] PRIMARY KEY CLUSTERED ([FormDraftId]),
        CONSTRAINT [UQ_onboarding_FormDrafts_Assignment] UNIQUE ([AssignmentId]),
        CONSTRAINT [FK_onboarding_FormDrafts_Assignment]
            FOREIGN KEY ([AssignmentId]) REFERENCES [onboarding].[Assignments]([AssignmentId])
    );
END
GO

IF OBJECT_ID(N'[onboarding].[FormSubmissions]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[FormSubmissions] (
        [FormSubmissionId]        BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_FormSubmissions_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [AssignmentId]            BIGINT NOT NULL,
        [UserId]                  BIGINT NOT NULL,
        [FormDefinitionVersionId] BIGINT NOT NULL,
        [RevisionNumber]          INT NOT NULL,
        /* Ordinary-HR fields only. Anything classified payroll or restricted is
           in [onboarding_hr].[RestrictedSubmissionData]. */
        [SubmittedDataJson]       NVARCHAR(MAX) NOT NULL,
        [HasRestrictedData]       BIT NOT NULL
            CONSTRAINT [DF_onboarding_FormSubmissions_HasRestrictedData] DEFAULT (0),
        [SubmittedAtUtc]          DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_FormSubmissions_SubmittedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [SupersededByRevision]    INT NULL,
        [IdempotencyKey]          NVARCHAR(128) NULL,
        CONSTRAINT [PK_onboarding_FormSubmissions] PRIMARY KEY CLUSTERED ([FormSubmissionId]),
        CONSTRAINT [UQ_onboarding_FormSubmissions_PublicId] UNIQUE ([PublicId]),
        /* The source's UNIQUE (form_assignment_id, revision_number). */
        CONSTRAINT [UQ_onboarding_FormSubmissions_Revision] UNIQUE ([AssignmentId], [RevisionNumber]),
        CONSTRAINT [CK_onboarding_FormSubmissions_RevisionNumber] CHECK ([RevisionNumber] >= 1),
        CONSTRAINT [FK_onboarding_FormSubmissions_Assignment]
            FOREIGN KEY ([AssignmentId]) REFERENCES [onboarding].[Assignments]([AssignmentId]),
        CONSTRAINT [FK_onboarding_FormSubmissions_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_FormSubmissions_DefinitionVersion]
            FOREIGN KEY ([FormDefinitionVersionId]) REFERENCES [onboarding].[FormDefinitionVersions]([FormDefinitionVersionId])
    );
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_FormSubmissions_IdempotencyKey]
        ON [onboarding].[FormSubmissions]([AssignmentId], [IdempotencyKey])
        WHERE [IdempotencyKey] IS NOT NULL;
    CREATE NONCLUSTERED INDEX [IX_onboarding_FormSubmissions_UserId]
        ON [onboarding].[FormSubmissions]([UserId], [SubmittedAtUtc] DESC);
END
GO

/* ============================================================================
   C5 — Generated document references

   B1: bytes do not live in Azure SQL. They go to a private blob container,
   following AzureBlobExpenseAttachmentStore, and this row holds the reference,
   the hash and the classification — nothing readable.

   [RetentionClassId] is what decides whether a download may be handed a SAS URL
   (C0–C2) or must be streamed through an authenticated, audited endpoint
   (C3–C4). That recommendation is open question 7 and is not yet accepted, so
   the column records the class and the delivery decision is made in code.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[DocumentReferences]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[DocumentReferences] (
        [DocumentReferenceId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]            UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_onboarding_DocumentReferences_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [UserId]              BIGINT NOT NULL,
        [AssignmentId]        BIGINT NULL,
        [FormSubmissionId]    BIGINT NULL,
        [DocumentKind]        NVARCHAR(40) NOT NULL,
        [RetentionClassId]    TINYINT NOT NULL,
        [BlobContainer]       NVARCHAR(120) NOT NULL,
        [BlobPath]            NVARCHAR(1024) NOT NULL,
        [ContentType]         NVARCHAR(120) NOT NULL,
        [ByteSize]            BIGINT NOT NULL,
        [ContentSha256]       CHAR(64) NOT NULL,
        [GeneratedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_DocumentReferences_GeneratedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [DeletedAtUtc]        DATETIME2(7) NULL,
        CONSTRAINT [PK_onboarding_DocumentReferences] PRIMARY KEY CLUSTERED ([DocumentReferenceId]),
        CONSTRAINT [UQ_onboarding_DocumentReferences_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_onboarding_DocumentReferences_Blob] UNIQUE ([BlobContainer], [BlobPath]),
        CONSTRAINT [CK_onboarding_DocumentReferences_ByteSize] CHECK ([ByteSize] > 0),
        CONSTRAINT [CK_onboarding_DocumentReferences_Kind]
            CHECK ([DocumentKind] IN (N'employee_details', N'legal_forms', N'workflow', N'policy_declaration', N'assessment', N'photo')),
        CONSTRAINT [FK_onboarding_DocumentReferences_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_DocumentReferences_Assignment]
            FOREIGN KEY ([AssignmentId]) REFERENCES [onboarding].[Assignments]([AssignmentId]),
        CONSTRAINT [FK_onboarding_DocumentReferences_FormSubmission]
            FOREIGN KEY ([FormSubmissionId]) REFERENCES [onboarding].[FormSubmissions]([FormSubmissionId]),
        CONSTRAINT [FK_onboarding_DocumentReferences_RetentionClass]
            FOREIGN KEY ([RetentionClassId]) REFERENCES [onboarding].[RetentionClasses]([RetentionClassId])
    );
    CREATE NONCLUSTERED INDEX [IX_onboarding_DocumentReferences_UserId]
        ON [onboarding].[DocumentReferences]([UserId]) WHERE [DeletedAtUtc] IS NULL;
    CREATE NONCLUSTERED INDEX [IX_onboarding_DocumentReferences_RetentionClassId]
        ON [onboarding].[DocumentReferences]([RetentionClassId]) WHERE [DeletedAtUtc] IS NULL;
END
GO

/* ============================================================================
   C1 — Notification log

   A8: reminders cannot double-send. The source's
   UNIQUE (trainee_id, notification_type, subject_type, subject_public_id, sent_on)
   restated, with [SentOn] a DATE so "once a day" is enforced by the database
   rather than by the job remembering to ask.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[NotificationLog]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[NotificationLog] (
        [NotificationLogId] BIGINT IDENTITY(1,1) NOT NULL,
        [UserId]            BIGINT NOT NULL,
        [NotificationType]  NVARCHAR(64) NOT NULL,
        [SubjectType]       NVARCHAR(32) NOT NULL,
        [SubjectPublicId]   UNIQUEIDENTIFIER NULL,
        [SentOn]            DATE NOT NULL,
        [SentAtUtc]         DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_NotificationLog_SentAtUtc] DEFAULT (SYSUTCDATETIME()),
        [Channel]           NVARCHAR(24) NOT NULL
            CONSTRAINT [DF_onboarding_NotificationLog_Channel] DEFAULT (N'email'),
        [Outcome]           NVARCHAR(24) NOT NULL,
        [FailureReason]     NVARCHAR(400) NULL,
        CONSTRAINT [PK_onboarding_NotificationLog] PRIMARY KEY CLUSTERED ([NotificationLogId]),
        CONSTRAINT [CK_onboarding_NotificationLog_Outcome]
            CHECK ([Outcome] IN (N'sent', N'failed', N'suppressed')),
        CONSTRAINT [FK_onboarding_NotificationLog_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE UNIQUE NONCLUSTERED INDEX [UX_onboarding_NotificationLog_OncePerDay]
        ON [onboarding].[NotificationLog]([UserId], [NotificationType], [SubjectType], [SubjectPublicId], [SentOn]);
END
GO

/* ============================================================================
   D3 — Idempotency keys for invitations, submissions and scheduled work

   Follows quote.QuoteWriteIdempotency exactly, including its state vocabulary,
   because two different shapes of the same idea in one database is how one of
   them ends up wrong.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[OnboardingWriteIdempotency]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[OnboardingWriteIdempotency] (
        [IdempotencyId]       UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [PK_onboarding_OnboardingWriteIdempotency] PRIMARY KEY
            CONSTRAINT [DF_onboarding_OnboardingWriteIdempotency_Id] DEFAULT (NEWSEQUENTIALID()),
        [ActorKey]            NVARCHAR(128) NOT NULL,
        [Operation]           NVARCHAR(64) NOT NULL,
        [IdempotencyKey]      NVARCHAR(128) NOT NULL,
        [RequestHash]         CHAR(64) NOT NULL,
        [State]               NVARCHAR(32) NOT NULL,
        [PlatformOperationId] UNIQUEIDENTIFIER NOT NULL,
        [ResultPublicId]      UNIQUEIDENTIFIER NULL,
        [FailureReason]       NVARCHAR(256) NULL,
        [CreatedAtUtc]        DATETIME2(3) NOT NULL
            CONSTRAINT [DF_onboarding_OnboardingWriteIdempotency_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]        DATETIME2(3) NOT NULL
            CONSTRAINT [DF_onboarding_OnboardingWriteIdempotency_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [CK_onboarding_OnboardingWriteIdempotency_State]
            CHECK ([State] IN (N'IN_PROGRESS', N'COMPLETED', N'FAILED')),
        CONSTRAINT [UX_onboarding_OnboardingWriteIdempotency_Actor_Op_Key]
            UNIQUE ([ActorKey], [Operation], [IdempotencyKey]),
        CONSTRAINT [UX_onboarding_OnboardingWriteIdempotency_PlatformOperationId]
            UNIQUE ([PlatformOperationId])
    );
    CREATE NONCLUSTERED INDEX [IX_onboarding_OnboardingWriteIdempotency_State_Updated]
        ON [onboarding].[OnboardingWriteIdempotency]([State], [UpdatedAtUtc]);
END
GO

/* ============================================================================
   D7 — Completion projection

   Work order §6 and §12.10: person id, item id/type, completion date/status and
   an evidence reference. Nothing else. This is what Skill Matrix reads, and
   every column that is not here is a column that cannot leak into it. There is
   deliberately no score, no form payload, no document body and no note.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[CompletionProjection]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding].[CompletionProjection] (
        [CompletionProjectionId] BIGINT IDENTITY(1,1) NOT NULL,
        [UserId]                 BIGINT NOT NULL,
        [ItemType]               NVARCHAR(16) NOT NULL,
        [ItemPublicId]           UNIQUEIDENTIFIER NOT NULL,
        [Status]                 NVARCHAR(24) NOT NULL,
        [CompletedOn]            DATE NULL,
        /* [Assignments].[PublicId] — the evidence, fetched through an authorized
           endpoint by whoever is entitled to it. Not the evidence itself. */
        [EvidencePublicId]       UNIQUEIDENTIFIER NOT NULL,
        [UpdatedAtUtc]           DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_CompletionProjection_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_onboarding_CompletionProjection] PRIMARY KEY CLUSTERED ([CompletionProjectionId]),
        CONSTRAINT [UQ_onboarding_CompletionProjection_Evidence] UNIQUE ([EvidencePublicId]),
        CONSTRAINT [CK_onboarding_CompletionProjection_ItemType]
            CHECK ([ItemType] IN (N'training', N'policy', N'form')),
        CONSTRAINT [CK_onboarding_CompletionProjection_Status]
            CHECK ([Status] IN (N'not_started', N'in_progress', N'need_help', N'submitted', N'fix_and_resubmit', N'completed')),
        CONSTRAINT [FK_onboarding_CompletionProjection_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE NONCLUSTERED INDEX [IX_onboarding_CompletionProjection_UserId]
        ON [onboarding].[CompletionProjection]([UserId], [ItemType]);
END
GO

/* ============================================================================
   Audit — status transitions and restricted-document reads

   Separate [audit] schema, append only, following audit.UserManagementEvents.
   Two different questions, so two tables:

     - who moved an assignment, when, from what, to what, wearing which hat;
     - who READ a payroll or restricted-HR document (architecture doc §8). The
       source logs no reads at all, and this is the auditing that matters most:
       it is the argument for streaming C3/C4 downloads through the API rather
       than handing out SAS URLs nobody can attribute afterwards.
   ============================================================================ */
IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'audit')
BEGIN
    EXEC(N'CREATE SCHEMA [audit]');
END
GO

IF OBJECT_ID(N'[audit].[OnboardingStatusEvents]', N'U') IS NULL
BEGIN
    CREATE TABLE [audit].[OnboardingStatusEvents] (
        [OnboardingStatusEventId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_audit_OnboardingStatusEvents_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [AssignmentPublicId]      UNIQUEIDENTIFIER NOT NULL,
        [SubjectUserId]           BIGINT NOT NULL,
        [ActorUserId]             BIGINT NULL,
        /* trainee | reviewer | system — TriCab\Core\Onboarding\Domain\ValueObject\StatusActor.
           NULL [ActorUserId] is the system acting, and the check below is what
           stops an unattributed human action being recorded as one. */
        [ActorType]               NVARCHAR(16) NOT NULL,
        [FromStatus]              NVARCHAR(24) NULL,
        [ToStatus]                NVARCHAR(24) NOT NULL,
        [Note]                    NVARCHAR(MAX) NULL,
        [RequestCorrelationId]    NVARCHAR(100) NULL,
        [CreatedAtUtc]            DATETIMEOFFSET NOT NULL
            CONSTRAINT [DF_audit_OnboardingStatusEvents_CreatedAtUtc] DEFAULT (SYSDATETIMEOFFSET()),
        CONSTRAINT [PK_audit_OnboardingStatusEvents] PRIMARY KEY CLUSTERED ([OnboardingStatusEventId]),
        CONSTRAINT [UQ_audit_OnboardingStatusEvents_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_audit_OnboardingStatusEvents_ActorType]
            CHECK ([ActorType] IN (N'trainee', N'reviewer', N'system')),
        CONSTRAINT [CK_audit_OnboardingStatusEvents_Attribution]
            CHECK ([ActorType] = N'system' OR [ActorUserId] IS NOT NULL),
        CONSTRAINT [CK_audit_OnboardingStatusEvents_ToStatus]
            CHECK ([ToStatus] IN (N'not_started', N'in_progress', N'need_help', N'submitted', N'fix_and_resubmit', N'completed')),
        CONSTRAINT [FK_audit_OnboardingStatusEvents_SubjectUser]
            FOREIGN KEY ([SubjectUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_audit_OnboardingStatusEvents_ActorUser]
            FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION
    );
    CREATE NONCLUSTERED INDEX [IX_audit_OnboardingStatusEvents_Assignment]
        ON [audit].[OnboardingStatusEvents]([AssignmentPublicId], [CreatedAtUtc] DESC);
    CREATE NONCLUSTERED INDEX [IX_audit_OnboardingStatusEvents_SubjectUserId]
        ON [audit].[OnboardingStatusEvents]([SubjectUserId], [CreatedAtUtc] DESC);
END
GO

IF OBJECT_ID(N'[audit].[OnboardingDocumentAccessEvents]', N'U') IS NULL
BEGIN
    CREATE TABLE [audit].[OnboardingDocumentAccessEvents] (
        [OnboardingDocumentAccessEventId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId]                UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_audit_OnboardingDocumentAccessEvents_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [DocumentPublicId]        UNIQUEIDENTIFIER NOT NULL,
        [SubjectUserId]           BIGINT NOT NULL,
        [ActorUserId]             BIGINT NOT NULL,
        [RetentionClassId]        TINYINT NOT NULL,
        [AccessKind]              NVARCHAR(16) NOT NULL,
        [PermissionCode]          NVARCHAR(80) NOT NULL,
        [RequestCorrelationId]    NVARCHAR(100) NULL,
        [IpAddress]               NVARCHAR(64) NULL,
        [UserAgent]               NVARCHAR(500) NULL,
        [CreatedAtUtc]            DATETIMEOFFSET NOT NULL
            CONSTRAINT [DF_audit_OnboardingDocumentAccessEvents_CreatedAtUtc] DEFAULT (SYSDATETIMEOFFSET()),
        CONSTRAINT [PK_audit_OnboardingDocumentAccessEvents] PRIMARY KEY CLUSTERED ([OnboardingDocumentAccessEventId]),
        CONSTRAINT [UQ_audit_OnboardingDocumentAccessEvents_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_audit_OnboardingDocumentAccessEvents_AccessKind]
            CHECK ([AccessKind] IN (N'read', N'download', N'print', N'export')),
        CONSTRAINT [FK_audit_OnboardingDocumentAccessEvents_SubjectUser]
            FOREIGN KEY ([SubjectUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_audit_OnboardingDocumentAccessEvents_ActorUser]
            FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION
    );
    CREATE NONCLUSTERED INDEX [IX_audit_OnboardingDocumentAccessEvents_Document]
        ON [audit].[OnboardingDocumentAccessEvents]([DocumentPublicId], [CreatedAtUtc] DESC);
    CREATE NONCLUSTERED INDEX [IX_audit_OnboardingDocumentAccessEvents_SubjectUserId]
        ON [audit].[OnboardingDocumentAccessEvents]([SubjectUserId], [CreatedAtUtc] DESC);
    CREATE NONCLUSTERED INDEX [IX_audit_OnboardingDocumentAccessEvents_ActorUserId]
        ON [audit].[OnboardingDocumentAccessEvents]([ActorUserId], [CreatedAtUtc] DESC);
END
GO

/* ============================================================================
   Immutability triggers

   A5 asks for exactly this: "an update to a submitted row FAILS rather than
   silently succeeding". A convention in a repository class cannot promise that —
   the next repository, the next script, the next ad-hoc fix in a query window
   are all outside it. These are the rows a regulator would be shown, so the
   guarantee belongs where nothing can route around it.

   Triggers are deliberately narrow: they block UPDATE and DELETE and nothing
   else. A correction is a new revision, and a withdrawal is a date on the
   assignment.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding].[TR_FormSubmissions_Immutable]', N'TR') IS NOT NULL
    DROP TRIGGER [onboarding].[TR_FormSubmissions_Immutable];
GO
CREATE TRIGGER [onboarding].[TR_FormSubmissions_Immutable]
ON [onboarding].[FormSubmissions]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    /* One exception, and it is bookkeeping rather than content: marking a
       revision superseded when its replacement arrives. Nothing a person wrote
       can be changed by it. */
    IF UPDATE([SubmittedDataJson]) OR UPDATE([RevisionNumber]) OR UPDATE([SubmittedAtUtc])
        OR UPDATE([UserId]) OR UPDATE([AssignmentId]) OR UPDATE([FormDefinitionVersionId])
        OR EXISTS (SELECT 1 FROM deleted WHERE NOT EXISTS (SELECT 1 FROM inserted i WHERE i.[FormSubmissionId] = deleted.[FormSubmissionId]))
    BEGIN
        THROW 50001, N'onboarding.FormSubmissions is immutable. Record a correction as a new revision.', 1;
    END;

    UPDATE t
       SET t.[SupersededByRevision] = i.[SupersededByRevision]
      FROM [onboarding].[FormSubmissions] t
      INNER JOIN inserted i ON i.[FormSubmissionId] = t.[FormSubmissionId];
END;
GO

IF OBJECT_ID(N'[onboarding].[TR_PolicyAcknowledgements_Immutable]', N'TR') IS NOT NULL
    DROP TRIGGER [onboarding].[TR_PolicyAcknowledgements_Immutable];
GO
CREATE TRIGGER [onboarding].[TR_PolicyAcknowledgements_Immutable]
ON [onboarding].[PolicyAcknowledgements]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50002, N'onboarding.PolicyAcknowledgements is append-only. An acknowledgement is bound to the policy version that was read.', 1;
END;
GO

IF OBJECT_ID(N'[onboarding].[TR_AttemptAnswers_Immutable]', N'TR') IS NOT NULL
    DROP TRIGGER [onboarding].[TR_AttemptAnswers_Immutable];
GO
CREATE TRIGGER [onboarding].[TR_AttemptAnswers_Immutable]
ON [onboarding].[AttemptAnswers]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50003, N'onboarding.AttemptAnswers is append-only. A retake is a new attempt.', 1;
END;
GO

IF OBJECT_ID(N'[audit].[TR_OnboardingStatusEvents_AppendOnly]', N'TR') IS NOT NULL
    DROP TRIGGER [audit].[TR_OnboardingStatusEvents_AppendOnly];
GO
CREATE TRIGGER [audit].[TR_OnboardingStatusEvents_AppendOnly]
ON [audit].[OnboardingStatusEvents]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50004, N'audit.OnboardingStatusEvents is append-only.', 1;
END;
GO

IF OBJECT_ID(N'[audit].[TR_OnboardingDocumentAccessEvents_AppendOnly]', N'TR') IS NOT NULL
    DROP TRIGGER [audit].[TR_OnboardingDocumentAccessEvents_AppendOnly];
GO
CREATE TRIGGER [audit].[TR_OnboardingDocumentAccessEvents_AppendOnly]
ON [audit].[OnboardingDocumentAccessEvents]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50005, N'audit.OnboardingDocumentAccessEvents is append-only.', 1;
END;
GO

PRINT N'onboarding schema created/verified.';
GO
