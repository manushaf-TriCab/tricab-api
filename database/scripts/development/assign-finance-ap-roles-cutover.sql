/*
================================================================================
  PRODUCTION - Tri-AP cut-over: the first Tri-AP is retired and the AP2 app is
  Tri-AP (2 Nov 2026). Moves everyone from the old Tri-AP roles to the AP2 ones.

  STATUS: NOT APPLIED. Applied to tricab-mel by Manusha or Jeff, never by a
  builder. Run it when the cut-over API is deployed (the old /finance/ap API is
  gone from then on).

  - Idempotent / safe to re-run: a missing assignment is inserted, an inactive
    one is made active
  - Touches [identity].[UserRoles] and [identity].[Roles] only. It creates NO
    user and deletes nothing
  - Run after seed-finance-ap2-roles.sql

  Mapping (decided 2 Nov 2026; AP2 has no Preparer: asking for approval is the
  Operator's APPROVALS.REQUEST):
      FINANCE_AP_OPERATOR, FINANCE_AP_PREPARER  ->  FINANCE_AP2_OPERATOR
      FINANCE_AP_APPROVER                       ->  FINANCE_AP2_APPROVER
      FINANCE_AP_ADMIN                          ->  FINANCE_AP2_ADMIN
      nickh@tricab.com                          ->  FINANCE_AP2_BANK_VERIFIER
  The holders are read from the old roles' active assignments, so nobody is
  typed in here twice.

  Then every assignment of the four old roles is made inactive, and the old roles
  themselves (they open nothing any more). Their rows stay for the record.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Map TABLE ([OldRole] NVARCHAR(100) NOT NULL, [NewRole] NVARCHAR(100) NOT NULL, PRIMARY KEY ([OldRole], [NewRole]));
    INSERT INTO @Map ([OldRole], [NewRole])
    VALUES
        (N'FINANCE_AP_OPERATOR', N'FINANCE_AP2_OPERATOR'),
        (N'FINANCE_AP_PREPARER', N'FINANCE_AP2_OPERATOR'),
        (N'FINANCE_AP_APPROVER', N'FINANCE_AP2_APPROVER'),
        (N'FINANCE_AP_ADMIN',    N'FINANCE_AP2_ADMIN');

    DECLARE @Named TABLE ([Email] NVARCHAR(320) NOT NULL, [RoleCode] NVARCHAR(100) NOT NULL, PRIMARY KEY ([Email], [RoleCode]));
    INSERT INTO @Named ([Email], [RoleCode])
    VALUES (N'nickh@tricab.com', N'FINANCE_AP2_BANK_VERIFIER');

    IF EXISTS (
        SELECT [NewRole] AS [RoleCode] FROM @Map UNION SELECT [RoleCode] FROM @Named
        EXCEPT
        SELECT UPPER([RoleCode]) FROM [identity].[Roles] WHERE [IsActive] = 1
    )
    BEGIN
        THROW 54221, N'A Tri-AP (AP2) role does not exist. Run seed-finance-ap2-roles.sql first.', 1;
    END;

    /* Who gets what: the old roles' active holders, mapped, and the named people. */
    DECLARE @Grant TABLE ([UserId] BIGINT NOT NULL, [RoleId] BIGINT NOT NULL, [Source] NVARCHAR(100) NOT NULL, PRIMARY KEY ([UserId], [RoleId]));
    INSERT INTO @Grant ([UserId], [RoleId], [Source])
    SELECT DISTINCT ur.[UserId], nr.[RoleId], MIN(m.[OldRole]) OVER (PARTITION BY ur.[UserId], nr.[RoleId])
    FROM [identity].[UserRoles] ur
    JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
    JOIN @Map m ON m.[OldRole] = UPPER(r.[RoleCode])
    JOIN [identity].[Roles] nr ON UPPER(nr.[RoleCode]) = m.[NewRole]
    WHERE ur.[IsActive] = 1;

    INSERT INTO @Grant ([UserId], [RoleId], [Source])
    SELECT u.[UserId], r.[RoleId], N'named'
    FROM @Named n
    JOIN [identity].[Users] u ON u.[NormalizedEmailAddress] = LOWER(n.[Email])
    JOIN [identity].[Roles] r ON UPPER(r.[RoleCode]) = n.[RoleCode]
    WHERE NOT EXISTS (SELECT 1 FROM @Grant g WHERE g.[UserId] = u.[UserId] AND g.[RoleId] = r.[RoleId]);

    INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [IsActive])
    SELECT g.[UserId], g.[RoleId], 1
    FROM @Grant g
    WHERE NOT EXISTS (SELECT 1 FROM [identity].[UserRoles] ur WHERE ur.[UserId] = g.[UserId] AND ur.[RoleId] = g.[RoleId]);

    UPDATE ur
    SET ur.[IsActive] = 1
    FROM [identity].[UserRoles] ur
    JOIN @Grant g ON g.[UserId] = ur.[UserId] AND g.[RoleId] = ur.[RoleId]
    WHERE ur.[IsActive] = 0;

    /* The old roles open nothing now: their assignments and the roles go inactive. */
    UPDATE ur
    SET ur.[IsActive] = 0
    FROM [identity].[UserRoles] ur
    JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
    WHERE UPPER(r.[RoleCode]) IN (SELECT [OldRole] FROM @Map) AND ur.[IsActive] = 1;

    UPDATE [identity].[Roles]
    SET [IsActive] = 0
    WHERE UPPER([RoleCode]) IN (SELECT [OldRole] FROM @Map) AND [IsActive] = 1;

    /* What is in place now. */
    SELECT u.[DisplayName], r.[RoleCode], CASE WHEN ur.[IsActive] = 1 THEN N'active' ELSE N'inactive' END AS [State]
    FROM [identity].[UserRoles] ur
    JOIN [identity].[Users] u ON u.[UserId] = ur.[UserId]
    JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
    WHERE UPPER(r.[RoleCode]) LIKE N'FINANCE[_]AP%'
    ORDER BY r.[RoleCode], u.[DisplayName];

    SELECT n.[Email], N'NO PLATFORM USER - nothing assigned' AS [Result]
    FROM @Named n
    WHERE NOT EXISTS (SELECT 1 FROM [identity].[Users] u WHERE u.[NormalizedEmailAddress] = LOWER(n.[Email]));

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
