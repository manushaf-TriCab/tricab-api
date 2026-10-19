/*
================================================================================
  [cablefit] schema - CableFit candidate designs and their revisions.

  Ported from the SQLite npr_cablefit_candidates / npr_cablefit_candidate_revisions
  tables in the NPR portal. Differences from the source:

    - A candidate may exist with no NPR request (npr_request_public_id and
      npr_variant_id are NULLable). The request is named by its public id; there
      is deliberately no foreign key, as the NPR tables live in another module.
    - The author is [identity].[Users].[UserId], not a copied id/email/name.
    - The design (construction, provenance, unresolved requirements, chosen
      alternatives) lives only in [candidate_revisions].[design_json]; a save
      appends a revision. [candidates].[current_revision_number] is the version
      and the optimistic-concurrency guard: a save runs
      UPDATE ... WHERE current_revision_number = @expected, and zero rows
      affected means a conflict. There is no row-version column.
    - npr_request_public_id is NVARCHAR(64): the NPR public-id format belongs
      to another module, so it is not assumed to be a GUID.

  Conventions as create-mail-schema.sql / create-onboarding-schema.sql: BIGINT
  IDENTITY keys, UNIQUEIDENTIFIER public_id, DATETIME2(7) UTC, ISJSON checks on
  JSON columns, every object guarded so the file is idempotent.

  Run after create-identity-schema.sql.
================================================================================
*/

SET NOCOUNT ON;
GO

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'cablefit')
BEGIN
    EXEC(N'CREATE SCHEMA [cablefit]');
END
GO

IF OBJECT_ID(N'[cablefit].[candidates]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablefit].[candidates] (
        [candidate_id]                    BIGINT IDENTITY(1,1) NOT NULL,
        [public_id]                       UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_cablefit_candidates_public_id] DEFAULT (NEWSEQUENTIALID()),
        [npr_request_public_id]           NVARCHAR(64) NULL,
        [npr_variant_id]                  NVARCHAR(64) NULL,
        [candidate_name]                  NVARCHAR(255) NOT NULL,
        [current_revision_number]         INT NOT NULL
            CONSTRAINT [DF_cablefit_candidates_current_revision_number] DEFAULT (0),
        [created_by_user_id]              BIGINT NOT NULL,
        [created_at]                      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cablefit_candidates_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_by_user_id]              BIGINT NOT NULL,
        [updated_at]                      DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cablefit_candidates_updated_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_cablefit_candidates] PRIMARY KEY CLUSTERED ([candidate_id]),
        CONSTRAINT [UQ_cablefit_candidates_public_id] UNIQUE ([public_id]),
        CONSTRAINT [FK_cablefit_candidates_created_by]
            FOREIGN KEY ([created_by_user_id]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_cablefit_candidates_updated_by]
            FOREIGN KEY ([updated_by_user_id]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablefit_candidates_npr_request'
               AND object_id = OBJECT_ID(N'[cablefit].[candidates]'))
BEGIN
    CREATE INDEX [IX_cablefit_candidates_npr_request]
        ON [cablefit].[candidates] ([npr_request_public_id], [npr_variant_id]);
END
GO

IF OBJECT_ID(N'[cablefit].[candidate_revisions]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablefit].[candidate_revisions] (
        [revision_id]          BIGINT IDENTITY(1,1) NOT NULL,
        [public_id]            UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_cablefit_candidate_revisions_public_id] DEFAULT (NEWSEQUENTIALID()),
        [candidate_id]         BIGINT NOT NULL,
        [revision_number]      INT NOT NULL,
        [npr_revision_number]  INT NULL,
        [mapping_version]      NVARCHAR(16) NOT NULL,
        [stale]                BIT NOT NULL
            CONSTRAINT [DF_cablefit_candidate_revisions_stale] DEFAULT (0),
        [design_json]          NVARCHAR(MAX) NOT NULL,
        [notes]                NVARCHAR(MAX) NULL,
        [saved_by_user_id]     BIGINT NOT NULL,
        [created_at]           DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cablefit_candidate_revisions_created_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_cablefit_candidate_revisions] PRIMARY KEY CLUSTERED ([revision_id]),
        CONSTRAINT [UQ_cablefit_candidate_revisions_public_id] UNIQUE ([public_id]),
        CONSTRAINT [UQ_cablefit_candidate_revisions_candidate_number] UNIQUE ([candidate_id], [revision_number]),
        CONSTRAINT [CK_cablefit_candidate_revisions_design_json] CHECK (ISJSON([design_json]) = 1),
        CONSTRAINT [FK_cablefit_candidate_revisions_candidate]
            FOREIGN KEY ([candidate_id]) REFERENCES [cablefit].[candidates]([candidate_id]) ON DELETE CASCADE,
        CONSTRAINT [FK_cablefit_candidate_revisions_saved_by]
            FOREIGN KEY ([saved_by_user_id]) REFERENCES [identity].[Users]([UserId])
    );
END
GO
