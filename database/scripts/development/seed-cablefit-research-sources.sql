/*
================================================================================
  DEVELOPMENT seed - CableFit research sources (the first list).

  OPEN QUESTION in the C3 plan: Chabilal reviews this list before it is used for
  anything real. Treat it as a starting point, not an approved allow-list.

  - Idempotent / safe to re-run: inserts the domains that are missing, matched by
    domain, and never overwrites a row (a name, category, notes or enabled change
    made through the API survives a re-run).
  - created_by_user_id / updated_by_user_id stay NULL: these are system rows, not
    a person's edit. The columns are nullable for exactly this.
  - Creates no users and no roles.

  Run after create-cablefit-schema.sql.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Sources TABLE (
        [domain]   NVARCHAR(253) NOT NULL PRIMARY KEY,
        [name]     NVARCHAR(200) NOT NULL,
        [category] NVARCHAR(30)  NOT NULL
    );

    INSERT INTO @Sources ([domain], [name], [category])
    VALUES
        (N'iec.ch',               N'International Electrotechnical Commission',       N'standards_body'),
        (N'standards.org.au',     N'Standards Australia',                             N'standards_body'),
        (N'ieee.org',             N'IEEE',                                            N'standards_body'),
        (N'cigre.org',            N'CIGRE',                                           N'standards_body'),
        (N'iso.org',              N'International Organization for Standardization',  N'standards_body'),
        (N'prysmian.com',         N'Prysmian',                                        N'manufacturer'),
        (N'nexans.com',           N'Nexans',                                          N'manufacturer'),
        (N'southwire.com',        N'Southwire',                                       N'manufacturer'),
        (N'generalcable.com',     N'General Cable',                                   N'manufacturer'),
        (N'lscns.com',            N'LS Cable & System',                               N'manufacturer'),
        (N'sumitomoelectric.com', N'Sumitomo Electric',                               N'manufacturer'),
        (N'olex.com.au',          N'Olex',                                            N'manufacturer'),
        (N'theiet.org',           N'The Institution of Engineering and Technology',   N'technical_publisher'),
        (N'sciencedirect.com',    N'ScienceDirect',                                   N'technical_publisher'),
        (N'springer.com',         N'Springer',                                        N'technical_publisher'),
        (N'mdpi.com',             N'MDPI',                                            N'technical_publisher');

    INSERT INTO [cablefit].[research_sources] ([domain], [name], [category], [enabled])
    SELECT s.[domain], s.[name], s.[category], 1
    FROM @Sources s
    WHERE NOT EXISTS (
        SELECT 1 FROM [cablefit].[research_sources] e WHERE e.[domain] = s.[domain]
    );

    PRINT CONCAT(N'CableFit research sources inserted: ', @@ROWCOUNT);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
