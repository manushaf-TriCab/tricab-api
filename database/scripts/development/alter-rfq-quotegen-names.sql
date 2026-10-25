/*
================================================================================
  RFQ module renamed Quotegen in the UI (26 Oct 2026): display names only.

  STATUS: NOT APPLIED to any shared database. Applying it to tricab-mel is
  Manusha's call.

  seed-rfq-permissions.sql leaves existing permission rows alone, so a database
  seeded before the rename keeps "Access RFQs" and "Administer RFQs". This
  script renames those two permissions and the three RFQ roles. It touches only
  rows that still hold the old name, so a name an administrator has since
  changed is left alone, and running it again does nothing. Codes are unchanged.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    UPDATE [identity].[Permissions]
    SET [PermissionName] = N'Access Quotegen',
        [Description] = N'Reach the Quotegen module and see one''s own RFQ access. Grants no RFQ.'
    WHERE UPPER([PermissionCode]) = N'RFQ.ACCESS' AND [PermissionName] = N'Access RFQs';

    UPDATE [identity].[Permissions]
    SET [PermissionName] = N'Administer Quotegen',
        [Description] = N'Administer the Quotegen module. Reads no RFQ.'
    WHERE UPPER([PermissionCode]) = N'RFQ.ADMIN' AND [PermissionName] = N'Administer RFQs';

    UPDATE [identity].[Roles]
    SET [RoleName] = N'Quotegen Sales', [UpdatedAt] = SYSDATETIMEOFFSET()
    WHERE UPPER([RoleCode]) = N'RFQ_SALES' AND [RoleName] = N'RFQ Sales';

    UPDATE [identity].[Roles]
    SET [RoleName] = N'Quotegen Administrator',
        [Description] = N'Quotegen Sales, plus assigning work, restoring archived RFQs, analytics, the activity log, reports, cost and margin, and module administration.',
        [UpdatedAt] = SYSDATETIMEOFFSET()
    WHERE UPPER([RoleCode]) = N'RFQ_ADMIN' AND [RoleName] = N'RFQ Administrator';

    UPDATE [identity].[Roles]
    SET [RoleName] = N'Quotegen Developer',
        [Description] = N'Quotegen Administrator, plus review logs, parser diagnostics and permanent deletion.',
        [UpdatedAt] = SYSDATETIMEOFFSET()
    WHERE UPPER([RoleCode]) = N'RFQ_DEVELOPER' AND [RoleName] = N'RFQ Developer';

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
