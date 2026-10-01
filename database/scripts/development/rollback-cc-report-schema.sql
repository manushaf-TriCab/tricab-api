/*
================================================================================
  Rollback for create-cc-report-schema.sql.

  STATUS: NOT APPLIED. Do not run without explicit approval.

  Drops only [cc_report] objects. Does not touch [customer].[CustomerContacts]
  or [customer].[CustomerContactUsers].

  Refuses while [cc_report].[Reports] has any row unless @Force = 1.
  Set @Force in this file before a forced run. The default is 0.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @Force BIT = 0;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[cc_report].[Reports]', N'U') IS NOT NULL
    BEGIN
        DECLARE @Reports INT = (SELECT COUNT(*) FROM [cc_report].[Reports]);
        IF @Reports > 0 AND @Force = 0
        BEGIN
            DECLARE @Msg NVARCHAR(400) = N'Refuse cc_report rollback: '
                + CAST(@Reports AS NVARCHAR(20))
                + N' report(s) exist. Set @Force = 1 to drop them.';
            THROW 50001, @Msg, 1;
        END;
    END;

    IF OBJECT_ID(N'[cc_report].[Approvals]', N'U') IS NOT NULL
        DROP TABLE [cc_report].[Approvals];

    IF OBJECT_ID(N'[cc_report].[Attachments]', N'U') IS NOT NULL
        DROP TABLE [cc_report].[Attachments];

    IF OBJECT_ID(N'[cc_report].[NumberSequences]', N'U') IS NOT NULL
        DROP TABLE [cc_report].[NumberSequences];

    IF OBJECT_ID(N'[cc_report].[Reports]', N'U') IS NOT NULL
        DROP TABLE [cc_report].[Reports];

    IF SCHEMA_ID(N'cc_report') IS NOT NULL
       AND NOT EXISTS (
            SELECT 1
            FROM sys.objects
            WHERE schema_id = SCHEMA_ID(N'cc_report')
       )
        DROP SCHEMA [cc_report];

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
