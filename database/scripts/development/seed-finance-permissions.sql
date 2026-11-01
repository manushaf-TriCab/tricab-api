/*
================================================================================
  DEVELOPMENT seed - Finance module permissions.

  - Idempotent / safe to re-run
  - Creates no users, no roles and no finance data
  - Does not modify permissions outside the FINANCE.* family
  - WRITTEN, NOT EXECUTED against any shared database (plan 9, 6 Oct 2026).
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.

  Must match TriCab\Core\Finance\Module::PERSON_PERMISSIONS;
  tests/Finance/FinanceSeedScriptTest.php fails if they drift.

  Phase 1 seeds the shared permissions and one access permission per app, and
  nothing app-specific (decision D4). Each app's own permissions arrive in its
  phase. The whole map from the finance portal's vocabulary is in
  docs/finance/permissions.md.

  Role mapping:

    ADMINISTRATOR   FINANCE.ADMIN only. Finance administration, which opens no
                    app and reads no record (decision D3). The TriExpense,
                    Onboarding and RFQ pattern: a platform administrator can
                    grant finance access without being inside the records.
    EMPLOYEE        NOTHING. Guarded below.
    GUEST           NOTHING, ever. Guarded below.

  The FINANCE_ADMIN role is created by seed-finance-roles.sql, assigned to
  nobody. Run this script first.
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
        (N'FINANCE.ACCESS',          N'Access Finance',
         N'Reach the finance launcher and see one''s own finance access. Opens no app and grants no record.'),
        (N'FINANCE.TASK.ACCESS',     N'Access Tri-Task',
         N'Reach Tri-Task. Grants no record.'),
        (N'FINANCE.CREDIT.ACCESS',   N'Access Tri-Credit Notes',
         N'Reach Tri-Credit Notes. Grants no record.'),
        (N'FINANCE.COLLECT.ACCESS',  N'Access Argo Collect',
         N'Reach Argo Collect. Grants no record.'),
        (N'FINANCE.AR.ACCESS',       N'Access Tri-AR',
         N'Reach Tri-AR. Grants no record.'),
        (N'FINANCE.AP2.ACCESS',      N'Access Tri-AP',
         N'Reach Tri-AP. Grants no record.'),
        (N'FINANCE.ADMIN',           N'Administer Finance',
         N'Administer the finance module: the mailbox registry, and later the grants and reference lists. Opens no app and reads no record.');

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
       The platform-role mappings this script owns.
       ------------------------------------------------------------------ */
    DECLARE @Mappings TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );
    INSERT INTO @Mappings ([RoleCode], [PermissionCode])
    VALUES
        (N'ADMINISTRATOR', N'FINANCE.ADMIN');

    INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
    SELECT r.[RoleId], p.[PermissionId], 1
    FROM @Mappings m
    JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = m.[RoleCode]
    JOIN [identity].[Permissions] p ON UPPER(p.[PermissionCode]) = m.[PermissionCode]
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = r.[RoleId] AND rp.[PermissionId] = p.[PermissionId]
    );

    UPDATE rp
    SET rp.[IsActive] = 1
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    JOIN @Mappings m ON m.[RoleCode] = UPPER(r.[RoleCode]) AND m.[PermissionCode] = UPPER(p.[PermissionCode])
    WHERE rp.[IsActive] = 0;

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

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [FinancePermissions],
        (SELECT COUNT(*)
           FROM [identity].[RolePermissions] rp
           JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
          WHERE p.[ModuleCode] = @ModuleCode AND rp.[IsActive] = 1) AS [ActiveFinanceMappings];

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
