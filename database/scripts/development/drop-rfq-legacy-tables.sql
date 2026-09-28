/*
================================================================================
  Drops the seven EMPTY [rfq] tables that a 5 Aug run of the old RFQ app's own
  SQL Server script created on tricab-mel / tc. They were meant as a migration
  target, but the old app moved to MySQL instead (11 Sep), and they are behind
  that schema: two columns and eight tables short. create-rfq-schema.sql
  replaces them in the live MySQL shape.

  STATUS: NOT APPLIED to any shared database. Owner decision, 28 Sep 2026:
  drop and recreate. Every --apply on tricab-mel is still an owner decision.

  Refuses, changing nothing, if any of the seven holds a single row, or if a
  table has a foreign key into it from outside the seven. Touches nothing else
  in [rfq], and never the schema itself.

  Reads as destructive to bin/run-azure-sql-script.php, which asks for
  --i-understand-this-can-destroy-data.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @legacy TABLE ([name] SYSNAME PRIMARY KEY);
    INSERT INTO @legacy ([name]) VALUES
        (N'rfq_entries'), (N'rfq_sessions'), (N'rfq_draft_rows'), (N'rfq_draft_activity'),
        (N'rfq_draft_quotes'), (N'correction_memory'), (N'mail_audit');

    -- rfq_sync_attempts and rfq_correction_memory exist only in the new shape.
    -- If either is here, the shared names (rfq_draft_quotes, ...) are new
    -- tables, and dropping them would be dropping live data.
    IF OBJECT_ID(N'[rfq].[rfq_sync_attempts]', N'U') IS NOT NULL
       OR OBJECT_ID(N'[rfq].[rfq_correction_memory]', N'U') IS NOT NULL
        THROW 50002, N'The new RFQ tables are here: these are not the 5 Aug tables. Nothing dropped.', 1;

    DECLARE @rows BIGINT = (
        SELECT COALESCE(SUM(p.[rows]), 0)
        FROM sys.tables t
        JOIN sys.partitions p ON p.[object_id] = t.[object_id] AND p.index_id IN (0, 1)
        WHERE t.[schema_id] = SCHEMA_ID(N'rfq') AND t.[name] IN (SELECT [name] FROM @legacy));
    -- sys.partitions can lag; count for real under a lock as well.
    IF @rows = 0 AND OBJECT_ID(N'[rfq].[rfq_draft_quotes]', N'U') IS NOT NULL
        SELECT @rows = @rows + COUNT_BIG(*) FROM [rfq].[rfq_draft_quotes] WITH (TABLOCKX, HOLDLOCK);
    IF @rows = 0 AND OBJECT_ID(N'[rfq].[rfq_draft_rows]', N'U') IS NOT NULL
        SELECT @rows = @rows + COUNT_BIG(*) FROM [rfq].[rfq_draft_rows] WITH (TABLOCKX, HOLDLOCK);
    IF @rows = 0 AND OBJECT_ID(N'[rfq].[rfq_draft_activity]', N'U') IS NOT NULL
        SELECT @rows = @rows + COUNT_BIG(*) FROM [rfq].[rfq_draft_activity] WITH (TABLOCKX, HOLDLOCK);
    IF @rows = 0 AND OBJECT_ID(N'[rfq].[rfq_sessions]', N'U') IS NOT NULL
        SELECT @rows = @rows + COUNT_BIG(*) FROM [rfq].[rfq_sessions] WITH (TABLOCKX, HOLDLOCK);
    IF @rows = 0 AND OBJECT_ID(N'[rfq].[rfq_entries]', N'U') IS NOT NULL
        SELECT @rows = @rows + COUNT_BIG(*) FROM [rfq].[rfq_entries] WITH (TABLOCKX, HOLDLOCK);
    IF @rows = 0 AND OBJECT_ID(N'[rfq].[correction_memory]', N'U') IS NOT NULL
        SELECT @rows = @rows + COUNT_BIG(*) FROM [rfq].[correction_memory] WITH (TABLOCKX, HOLDLOCK);
    IF @rows = 0 AND OBJECT_ID(N'[rfq].[mail_audit]', N'U') IS NOT NULL
        SELECT @rows = @rows + COUNT_BIG(*) FROM [rfq].[mail_audit] WITH (TABLOCKX, HOLDLOCK);
    IF @rows > 0
        THROW 50003, N'A 5 Aug RFQ table holds rows. Nothing dropped.', 1;

    IF EXISTS (
        SELECT 1 FROM sys.foreign_keys fk
        WHERE OBJECT_SCHEMA_NAME(fk.referenced_object_id) = N'rfq'
          AND OBJECT_NAME(fk.referenced_object_id) IN (SELECT [name] FROM @legacy)
          AND NOT (OBJECT_SCHEMA_NAME(fk.parent_object_id) = N'rfq'
                   AND OBJECT_NAME(fk.parent_object_id) IN (SELECT [name] FROM @legacy)))
        THROW 50004, N'Another table has a foreign key into the 5 Aug RFQ tables. Nothing dropped.', 1;

    -- Children before parents.
    IF OBJECT_ID(N'[rfq].[rfq_entries]', N'U') IS NOT NULL DROP TABLE [rfq].[rfq_entries];
    IF OBJECT_ID(N'[rfq].[rfq_sessions]', N'U') IS NOT NULL DROP TABLE [rfq].[rfq_sessions];
    IF OBJECT_ID(N'[rfq].[rfq_draft_rows]', N'U') IS NOT NULL DROP TABLE [rfq].[rfq_draft_rows];
    IF OBJECT_ID(N'[rfq].[rfq_draft_activity]', N'U') IS NOT NULL DROP TABLE [rfq].[rfq_draft_activity];
    IF OBJECT_ID(N'[rfq].[rfq_draft_quotes]', N'U') IS NOT NULL DROP TABLE [rfq].[rfq_draft_quotes];
    IF OBJECT_ID(N'[rfq].[correction_memory]', N'U') IS NOT NULL DROP TABLE [rfq].[correction_memory];
    IF OBJECT_ID(N'[rfq].[mail_audit]', N'U') IS NOT NULL DROP TABLE [rfq].[mail_audit];

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
