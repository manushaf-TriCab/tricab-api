/*
================================================================================
  DEVELOPMENT seed — Customer Contact report permissions.

  This is the complaint register, not the customer-person feature.
  It does not touch CUSTOMER.CONTACT.* or [customer].[CustomerContacts].

  - Idempotent / safe to re-run
  - Creates no users, no roles and no Customer Contact rows
  - Does not modify permissions outside the CUSTOMER_CONTACT.* family
  - WRITTEN, NOT EXECUTED against Azure SQL in Phase D

  Must match TriCab\Core\CustomerContactReport\Module.
  tests/CustomerContactReport/CustomerContactReportSeedScriptTest.php fails
  if they drift.

  This script creates permission definitions only. It maps them to nobody.
  Roles are seed-customer-contact-roles.sql, assigned to nobody.
  EMPLOYEE, ADMINISTRATOR and GUEST stay unchanged. A report permission on
  any of those platform roles fails this script.
  ayanj@tricab.com is not a hardcoded authorisation.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'customer_contact';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'CUSTOMER_CONTACT.ACCESS',   N'Access Customer Contacts',
         N'Reach the Customer Contact report module. Grants no report by itself.'),
        (N'CUSTOMER_CONTACT.READ',     N'Read own Customer Contacts',
         N'List and open Customer Contact reports the caller created.'),
        (N'CUSTOMER_CONTACT.READ_ALL', N'Read every Customer Contact',
         N'See the complaint register, including office and ICAR fields. Not granted to employees by this script.'),
        (N'CUSTOMER_CONTACT.CREATE',   N'Create Customer Contacts',
         N'Create a submitted Customer Contact report. Does not allocate a CC number.'),
        (N'CUSTOMER_CONTACT.ASSIGN',   N'Assign Customer Contacts',
         N'Assign or reassign a Customer Contact report and allocate its CC number on first assignment. Not granted to employees by this script.'),
        (N'CUSTOMER_CONTACT.COMPLETE', N'Complete Customer Contacts',
         N'Record assignee findings and mark an assigned report complete. The caller must also be the current assignee.'),
        (N'CUSTOMER_CONTACT.CLOSE',    N'Close Customer Contacts',
         N'Close a completed Customer Contact report. Not granted to employees by this script.'),
        (N'CUSTOMER_CONTACT.REOPEN',   N'Reopen Customer Contacts',
         N'Reopen a completed or closed Customer Contact report back to its assignee. Not granted to employees by this script.'),
        (N'CUSTOMER_CONTACT.REMEDY',   N'Request Customer Contact remedies',
         N'Request credit or rework approval. Not granted to employees by this script.'),
        (N'CUSTOMER_CONTACT.APPROVE',  N'Decide Customer Contact approvals',
         N'Decide a pending credit or rework approval when the caller is the recorded approver. Not granted to employees by this script.'),
        (N'CUSTOMER_CONTACT.ICAR_SIGN', N'Sign ICAR Customer Contacts',
         N'Record the ICAR signature when the caller is the current assignee of an assigned ICAR report.');

    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) IN (N'EMPLOYEE', N'ADMINISTRATOR', N'GUEST')
          -- Exact prefix. An unescaped underscore in LIKE is one character in
          -- SQL Server and would also match the person-contact permission codes.
          AND LEFT(UPPER(p.[PermissionCode]), LEN(N'CUSTOMER_CONTACT.')) = N'CUSTOMER_CONTACT.'
    )
    BEGIN
        THROW 54011, N'EMPLOYEE, ADMINISTRATOR or GUEST holds a Customer Contact report permission. Refusing to continue.', 1;
    END;

    SELECT
        (SELECT COUNT(*) FROM [identity].[Permissions] WHERE [ModuleCode] = @ModuleCode) AS [CustomerContactPermissions],
        (SELECT COUNT(*)
           FROM [identity].[RolePermissions] rp
           JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
          WHERE p.[ModuleCode] = @ModuleCode AND rp.[IsActive] = 1) AS [ActiveCustomerContactMappings];

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
