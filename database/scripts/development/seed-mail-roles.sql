/*
================================================================================
  DEVELOPMENT seed - mail review grants on the RFQ roles (inbox plan I2, 2.1).

  - Idempotent / safe to re-run
  - Creates NO roles and assigns nobody: it grants MAIL.ACCESS and MAIL.REVIEW to
    roles that already exist
  - Does not modify EMPLOYEE, GUEST or ADMINISTRATOR (seed-mail-permissions.sql
    does ADMINISTRATOR)
  - WRITTEN, NOT EXECUTED against any shared database (18 Oct 2026)
  - Run after seed-mail-permissions.sql and seed-rfq-roles.sql

  Decision 0.4 (18 Oct): everyone with RFQ_SALES holds both permissions, and every
  reviewer may mark labels. RFQ_ADMIN and RFQ_DEVELOPER hold RFQ_SALES' rights and
  more, so they get the same two; otherwise promoting someone from sales to admin
  would take their inbox away.

  Must match TriCab\Core\Mail\Module::ROLE_PERMISSIONS;
  tests/Mail/MailSeedScriptTest.php fails if they drift. This script owns the MAIL.*
  mappings of these three roles: any other MAIL.* on them is deactivated, never
  deleted.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'RFQ_SALES', N'MAIL.ACCESS'),
        (N'RFQ_SALES', N'MAIL.REVIEW'),
        (N'RFQ_ADMIN', N'MAIL.ACCESS'),
        (N'RFQ_ADMIN', N'MAIL.REVIEW'),
        (N'RFQ_DEVELOPER', N'MAIL.ACCESS'),
        (N'RFQ_DEVELOPER', N'MAIL.REVIEW');

    /* The roles come from seed-rfq-roles.sql. A missing one is a faulty order, not something to create here. */
    IF EXISTS (
        SELECT 1 FROM @Grants g
        WHERE NOT EXISTS (
            SELECT 1 FROM [identity].[Roles] r
            WHERE UPPER(r.[RoleCode]) = UPPER(g.[RoleCode]) AND r.[IsActive] = 1
        )
    )
        THROW 54211, N'An RFQ role is missing. Run seed-rfq-roles.sql first.', 1;

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

    /* Withdraw any other MAIL.* mapping on these roles. Scoped to MAIL.*: an unrelated permission
       somebody deliberately added in Roles & Permissions is theirs to unmake, not this script's. */
    UPDATE rp
    SET rp.[IsActive] = 0
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Grants)
      AND UPPER(p.[PermissionCode]) LIKE N'MAIL.%'
      AND NOT EXISTS (
          SELECT 1 FROM @Grants g
          WHERE UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
            AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
      );

    /* Guard: every grant landed. */
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
            CONCAT(N'Mail roles hold ', @Granted, N' of ', @Expected, N' expected grants. Run seed-mail-permissions.sql first.');
        THROW 54212, @GrantedMessage, 1;
    END;

    SELECT r.[RoleCode], COUNT(*) AS [ActiveMailPermissions]
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Grants)
      AND UPPER(p.[PermissionCode]) LIKE N'MAIL.%'
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
