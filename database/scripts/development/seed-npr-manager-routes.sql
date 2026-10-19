/*
================================================================================
  DEVELOPMENT seed: NPR manager routes (plan NPR-TSD-MIGRATION-PLAN N2.1).

  - Idempotent / safe to re-run
  - ONLY the routes that are confirmed. npr-portal `operational-config.json`
    confirms one: Brisbane -> Shane Plumridge (shanep@tricab.com), "user
    explicitly selected Shane as Brisbane NPR endorser". Every other branch has
    no route, so its requests save as drafts and cannot be submitted until
    management confirms an approver. Nothing is inferred from the sales roster.
  - The manager is looked up in [identity].[Users] by email; if that account
    does not exist the script PRINTs and skips, never inventing a user.
  - Skips when the branch already has an open-ended or currently active
    regular (non-delegate) route, so a re-run, or a route changed by an admin,
    is left alone.
  - WRITTEN, NOT EXECUTED against any shared database. Run after
    create-npr-schema.sql.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Branch NVARCHAR(64) = N'Brisbane';
    DECLARE @Email NVARCHAR(256) = N'shanep@tricab.com';
    DECLARE @FallbackName NVARCHAR(200) = N'Shane Plumridge';
    DECLARE @Today DATE = CAST(SYSUTCDATETIME() AS DATE);

    DECLARE @UserId BIGINT = (
        SELECT TOP (1) [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = LOWER(@Email)
    );

    IF @UserId IS NULL
    BEGIN
        PRINT N'Skipped: no [identity].[Users] row for shanep@tricab.com, so the Brisbane route was not created.';
    END
    ELSE IF EXISTS (
        SELECT 1 FROM [npr].[ManagerRoutes]
        WHERE [Branch] = @Branch AND [IsDelegate] = 0
          AND ([ValidTo] IS NULL OR [ValidTo] >= @Today)
    )
    BEGIN
        PRINT N'Skipped: Brisbane already has a regular manager route that is open-ended or still active.';
    END
    ELSE
    BEGIN
        INSERT INTO [npr].[ManagerRoutes]
            ([Branch], [ManagerUserId], [ManagerEmail], [ManagerName], [IsDelegate], [ValidFrom], [ValidTo], [ApprovedByUserId])
        SELECT @Branch, u.[UserId], LOWER(@Email), ISNULL(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), @FallbackName),
               0, @Today, NULL, NULL
        FROM [identity].[Users] u
        WHERE u.[UserId] = @UserId;

        PRINT N'Created the Brisbane route: Shane Plumridge (shanep@tricab.com).';
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
