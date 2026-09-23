/*
================================================================================
  Handbook policy import, Step 2 (decision D3) — policy metadata columns.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  The Employee Handbook's policies carry a document reference, an issued and a
  review-due label, a chapter and a position, and the source tells apart the
  policies a new starter acknowledges from the ones they only read.
  `[onboarding].[Policies]` and `[onboarding].[PolicyVersions]` had a column
  for none of it. See platform/ONBOARDING-POLICY-IMPORT-PLAN.md.

  On the POLICY — it names and places the policy, and changing it is an
  ordinary edit that every holder sees at once, as with [Title]:

    [Summary]       one or two sentences shown under the title in lists.
    [ChapterTitle]  the handbook chapter it sits in ("Working at TriCab").
    [SortOrder]     its position in the handbook; NULL sorts after the rest.

  On the VERSION — it is part of what was published, and a new wording under
  a new reference is a new version:

    [DocumentReference]        e.g. HR-POL-034-V000. Carries the document's own
                               version, which is not the platform's.
    [IssuedLabel], [ReviewDueLabel]
                               as printed ("September-25", "Effective
                               15/09/2023"). Labels, not dates: the source
                               prints months, years and prose, and a DATE
                               column would invent a day nobody chose.
    [RequiresAcknowledgement]  1: completing it means accepting the declaration,
                               and writes a [PolicyAcknowledgements] row.
                               0: it is read, not accepted — "Mark as read"
                               completes it with a status event and no
                               acknowledgement row. DEFAULT 1, so every policy
                               already published keeps the behaviour it had.

  Additive only: new nullable columns and one NOT NULL column with a default.
  Nothing is dropped, renamed or rewritten. Guarded and idempotent.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF COL_LENGTH(N'[onboarding].[Policies]', N'Summary') IS NULL
        ALTER TABLE [onboarding].[Policies] ADD [Summary] NVARCHAR(1000) NULL;

    IF COL_LENGTH(N'[onboarding].[Policies]', N'ChapterTitle') IS NULL
        ALTER TABLE [onboarding].[Policies] ADD [ChapterTitle] NVARCHAR(120) NULL;

    IF COL_LENGTH(N'[onboarding].[Policies]', N'SortOrder') IS NULL
        ALTER TABLE [onboarding].[Policies] ADD [SortOrder] INT NULL;

    IF COL_LENGTH(N'[onboarding].[PolicyVersions]', N'DocumentReference') IS NULL
        ALTER TABLE [onboarding].[PolicyVersions] ADD [DocumentReference] NVARCHAR(80) NULL;

    IF COL_LENGTH(N'[onboarding].[PolicyVersions]', N'IssuedLabel') IS NULL
        ALTER TABLE [onboarding].[PolicyVersions] ADD [IssuedLabel] NVARCHAR(40) NULL;

    IF COL_LENGTH(N'[onboarding].[PolicyVersions]', N'ReviewDueLabel') IS NULL
        ALTER TABLE [onboarding].[PolicyVersions] ADD [ReviewDueLabel] NVARCHAR(40) NULL;

    IF COL_LENGTH(N'[onboarding].[PolicyVersions]', N'RequiresAcknowledgement') IS NULL
        ALTER TABLE [onboarding].[PolicyVersions] ADD [RequiresAcknowledgement] BIT NOT NULL
            CONSTRAINT [DF_onboarding_PolicyVersions_RequiresAcknowledgement] DEFAULT (1);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

/* A CHECK needs the column to exist when the batch compiles, so it has its own. */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_onboarding_Policies_SortOrder')
    ALTER TABLE [onboarding].[Policies] ADD CONSTRAINT [CK_onboarding_Policies_SortOrder]
        CHECK ([SortOrder] IS NULL OR [SortOrder] BETWEEN 1 AND 10000);
GO

/* Verification — read-only. */
SELECT t.name AS [Table], c.name AS [Column], TYPE_NAME(c.user_type_id) AS [Type], c.max_length AS [MaxLength], c.is_nullable AS [IsNullable]
FROM sys.columns c
JOIN sys.tables t ON t.object_id = c.object_id
WHERE SCHEMA_NAME(t.schema_id) = N'onboarding'
  AND ((t.name = N'Policies' AND c.name IN (N'Summary', N'ChapterTitle', N'SortOrder'))
    OR (t.name = N'PolicyVersions' AND c.name IN (N'DocumentReference', N'IssuedLabel', N'ReviewDueLabel', N'RequiresAcknowledgement')))
ORDER BY t.name, c.column_id;
GO
