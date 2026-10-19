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

/*
================================================================================
  Research agent (CableFit C3). The agent is advisory: it never changes a fit
  score or a design. Three tables:

    - [research_sources]   the domains the agent may search and cite. A domain
                           also covers its subdomains. Chabilal reviews the list.
    - [research_runs]      one queued/running/finished run for a candidate. The
                           worker claims a queued run by moving it to 'running'
                           (started_at is the claim time); a claim older than
                           30 minutes is taken again. The parameters and application are stored, so
                           a run is repeatable and auditable.
    - [research_findings]  what a run found; findings whose URL host is not an
                           enabled source are dropped before they are saved
                           (research_runs.dropped_findings counts them).

  published_on holds YYYY, YYYY-MM or YYYY-MM-DD as the source gave it.
================================================================================
*/

IF OBJECT_ID(N'[cablefit].[research_sources]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablefit].[research_sources] (
        [source_id]           BIGINT IDENTITY(1,1) NOT NULL,
        [public_id]           UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_cablefit_research_sources_public_id] DEFAULT (NEWSEQUENTIALID()),
        [domain]              NVARCHAR(253) NOT NULL,
        [name]                NVARCHAR(200) NOT NULL,
        [category]            NVARCHAR(30) NOT NULL,
        [enabled]             BIT NOT NULL
            CONSTRAINT [DF_cablefit_research_sources_enabled] DEFAULT (1),
        [notes]               NVARCHAR(1000) NULL,
        [created_by_user_id]  BIGINT NULL,
        [created_at]          DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cablefit_research_sources_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_by_user_id]  BIGINT NULL,
        [updated_at]          DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cablefit_research_sources_updated_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_cablefit_research_sources] PRIMARY KEY CLUSTERED ([source_id]),
        CONSTRAINT [UQ_cablefit_research_sources_public_id] UNIQUE ([public_id]),
        CONSTRAINT [UQ_cablefit_research_sources_domain] UNIQUE ([domain]),
        CONSTRAINT [CK_cablefit_research_sources_domain_lower]
            CHECK ([domain] COLLATE Latin1_General_BIN2 = LOWER([domain]) COLLATE Latin1_General_BIN2),
        CONSTRAINT [CK_cablefit_research_sources_category]
            CHECK ([category] IN (N'standards_body', N'manufacturer', N'technical_publisher', N'other')),
        CONSTRAINT [FK_cablefit_research_sources_created_by]
            FOREIGN KEY ([created_by_user_id]) REFERENCES [identity].[Users]([UserId]),
        CONSTRAINT [FK_cablefit_research_sources_updated_by]
            FOREIGN KEY ([updated_by_user_id]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF OBJECT_ID(N'[cablefit].[research_runs]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablefit].[research_runs] (
        [run_id]               BIGINT IDENTITY(1,1) NOT NULL,
        [public_id]            UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_cablefit_research_runs_public_id] DEFAULT (NEWSEQUENTIALID()),
        [candidate_id]         BIGINT NOT NULL,
        [status]               NVARCHAR(16) NOT NULL
            CONSTRAINT [DF_cablefit_research_runs_status] DEFAULT (N'queued'),
        [application]          NVARCHAR(2000) NOT NULL,
        [parameters_json]      NVARCHAR(MAX) NOT NULL,
        [sources_used_json]    NVARCHAR(MAX) NULL,
        [model]                NVARCHAR(100) NULL,
        [input_tokens]         INT NULL,
        [output_tokens]        INT NULL,
        [search_requests]      INT NULL,
        [dropped_findings]     INT NOT NULL
            CONSTRAINT [DF_cablefit_research_runs_dropped_findings] DEFAULT (0),
        [error]                NVARCHAR(2000) NULL,
        [requested_by_user_id] BIGINT NOT NULL,
        [requested_at]         DATETIME2(7) NOT NULL
            CONSTRAINT [DF_cablefit_research_runs_requested_at] DEFAULT (SYSUTCDATETIME()),
        [started_at]           DATETIME2(7) NULL,
        [finished_at]          DATETIME2(7) NULL,
        CONSTRAINT [PK_cablefit_research_runs] PRIMARY KEY CLUSTERED ([run_id]),
        CONSTRAINT [UQ_cablefit_research_runs_public_id] UNIQUE ([public_id]),
        CONSTRAINT [CK_cablefit_research_runs_status]
            CHECK ([status] IN (N'queued', N'running', N'succeeded', N'failed')),
        CONSTRAINT [CK_cablefit_research_runs_parameters_json] CHECK (ISJSON([parameters_json]) = 1),
        CONSTRAINT [CK_cablefit_research_runs_sources_used_json]
            CHECK ([sources_used_json] IS NULL OR ISJSON([sources_used_json]) = 1),
        CONSTRAINT [FK_cablefit_research_runs_candidate]
            FOREIGN KEY ([candidate_id]) REFERENCES [cablefit].[candidates]([candidate_id]) ON DELETE CASCADE,
        CONSTRAINT [FK_cablefit_research_runs_requested_by]
            FOREIGN KEY ([requested_by_user_id]) REFERENCES [identity].[Users]([UserId])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablefit_research_runs_status_requested'
               AND object_id = OBJECT_ID(N'[cablefit].[research_runs]'))
BEGIN
    CREATE INDEX [IX_cablefit_research_runs_status_requested]
        ON [cablefit].[research_runs] ([status], [requested_at]);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablefit_research_runs_candidate'
               AND object_id = OBJECT_ID(N'[cablefit].[research_runs]'))
BEGIN
    CREATE INDEX [IX_cablefit_research_runs_candidate]
        ON [cablefit].[research_runs] ([candidate_id], [requested_at]);
END
GO

IF OBJECT_ID(N'[cablefit].[research_findings]', N'U') IS NULL
BEGIN
    CREATE TABLE [cablefit].[research_findings] (
        [finding_id]    BIGINT IDENTITY(1,1) NOT NULL,
        [public_id]     UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_cablefit_research_findings_public_id] DEFAULT (NEWSEQUENTIALID()),
        [run_id]        BIGINT NOT NULL,
        [parameter]     NVARCHAR(200) NOT NULL,
        [claim]         NVARCHAR(2000) NOT NULL,
        [relevance]     NVARCHAR(2000) NOT NULL,
        [url]           NVARCHAR(2000) NOT NULL,
        [title]         NVARCHAR(500) NOT NULL,
        [publisher]     NVARCHAR(200) NULL,
        [published_on]  NVARCHAR(10) NULL,
        [date_basis]    NVARCHAR(16) NOT NULL,
        [confidence]    NVARCHAR(8) NOT NULL,
        [sort_order]    INT NOT NULL,
        CONSTRAINT [PK_cablefit_research_findings] PRIMARY KEY CLUSTERED ([finding_id]),
        CONSTRAINT [UQ_cablefit_research_findings_public_id] UNIQUE ([public_id]),
        CONSTRAINT [CK_cablefit_research_findings_date_basis]
            CHECK ([date_basis] IN (N'page_age', N'stated_on_page', N'undated')),
        CONSTRAINT [CK_cablefit_research_findings_confidence]
            CHECK ([confidence] IN (N'high', N'medium', N'low')),
        CONSTRAINT [CK_cablefit_research_findings_published_on]
            CHECK ([published_on] IS NULL
                OR [published_on] LIKE N'[0-9][0-9][0-9][0-9]'
                OR [published_on] LIKE N'[0-9][0-9][0-9][0-9]-[0-9][0-9]'
                OR [published_on] LIKE N'[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'),
        CONSTRAINT [FK_cablefit_research_findings_run]
            FOREIGN KEY ([run_id]) REFERENCES [cablefit].[research_runs]([run_id]) ON DELETE CASCADE
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablefit_research_findings_run'
               AND object_id = OBJECT_ID(N'[cablefit].[research_findings]'))
BEGIN
    CREATE INDEX [IX_cablefit_research_findings_run]
        ON [cablefit].[research_findings] ([run_id], [sort_order]);
END
GO
