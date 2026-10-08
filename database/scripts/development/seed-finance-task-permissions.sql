/*
================================================================================
  DEVELOPMENT seed - Tri-Task permissions (finance Phase 2, task T2.1).

  - Idempotent / safe to re-run
  - Creates no users, no roles and no finance data
  - Touches only FINANCE.TASK.* permissions
  - WRITTEN, NOT EXECUTED against any shared database (7 Oct 2026).
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.
  - Run after seed-finance-permissions.sql (which seeds FINANCE.TASK.ACCESS).

  Must match TriCab\Core\Finance\Module::TASK_PERMISSIONS;
  tests/Finance/Task/FinanceTaskSeedScriptTest.php fails if they drift. The map
  from the portal's task.* strings is docs/finance/permissions.md section 4.

  Eleven permissions. `task.users.manage` and `task.roles.assign_admin` are not
  carried (permissions.md section 3). The platform roles are not changed here:
  ADMINISTRATOR holds FINANCE.ADMIN only, EMPLOYEE and GUEST nothing. The three
  Tri-Task roles are created by seed-finance-task-roles.sql.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'finance';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'FINANCE.TASK.TASKS.READ', N'Read Tri-Task tasks',
         N'See the tasks one prepares or reviews, the projects, templates, statement areas and entities, and the team.'),
        (N'FINANCE.TASK.TASKS.CREATE', N'Create Tri-Task tasks',
         N'Create a task.'),
        (N'FINANCE.TASK.TASKS.UPDATE_OWN', N'Update own Tri-Task tasks',
         N'Change a task one prepares or reviews.'),
        (N'FINANCE.TASK.TASKS.DELETE_OWN', N'Delete own Tri-Task tasks',
         N'Move a task one prepares or reviews to the Bin, and restore it.'),
        (N'FINANCE.TASK.TASKS.READ_ALL', N'Read all Tri-Task tasks',
         N'See every task, not only one''s own.'),
        (N'FINANCE.TASK.TASKS.UPDATE', N'Update any Tri-Task task',
         N'Change any task.'),
        (N'FINANCE.TASK.TASKS.DELETE', N'Delete any Tri-Task task',
         N'Move any task to the Bin, restore it, and delete it for good from the Bin.'),
        (N'FINANCE.TASK.SETUP.MANAGE', N'Manage Tri-Task setup',
         N'Manage projects, templates, statement areas and entities, run the import, and set job titles.'),
        (N'FINANCE.TASK.MAIL.SEND', N'Send Tri-Task mail',
         N'Send task emails and reminders, and use the AI email drafts.'),
        (N'FINANCE.TASK.ANALYTICS.READ', N'Read Tri-Task analytics',
         N'Read the analytics and the status history of every task.'),
        (N'FINANCE.TASK.SETTINGS.MANAGE', N'Manage Tri-Task settings',
         N'Manage the daily summary recipients.');

    /* Insert what is missing, leave existing rows alone. */
    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    /* ------------------------------------------------------------------
       Guards. Each would be a serious fault, so each fails the script.
       ------------------------------------------------------------------ */

    /* Nobody is a finance user by default: employees and guests hold nothing. */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(p.[PermissionCode]) LIKE N'FINANCE.%'
          AND UPPER(r.[RoleCode]) IN (N'GUEST', N'EMPLOYEE')
    )
    BEGIN
        THROW 54101, N'EMPLOYEE or GUEST holds a finance permission. Finance access is granted through the finance roles only. Refusing to continue.', 1;
    END;

    /* The platform administrator administers finance and opens no app. */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
          AND UPPER(p.[PermissionCode]) LIKE N'FINANCE.%'
          AND UPPER(p.[PermissionCode]) <> N'FINANCE.ADMIN'
    )
    BEGIN
        THROW 54102, N'ADMINISTRATOR holds a finance permission other than FINANCE.ADMIN. Refusing to continue.', 1;
    END;

    /* Every permission landed. */
    IF (SELECT COUNT(*) FROM @Permissions p
         JOIN [identity].[Permissions] e ON UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
         WHERE e.[IsActive] = 1) <> (SELECT COUNT(*) FROM @Permissions)
    BEGIN
        THROW 54112, N'A Tri-Task permission is missing or inactive after the seed. Refusing to continue.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [FinancePermissions],
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode
            AND UPPER([PermissionCode]) LIKE N'FINANCE.TASK.%') AS [TaskPermissionsIncludingAccess];

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
