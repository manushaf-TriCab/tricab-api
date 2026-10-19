/*
================================================================================
  Mail review: delete a conversation from the inbox (19 Oct 2026).
  Additive to create-mail-schema.sql; run after it.

  STATUS: NOT APPLIED to any shared database. Apply to tricab-mel BEFORE the
  API change that reads these columns is deployed: until then every inbox list
  and count fails. Applying it to tricab-mel is Manusha's or Jeff's.

  Adds [deleted_at], [deleted_by_user_id] and [deleted_by_name] to
  [mail].[threads]. A deleted thread is left out of every list, count, export
  and read, but its rows stay, so Graph delivering the same mail again (after a
  deploy empties the reader's cursor) cannot bring it back. Nothing is deleted
  in Exchange. A new message in the conversation clears the columns and the
  thread returns.

  create-mail-schema.sql now creates the columns. This script is for a table
  created before that change; on a table that has them it does nothing, so it
  is safe to run any number of times. It changes no data.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF COL_LENGTH(N'mail.threads', N'deleted_at') IS NULL
BEGIN
    BEGIN TRY
        BEGIN TRANSACTION;

        ALTER TABLE [mail].[threads] ADD
            [deleted_at]         DATETIME2(6)  NULL,
            [deleted_by_user_id] BIGINT        NULL,
            [deleted_by_name]    NVARCHAR(200) NULL;

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
END;
GO

SELECT COUNT(*) AS [DeletedColumns]
  FROM sys.columns
 WHERE [object_id] = OBJECT_ID(N'mail.threads')
   AND [name] IN (N'deleted_at', N'deleted_by_user_id', N'deleted_by_name');
GO
