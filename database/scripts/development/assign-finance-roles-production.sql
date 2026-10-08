/*
================================================================================
  PRODUCTION - who holds which finance role, for the Tri-Task and Tri-AR go-live.

  Written 8 Oct 2026. STATUS: NOT APPLIED. Applied to tricab-mel by Manusha or
  Jeff, never by a builder.

  - Idempotent / safe to re-run: a missing assignment is inserted, an inactive
    one is made active; nothing is ever removed
  - Run after seed-finance-roles.sql, seed-finance-task-roles.sql and
    seed-finance-ar-roles.sql
  - Touches [identity].[UserRoles] only. It creates NO user: a person must have
    signed in to the platform once (or been added in user administration) first.
    Anyone not found is listed at the end and gets nothing; add them and run
    this again.

  The list is the finance portal's People page as Manusha gave it on 8 Oct 2026,
  with his rule: the basic role of each app for everyone, the administrator role
  for the portal's three administrators.

    Portal "Admin" (admin@, manushaf@, nickh@)
        FINANCE_ADMIN, FINANCE_TASK_ADMIN, FINANCE_AR_ADMIN
    Everyone else, per app they hold in the portal
        Tri-Task  FINANCE_TASK_USER     (Team Member)
        Tri-AR    FINANCE_AR_REVIEWER   (reads the register; changes nothing)

  NOTE on Tri-AR: a Reviewer cannot edit a record, scan or send, and is not in
  the assignee list (assignees are the holders of RECORDS.WRITE, that is
  Operators and Administrators). To let someone work the register, change their
  row below to FINANCE_AR_OPERATOR.

  Tri-AP, Tri-Credit Notes and Argo Collect access is not assigned here: Tri-AP's
  roles arrive in Phase 6, the other two apps are deferred.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @People TABLE (
        [Email]    NVARCHAR(320) NOT NULL,
        [RoleCode] NVARCHAR(100) NOT NULL,
        PRIMARY KEY ([Email], [RoleCode])
    );

    INSERT INTO @People ([Email], [RoleCode])
    VALUES
        /* The portal's administrators. */
        (N'admin@tricab.com',     N'FINANCE_ADMIN'),
        (N'admin@tricab.com',     N'FINANCE_TASK_ADMIN'),
        (N'admin@tricab.com',     N'FINANCE_AR_ADMIN'),
        (N'manushaf@tricab.com',  N'FINANCE_ADMIN'),
        (N'manushaf@tricab.com',  N'FINANCE_TASK_ADMIN'),
        (N'manushaf@tricab.com',  N'FINANCE_AR_ADMIN'),
        (N'nickh@tricab.com',     N'FINANCE_ADMIN'),
        (N'nickh@tricab.com',     N'FINANCE_TASK_ADMIN'),
        (N'nickh@tricab.com',     N'FINANCE_AR_ADMIN'),
        /* Tri-Task, basic. */
        (N'christine@tricab.com', N'FINANCE_TASK_USER'),
        (N'deew@tricab.com',      N'FINANCE_TASK_USER'),
        (N'linx@tricab.com',      N'FINANCE_TASK_USER'),
        (N'melissaw@tricab.com',  N'FINANCE_TASK_USER'),
        (N'mial@tricab.com',      N'FINANCE_TASK_USER'),
        (N'mingl@tricab.com',     N'FINANCE_TASK_USER'),
        (N'olivern@tricab.com',   N'FINANCE_TASK_USER'),
        /* Tri-AR, basic. */
        (N'linx@tricab.com',      N'FINANCE_AR_REVIEWER'),
        (N'melissaw@tricab.com',  N'FINANCE_AR_REVIEWER'),
        (N'mingl@tricab.com',     N'FINANCE_AR_REVIEWER');

    /* Every role named above must exist; a typo here would otherwise grant nothing silently. */
    IF EXISTS (
        SELECT 1 FROM @People p
        WHERE NOT EXISTS (SELECT 1 FROM [identity].[Roles] r WHERE UPPER(r.[RoleCode]) = p.[RoleCode] AND r.[IsActive] = 1)
    )
    BEGIN
        THROW 54211, N'A finance role named in this script does not exist. Run seed-finance-roles.sql, seed-finance-task-roles.sql and seed-finance-ar-roles.sql first.', 1;
    END;

    INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [IsActive])
    SELECT u.[UserId], r.[RoleId], 1
    FROM @People p
    JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(p.[Email])
    JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = p.[RoleCode]
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[UserRoles] ur WHERE ur.[UserId] = u.[UserId] AND ur.[RoleId] = r.[RoleId]
    );

    UPDATE ur
    SET ur.[IsActive] = 1
    FROM [identity].[UserRoles] ur
    JOIN [identity].[Users] u ON u.[UserId] = ur.[UserId]
    JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
    JOIN @People p ON LOWER(p.[Email]) = u.[NormalizedEmailAddress] AND p.[RoleCode] = UPPER(r.[RoleCode])
    WHERE ur.[IsActive] = 0;

    /* What is in place now. */
    SELECT p.[Email], p.[RoleCode],
           CASE WHEN u.[UserId] IS NULL THEN N'NO PLATFORM USER - nothing assigned'
                WHEN ur.[UserRoleId] IS NULL THEN N'not assigned'
                ELSE N'assigned' END AS [Result]
    FROM @People p
    LEFT JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(p.[Email])
    LEFT JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = p.[RoleCode]
    LEFT JOIN [identity].[UserRoles] ur ON ur.[UserId] = u.[UserId] AND ur.[RoleId] = r.[RoleId] AND ur.[IsActive] = 1
    ORDER BY p.[Email], p.[RoleCode];

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
