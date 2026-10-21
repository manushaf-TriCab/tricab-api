/*
    CableFit design delete: a soft delete, so a deleted design leaves every list and lookup but it, its revisions and
    its research runs (which cost money) stay on record.

    Run against a database whose [cablefit] schema was created by create-cablefit-schema.sql. Idempotent: safe to
    run any number of times. It changes no data: every existing design keeps NULL and stays visible.

    [cablefit].[candidates]
    - deleted_at          when it was deleted; NULL while it is live. Every read filters on deleted_at IS NULL.
    - deleted_by_user_id  who deleted it (its creator or an Administrator).

    Apply this before the API change that reads these columns is deployed: until then, every CableFit query fails.
    The foreign key is added in a later batch than its column (SQL Server compiles a whole batch first).
*/
SET NOCOUNT ON;
GO

IF OBJECT_ID(N'[cablefit].[candidates]', N'U') IS NULL
BEGIN
    THROW 50000, N'[cablefit].[candidates] does not exist; run create-cablefit-schema.sql first.', 1;
END
GO

IF COL_LENGTH(N'[cablefit].[candidates]', N'deleted_at') IS NULL
BEGIN
    ALTER TABLE [cablefit].[candidates] ADD [deleted_at] DATETIME2(7) NULL;
    PRINT N'Added candidates.deleted_at.';
END
GO

IF COL_LENGTH(N'[cablefit].[candidates]', N'deleted_by_user_id') IS NULL
BEGIN
    ALTER TABLE [cablefit].[candidates] ADD [deleted_by_user_id] BIGINT NULL;
    PRINT N'Added candidates.deleted_by_user_id.';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_cablefit_candidates_deleted_by')
BEGIN
    ALTER TABLE [cablefit].[candidates] WITH CHECK ADD CONSTRAINT [FK_cablefit_candidates_deleted_by]
        FOREIGN KEY ([deleted_by_user_id]) REFERENCES [identity].[Users]([UserId]);
    PRINT N'Added FK_cablefit_candidates_deleted_by.';
END
GO
