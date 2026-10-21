/*
    CableFit research assessment (C4.3): what a run researched, and the agent's advisory verdict.

    Run against a database whose [cablefit] schema was created by create-cablefit-schema.sql. Idempotent: safe to
    run any number of times. It changes no data: existing runs keep NULL in every new column and are shown as before
    (findings only, no verdict).

    [cablefit].[research_runs]
    - candidate_revision_number  the saved revision that was researched (Evaluate saves first).
    - application_code           an ApplicationType code (FIXED, DRAG_CHAIN, ...) or OTHER for free text.
    - standard_code              e.g. AS_NZS_2802; NULL means the minimum baseline.
    - rule_check_json            the browser rule check's result sent with the request (Reeling & Unreeling only).
    - verdict                    likely_suitable | suitable_with_concerns | likely_unsuitable | insufficient_evidence.
    - assessment_json            summary, pros, cons, suggested changes and checks, as parsed.
    - IX_cablefit_research_runs_reuse: finds a succeeded run for the same saved revision, application and standard.

    [cablefit].[research_findings]
    - stance                     supports | concern | neutral, for this design in this application.

    Each column is added in its own batch and its CHECK in a later one: SQL Server compiles a whole batch first, so a
    CHECK naming a column that the same batch adds fails with Msg 207.
*/
SET NOCOUNT ON;
GO

IF OBJECT_ID(N'[cablefit].[research_runs]', N'U') IS NULL OR OBJECT_ID(N'[cablefit].[research_findings]', N'U') IS NULL
BEGIN
    THROW 50000, N'[cablefit].[research_runs] or [research_findings] does not exist; run create-cablefit-schema.sql first.', 1;
END
GO

-- Columns ---------------------------------------------------------------------------------------------------------

IF COL_LENGTH(N'[cablefit].[research_runs]', N'candidate_revision_number') IS NULL
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD [candidate_revision_number] INT NULL;
    PRINT N'Added research_runs.candidate_revision_number.';
END
GO

IF COL_LENGTH(N'[cablefit].[research_runs]', N'application_code') IS NULL
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD [application_code] NVARCHAR(32) NULL;
    PRINT N'Added research_runs.application_code.';
END
GO

IF COL_LENGTH(N'[cablefit].[research_runs]', N'standard_code') IS NULL
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD [standard_code] NVARCHAR(64) NULL;
    PRINT N'Added research_runs.standard_code.';
END
GO

IF COL_LENGTH(N'[cablefit].[research_runs]', N'rule_check_json') IS NULL
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD [rule_check_json] NVARCHAR(MAX) NULL;
    PRINT N'Added research_runs.rule_check_json.';
END
GO

IF COL_LENGTH(N'[cablefit].[research_runs]', N'verdict') IS NULL
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD [verdict] NVARCHAR(32) NULL;
    PRINT N'Added research_runs.verdict.';
END
GO

IF COL_LENGTH(N'[cablefit].[research_runs]', N'assessment_json') IS NULL
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD [assessment_json] NVARCHAR(MAX) NULL;
    PRINT N'Added research_runs.assessment_json.';
END
GO

IF COL_LENGTH(N'[cablefit].[research_findings]', N'stance') IS NULL
BEGIN
    ALTER TABLE [cablefit].[research_findings] ADD [stance] NVARCHAR(16) NULL;
    PRINT N'Added research_findings.stance.';
END
GO

-- Checks ----------------------------------------------------------------------------------------------------------

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cablefit_research_runs_verdict'
               AND parent_object_id = OBJECT_ID(N'[cablefit].[research_runs]'))
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD CONSTRAINT [CK_cablefit_research_runs_verdict]
        CHECK ([verdict] IS NULL OR [verdict] IN
            (N'likely_suitable', N'suitable_with_concerns', N'likely_unsuitable', N'insufficient_evidence'));
    PRINT N'Added CK_cablefit_research_runs_verdict.';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cablefit_research_runs_assessment_json'
               AND parent_object_id = OBJECT_ID(N'[cablefit].[research_runs]'))
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD CONSTRAINT [CK_cablefit_research_runs_assessment_json]
        CHECK ([assessment_json] IS NULL OR ISJSON([assessment_json]) = 1);
    PRINT N'Added CK_cablefit_research_runs_assessment_json.';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cablefit_research_runs_rule_check_json'
               AND parent_object_id = OBJECT_ID(N'[cablefit].[research_runs]'))
BEGIN
    ALTER TABLE [cablefit].[research_runs] ADD CONSTRAINT [CK_cablefit_research_runs_rule_check_json]
        CHECK ([rule_check_json] IS NULL OR ISJSON([rule_check_json]) = 1);
    PRINT N'Added CK_cablefit_research_runs_rule_check_json.';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_cablefit_research_findings_stance'
               AND parent_object_id = OBJECT_ID(N'[cablefit].[research_findings]'))
BEGIN
    ALTER TABLE [cablefit].[research_findings] ADD CONSTRAINT [CK_cablefit_research_findings_stance]
        CHECK ([stance] IS NULL OR [stance] IN (N'supports', N'concern', N'neutral'));
    PRINT N'Added CK_cablefit_research_findings_stance.';
END
GO

-- Reuse index -----------------------------------------------------------------------------------------------------

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablefit_research_runs_reuse'
               AND object_id = OBJECT_ID(N'[cablefit].[research_runs]'))
BEGIN
    CREATE INDEX [IX_cablefit_research_runs_reuse]
        ON [cablefit].[research_runs] ([candidate_id], [candidate_revision_number], [application_code], [standard_code], [status]);
    PRINT N'Created IX_cablefit_research_runs_reuse.';
END
GO
