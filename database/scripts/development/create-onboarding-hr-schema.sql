/*
================================================================================
  Phase 2.2 — [onboarding_hr] schema (classes C3 and C4).

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Run AFTER create-onboarding-schema.sql: this file has foreign keys into
  [onboarding] and into [identity], and none the other way.

  ------------------------------------------------------------------------------
  This is the most sensitive data the TriCab platform holds anywhere.

  C3 payroll-sensitive — bank account, superannuation, tax file declaration,
  date of birth, home address.
  C4 restricted HR — health conditions, WHS medical questionnaire, immigration
  and passport documents, visa details, Aboriginal or Torres Strait Islander
  status, cultural identity, gender identity, dietary needs, emergency contacts.

  C4 is special-category personal information under the Privacy Act 1988 (Cth).
  Emergency contacts are third-party personal data: the people named never
  consented to anything, hold no account, and cannot be the subject of a
  retention decision the candidate makes.

  ------------------------------------------------------------------------------
  Who may read it: the Onboarding admin, holding ONBOARDING.PEOPLE.MANAGE.
  Settled 21 Sep 2026 with the two-user-type decision — an admin who cannot open
  a trainee's forms cannot do the job the role exists for. No payroll-scoped or
  restricted-scoped permission is introduced.

  So be honest about what this schema separation buys, because it is not a
  database permission boundary today: the API connects as a single principal.
  It buys three things:
    1. An accidental SELECT * over [onboarding] cannot return a bank account.
    2. Reads of these tables are the ones worth auditing, and auditing them
       requires knowing which they are — see [audit].[OnboardingDocumentAccessEvents].
    3. A least-privilege pass later can GRANT per schema without moving a table,
       and a narrower role becomes a permission grant rather than a migration.

  [onboarding_hr] is the first platform schema name containing an underscore —
  every other schema here is one lowercase word. **The owner accepted the name on
  21 Sep 2026**, so this is a settled deviation rather than an open one. The
  alternatives considered and declined were `personnel` (names the data rather
  than the module that collects it, but claims a domain the platform does not
  have yet), `restricted` (names the sensitivity, which invites every other
  module to use it as an attic) and `hr` (the only abbreviation among twelve
  full words). What the chosen name buys is that nobody ever has to ask what is
  in here or who owns it.
================================================================================
*/

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'onboarding_hr')
BEGIN
    EXEC(N'CREATE SCHEMA [onboarding_hr]');
END
GO

/* ============================================================================
   The per-field classification map

   Architecture doc §5: `employee-details` arrives as ONE payload mixing ordinary
   HR, payroll and restricted fields. A submission therefore cannot be classified
   as a whole, and filtering it on the way OUT is the wrong control — by then the
   bank account is already sitting in the workflow schema, in every backup and in
   every query result that forgot to filter.

   So: classification is per field, it is data rather than code, and it runs on
   the way IN. The submission handler reads this table for the form version being
   submitted, routes each field to its class, and writes the ordinary-HR
   remainder to [onboarding].[FormSubmissions].

   ## Why C2 is storable here, and the fail-open gap it closes

   An earlier revision admitted only C3 and C4, on the reasoning that calling a
   field ordinary is said by leaving it out. That made the default fail OPEN: a
   sensitive field added to a form and never classified was written to
   [onboarding] in clear, and nothing could tell "nobody has classified this" from
   "somebody decided it is ordinary".

   So C2 is a recordable answer. On a form whose [AccessGroup] is `payroll` or
   `restricted_hr`, EVERY field in the published schema must have a row here —
   C2, C3 or C4 — and FormVersionPublishGuard refuses to publish until they do.
   Absence is then unambiguous: it means unclassified, which means unpublishable.

   The guard is application code because the rule spans this table, a form
   version's JSON schema and a publish action; no CHECK constraint can express
   it. It is covered by tests rather than by the database, and that is the
   residual risk, stated rather than hidden: a write that bypasses the guard
   bypasses the rule.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding_hr].[FieldClassifications]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding_hr].[FieldClassifications] (
        [FieldClassificationId]   BIGINT IDENTITY(1,1) NOT NULL,
        [FormDefinitionVersionId] BIGINT NOT NULL,
        /* Dotted path into the submitted payload, e.g. `bank.accountNumber`. */
        [FieldPath]               NVARCHAR(200) NOT NULL,
        [RetentionClassId]        TINYINT NOT NULL,
        [ClassifiedByUserId]      BIGINT NOT NULL,
        [ClassifiedAtUtc]         DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_hr_FieldClassifications_ClassifiedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_onboarding_hr_FieldClassifications] PRIMARY KEY CLUSTERED ([FieldClassificationId]),
        CONSTRAINT [UQ_onboarding_hr_FieldClassifications_Field]
            UNIQUE ([FormDefinitionVersionId], [FieldPath]),
        /* C2 = explicitly ordinary, stays in [onboarding]. C3/C4 route here.
           C0 and C1 are content and workflow, which a submitted field never is. */
        CONSTRAINT [CK_onboarding_hr_FieldClassifications_Class]
            CHECK ([RetentionClassId] IN (2, 3, 4)),
        CONSTRAINT [CK_onboarding_hr_FieldClassifications_FieldPath]
            CHECK (LEN(LTRIM(RTRIM([FieldPath]))) > 0),
        CONSTRAINT [FK_onboarding_hr_FieldClassifications_Version]
            FOREIGN KEY ([FormDefinitionVersionId]) REFERENCES [onboarding].[FormDefinitionVersions]([FormDefinitionVersionId]),
        CONSTRAINT [FK_onboarding_hr_FieldClassifications_Class]
            FOREIGN KEY ([RetentionClassId]) REFERENCES [onboarding].[RetentionClasses]([RetentionClassId]),
        CONSTRAINT [FK_onboarding_hr_FieldClassifications_ClassifiedBy]
            FOREIGN KEY ([ClassifiedByUserId]) REFERENCES [identity].[Users]([UserId])
    );
    CREATE NONCLUSTERED INDEX [IX_onboarding_hr_FieldClassifications_Version]
        ON [onboarding_hr].[FieldClassifications]([FormDefinitionVersionId])
        INCLUDE ([FieldPath], [RetentionClassId]);
END
GO

/* ============================================================================
   The split payload (IMMUTABLE)

   One row per submission per class, holding the fields this class claimed.
   Keyed by [onboarding].[FormSubmissions].[PublicId] rather than by its BIGINT,
   because a stable public identifier is the one thing worth carrying across a
   boundary that may one day be a database boundary.

   The ordinary-HR remainder stays in [onboarding].[FormSubmissions]. Nothing
   here is ever written by a repository that also writes there.

   NOTE — `payslipPin`. Open question 6 was answered by the owner on 21 Sep 2026:
   **keep source behaviour knowingly.** It is collected on the employee-details
   form, classified C3, and printed onto the generated PDF exactly as the source
   does. The column is [PayrollDetails].[PayslipPin] below.

   "Knowingly" is doing real work in that sentence, so record what is known: a
   payslip PIN is a live credential, not a datum. Storing it means it is
   readable by anyone holding ONBOARDING.PEOPLE.MANAGE, it appears in a generated
   PDF, and it cannot be rotated by the platform because payroll owns it. The
   target improves on the source in exactly one respect, and it is worth having:
   the PDF lives in a private blob container and, under the delivery decision of
   the same date, every read of it is streamed through an authenticated endpoint
   and written to [audit].[OnboardingDocumentAccessEvents]. The source's PDF sat
   base64-encoded in a database column that nothing audited.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding_hr].[RestrictedSubmissionData]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding_hr].[RestrictedSubmissionData] (
        [RestrictedSubmissionDataId] BIGINT IDENTITY(1,1) NOT NULL,
        [FormSubmissionPublicId]     UNIQUEIDENTIFIER NOT NULL,
        [UserId]                     BIGINT NOT NULL,
        [RetentionClassId]           TINYINT NOT NULL,
        [PayloadJson]                NVARCHAR(MAX) NOT NULL,
        [FieldCount]                 INT NOT NULL,
        [WrittenAtUtc]               DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_hr_RestrictedSubmissionData_WrittenAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_onboarding_hr_RestrictedSubmissionData] PRIMARY KEY CLUSTERED ([RestrictedSubmissionDataId]),
        CONSTRAINT [UQ_onboarding_hr_RestrictedSubmissionData_SubmissionClass]
            UNIQUE ([FormSubmissionPublicId], [RetentionClassId]),
        CONSTRAINT [CK_onboarding_hr_RestrictedSubmissionData_Class]
            CHECK ([RetentionClassId] IN (3, 4)),
        CONSTRAINT [CK_onboarding_hr_RestrictedSubmissionData_FieldCount]
            CHECK ([FieldCount] > 0),
        CONSTRAINT [FK_onboarding_hr_RestrictedSubmissionData_Submission]
            FOREIGN KEY ([FormSubmissionPublicId]) REFERENCES [onboarding].[FormSubmissions]([PublicId]),
        CONSTRAINT [FK_onboarding_hr_RestrictedSubmissionData_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_hr_RestrictedSubmissionData_Class]
            FOREIGN KEY ([RetentionClassId]) REFERENCES [onboarding].[RetentionClasses]([RetentionClassId])
    );
    CREATE NONCLUSTERED INDEX [IX_onboarding_hr_RestrictedSubmissionData_UserId]
        ON [onboarding_hr].[RestrictedSubmissionData]([UserId], [RetentionClassId]);
END
GO

/* ============================================================================
   C3 — Payroll details

   The current payroll picture for a person, distinct from the immutable
   submission that produced it. Both are needed: the submission is the evidence
   of what somebody declared on a date, and this is what payroll reads today
   after a correction.

   Superannuation and tax-file-number handling are NOT modelled here. A TFN is
   governed by the Privacy (Tax File Number) Rule 2015 and by s.8WA of the
   Taxation Administration Act 1953, which restrict who may collect, use and even
   request one, and it is not a field to add because a form has a box for it.
   Recorded as a gap rather than guessed at.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding_hr].[PayrollDetails]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding_hr].[PayrollDetails] (
        [PayrollDetailId]       BIGINT IDENTITY(1,1) NOT NULL,
        [UserId]                BIGINT NOT NULL,
        [DateOfBirth]           DATE NULL,
        [ResidentialAddress]    NVARCHAR(500) NULL,
        [PostalAddress]         NVARCHAR(500) NULL,
        [PayslipEmailAddress]   NVARCHAR(320) NULL,
        /* A live credential, kept in clear because payroll needs to read it back
           and print it. Owner decision, 21 Sep 2026, open question 6. Never
           projected into a list view, a report or the completion projection, and
           every read of the document carrying it is audited. */
        [PayslipPin]            NVARCHAR(32) NULL,
        [PrimaryBankName]       NVARCHAR(200) NULL,
        [PrimaryBsb]            NVARCHAR(16) NULL,
        [PrimaryAccountNumber]  NVARCHAR(32) NULL,
        [PrimaryAccountName]    NVARCHAR(200) NULL,
        [SecondaryBankName]     NVARCHAR(200) NULL,
        [SecondaryBsb]          NVARCHAR(16) NULL,
        [SecondaryAccountNumber] NVARCHAR(32) NULL,
        [SecondaryAccountName]  NVARCHAR(200) NULL,
        /* The source requires an allocation whenever a second account is given,
           and so does the target's form validation. Restated here so a row
           written by anything else still cannot be half-specified. */
        [SecondaryAllocationAmount] DECIMAL(18,2) NULL,
        [SecondaryAllocationPercent] DECIMAL(5,2) NULL,
        [SourceSubmissionPublicId] UNIQUEIDENTIFIER NULL,
        [CreatedAtUtc]          DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_hr_PayrollDetails_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]          DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_hr_PayrollDetails_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]            ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_hr_PayrollDetails] PRIMARY KEY CLUSTERED ([PayrollDetailId]),
        CONSTRAINT [UQ_onboarding_hr_PayrollDetails_UserId] UNIQUE ([UserId]),
        CONSTRAINT [CK_onboarding_hr_PayrollDetails_SecondaryAccount] CHECK (
            [SecondaryAccountNumber] IS NULL
            OR [SecondaryAllocationAmount] IS NOT NULL
            OR [SecondaryAllocationPercent] IS NOT NULL
        ),
        CONSTRAINT [CK_onboarding_hr_PayrollDetails_AllocationPercent]
            CHECK ([SecondaryAllocationPercent] IS NULL OR [SecondaryAllocationPercent] BETWEEN 0 AND 100),
        CONSTRAINT [FK_onboarding_hr_PayrollDetails_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_hr_PayrollDetails_SourceSubmission]
            FOREIGN KEY ([SourceSubmissionPublicId]) REFERENCES [onboarding].[FormSubmissions]([PublicId])
    );
END
GO

/* ============================================================================
   C4 — Restricted personal details

   Health, immigration and identity. Held as a classified payload rather than as
   forty named columns, because the questions asked change with the form version
   and because a column list in DDL is a second place for the field map to drift
   from. What is fixed is the class, the subject and the auditability.

   The few structured columns below exist because they are queried rather than
   displayed: a visa expiry drives a reminder, and immigration status decides
   whether one is generated at all.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding_hr].[RestrictedPersonalDetails]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding_hr].[RestrictedPersonalDetails] (
        [RestrictedPersonalDetailId] BIGINT IDENTITY(1,1) NOT NULL,
        [UserId]                  BIGINT NOT NULL,
        [ImmigrationStatus]       NVARCHAR(80) NULL,
        [VisaSubclass]            NVARCHAR(40) NULL,
        [VisaExpiresOn]           DATE NULL,
        [HasDeclaredHealthConditions] BIT NULL,
        [HasDeclaredDietaryNeeds] BIT NULL,
        /* Everything else the person declared, already classified C4 on the way
           in. Passport and visa numbers, health declarations, cultural identity
           and gender identity are in here and are never projected into a list
           view, a report or the completion projection. */
        [DetailsJson]             NVARCHAR(MAX) NULL,
        [SourceSubmissionPublicId] UNIQUEIDENTIFIER NULL,
        [CreatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_hr_RestrictedPersonalDetails_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]            DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_hr_RestrictedPersonalDetails_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]              ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_hr_RestrictedPersonalDetails] PRIMARY KEY CLUSTERED ([RestrictedPersonalDetailId]),
        CONSTRAINT [UQ_onboarding_hr_RestrictedPersonalDetails_UserId] UNIQUE ([UserId]),
        CONSTRAINT [FK_onboarding_hr_RestrictedPersonalDetails_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_hr_RestrictedPersonalDetails_SourceSubmission]
            FOREIGN KEY ([SourceSubmissionPublicId]) REFERENCES [onboarding].[FormSubmissions]([PublicId])
    );
    CREATE NONCLUSTERED INDEX [IX_onboarding_hr_RestrictedPersonalDetails_VisaExpiresOn]
        ON [onboarding_hr].[RestrictedPersonalDetails]([VisaExpiresOn])
        WHERE [VisaExpiresOn] IS NOT NULL;
END
GO

/* ============================================================================
   C4 — Emergency contacts

   Third-party personal data. The named person has no account, gave no consent
   and cannot ask what is held about them. Architecture doc §9 gap 5 records that
   their retention and correction rules still need stating even if the answer
   turns out to be "delete with the employee's record" — which is why this table
   is separate rather than a JSON blob inside the row above: a deletion rule that
   applies to somebody else's data has to be executable on its own.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding_hr].[EmergencyContacts]', N'U') IS NULL
BEGIN
    CREATE TABLE [onboarding_hr].[EmergencyContacts] (
        [EmergencyContactId] BIGINT IDENTITY(1,1) NOT NULL,
        [UserId]             BIGINT NOT NULL,
        [Ordinal]            INT NOT NULL,
        [FullName]           NVARCHAR(240) NOT NULL,
        [Relationship]       NVARCHAR(120) NULL,
        [PrimaryPhone]       NVARCHAR(64) NULL,
        [SecondaryPhone]     NVARCHAR(64) NULL,
        [EmailAddress]       NVARCHAR(320) NULL,
        [SourceSubmissionPublicId] UNIQUEIDENTIFIER NULL,
        [CreatedAtUtc]       DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_hr_EmergencyContacts_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]       DATETIME2(7) NOT NULL
            CONSTRAINT [DF_onboarding_hr_EmergencyContacts_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]         ROWVERSION NOT NULL,
        CONSTRAINT [PK_onboarding_hr_EmergencyContacts] PRIMARY KEY CLUSTERED ([EmergencyContactId]),
        CONSTRAINT [UQ_onboarding_hr_EmergencyContacts_Ordinal] UNIQUE ([UserId], [Ordinal]),
        CONSTRAINT [CK_onboarding_hr_EmergencyContacts_FullName] CHECK (LEN(LTRIM(RTRIM([FullName]))) > 0),
        /* A contact nobody can be reached on is not a contact. */
        CONSTRAINT [CK_onboarding_hr_EmergencyContacts_Reachable]
            CHECK ([PrimaryPhone] IS NOT NULL OR [SecondaryPhone] IS NOT NULL OR [EmailAddress] IS NOT NULL),
        CONSTRAINT [FK_onboarding_hr_EmergencyContacts_UserId]
            FOREIGN KEY ([UserId]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_onboarding_hr_EmergencyContacts_SourceSubmission]
            FOREIGN KEY ([SourceSubmissionPublicId]) REFERENCES [onboarding].[FormSubmissions]([PublicId])
    );
END
GO

/* ============================================================================
   Immutability — the split payload is evidence, exactly as the submission is

   Without this, splitting a payload would have created a way to rewrite half of
   an immutable submission: the half that holds the bank account.
   ============================================================================ */
IF OBJECT_ID(N'[onboarding_hr].[TR_RestrictedSubmissionData_Immutable]', N'TR') IS NOT NULL
    DROP TRIGGER [onboarding_hr].[TR_RestrictedSubmissionData_Immutable];
GO
CREATE TRIGGER [onboarding_hr].[TR_RestrictedSubmissionData_Immutable]
ON [onboarding_hr].[RestrictedSubmissionData]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50011, N'onboarding_hr.RestrictedSubmissionData is append-only. It is half of an immutable submission; record a correction as a new revision.', 1;
END;
GO

PRINT N'onboarding_hr schema created/verified.';
GO
