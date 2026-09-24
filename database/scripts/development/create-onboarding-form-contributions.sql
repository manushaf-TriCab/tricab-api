/*
================================================================================
  Phase 5.4 — [onboarding].[FormContributions]: the parts of a form that HR or
  the starter's named manager fill (the induction checklist's items and
  sign-off, the vehicle inspection).

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Owner decision, 24 Sep 2026: HR fills any part; the manager recorded on the
  starter's profile ([TraineeProfiles].[ManagerUserId]) fills the manager parts.

    - One row per save, append-only: the latest row per assignment and role is
      what the form shows, and every earlier one stays as evidence of who wrote
      what and when. An INSTEAD OF UPDATE, DELETE trigger holds that, as on the
      other evidence tables.
    - [AnswersJson] holds ordinary HR data only (C2). Only an `hr` form may have
      parts filled by somebody other than the starter (FormSchemaRules), so
      nothing here is payroll or restricted, and [onboarding_hr] is untouched.
    - [Role] is `hr` or `manager`; [ContributorUserId] is who.

  Written in GO-separated batches because CREATE TRIGGER must begin its batch.
  Guarded and idempotent; needs the onboarding schema already applied.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[onboarding].[FormContributions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [onboarding].[FormContributions] (
            [FormContributionId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId]           UNIQUEIDENTIFIER NOT NULL
                CONSTRAINT [DF_onboarding_FormContributions_PublicId] DEFAULT (NEWSEQUENTIALID()),
            [AssignmentId]       BIGINT NOT NULL,
            [Role]               NVARCHAR(16) NOT NULL,
            [ContributorUserId]  BIGINT NOT NULL,
            [AnswersJson]        NVARCHAR(MAX) NOT NULL,
            [ContributedAtUtc]   DATETIME2(7) NOT NULL
                CONSTRAINT [DF_onboarding_FormContributions_ContributedAtUtc] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_onboarding_FormContributions] PRIMARY KEY CLUSTERED ([FormContributionId]),
            CONSTRAINT [UQ_onboarding_FormContributions_PublicId] UNIQUE ([PublicId]),
            CONSTRAINT [CK_onboarding_FormContributions_Role] CHECK ([Role] IN (N'hr', N'manager')),
            CONSTRAINT [CK_onboarding_FormContributions_AnswersJson] CHECK (ISJSON([AnswersJson]) = 1),
            CONSTRAINT [FK_onboarding_FormContributions_Assignment]
                FOREIGN KEY ([AssignmentId]) REFERENCES [onboarding].[Assignments]([AssignmentId]),
            CONSTRAINT [FK_onboarding_FormContributions_Contributor]
                FOREIGN KEY ([ContributorUserId]) REFERENCES [identity].[Users]([UserId])
        );
        CREATE NONCLUSTERED INDEX [IX_onboarding_FormContributions_Latest]
            ON [onboarding].[FormContributions]([AssignmentId], [Role], [FormContributionId] DESC);
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();
    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
GO

IF OBJECT_ID(N'[onboarding].[TR_FormContributions_AppendOnly]', N'TR') IS NOT NULL
    DROP TRIGGER [onboarding].[TR_FormContributions_AppendOnly];
GO
CREATE TRIGGER [onboarding].[TR_FormContributions_AppendOnly]
ON [onboarding].[FormContributions]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50007, N'onboarding.FormContributions is append-only: a correction is a new row.', 1;
END;
GO

PRINT N'onboarding form contributions created/verified.';
GO
