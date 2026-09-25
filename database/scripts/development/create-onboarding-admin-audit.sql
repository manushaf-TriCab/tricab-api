/*
================================================================================
  Phase 4.6 — [audit].[OnboardingAdminEvents]: every state-changing HR action.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval.

  Work order invariant 13: "All state-changing admin actions are audited with
  actor, action, subject, time and result, without sensitive payloads." The two
  onboarding audit tables that already exist do not cover it —
  [audit].[OnboardingStatusEvents] records status moves and
  [audit].[OnboardingDocumentAccessEvents] records document reads. Allocation,
  withdrawal, due dates, inviting a person, recording a departure and publishing
  content are recorded nowhere. The source app has no audit table at all.

  Shaped on [audit].[UserManagementEvents], the platform's admin audit table, so
  that one reader can follow both:

    - [Outcome] is the "result" half of invariant 13. `succeeded` rows are
      written in the same transaction as the change they describe, so a change
      cannot land without its row or a row without its change. `refused` rows
      are written after a domain refusal, on their own, so that somebody trying
      to allocate to a leaver, or to complete their own induction, leaves a trace.
    - [DetailsJson] carries identifiers, statuses, dates and counts — never a
      form value, an answer, an email address or free text a person typed. The
      application layer builds it from a fixed set of keys and the test suite
      reads it back to prove it.
    - No [RowVersion], and an INSTEAD OF UPDATE, DELETE trigger, as the other two
      onboarding audit tables have. An audit row that can be edited is a note.

  Written in GO-separated batches because CREATE TRIGGER must begin its batch.
  Guarded and idempotent, and runnable on a database that already has the rest
  of the onboarding schema.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'audit')
    BEGIN
        EXEC(N'CREATE SCHEMA [audit]');
    END;

    IF OBJECT_ID(N'[audit].[OnboardingAdminEvents]', N'U') IS NULL
    BEGIN
        CREATE TABLE [audit].[OnboardingAdminEvents] (
            [OnboardingAdminEventId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId]               UNIQUEIDENTIFIER NOT NULL
                CONSTRAINT [DF_audit_OnboardingAdminEvents_PublicId] DEFAULT (NEWSEQUENTIALID()),
            [ActorUserId]            BIGINT NOT NULL,
            /* The person the action was about. NULL for content actions, which
               are about a module, policy or form rather than a person. */
            [SubjectUserId]          BIGINT NULL,
            /* e.g. `allocation.allocated`, `allocation.withdrawn`. Codes are
               TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType. */
            [EventType]              NVARCHAR(80) NOT NULL,
            /* The assignment, person or content version acted on. */
            [TargetPublicId]         UNIQUEIDENTIFIER NULL,
            [Outcome]                NVARCHAR(16) NOT NULL,
            [EventSummary]           NVARCHAR(500) NOT NULL,
            [DetailsJson]            NVARCHAR(MAX) NULL,
            [PermissionCode]         NVARCHAR(80) NOT NULL,
            [RequestCorrelationId]   NVARCHAR(100) NULL,
            [IpAddress]              NVARCHAR(64) NULL,
            [UserAgent]              NVARCHAR(500) NULL,
            [CreatedAtUtc]           DATETIMEOFFSET NOT NULL
                CONSTRAINT [DF_audit_OnboardingAdminEvents_CreatedAtUtc] DEFAULT (SYSDATETIMEOFFSET()),
            CONSTRAINT [PK_audit_OnboardingAdminEvents] PRIMARY KEY CLUSTERED ([OnboardingAdminEventId]),
            CONSTRAINT [UQ_audit_OnboardingAdminEvents_PublicId] UNIQUE ([PublicId]),
            CONSTRAINT [CK_audit_OnboardingAdminEvents_Outcome]
                CHECK ([Outcome] IN (N'succeeded', N'refused')),
            CONSTRAINT [CK_audit_OnboardingAdminEvents_EventType_NotEmpty]
                CHECK (LEN(LTRIM(RTRIM([EventType]))) > 0),
            CONSTRAINT [CK_audit_OnboardingAdminEvents_EventSummary_NotEmpty]
                CHECK (LEN(LTRIM(RTRIM([EventSummary]))) > 0),
            CONSTRAINT [CK_audit_OnboardingAdminEvents_DetailsJson]
                CHECK ([DetailsJson] IS NULL OR ISJSON([DetailsJson]) = 1),
            CONSTRAINT [FK_audit_OnboardingAdminEvents_ActorUser]
                FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_audit_OnboardingAdminEvents_SubjectUser]
                FOREIGN KEY ([SubjectUserId]) REFERENCES [identity].[Users]([UserId]) ON DELETE NO ACTION
        );
    END;

    /* "Everything HR did to this person", newest first — the per-person history. */
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[OnboardingAdminEvents]') AND name = N'IX_audit_OnboardingAdminEvents_Subject')
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_audit_OnboardingAdminEvents_Subject]
            ON [audit].[OnboardingAdminEvents]([SubjectUserId], [CreatedAtUtc] DESC)
            WHERE [SubjectUserId] IS NOT NULL;
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[OnboardingAdminEvents]') AND name = N'IX_audit_OnboardingAdminEvents_Actor')
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_audit_OnboardingAdminEvents_Actor]
            ON [audit].[OnboardingAdminEvents]([ActorUserId], [CreatedAtUtc] DESC);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[OnboardingAdminEvents]') AND name = N'IX_audit_OnboardingAdminEvents_Target')
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_audit_OnboardingAdminEvents_Target]
            ON [audit].[OnboardingAdminEvents]([TargetPublicId], [CreatedAtUtc] DESC)
            WHERE [TargetPublicId] IS NOT NULL;
    END;

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
GO

IF OBJECT_ID(N'[audit].[TR_OnboardingAdminEvents_AppendOnly]', N'TR') IS NOT NULL
    DROP TRIGGER [audit].[TR_OnboardingAdminEvents_AppendOnly];
GO
CREATE TRIGGER [audit].[TR_OnboardingAdminEvents_AppendOnly]
ON [audit].[OnboardingAdminEvents]
INSTEAD OF UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 50006, N'audit.OnboardingAdminEvents is append-only.', 1;
END;
GO

PRINT N'onboarding admin audit created/verified.';
GO
