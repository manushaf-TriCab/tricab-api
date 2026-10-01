/*
================================================================================
  Rollback for the Customer Contact authorization seeds only.

  Removes:
    - user assignments to the three Customer Contact roles
    - role-permission rows for those roles and for the eleven report permissions
    - the eleven CUSTOMER_CONTACT.* permission definitions
    - the three Customer Contact roles

  Does not touch:
    - CUSTOMER.CONTACT.* or [customer].[CustomerContacts]
    - EMPLOYEE, ADMINISTRATOR, GUEST, or any other role
    - [cc_report]

  WRITTEN, NOT EXECUTED against Azure SQL.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Roles TABLE ([RoleCode] NVARCHAR(100) NOT NULL PRIMARY KEY);
    INSERT INTO @Roles ([RoleCode])
    VALUES
        (N'CUSTOMER_CONTACT_USER'),
        (N'CUSTOMER_CONTACT_COORDINATOR'),
        (N'CUSTOMER_CONTACT_APPROVER');

    DECLARE @Permissions TABLE ([PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY);
    INSERT INTO @Permissions ([PermissionCode])
    VALUES
        (N'CUSTOMER_CONTACT.ACCESS'),
        (N'CUSTOMER_CONTACT.READ'),
        (N'CUSTOMER_CONTACT.READ_ALL'),
        (N'CUSTOMER_CONTACT.CREATE'),
        (N'CUSTOMER_CONTACT.ASSIGN'),
        (N'CUSTOMER_CONTACT.COMPLETE'),
        (N'CUSTOMER_CONTACT.CLOSE'),
        (N'CUSTOMER_CONTACT.REOPEN'),
        (N'CUSTOMER_CONTACT.REMEDY'),
        (N'CUSTOMER_CONTACT.APPROVE'),
        (N'CUSTOMER_CONTACT.ICAR_SIGN');

    DELETE ur
    FROM [identity].[UserRoles] ur
    JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId]
    WHERE UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles);

    DELETE rp
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles)
       OR UPPER(p.[PermissionCode]) IN (SELECT UPPER([PermissionCode]) FROM @Permissions);

    DELETE p
    FROM [identity].[Permissions] p
    WHERE UPPER(p.[PermissionCode]) IN (SELECT UPPER([PermissionCode]) FROM @Permissions);

    DELETE r
    FROM [identity].[Roles] r
    WHERE UPPER(r.[RoleCode]) IN (SELECT UPPER([RoleCode]) FROM @Roles);

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
