/*
================================================================================
  Phase 7.4 — [migration].[OnboardingMigrationRows]: every row, account and
  blob an onboarding migration batch created, so the batch can be verified and
  rolled back without touching anything it did not create.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. (Owner decision,
  25 Sep 2026: the synthetic rehearsal runs on tricab-mel and needs this table.)

    - One row per thing created: an assignment, its status event, an attempt,
      each answer, a document and its blob, an account, an induction record.
      [TargetRole] tells apart several rows one source record produces
      ("attempt", "answer:3", "document:completed_pdf_base64"). [SubjectUserId] is
      whose it is: rollback works person by person. (Not a foreign key: a
      rolled-back batch's accounts are disabled, never deleted, so it always
      resolves; and the ledger must not stop identity housekeeping.)
    - The batch header lives in the generic ledger,
      [migration].[LegacyMigrationBatches] (ModuleCode = N'onboarding');
      [BatchPublicId] refers to it.
    - Idempotency: a filtered unique index allows one live row per
      (source record, role). A rerun of the same source record is refused by
      the database, not just skipped by PHP; a rolled-back row
      ([RolledBackAtUtc] set) no longer counts, so a batch can be re-run after
      its rollback.
    - Rollback sets [RolledBackAtUtc]; rows here are never deleted, so the
      record of what a batch did survives the batch.

  Guarded and idempotent; needs create-legacy-migration-schema.sql applied.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[migration].[LegacyMigrationBatches]', N'U') IS NULL
        THROW 50100, N'[migration].[LegacyMigrationBatches] is missing: apply create-legacy-migration-schema.sql first.', 1;

    IF OBJECT_ID(N'[migration].[OnboardingMigrationRows]', N'U') IS NULL
    BEGIN
        CREATE TABLE [migration].[OnboardingMigrationRows] (
            [OnboardingMigrationRowId] BIGINT IDENTITY(1,1) NOT NULL,
            [BatchPublicId]       UNIQUEIDENTIFIER NOT NULL,
            [SourceSystem]        NVARCHAR(50) NOT NULL,
            [SourceTable]         NVARCHAR(64) NOT NULL,
            [SourceId]            BIGINT NOT NULL,
            [SubjectUserId]       BIGINT NOT NULL,
            [TargetRole]          NVARCHAR(80) NOT NULL,
            [TargetTable]         NVARCHAR(128) NOT NULL,
            [TargetId]            BIGINT NULL,
            [TargetPublicId]      UNIQUEIDENTIFIER NULL,
            [TargetBlobContainer] NVARCHAR(120) NULL,
            [TargetBlobPath]      NVARCHAR(1024) NULL,
            [SourceHash]          CHAR(64) NULL,
            [CreatedAtUtc]        DATETIME2(7) NOT NULL
                CONSTRAINT [DF_migration_OnboardingMigrationRows_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [RolledBackAtUtc]     DATETIME2(7) NULL,
            CONSTRAINT [PK_migration_OnboardingMigrationRows] PRIMARY KEY CLUSTERED ([OnboardingMigrationRowId]),
            CONSTRAINT [FK_migration_OnboardingMigrationRows_Batch]
                FOREIGN KEY ([BatchPublicId]) REFERENCES [migration].[LegacyMigrationBatches]([PublicId]),
            CONSTRAINT [CK_migration_OnboardingMigrationRows_Target] CHECK (
                [TargetId] IS NOT NULL OR [TargetPublicId] IS NOT NULL OR [TargetBlobPath] IS NOT NULL
            ),
            CONSTRAINT [CK_migration_OnboardingMigrationRows_Blob] CHECK (
                ([TargetBlobPath] IS NULL AND [TargetBlobContainer] IS NULL)
                OR ([TargetBlobPath] IS NOT NULL AND [TargetBlobContainer] IS NOT NULL)
            )
        );
        CREATE NONCLUSTERED INDEX [IX_migration_OnboardingMigrationRows_Batch]
            ON [migration].[OnboardingMigrationRows]([BatchPublicId], [SubjectUserId], [OnboardingMigrationRowId]);
        CREATE UNIQUE NONCLUSTERED INDEX [UQ_migration_OnboardingMigrationRows_LiveSource]
            ON [migration].[OnboardingMigrationRows]([SourceSystem], [SourceTable], [SourceId], [TargetRole])
            WHERE [RolledBackAtUtc] IS NULL;
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

PRINT N'migration.OnboardingMigrationRows created/verified.';
GO
