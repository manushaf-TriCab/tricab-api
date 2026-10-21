/*
================================================================================
  DEVELOPMENT seed: NPR manager routes (plan NPR-TSD-MIGRATION-PLAN N2.1).

  - Idempotent / safe to re-run
  - One route per sales territory, as Manusha confirmed on 22 Oct 2026:
      VIC   -> danielf@    TAS -> stuartj@    SA/NT -> neilo@    WA -> scotth@
      NSW, QLD and NZ -> shanep@
    resources/npr/sales-roster.json puts each salesperson in their territory, so
    a request goes to the manager of the person who submits it. Anyone the
    roster leaves on an office name has no route: their drafts save but cannot
    be submitted.
  - The manager is looked up in [identity].[Users] by email; if that account
    does not exist the script PRINTs and skips that territory, never inventing
    a user.
  - Skips a territory that already has an open-ended or currently active
    regular (non-delegate) route, so a re-run, or a route changed by an admin,
    is left alone.
  - The 19 Oct Brisbane route is left as it is; no one on the roster is in
    'Brisbane' any more.
  - WRITTEN, NOT EXECUTED against any shared database. Run after
    create-npr-schema.sql.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Today DATE = CAST(SYSUTCDATETIME() AS DATE);

    DECLARE @Routes TABLE (
        [Branch]       NVARCHAR(64)  NOT NULL PRIMARY KEY,
        [Email]        NVARCHAR(256) NOT NULL,
        [FallbackName] NVARCHAR(200) NOT NULL
    );
    INSERT INTO @Routes ([Branch], [Email], [FallbackName]) VALUES
        (N'VIC',   N'danielf@tricab.com', N'Daniel Fitzell'),
        (N'TAS',   N'stuartj@tricab.com', N'Stuart Jones'),
        (N'SA/NT', N'neilo@tricab.com',   N'Neil O''Loughlin'),
        (N'WA',    N'scotth@tricab.com',  N'Scott Hewitt'),
        (N'NSW',   N'shanep@tricab.com',  N'Shane Plumridge'),
        (N'QLD',   N'shanep@tricab.com',  N'Shane Plumridge'),
        (N'NZ',    N'shanep@tricab.com',  N'Shane Plumridge');

    DECLARE @Branch NVARCHAR(64), @Email NVARCHAR(256), @FallbackName NVARCHAR(200), @UserId BIGINT;

    DECLARE route_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT [Branch], [Email], [FallbackName] FROM @Routes ORDER BY [Branch];
    OPEN route_cursor;
    FETCH NEXT FROM route_cursor INTO @Branch, @Email, @FallbackName;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @UserId = (
            SELECT TOP (1) [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = LOWER(@Email)
        );

        IF @UserId IS NULL
        BEGIN
            PRINT CONCAT(N'Skipped: no [identity].[Users] row for ', @Email, N', so the ', @Branch, N' route was not created.');
        END
        ELSE IF EXISTS (
            SELECT 1 FROM [npr].[ManagerRoutes]
            WHERE [Branch] = @Branch AND [IsDelegate] = 0
              AND ([ValidTo] IS NULL OR [ValidTo] >= @Today)
        )
        BEGIN
            PRINT CONCAT(N'Skipped: ', @Branch, N' already has a regular manager route that is open-ended or still active.');
        END
        ELSE
        BEGIN
            INSERT INTO [npr].[ManagerRoutes]
                ([Branch], [ManagerUserId], [ManagerEmail], [ManagerName], [IsDelegate], [ValidFrom], [ValidTo], [ApprovedByUserId])
            SELECT @Branch, u.[UserId], LOWER(@Email), ISNULL(NULLIF(LTRIM(RTRIM(u.[DisplayName])), N''), @FallbackName),
                   0, @Today, NULL, NULL
            FROM [identity].[Users] u
            WHERE u.[UserId] = @UserId;

            PRINT CONCAT(N'Created the ', @Branch, N' route: ', @FallbackName, N' (', @Email, N').');
        END;

        FETCH NEXT FROM route_cursor INTO @Branch, @Email, @FallbackName;
    END;

    CLOSE route_cursor;
    DEALLOCATE route_cursor;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
