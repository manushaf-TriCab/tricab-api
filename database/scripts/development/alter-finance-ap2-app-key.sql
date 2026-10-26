/*
================================================================================
  Tri-AP2: let the finance app key AP2 through the five application_key CHECK
  constraints (Tri-AP2 backend, Phase 1). Written 26 Oct 2026.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval. A fresh build does not need
  it: create-finance-schema.sql, create-finance-access-schema.sql and
  create-finance-attachments-schema.sql now list AP2. Run it once on a database
  built before that, before the Tri-AP2 permission seed is used.

  Idempotent: a constraint whose definition already lists AP2 is left alone.
  Each constraint is dropped and re-added in one transaction; no row changes,
  and every existing value (TASK, CREDIT, COLLECT, AR, AP) stays valid.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Targets TABLE (
        [TableName]      SYSNAME NOT NULL,
        [ConstraintName] SYSNAME NOT NULL,
        [Keys]           NVARCHAR(400) NOT NULL
    );

    INSERT INTO @Targets ([TableName], [ConstraintName], [Keys])
    VALUES
        (N'mailbox_grants',  N'CK_finance_mailbox_grants_application_key',  N'N''TASK'', N''CREDIT'', N''COLLECT'', N''AR'', N''AP'', N''AP2'''),
        (N'mail_deliveries', N'CK_finance_mail_deliveries_application_key', N'N''TASK'', N''CREDIT'', N''COLLECT'', N''AR'', N''AP'', N''AP2'''),
        (N'audit_events',    N'CK_finance_audit_events_application_key',    N'N''FINANCE'', N''TASK'', N''CREDIT'', N''COLLECT'', N''AR'', N''AP'', N''AP2'''),
        (N'access_scopes',   N'CK_finance_access_scopes_application_key',   N'N''TASK'', N''CREDIT'', N''COLLECT'', N''AR'', N''AP'', N''AP2'''),
        (N'attachments',     N'CK_finance_attachments_application_key',     N'N''TASK'', N''CREDIT'', N''COLLECT'', N''AR'', N''AP'', N''AP2''');

    DECLARE @Table SYSNAME, @Constraint SYSNAME, @Keys NVARCHAR(400), @Sql NVARCHAR(MAX);

    DECLARE targets CURSOR LOCAL FAST_FORWARD FOR
        SELECT [TableName], [ConstraintName], [Keys] FROM @Targets;
    OPEN targets;
    FETCH NEXT FROM targets INTO @Table, @Constraint, @Keys;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        IF OBJECT_ID(N'[finance].[' + @Table + N']', N'U') IS NOT NULL
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM sys.check_constraints c
                WHERE c.[name] = @Constraint
                  AND c.[parent_object_id] = OBJECT_ID(N'[finance].[' + @Table + N']')
                  AND c.[definition] LIKE N'%AP2%'
            )
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM sys.check_constraints c
                    WHERE c.[name] = @Constraint
                      AND c.[parent_object_id] = OBJECT_ID(N'[finance].[' + @Table + N']')
                )
                BEGIN
                    SET @Sql = N'ALTER TABLE [finance].[' + @Table + N'] DROP CONSTRAINT [' + @Constraint + N'];';
                    EXEC (@Sql);
                END;

                SET @Sql = N'ALTER TABLE [finance].[' + @Table + N'] ADD CONSTRAINT [' + @Constraint
                         + N'] CHECK ([application_key] IN (' + @Keys + N'));';
                EXEC (@Sql);
            END;
        END;

        FETCH NEXT FROM targets INTO @Table, @Constraint, @Keys;
    END;
    CLOSE targets;
    DEALLOCATE targets;

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
GO
