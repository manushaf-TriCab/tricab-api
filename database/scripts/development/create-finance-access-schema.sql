/*
================================================================================
  Finance access: scoped grants and the portal user map (Phase 1, task F1.10).
  Written 7 Oct 2026. Additive to create-finance-schema.sql; run after it.

  STATUS: NOT APPLIED to any shared database. Do not run against tricab-mel or
  production without the owner's explicit approval (plan 9). Run against the
  local Docker database only; applying it to tricab-mel is Manusha's or Jeff's.

  Two tables:

    [finance].[access_scopes]     what a platform role cannot say: "this person,
                                  in this app, only for these branches / these
                                  mailboxes" (portal role grants' scope_type and
                                  scope_value; decision D6)
    [finance].[portal_user_map]   the link from a finance portal user id to the
                                  platform user it becomes (decision D7)

  Scopes. identity.UserRoles has no scope column, so the scope lives here, keyed by
  the platform user. A row is one allowed value: Tri-Credit Notes uses `branch`
  and Tri-AP uses `mailbox` today. A person with NO row for an app and scope type
  is unrestricted on it, as in the portal. A person with rows is limited to
  exactly those values. The table holds no role and grants nothing by itself:
  whether a person may enter an app is still the platform role and permission.
  [scope_value] is stored as the app compares it (a mailbox address is lower-case).

  Portal user map. Every finance app recorded people by the portal's user id (a
  32-character uuid4 hex), which no longer exists once identity moves to the
  platform. One row per portal user that could be matched to a platform user, by
  email; [recorded_email] is the address the portal had, kept as evidence of the
  match and never used as a key. Portal users with no platform user have no row:
  the script that fills this table (bin/map-finance-portal-users.php) lists them
  instead. A platform user maps from at most one portal user.

  No foreign key to [identity] (the RFQ tables' rule, decision D7): the finance
  schema has no key outside itself. [user_id] and [platform_user_id] are
  identity.Users.UserId values.

  Conventions as create-finance-schema.sql: DATETIME2(6) in UTC, NVARCHAR text.

  Guarded and idempotent: each table is created only if missing.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF SCHEMA_ID(N'finance') IS NULL
    EXEC (N'CREATE SCHEMA [finance];');
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[finance].[access_scopes]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[access_scopes] (
            [user_id]          BIGINT         NOT NULL,
            [application_key]  NVARCHAR(16)   NOT NULL,
            [scope_type]       NVARCHAR(20)   NOT NULL,
            [scope_value]      NVARCHAR(200)  NOT NULL,
            [created_at]       DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_access_scopes_created_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_access_scopes]
                PRIMARY KEY CLUSTERED ([user_id], [application_key], [scope_type], [scope_value]),
            CONSTRAINT [CK_finance_access_scopes_application_key]
                CHECK ([application_key] IN (N'TASK', N'CREDIT', N'COLLECT', N'AR', N'AP', N'AP2')),
            CONSTRAINT [CK_finance_access_scopes_scope_type]
                CHECK ([scope_type] IN (N'branch', N'mailbox')),
            CONSTRAINT [CK_finance_access_scopes_scope_value]
                CHECK (LEN(LTRIM(RTRIM([scope_value]))) > 0)
        );
    END;

    IF OBJECT_ID(N'[finance].[portal_user_map]', N'U') IS NULL
    BEGIN
        CREATE TABLE [finance].[portal_user_map] (
            [portal_user_id]    NVARCHAR(32)   NOT NULL,
            [recorded_email]    NVARCHAR(320)  NOT NULL,
            [platform_user_id]  BIGINT         NOT NULL,
            [mapped_at]         DATETIME2(6)   NOT NULL
                CONSTRAINT [DF_finance_portal_user_map_mapped_at] DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT [PK_finance_portal_user_map] PRIMARY KEY CLUSTERED ([portal_user_id])
        );

        CREATE UNIQUE INDEX [UX_finance_portal_user_map_platform_user_id]
            ON [finance].[portal_user_map] ([platform_user_id]);
    END;

    COMMIT TRANSACTION;

    SELECT
        (SELECT COUNT(*) FROM sys.tables  WHERE [schema_id] = SCHEMA_ID(N'finance')) AS [FinanceTables],
        (SELECT COUNT(*) FROM sys.indexes i
           JOIN sys.tables t ON t.[object_id] = i.[object_id]
          WHERE t.[schema_id] = SCHEMA_ID(N'finance') AND i.[index_id] > 0) AS [FinanceIndexes];
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
