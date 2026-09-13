/*
  LEGACY_DO_NOT_USE — historical reference only.
  DO NOT RUN. Grants IsFinance/IsExpenseAdmin to all ADMINISTRATOR users.
  TriExpense Finance authority must come from TRIEXPENSE_FINANCE role only.
*/

/*
================================================================================
  LOCAL / DEVELOPMENT ONLY — do not deploy to production.

  Grants full TriExpense capability to every active user who holds the
  ADMINISTRATOR role:

    - Ensures TRIEXPENSE.ACCESS is mapped to ADMINISTRATOR
    - Upserts [expense].[ExpenseProfiles] with:
        IsActive = 1
        IsFinance = 1
        IsExpenseAdmin = 1

  Prerequisites:
    - identity schema + ADMINISTRATOR role
    - expense.ExpenseProfiles table (create-expense-schema.sql)
    - Prefer running seed-admin-business-module-permissions.sql first

  Safe to re-run. Does not touch non-ADMINISTRATOR users.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @PermissionCode NVARCHAR(150) = N'TRIEXPENSE.ACCESS';
    DECLARE @PermissionId BIGINT = NULL;
    DECLARE @AdministratorRoleId BIGINT = NULL;
    DECLARE @PermissionMapped INT = 0;
    DECLARE @ProfilesInserted INT = 0;
    DECLARE @ProfilesUpdated INT = 0;

    SELECT TOP (1) @PermissionId = p.[PermissionId]
    FROM [identity].[Permissions] p
    WHERE UPPER(p.[PermissionCode]) = UPPER(@PermissionCode)
      AND p.[IsActive] = 1
    ORDER BY p.[PermissionId];

    IF @PermissionId IS NULL
    BEGIN
        INSERT INTO [identity].[Permissions] (
            [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
        )
        VALUES (
            @PermissionCode,
            N'Access Tri Expense',
            N'Open Tri Expense from Admin.',
            N'triexpense',
            1
        );
        SET @PermissionId = SCOPE_IDENTITY();
    END;

    SELECT TOP (1) @AdministratorRoleId = r.[RoleId]
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
      AND r.[IsActive] = 1
    ORDER BY r.[RoleId];

    IF @AdministratorRoleId IS NULL
    BEGIN
        THROW 52201, N'ADMINISTRATOR role not found or inactive.', 1;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        WHERE rp.[RoleId] = @AdministratorRoleId
          AND rp.[PermissionId] = @PermissionId
          AND rp.[IsActive] = 1
    )
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM [identity].[RolePermissions] rp
            WHERE rp.[RoleId] = @AdministratorRoleId
              AND rp.[PermissionId] = @PermissionId
        )
        BEGIN
            UPDATE [identity].[RolePermissions]
            SET [IsActive] = 1
            WHERE [RoleId] = @AdministratorRoleId
              AND [PermissionId] = @PermissionId;
        END
        ELSE
        BEGIN
            INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
            VALUES (@AdministratorRoleId, @PermissionId, 1);
        END;
        SET @PermissionMapped = 1;
    END;

    IF OBJECT_ID(N'[expense].[ExpenseProfiles]', N'U') IS NULL
    BEGIN
        THROW 52202, N'expense.ExpenseProfiles is missing. Run create-expense-schema.sql first.', 1;
    END;

    DECLARE @AdminUsers TABLE (
        [UserId] BIGINT NOT NULL PRIMARY KEY,
        [EmailAddress] NVARCHAR(320) NOT NULL,
        [LegacyLogin] NVARCHAR(190) NOT NULL
    );

    INSERT INTO @AdminUsers ([UserId], [EmailAddress], [LegacyLogin])
    SELECT
        u.[UserId],
        u.[EmailAddress],
        LEFT(LOWER(LTRIM(RTRIM(u.[EmailAddress]))), 190)
    FROM [identity].[Users] u
    INNER JOIN [identity].[UserStatuses] s
        ON s.[UserStatusId] = u.[UserStatusId]
    INNER JOIN [identity].[UserRoles] ur
        ON ur.[UserId] = u.[UserId]
       AND ur.[IsActive] = 1
    WHERE ur.[RoleId] = @AdministratorRoleId
      AND s.[StatusCode] = N'active'
      AND s.[AllowsLogin] = 1
      AND s.[IsActive] = 1;

    /* Prefer updating existing profiles first (including inactive ones). */
    UPDATE p
    SET
        p.[IsActive] = 1,
        p.[IsFinance] = 1,
        p.[IsExpenseAdmin] = 1,
        p.[LegacyEmail] = a.[EmailAddress],
        p.[UpdatedAtUtc] = SYSUTCDATETIME()
    FROM [expense].[ExpenseProfiles] p
    INNER JOIN @AdminUsers a ON a.[UserId] = p.[UserId];

    SET @ProfilesUpdated = @@ROWCOUNT;

    INSERT INTO [expense].[ExpenseProfiles] (
        [UserId],
        [LegacyLogin],
        [LegacyEmail],
        [IsActive],
        [IsFinance],
        [IsExpenseAdmin],
        [ManagerUserId],
        [LegacyManagerLogin]
    )
    SELECT
        a.[UserId],
        a.[LegacyLogin],
        a.[EmailAddress],
        1,
        1,
        1,
        NULL,
        NULL
    FROM @AdminUsers a
    WHERE NOT EXISTS (
        SELECT 1
        FROM [expense].[ExpenseProfiles] p
        WHERE p.[UserId] = a.[UserId]
    )
      AND NOT EXISTS (
        SELECT 1
        FROM [expense].[ExpenseProfiles] p
        WHERE LOWER(p.[LegacyLogin]) = LOWER(a.[LegacyLogin])
      );

    SET @ProfilesInserted = @@ROWCOUNT;

    /* If insert skipped due to LegacyLogin collision, force-update that row's flags when it is the same user. */
    UPDATE p
    SET
        p.[IsActive] = 1,
        p.[IsFinance] = 1,
        p.[IsExpenseAdmin] = 1,
        p.[UpdatedAtUtc] = SYSUTCDATETIME()
    FROM [expense].[ExpenseProfiles] p
    INNER JOIN @AdminUsers a
        ON LOWER(p.[LegacyLogin]) = LOWER(a.[LegacyLogin])
       AND p.[UserId] = a.[UserId]
    WHERE p.[IsFinance] = 0
       OR p.[IsExpenseAdmin] = 0
       OR p.[IsActive] = 0;

    COMMIT TRANSACTION;

    SELECT
        @PermissionMapped AS PermissionMapped,
        @ProfilesInserted AS ProfilesInserted,
        @ProfilesUpdated AS ProfilesUpdated,
        (SELECT COUNT(*) FROM @AdminUsers) AS AdministratorUsersTargeted;

    SELECT
        u.[UserId],
        u.[EmailAddress],
        p.[IsActive],
        p.[IsFinance],
        p.[IsExpenseAdmin]
    FROM @AdminUsers a
    INNER JOIN [identity].[Users] u ON u.[UserId] = a.[UserId]
    LEFT JOIN [expense].[ExpenseProfiles] p ON p.[UserId] = a.[UserId]
    ORDER BY u.[UserId];
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
