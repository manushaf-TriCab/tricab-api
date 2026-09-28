/*
================================================================================
  DEVELOPMENT seed — RFQ module permissions.

  - Idempotent / safe to re-run
  - Creates no users, no roles and no RFQ data
  - Does not modify permissions outside the RFQ.* family
  - WRITTEN, NOT EXECUTED against any shared database (plan 1.3, 28 Sep 2026)

  Must match TriCab\Core\Rfq\Module::PERSON_PERMISSIONS and
  INTEGRATION_PERMISSIONS; tests/Rfq/RfqSeedScriptTest.php fails if they drift.

  Role mapping, and why there is almost none:

    ADMINISTRATOR   RFQ.ADMIN only. Module administration, which reads no RFQ.
                    The TriExpense and Onboarding pattern: a platform
                    administrator can grant RFQ access without being inside
                    every customer's quote. The source's WordPress
                    `manage_options` backstop — every WordPress admin held every
                    RFQ permission — is not carried.
    EMPLOYEE        NOTHING. The source makes every signed-in staff member a
                    salesperson (RFQ_DEFAULT_ROLE = sales). The platform plan
                    forbids that implicit grant; who gets RFQ access for the
                    pilot is owner question Q8.
    GUEST           NOTHING, ever. Guarded below.

  The four RFQ roles are created by seed-rfq-roles.sql, assigned to nobody.

  RFQ.MAIL.INGEST is for the mailbox worker's service identity (Phase 6) and is
  granted to no role here.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'rfq';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'RFQ.ACCESS',            N'Access RFQs',
         N'Reach the RFQ module and see one''s own RFQ access. Grants no RFQ.'),
        (N'RFQ.DRAFT.READ',        N'Read RFQs',
         N'List and open RFQs within the holder''s scope.'),
        (N'RFQ.DRAFT.CREATE',      N'Create RFQs',
         N'Create an RFQ from pasted text, files or images. The creator owns it.'),
        (N'RFQ.DRAFT.EDIT',        N'Edit RFQs',
         N'Save a revision and resolve reader proposals, on RFQs assigned to the holder, or any in scope for a manager.'),
        (N'RFQ.DRAFT.CLAIM',       N'Claim RFQs',
         N'Take an unassigned RFQ in scope.'),
        (N'RFQ.DRAFT.ASSIGN',      N'Assign RFQs',
         N'Assign and reassign, bulk actions, priority and due date. Also lets the holder work RFQs in scope that are not assigned to them.'),
        (N'RFQ.DRAFT.WORKFLOW',    N'Change RFQ workflow',
         N'Start, block and return RFQs to the queue, within the workflow rules.'),
        (N'RFQ.DRAFT.COMMENT',     N'Comment on RFQs',
         N'Add comments to RFQs in scope.'),
        (N'RFQ.DRAFT.ARCHIVE',     N'Archive RFQs',
         N'Archive an RFQ, with a reason.'),
        (N'RFQ.DRAFT.RESTORE',     N'Restore RFQs',
         N'Restore an archived RFQ.'),
        (N'RFQ.DRAFT.PURGE',       N'Permanently delete RFQs',
         N'Permanent deletion. No route uses it until a retention policy is defined.'),
        (N'RFQ.QUOTE.SYNC',        N'Send RFQ quotes to 4js',
         N'Hand a saved RFQ revision to 4js through a durable, deduplicated attempt.'),
        (N'RFQ.QUOTE.RECONCILE',   N'Reconcile uncertain 4js quotes',
         N'Resolve an interrupted sync after checking 4js: adopt the quote found, or confirm there is none.'),
        (N'RFQ.PRICING.VIEW',      N'See RFQ cost and margin',
         N'See cost, margin and sell prices on RFQs. Whether this hides or withholds is an open owner decision.'),
        (N'RFQ.ANALYTICS.READ',    N'Read RFQ analytics',
         N'Scoped RFQ statistics.'),
        (N'RFQ.REPORTS.READ',      N'Read RFQ reports',
         N'Review metrics and activity summaries across every branch.'),
        (N'RFQ.AUDIT.READ',        N'Read RFQ activity',
         N'The RFQ activity log, within scope.'),
        (N'RFQ.REVIEWLOG.READ',    N'Read RFQ review logs',
         N'Raw review sessions and row edit history, including customer text.'),
        (N'RFQ.TECHNICAL.READ',    N'Read RFQ diagnostics',
         N'Parser, reader, catalogue and job diagnostics.'),
        (N'RFQ.SCOPE.BRANCH',      N'RFQ scope: branch',
         N'Widen RFQ reads from the holder''s own to their branch''s.'),
        (N'RFQ.SCOPE.ALL',         N'RFQ scope: all branches',
         N'Widen RFQ reads to every branch.'),
        (N'RFQ.ADMIN',             N'Administer RFQs',
         N'Administer the RFQ module. Reads no RFQ.'),
        (N'RFQ.MAIL.INGEST',       N'RFQ mailbox intake',
         N'For the mailbox worker''s service identity only. Never granted to a person''s role.');

    /* ------------------------------------------------------------------
       Permissions: insert what is missing, leave existing rows alone
       ------------------------------------------------------------------ */
    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    /* ------------------------------------------------------------------
       The one platform-role mapping this script owns.
       ------------------------------------------------------------------ */
    INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
    SELECT r.[RoleId], p.[PermissionId], 1
    FROM [identity].[Roles] r
    JOIN [identity].[Permissions] p ON UPPER(p.[PermissionCode]) = N'RFQ.ADMIN'
    WHERE UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
      AND NOT EXISTS (
          SELECT 1 FROM [identity].[RolePermissions] rp
          WHERE rp.[RoleId] = r.[RoleId] AND rp.[PermissionId] = p.[PermissionId]
      );

    UPDATE rp
    SET rp.[IsActive] = 1
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
      AND UPPER(p.[PermissionCode]) = N'RFQ.ADMIN'
      AND rp.[IsActive] = 0;

    /* ------------------------------------------------------------------
       Guards. Each would be a serious fault, so each fails the script.
       ------------------------------------------------------------------ */

    /* No implicit grant to all employees, and nothing to guests (plan). */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) IN (N'EMPLOYEE', N'GUEST')
          AND UPPER(p.[PermissionCode]) LIKE N'RFQ.%'
    )
    BEGIN
        THROW 54001, N'An RFQ permission is mapped to EMPLOYEE or GUEST. RFQ access is granted explicitly, never to everybody. Refusing to continue.', 1;
    END;

    /* The platform administrator administers the module and reads no RFQ. */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
          AND UPPER(p.[PermissionCode]) LIKE N'RFQ.%'
          AND UPPER(p.[PermissionCode]) <> N'RFQ.ADMIN'
    )
    BEGIN
        THROW 54002, N'ADMINISTRATOR holds an RFQ permission other than RFQ.ADMIN. Refusing to continue.', 1;
    END;

    /* The mail-ingest permission belongs to a service identity, not a role. */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(p.[PermissionCode]) = N'RFQ.MAIL.INGEST'
    )
    BEGIN
        THROW 54003, N'RFQ.MAIL.INGEST is mapped to a role. It is for the mailbox worker''s service identity only. Refusing to continue.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [RfqPermissions],
        (SELECT COUNT(*)
           FROM [identity].[RolePermissions] rp
           JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
          WHERE p.[ModuleCode] = @ModuleCode AND rp.[IsActive] = 1) AS [ActiveRfqMappings];

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
