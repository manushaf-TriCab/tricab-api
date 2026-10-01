/*
================================================================================
  Customer Contact reports — [cc_report] schema.

  STATUS: NOT APPLIED. Do not run against tricab-mel or any shared database
  without explicit approval.

  This is the complaint / CC-number register. It is not the customer-person
  feature. Do not alter:

    [customer].[CustomerContacts]
    [customer].[CustomerContactUsers]
    /api/v1/admin/customer-contacts

  Idempotent. Each table is created only when missing. Re-running does not
  alter an existing table. Legacy MySQL is not read or written.

  Apply later, dry-run first:

    php bin/run-azure-sql-script.php database/scripts/development/create-cc-report-schema.sql
    php bin/run-azure-sql-script.php database/scripts/development/create-cc-report-schema.sql --apply --server=tricab-mel.database.windows.net --database=tc

  Conventions follow create-expense-schema.sql and create-onboarding-schema.sql:
  BIGINT IDENTITY, UNIQUEIDENTIFIER PublicId, DATETIME2(7) UTC, ROWVERSION,
  DECIMAL money, FK to [identity].[Users] only as an optional resolved link.
  The login or email string remains the value the workflow matches.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'cc_report')
BEGIN
    EXEC(N'CREATE SCHEMA [cc_report]');
END
GO

/* ============================================================================
   cc_report.Reports
   One Customer Contact. Status starts as submitted with no CC number.
   The number is written later, in the same statement that assigns the report.
   ============================================================================ */
IF OBJECT_ID(N'[cc_report].[Reports]', N'U') IS NULL
BEGIN
    CREATE TABLE [cc_report].[Reports] (
        [ReportId]                  BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId]                  BIGINT NULL,
        [PublicId]                  UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_cc_report_Reports_PublicId] DEFAULT (NEWSEQUENTIALID()),
        /* Resolved staff user when the new directory can match the email.
           NULL is valid: a later historical import may not match. */
        [CreatedByUserId]           BIGINT NULL,
        [CreatedByEmail]            NVARCHAR(320) NOT NULL,
        [CreatedByName]             NVARCHAR(255) NOT NULL,
        [CreatedAtUtc]              DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cc_report_Reports_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]              DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cc_report_Reports_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]                ROWVERSION NOT NULL,

        [ReportDate]                DATE NOT NULL,
        /* Branch letter, not the website ?rgn= country. */
        [Location]                  NVARCHAR(1) NOT NULL,
        /* Free text code. No FK to [customer].[Customers]: inactive and
           unknown historical codes must still be storable. */
        [CustomerCode]              NVARCHAR(50) NOT NULL,
        /* The customer's person, as typed. Not a FK to [customer].[CustomerContacts]. */
        [CustomerContact]           NVARCHAR(255) NULL,
        [TricabRefTrace]            NVARCHAR(255) NULL,
        [TraceNum]                  NVARCHAR(255) NULL,
        [ConNoteNo]                 NVARCHAR(100) NULL,
        [TricabContact]             NVARCHAR(255) NULL,
        [ProductCode]               NVARCHAR(255) NOT NULL,
        [Description]               NVARCHAR(MAX) NOT NULL,

        [Status]                    NVARCHAR(16) NOT NULL
            CONSTRAINT [DF_cc_report_Reports_Status] DEFAULT (N'submitted'),
        [Route]                     NVARCHAR(16) NULL,
        [CcNumber]                  NVARCHAR(20) NULL,
        [ErrorCode]                 NVARCHAR(3) NULL,

        /* Login is the authorization identity. The user id is optional. */
        [AssignedTo]                NVARCHAR(190) NULL,
        [AssignedToUserId]          BIGINT NULL,
        [AssignedToName]            NVARCHAR(255) NULL,
        [AssignedToEmail]           NVARCHAR(320) NULL,
        [AssignedBy]                NVARCHAR(320) NULL,
        [AssignedAtUtc]             DATETIME2(7) NULL,
        [RequestedActions]          NVARCHAR(MAX) NULL,
        [AssigneeActionTaken]       NVARCHAR(MAX) NULL,
        [AssigneeFindings]          NVARCHAR(MAX) NULL,
        [AssigneeCompletedAtUtc]    DATETIME2(7) NULL,

        [ClosedBy]                  NVARCHAR(255) NULL,
        [ClosedByUserId]            BIGINT NULL,
        [ClosedDate]                DATE NULL,
        [ClosedAtUtc]               DATETIME2(7) NULL,

        [InvestigationDueDate]      DATE NULL,
        [CcAssessedBy]              NVARCHAR(255) NULL,
        /* Office text, maxlength 20 in the legacy form. Not a bit:
           the legacy renderer does not restrict these three to yes/na. */
        [CreditRequired]            NVARCHAR(20) NULL,
        [RestockingFeeApplies]      NVARCHAR(20) NULL,
        [ActionsTaken]              NVARCHAR(MAX) NULL,
        [SchedulingInformed]        NVARCHAR(20) NULL,
        [ReturnArrangedBy]          NVARCHAR(255) NULL,
        [ExpectedReturnDate]        DATE NULL,
        /* Legacy VARCHAR(10): yes, na, or empty. Empty is stored as NULL. */
        [GoodsReturned]             NVARCHAR(10) NULL,
        [GoodsReturnedDate]         DATE NULL,
        [CarrierBooked]             NVARCHAR(10) NULL,
        [CarrierBookedDate]         DATE NULL,
        [CreditRaised]              NVARCHAR(10) NULL,
        [CreditRaisedDate]          DATE NULL,
        [GoodsReplaced]             NVARCHAR(10) NULL,
        [GoodsReplacedDate]         DATE NULL,
        [CarRaised]                 NVARCHAR(10) NULL,
        [CarRaisedDate]             DATE NULL,
        /* Legacy sanitiser stores two decimal places. */
        [ValueExGst]                DECIMAL(18,2) NULL,
        [RestockingAmount]          DECIMAL(18,2) NULL,
        [SubTotal]                  DECIMAL(18,2) NULL,
        [Gst]                       DECIMAL(18,2) NULL,
        [CreditTotal]               DECIMAL(18,2) NULL,
        [RootCause]                 NVARCHAR(MAX) NULL,
        [CorrectiveAction]          NVARCHAR(MAX) NULL,
        [Notes]                     NVARCHAR(MAX) NULL,
        [CreditReviewedBy]          NVARCHAR(255) NULL,
        [CreditReviewedDate]        DATE NULL,

        [IcarOperatorName]          NVARCHAR(255) NULL,
        [IcarSignedAt]              DATE NULL,
        [IcarSignatureUrl]          NVARCHAR(1024) NULL,

        CONSTRAINT [PK_cc_report_Reports] PRIMARY KEY CLUSTERED ([ReportId]),
        CONSTRAINT [UQ_cc_report_Reports_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [FK_cc_report_Reports_CreatedByUserId]
            FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_cc_report_Reports_AssignedToUserId]
            FOREIGN KEY ([AssignedToUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_cc_report_Reports_ClosedByUserId]
            FOREIGN KEY ([ClosedByUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [CK_cc_report_Reports_Status]
            CHECK ([Status] IN (N'submitted', N'assigned', N'completed', N'closed')),
        CONSTRAINT [CK_cc_report_Reports_Route]
            CHECK ([Route] IS NULL OR [Route] IN (N'branch', N'icar')),
        CONSTRAINT [CK_cc_report_Reports_Location]
            CHECK ([Location] IN (N'A', N'B', N'C', N'H', N'M', N'P', N'S')),
        CONSTRAINT [CK_cc_report_Reports_ErrorCode]
            CHECK ([ErrorCode] IS NULL OR [ErrorCode] IN (N'SLS', N'CUS', N'MFG', N'MWH', N'SWH', N'PMW', N'MSC')),
        CONSTRAINT [CK_cc_report_Reports_CcNumber]
            CHECK ([CcNumber] IS NULL OR LEN(LTRIM(RTRIM([CcNumber]))) > 0),
        CONSTRAINT [CK_cc_report_Reports_RequiredText]
            CHECK (
                LEN(LTRIM(RTRIM([CustomerCode]))) > 0
                AND LEN(LTRIM(RTRIM([ProductCode]))) > 0
                AND LEN(LTRIM(RTRIM([Description]))) > 0
                AND LEN(LTRIM(RTRIM([CreatedByEmail]))) > 0
            ),
        /* A raised report has no number, route, or assignee.
           Anything past submitted must already have been assigned. */
        CONSTRAINT [CK_cc_report_Reports_StatusShape]
            CHECK (
                ([Status] = N'submitted'
                    AND [CcNumber] IS NULL
                    AND [Route] IS NULL
                    AND [AssignedTo] IS NULL)
                OR
                ([Status] IN (N'assigned', N'completed', N'closed')
                    AND [CcNumber] IS NOT NULL
                    AND [Route] IS NOT NULL
                    AND [AssignedTo] IS NOT NULL
                    AND [ErrorCode] IS NOT NULL)
            ),
        CONSTRAINT [CK_cc_report_Reports_Checklist]
            CHECK (
                ([GoodsReturned] IS NULL OR [GoodsReturned] IN (N'yes', N'na'))
                AND ([CarrierBooked] IS NULL OR [CarrierBooked] IN (N'yes', N'na'))
                AND ([CreditRaised] IS NULL OR [CreditRaised] IN (N'yes', N'na'))
                AND ([GoodsReplaced] IS NULL OR [GoodsReplaced] IN (N'yes', N'na'))
                AND ([CarRaised] IS NULL OR [CarRaised] IN (N'yes', N'na'))
            )
    );

    /* Many new reports have no legacy id and no CC number. A UNIQUE
       constraint would allow only one NULL, so these are filtered indexes. */
    CREATE UNIQUE NONCLUSTERED INDEX [UX_cc_report_Reports_LegacyId]
        ON [cc_report].[Reports]([LegacyId])
        WHERE [LegacyId] IS NOT NULL;

    CREATE UNIQUE NONCLUSTERED INDEX [UX_cc_report_Reports_CcNumber]
        ON [cc_report].[Reports]([CcNumber])
        WHERE [CcNumber] IS NOT NULL;

    /* Dashboard cards and the default list: status bucket, then report date. */
    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_Status_ReportDate]
        ON [cc_report].[Reports]([Status], [ReportDate] DESC, [ReportId] DESC);

    /* Month/year filter when status is not selected. */
    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_ReportDate]
        ON [cc_report].[Reports]([ReportDate] DESC, [ReportId] DESC);

    /* Originator visibility. Email is what the legacy row stores. */
    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_CreatedByEmail]
        ON [cc_report].[Reports]([CreatedByEmail]);

    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_CreatedByUserId]
        ON [cc_report].[Reports]([CreatedByUserId])
        WHERE [CreatedByUserId] IS NOT NULL;

    /* Assignee visibility and the assignee filter. */
    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_AssignedTo_Status]
        ON [cc_report].[Reports]([AssignedTo], [Status])
        WHERE [AssignedTo] IS NOT NULL;

    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_CustomerCode_ReportDate]
        ON [cc_report].[Reports]([CustomerCode], [ReportDate] DESC);

    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_Location_ReportDate]
        ON [cc_report].[Reports]([Location], [ReportDate] DESC);

    CREATE NONCLUSTERED INDEX [IX_cc_report_Reports_ErrorCode_ReportDate]
        ON [cc_report].[Reports]([ErrorCode], [ReportDate] DESC)
        WHERE [ErrorCode] IS NOT NULL;
END
GO

/* ============================================================================
   cc_report.NumberSequences
   One row per calendar year. The API increments LastSequence under
   UPDLOCK, HOLDLOCK. YYMM in the CC number still comes from ReportDate.
   Do not allocate a number when the report is created.
   ============================================================================ */
IF OBJECT_ID(N'[cc_report].[NumberSequences]', N'U') IS NULL
BEGIN
    CREATE TABLE [cc_report].[NumberSequences] (
        [SequenceYear]  SMALLINT NOT NULL,
        [LastSequence]  INT NOT NULL
            CONSTRAINT [DF_cc_report_NumberSequences_LastSequence] DEFAULT (0),
        [UpdatedAtUtc]  DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cc_report_NumberSequences_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_cc_report_NumberSequences] PRIMARY KEY CLUSTERED ([SequenceYear]),
        CONSTRAINT [CK_cc_report_NumberSequences_Year]
            CHECK ([SequenceYear] BETWEEN 2000 AND 2100),
        CONSTRAINT [CK_cc_report_NumberSequences_LastSequence]
            CHECK ([LastSequence] >= 0)
    );
END
GO

/* ============================================================================
   cc_report.Attachments
   Evidence, credit notes, and superseded ICAR signatures.
   No delete workflow: the FK does not cascade.
   ============================================================================ */
IF OBJECT_ID(N'[cc_report].[Attachments]', N'U') IS NULL
BEGIN
    CREATE TABLE [cc_report].[Attachments] (
        [AttachmentId]      BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId]          BIGINT NULL,
        [ReportId]          BIGINT NOT NULL,
        [FileUrl]           NVARCHAR(1024) NOT NULL,
        [FileName]          NVARCHAR(255) NOT NULL,
        [FileSize]          INT NOT NULL
            CONSTRAINT [DF_cc_report_Attachments_FileSize] DEFAULT (0),
        [ContentType]       NVARCHAR(128) NOT NULL
            CONSTRAINT [DF_cc_report_Attachments_ContentType] DEFAULT (N''),
        [Kind]              NVARCHAR(32) NOT NULL,
        [CreatedByUserId]   BIGINT NULL,
        [CreatedByEmail]    NVARCHAR(320) NULL,
        [CreatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cc_report_Attachments_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_cc_report_Attachments] PRIMARY KEY CLUSTERED ([AttachmentId]),
        CONSTRAINT [FK_cc_report_Attachments_ReportId]
            FOREIGN KEY ([ReportId]) REFERENCES [cc_report].[Reports]([ReportId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_cc_report_Attachments_CreatedByUserId]
            FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [CK_cc_report_Attachments_Kind]
            CHECK ([Kind] IN (N'evidence', N'credit_note', N'signature_superseded')),
        CONSTRAINT [CK_cc_report_Attachments_FileUrl]
            CHECK (LEN(LTRIM(RTRIM([FileUrl]))) > 0),
        CONSTRAINT [CK_cc_report_Attachments_FileSize]
            CHECK ([FileSize] >= 0 AND [FileSize] <= 15728640)
    );

    CREATE UNIQUE NONCLUSTERED INDEX [UX_cc_report_Attachments_LegacyId]
        ON [cc_report].[Attachments]([LegacyId])
        WHERE [LegacyId] IS NOT NULL;

    CREATE NONCLUSTERED INDEX [IX_cc_report_Attachments_ReportId_Kind]
        ON [cc_report].[Attachments]([ReportId], [Kind], [AttachmentId]);
END
GO

/* ============================================================================
   cc_report.Approvals
   One credit, one rework, and one settlement per report.
   These rows do not replace Reports.Status.
   ============================================================================ */
IF OBJECT_ID(N'[cc_report].[Approvals]', N'U') IS NULL
BEGIN
    CREATE TABLE [cc_report].[Approvals] (
        [ApprovalId]        BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId]          BIGINT NULL,
        [ReportId]          BIGINT NOT NULL,
        [Kind]              NVARCHAR(16) NOT NULL,
        /* Login, lower-cased by the application. Not a display name. */
        [Approver]          NVARCHAR(190) NOT NULL,
        [ApproverUserId]    BIGINT NULL,
        [ApproverName]      NVARCHAR(255) NOT NULL
            CONSTRAINT [DF_cc_report_Approvals_ApproverName] DEFAULT (N''),
        [ApproverEmail]     NVARCHAR(320) NOT NULL
            CONSTRAINT [DF_cc_report_Approvals_ApproverEmail] DEFAULT (N''),
        [Status]            NVARCHAR(16) NOT NULL
            CONSTRAINT [DF_cc_report_Approvals_Status] DEFAULT (N'pending'),
        [RequestedBy]       NVARCHAR(320) NOT NULL,
        [RequestedByUserId] BIGINT NULL,
        [RequestedAtUtc]    DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cc_report_Approvals_RequestedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [Note]              NVARCHAR(MAX) NULL,
        [DecidedAtUtc]      DATETIME2(7) NULL,
        [CreatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cc_report_Approvals_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc]      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cc_report_Approvals_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [RowVersion]        ROWVERSION NOT NULL,
        CONSTRAINT [PK_cc_report_Approvals] PRIMARY KEY CLUSTERED ([ApprovalId]),
        CONSTRAINT [UQ_cc_report_Approvals_ReportId_Kind] UNIQUE ([ReportId], [Kind]),
        CONSTRAINT [FK_cc_report_Approvals_ReportId]
            FOREIGN KEY ([ReportId]) REFERENCES [cc_report].[Reports]([ReportId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_cc_report_Approvals_ApproverUserId]
            FOREIGN KEY ([ApproverUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [FK_cc_report_Approvals_RequestedByUserId]
            FOREIGN KEY ([RequestedByUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
        CONSTRAINT [CK_cc_report_Approvals_Kind]
            CHECK ([Kind] IN (N'credit', N'rework', N'settlement')),
        CONSTRAINT [CK_cc_report_Approvals_Status]
            CHECK ([Status] IN (N'pending', N'approved', N'declined')),
        CONSTRAINT [CK_cc_report_Approvals_Approver]
            CHECK (LEN(LTRIM(RTRIM([Approver]))) > 0),
        CONSTRAINT [CK_cc_report_Approvals_Decision]
            CHECK (
                ([Status] = N'pending' AND [DecidedAtUtc] IS NULL)
                OR ([Status] IN (N'approved', N'declined') AND [DecidedAtUtc] IS NOT NULL)
            )
    );

    CREATE UNIQUE NONCLUSTERED INDEX [UX_cc_report_Approvals_LegacyId]
        ON [cc_report].[Approvals]([LegacyId])
        WHERE [LegacyId] IS NOT NULL;

    /* Approver visibility: a manager can still open a report after deciding. */
    CREATE NONCLUSTERED INDEX [IX_cc_report_Approvals_Approver]
        ON [cc_report].[Approvals]([Approver], [ReportId]);
END
GO
