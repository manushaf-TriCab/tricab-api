/*
================================================================================
  DEVELOPMENT seed — Customer Contact roles. DRAFT FOR REVIEW.

  - Idempotent / safe to re-run
  - Run after seed-customer-contact-report-permissions.sql
  - Creates three roles and ASSIGNS THEM TO NOBODY
  - Does not modify EMPLOYEE, ADMINISTRATOR, GUEST, or [cc_report]
  - Does not insert [identity].[UserRoles]
  - WRITTEN, NOT EXECUTED against Azure SQL

  CUSTOMER_CONTACT_USER
      Raise a report, see reports the caller created or is assigned to,
      complete an assigned report, and sign an assigned ICAR report.
  CUSTOMER_CONTACT_COORDINATOR
      See the register and its office fields, assign, close, reopen, and
      request credit or rework. Does not approve.
  CUSTOMER_CONTACT_APPROVER
      Decide a pending credit or rework when the caller is the recorded
      approver and not the person who requested that remedy. Does not
      request remedies. Holding this role with coordinator is allowed.

  Must match TriCab\Core\CustomerContactReport\Module::ROLE_PERMISSIONS.
  This script owns the report-permission mappings of these three roles.
  A later explicit user assignment is a separate [identity].[UserRoles] row.
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
        (N'CUSTOMER_CONTACT_USER', N'Customer Contact User',
         N'Raise a Customer Contact, see reports they created or are assigned, complete an assigned report, and sign an assigned ICAR report.'),
        (N'CUSTOMER_CONTACT_COORDINATOR', N'Customer Contact Coordinator',
         N'See the Customer Contact register, assign and reassign, close, reopen, and request credit or rework. Does not approve.'),
        (N'CUSTOMER_CONTACT_APPROVER', N'Customer Contact Approver',
         N'Decide a pending Customer Contact credit or rework when they are the recorded approver and not the requester. Does not request remedies.');

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
    WHERE e.[IsActive] = 0
       OR e.[RoleName] <> r.[RoleName]
       OR ISNULL(e.[Description], N'') <> r.[Description];

    DECLARE @Grants TABLE (
        [RoleCode]       NVARCHAR(100) NOT NULL,
        [PermissionCode] NVARCHAR(150) NOT NULL,
        PRIMARY KEY ([RoleCode], [PermissionCode])
    );

    INSERT INTO @Grants ([RoleCode], [PermissionCode])
    VALUES
        (N'CUSTOMER_CONTACT_USER', N'CUSTOMER_CONTACT.ACCESS'),
        (N'CUSTOMER_CONTACT_USER', N'CUSTOMER_CONTACT.READ'),
        (N'CUSTOMER_CONTACT_USER', N'CUSTOMER_CONTACT.CREATE'),
        (N'CUSTOMER_CONTACT_USER', N'CUSTOMER_CONTACT.COMPLETE'),
        (N'CUSTOMER_CONTACT_USER', N'CUSTOMER_CONTACT.ICAR_SIGN'),
        (N'CUSTOMER_CONTACT_COORDINATOR', N'CUSTOMER_CONTACT.ACCESS'),
        (N'CUSTOMER_CONTACT_COORDINATOR', N'CUSTOMER_CONTACT.READ'),
        (N'CUSTOMER_CONTACT_COORDINATOR', N'CUSTOMER_CONTACT.READ_ALL'),
        (N'CUSTOMER_CONTACT_COORDINATOR', N'CUSTOMER_CONTACT.ASSIGN'),
        (N'CUSTOMER_CONTACT_COORDINATOR', N'CUSTOMER_CONTACT.CLOSE'),
        (N'CUSTOMER_CONTACT_COORDINATOR', N'CUSTOMER_CONTACT.REOPEN'),
        (N'CUSTOMER_CONTACT_COORDINATOR', N'CUSTOMER_CONTACT.REMEDY'),
        (N'CUSTOMER_CONTACT_APPROVER', N'CUSTOMER_CONTACT.ACCESS'),
        (N'CUSTOMER_CONTACT_APPROVER', N'CUSTOMER_CONTACT.READ'),
        (N'CUSTOMER_CONTACT_APPROVER', N'CUSTOMER_CONTACT.APPROVE');

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

    /* This script owns report permissions on these three roles only. */
    UPDATE rp
    SET rp.[IsActive] = 0
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE rp.[IsActive] = 1
      AND UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles)
      AND LEFT(UPPER(p.[PermissionCode]), LEN(N'CUSTOMER_CONTACT.')) = N'CUSTOMER_CONTACT.'
      AND NOT EXISTS (
          SELECT 1 FROM @Grants g
          WHERE UPPER(g.[RoleCode]) = UPPER(r.[RoleCode])
            AND UPPER(g.[PermissionCode]) = UPPER(p.[PermissionCode])
      );

    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) IN (N'EMPLOYEE', N'ADMINISTRATOR', N'GUEST')
          AND LEFT(UPPER(p.[PermissionCode]), LEN(N'CUSTOMER_CONTACT.')) = N'CUSTOMER_CONTACT.'
    )
    BEGIN
        THROW 54012, N'EMPLOYEE, ADMINISTRATOR or GUEST holds a Customer Contact report permission. Refusing to continue.', 1;
    END;

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
            CONCAT(N'Customer Contact roles hold ', @Granted, N' of ', @Expected, N' expected grants. Run seed-customer-contact-report-permissions.sql first.');
        THROW 54013, @GrantedMessage, 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM @Grants g
        WHERE UPPER(g.[RoleCode]) = N'CUSTOMER_CONTACT_COORDINATOR'
          AND UPPER(g.[PermissionCode]) = N'CUSTOMER_CONTACT.APPROVE'
    )
    OR EXISTS (
        SELECT 1
        FROM @Grants g
        WHERE UPPER(g.[RoleCode]) = N'CUSTOMER_CONTACT_APPROVER'
          AND UPPER(g.[PermissionCode]) = N'CUSTOMER_CONTACT.REMEDY'
    )
    BEGIN
        THROW 54014, N'Customer Contact coordinator and approver permissions must stay on different roles.', 1;
    END;

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
