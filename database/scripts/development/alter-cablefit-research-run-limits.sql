/*
    CableFit research run limits (C3.6): one active run per candidate, and the per-user daily cap count.

    Run against a database whose [cablefit] schema was created by create-cablefit-schema.sql. Idempotent: safe to
    run any number of times. It changes no data.

    - UX_cablefit_research_runs_one_active: a candidate has at most one run with status queued or running.
      If a candidate already has two active runs the index cannot be built; the script stops and lists them.
      Fail or remove the extra runs, then run it again.
    - IX_cablefit_research_runs_user_requested: ([requested_by_user_id], [requested_at]), the daily-cap count.
*/
SET NOCOUNT ON;
GO

IF OBJECT_ID(N'[cablefit].[research_runs]', N'U') IS NULL
BEGIN
    THROW 50000, N'[cablefit].[research_runs] does not exist; run create-cablefit-schema.sql first.', 1;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'UX_cablefit_research_runs_one_active'
               AND object_id = OBJECT_ID(N'[cablefit].[research_runs]'))
BEGIN
    IF EXISTS (SELECT 1 FROM [cablefit].[research_runs]
               WHERE [status] IN (N'queued', N'running')
               GROUP BY [candidate_id] HAVING COUNT(*) > 1)
    BEGIN
        SELECT [candidate_id], COUNT(*) AS [active_runs]
        FROM [cablefit].[research_runs]
        WHERE [status] IN (N'queued', N'running')
        GROUP BY [candidate_id] HAVING COUNT(*) > 1;

        THROW 50001, N'Some candidates have more than one queued or running research run; fail or remove the extras (listed above), then run this script again.', 1;
    END

    CREATE UNIQUE INDEX [UX_cablefit_research_runs_one_active]
        ON [cablefit].[research_runs] ([candidate_id])
        WHERE [status] IN (N'queued', N'running');
    PRINT N'Created UX_cablefit_research_runs_one_active.';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_cablefit_research_runs_user_requested'
               AND object_id = OBJECT_ID(N'[cablefit].[research_runs]'))
BEGIN
    CREATE INDEX [IX_cablefit_research_runs_user_requested]
        ON [cablefit].[research_runs] ([requested_by_user_id], [requested_at]);
    PRINT N'Created IX_cablefit_research_runs_user_requested.';
END
GO
