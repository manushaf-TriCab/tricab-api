/*
================================================================================
  DEVELOPMENT seed — RFQ roles. DRAFT FOR OWNER REVIEW (plan 1.3).

  - Idempotent / safe to re-run
  - Creates four roles and ASSIGNS THEM TO NOBODY
  - Does not modify permissions, EMPLOYEE, GUEST or ADMINISTRATOR
  - WRITTEN, NOT EXECUTED against any shared database (28 Sep 2026)
  - Run after seed-rfq-permissions.sql

  The source's four roles (access.py), re-expressed as platform roles:

    RFQ_SALES          work own and branch RFQs: create, edit, claim, workflow,
                       comment, archive, sync and reconcile. Branch scope.
    RFQ_SALES_MANAGER  sales + assign, restore, analytics, activity log.
    RFQ_ADMIN          manager + all-branch reports, cost and margin, all scope,
                       module administration.
    RFQ_DEVELOPER      admin + review logs, diagnostics, permanent delete.

  What is NOT decided, and why this is a draft:

    - Who holds these (owner question Q8). Nobody is assigned here. Assigning
      people is plan 1.4, through User Management, not this script.
    - Branch scope does nothing yet: platform identity has no branch (Q7), so
      RFQ.SCOPE.BRANCH resolves to own-scope for everybody.
    - RFQ.PRICING.VIEW sits with admin and developer, where the source put
      pricing.view. In the source it HIDES prices in the browser rather than
      withholding them (Q9). If the answer is "withhold", sales and managers
      will need it, or will stop seeing prices they see today.
    - The source has no separate reconcile permission; RFQ.QUOTE.RECONCILE goes
      wherever drafts.push was.

  Must match TriCab\Core\Rfq\Module::ROLE_PERMISSIONS; tests/Rfq/RfqSeedScriptTest.php
  fails if they drift. This script OWNS the RFQ.* mappings of these four roles:
  anything else RFQ.* on them is deactivated, never deleted.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Roles TABLE (
        [RoleCode]    NVARCHAR(100) NOT NULL PRIMARY KEY,
        [RoleName]    NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL
    );

    INSERT INTO @Roles ([RoleCode], [RoleName], [Description])
    VALUES
        (N'RFQ_SALES',         N'RFQ Sales',
         N'Work own and branch RFQs: create, edit, claim, change workflow, comment, archive and send to 4js.'),
        (N'RFQ_SALES_MANAGER', N'RFQ Sales Manager',
         N'RFQ Sales, plus assigning work, restoring archived RFQs, analytics and the activity log.'),
        (N'RFQ_ADMIN',         N'RFQ Administrator',
         N'RFQ Sales Manager across every branch, plus reports, cost and margin, and module administration.'),
        (N'RFQ_DEVELOPER',     N'RFQ Developer',
         N'RFQ Administrator, plus review logs, parser diagnostics and permanent deletion.');

    /* ------------------------------------------------------------------
       The roles. IsSystemRole = 0 so an administrator can edit them in
       Roles & Permissions without fighting the seed.
       ------------------------------------------------------------------ */
    INSERT INTO [identity].[Roles] ([RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive])
    SELECT r.[RoleCode], r.[RoleName], r.[Description], 0, 1
    FROM @Roles r
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Roles] e WHERE UPPER(e.[RoleCode]) = UPPER(r.[RoleCode])
    );

    UPDATE e
    SET e.[IsActive] = 1,
        e.[RoleName] = r.[RoleName],
        e.[Description] = r.[Description],
        e.[UpdatedAt] = SYSDATETIMEOFFSET()
    FROM [identity].[Roles] e
    JOIN @Roles r ON UPPER(r.[RoleCode]) = UPPER(e.[RoleCode])
    WHERE e.[IsActive] = 0 OR e.[RoleName] <> r.[RoleName] OR ISNULL(e.[Description], N'') <> r.[Description];

    /* ------------------------------------------------------------------
       Grants. Generated from Module::ROLE_PERMISSIONS.
       ------------------------------------------------------------------ */
    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'RFQ_SALES', N'RFQ.ACCESS'),
        (N'RFQ_SALES', N'RFQ.DRAFT.READ'),
        (N'RFQ_SALES', N'RFQ.DRAFT.CREATE'),
        (N'RFQ_SALES', N'RFQ.DRAFT.EDIT'),
        (N'RFQ_SALES', N'RFQ.DRAFT.CLAIM'),
        (N'RFQ_SALES', N'RFQ.DRAFT.WORKFLOW'),
        (N'RFQ_SALES', N'RFQ.DRAFT.COMMENT'),
        (N'RFQ_SALES', N'RFQ.DRAFT.ARCHIVE'),
        (N'RFQ_SALES', N'RFQ.QUOTE.SYNC'),
        (N'RFQ_SALES', N'RFQ.QUOTE.RECONCILE'),
        (N'RFQ_SALES', N'RFQ.SCOPE.BRANCH'),
        (N'RFQ_SALES_MANAGER', N'RFQ.ACCESS'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.READ'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.CREATE'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.EDIT'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.CLAIM'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.WORKFLOW'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.COMMENT'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.ARCHIVE'),
        (N'RFQ_SALES_MANAGER', N'RFQ.QUOTE.SYNC'),
        (N'RFQ_SALES_MANAGER', N'RFQ.QUOTE.RECONCILE'),
        (N'RFQ_SALES_MANAGER', N'RFQ.SCOPE.BRANCH'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.ASSIGN'),
        (N'RFQ_SALES_MANAGER', N'RFQ.DRAFT.RESTORE'),
        (N'RFQ_SALES_MANAGER', N'RFQ.ANALYTICS.READ'),
        (N'RFQ_SALES_MANAGER', N'RFQ.AUDIT.READ'),
        (N'RFQ_ADMIN', N'RFQ.ACCESS'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.READ'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.CREATE'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.EDIT'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.CLAIM'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.WORKFLOW'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.COMMENT'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.ARCHIVE'),
        (N'RFQ_ADMIN', N'RFQ.QUOTE.SYNC'),
        (N'RFQ_ADMIN', N'RFQ.QUOTE.RECONCILE'),
        (N'RFQ_ADMIN', N'RFQ.SCOPE.BRANCH'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.ASSIGN'),
        (N'RFQ_ADMIN', N'RFQ.DRAFT.RESTORE'),
        (N'RFQ_ADMIN', N'RFQ.ANALYTICS.READ'),
        (N'RFQ_ADMIN', N'RFQ.AUDIT.READ'),
        (N'RFQ_ADMIN', N'RFQ.REPORTS.READ'),
        (N'RFQ_ADMIN', N'RFQ.PRICING.VIEW'),
        (N'RFQ_ADMIN', N'RFQ.SCOPE.ALL'),
        (N'RFQ_ADMIN', N'RFQ.ADMIN'),
        (N'RFQ_DEVELOPER', N'RFQ.ACCESS'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.READ'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.CREATE'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.EDIT'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.CLAIM'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.WORKFLOW'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.COMMENT'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.ARCHIVE'),
        (N'RFQ_DEVELOPER', N'RFQ.QUOTE.SYNC'),
        (N'RFQ_DEVELOPER', N'RFQ.QUOTE.RECONCILE'),
        (N'RFQ_DEVELOPER', N'RFQ.SCOPE.BRANCH'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.ASSIGN'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.RESTORE'),
        (N'RFQ_DEVELOPER', N'RFQ.ANALYTICS.READ'),
        (N'RFQ_DEVELOPER', N'RFQ.AUDIT.READ'),
        (N'RFQ_DEVELOPER', N'RFQ.REPORTS.READ'),
        (N'RFQ_DEVELOPER', N'RFQ.PRICING.VIEW'),
        (N'RFQ_DEVELOPER', N'RFQ.SCOPE.ALL'),
        (N'RFQ_DEVELOPER', N'RFQ.ADMIN'),
        (N'RFQ_DEVELOPER', N'RFQ.REVIEWLOG.READ'),
        (N'RFQ_DEVELOPER', N'RFQ.TECHNICAL.READ'),
        (N'RFQ_DEVELOPER', N'RFQ.DRAFT.PURGE');

    INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
    SELECT r.[RoleId], p.[PermissionId], 1
    FROM @Grants g
    JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = UPPER(g.[RoleCode])
    JOIN [identity].[Permissions] p ON UPPER(p.[PermissionCode]) = UPPER(g.[PermissionCode])
    WHERE p.[IsActive] = 1
      AND NOT EXISTS (
          SELECT 1 FROM [identity].[RolePermissions] rp
          WHERE rp.[RoleId] = r.[RoleId] AND rp.[PermissionId] = p.[PermissionId]
      );

    UPDATE rp
    SET rp.[IsActive] = 1
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    JOIN @Grants g
      ON UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
     AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
    WHERE rp.[IsActive] = 0;

    /* Withdraw any other RFQ.* mapping on these four roles. Scoped to RFQ.*:
       an unrelated permission somebody deliberately added in Roles &
       Permissions is theirs to unmake, not this script's. */
    UPDATE rp
    SET rp.[IsActive] = 0
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles)
      AND UPPER(p.[PermissionCode]) LIKE N'RFQ.%'
      AND NOT EXISTS (
          SELECT 1 FROM @Grants g
          WHERE UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
            AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
      );

    /* ------------------------------------------------------------------
       Guard: every grant landed. A role missing a permission is found by a
       salesperson hitting a 403 halfway through a quote.
       ------------------------------------------------------------------ */
    DECLARE @Expected INT = (SELECT COUNT(*) FROM @Grants);
    DECLARE @Granted INT = (
        SELECT COUNT(*)
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        JOIN @Grants g
          ON UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
         AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
        WHERE rp.[IsActive] = 1
    );

    IF @Granted <> @Expected
    BEGIN
        DECLARE @GrantedMessage NVARCHAR(300) =
            CONCAT(N'RFQ roles hold ', @Granted, N' of ', @Expected, N' expected grants. Run seed-rfq-permissions.sql first.');
        THROW 54011, @GrantedMessage, 1;
    END;

    SELECT r.[RoleCode], COUNT(*) AS [ActiveRfqPermissions]
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles)
      AND UPPER(p.[PermissionCode]) LIKE N'RFQ.%'
    GROUP BY r.[RoleCode];

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
