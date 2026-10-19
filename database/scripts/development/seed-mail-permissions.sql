/*
================================================================================
  DEVELOPMENT seed - mail review permissions (inbox integration plan I2, 2.1).

  - Idempotent / safe to re-run
  - Creates no users, no roles and no mail data
  - Touches only MAIL.* permissions
  - WRITTEN, NOT EXECUTED against any shared database (18 Oct 2026).
    Run against the local Docker database only; applying it to tricab-mel is
    Manusha's or Jeff's.

  Must match TriCab\Core\Mail\Module::PERMISSIONS;
  tests/Mail/MailSeedScriptTest.php fails if they drift.

  Two permissions (decision 0.4, 18 Oct):

    MAIL.ACCESS   open the inbox: see every thread and message, and mark one read
    MAIL.REVIEW   mark a label right or wrong, create an RFQ draft, ignore

  ADMINISTRATOR holds both, as it does for the Tel Directory and the admin
  business modules. (RFQ and Finance give ADMINISTRATOR only their module-admin
  code; mail has no such code, and the decision was "admin too".) EMPLOYEE and
  GUEST hold neither. RFQ_SALES, RFQ_ADMIN and RFQ_DEVELOPER get them in
  seed-mail-roles.sql.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @ModuleCode NVARCHAR(100) = N'mail';

    DECLARE @Permissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description]    NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Permissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'MAIL.ACCESS', N'Open the mail inbox',
         N'See every conversation in the shared mailboxes, with the classifier''s label, and open messages and attachments. Mark a conversation read or unread.'),
        (N'MAIL.REVIEW', N'Review mail',
         N'Mark the classifier''s label right or wrong, create an RFQ draft from a conversation, or ignore it.');

    /* Insert what is missing, leave existing rows alone. */
    INSERT INTO [identity].[Permissions]
        ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
    SELECT p.[PermissionCode], p.[PermissionName], p.[Description], @ModuleCode, 1
    FROM @Permissions p
    WHERE NOT EXISTS (
        SELECT 1 FROM [identity].[Permissions] e
        WHERE UPPER(e.[PermissionCode]) = UPPER(p.[PermissionCode])
    );

    UPDATE e
    SET e.[IsActive] = 1,
        e.[PermissionName] = p.[PermissionName],
        e.[Description] = p.[Description],
        e.[ModuleCode] = @ModuleCode,
        e.[UpdatedAt] = SYSDATETIMEOFFSET()
    FROM [identity].[Permissions] e
    JOIN @Permissions p ON UPPER(p.[PermissionCode]) = UPPER(e.[PermissionCode])
    WHERE e.[IsActive] = 0;

    /* Platform administrators. */
    DECLARE @AdministratorMappingsInserted INT = 0;

    INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
    SELECT r.[RoleId], p.[PermissionId], 1
    FROM [identity].[Roles] r
    JOIN [identity].[Permissions] p
      ON UPPER(p.[PermissionCode]) IN (SELECT UPPER([PermissionCode]) FROM @Permissions)
    WHERE UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
      AND p.[IsActive] = 1
      AND NOT EXISTS (
          SELECT 1 FROM [identity].[RolePermissions] rp
          WHERE rp.[RoleId] = r.[RoleId] AND rp.[PermissionId] = p.[PermissionId]
      );
    SET @AdministratorMappingsInserted = @@ROWCOUNT;

    UPDATE rp
    SET rp.[IsActive] = 1
    FROM [identity].[RolePermissions] rp
    JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
    JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
    WHERE UPPER(r.[RoleCode]) = N'ADMINISTRATOR'
      AND UPPER(p.[PermissionCode]) IN (SELECT UPPER([PermissionCode]) FROM @Permissions)
      AND rp.[IsActive] = 0;

    /* ------------------------------------------------------------------
       Guards. Each would be a serious fault, so each fails the script.
       ------------------------------------------------------------------ */

    /* Mail is customer correspondence: employees and guests hold none by default. */
    IF EXISTS (
        SELECT 1
        FROM [identity].[RolePermissions] rp
        JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
        JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
        WHERE rp.[IsActive] = 1
          AND UPPER(r.[RoleCode]) IN (N'EMPLOYEE', N'GUEST')
          AND UPPER(p.[PermissionCode]) LIKE N'MAIL.%'
    )
        THROW 54201, N'EMPLOYEE or GUEST holds a mail permission. Mail access is granted through the RFQ roles and ADMINISTRATOR only. Refusing to continue.', 1;

    IF (SELECT COUNT(*) FROM [identity].[Permissions] p
        WHERE p.[IsActive] = 1 AND UPPER(p.[PermissionCode]) IN (SELECT UPPER([PermissionCode]) FROM @Permissions)) <> 2
        THROW 54202, N'A mail permission is missing or inactive after the seed. Refusing to continue.', 1;

    COMMIT TRANSACTION;

    SELECT @AdministratorMappingsInserted AS [AdministratorMappingsInserted];
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
