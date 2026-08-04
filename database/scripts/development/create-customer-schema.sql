/*
================================================================================
  Additive development migration: Customer / Contact foundation (Stage 2).

  Creates:
    [customer] schema
    [customer].[Customers]
    [customer].[CustomerContacts]
    [customer].[CustomerContactUsers]
    [migration] schema
    [migration].[LegacyCustomerUserMappings]

  Azure SQL-compatible, idempotent guards.
  Does NOT modify or modify legacy MySQL tc.cust / tc.contacts / wp_users.
  DEVELOPMENT / additive only — review before production deploy.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'customer')
    BEGIN
        EXEC(N'CREATE SCHEMA [customer]');
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'migration')
    BEGIN
        EXEC(N'CREATE SCHEMA [migration]');
    END;

    /* ------------------------------------------------------------------
       customer.Customers
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[customer].[Customers]', N'U') IS NULL
    BEGIN
        CREATE TABLE [customer].[Customers] (
            [CustomerId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_customer_Customers_PublicId DEFAULT (NEWSEQUENTIALID()),
            [LegacyCustomerId] INT NULL,
            [CustomerCode] NVARCHAR(100) NULL,
            [CustomerName] NVARCHAR(200) NOT NULL,
            [StatusCode] NVARCHAR(20) NOT NULL,
            [AddressLine1] NVARCHAR(100) NULL,
            [AddressLine2] NVARCHAR(100) NULL,
            [AddressLine3] NVARCHAR(100) NULL,
            [AddressLine4] NVARCHAR(100) NULL,
            [Suburb] NVARCHAR(60) NULL,
            [Postcode] NVARCHAR(20) NULL,
            [CountryCode] NVARCHAR(4) NULL,
            [MarketCode] NVARCHAR(45) NULL,
            [SalesRepresentative] NVARCHAR(100) NULL,
            [TerritoryCode] NVARCHAR(45) NULL,
            [PhoneNumber] NVARCHAR(50) NULL,
            [IsActive] BIT NOT NULL CONSTRAINT DF_customer_Customers_IsActive DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_Customers_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_Customers_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT PK_customer_Customers PRIMARY KEY CLUSTERED ([CustomerId]),
            CONSTRAINT UQ_customer_Customers_PublicId UNIQUE ([PublicId]),
            CONSTRAINT CK_customer_Customers_CustomerName_NotEmpty CHECK (LEN(LTRIM(RTRIM([CustomerName]))) > 0),
            CONSTRAINT CK_customer_Customers_StatusCode_NotEmpty CHECK (LEN(LTRIM(RTRIM([StatusCode]))) > 0)
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[Customers]') AND name = N'UX_customer_Customers_CustomerCode_NotNull')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_customer_Customers_CustomerCode_NotNull
            ON [customer].[Customers] ([CustomerCode])
            WHERE [CustomerCode] IS NOT NULL;
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[Customers]') AND name = N'UX_customer_Customers_LegacyCustomerId_NotNull')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_customer_Customers_LegacyCustomerId_NotNull
            ON [customer].[Customers] ([LegacyCustomerId])
            WHERE [LegacyCustomerId] IS NOT NULL;
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[Customers]') AND name = N'IX_customer_Customers_CustomerName')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_Customers_CustomerName
            ON [customer].[Customers] ([CustomerName]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[Customers]') AND name = N'IX_customer_Customers_StatusCode')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_Customers_StatusCode
            ON [customer].[Customers] ([StatusCode]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[Customers]') AND name = N'IX_customer_Customers_CountryCode')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_Customers_CountryCode
            ON [customer].[Customers] ([CountryCode]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[Customers]') AND name = N'IX_customer_Customers_TerritoryCode')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_Customers_TerritoryCode
            ON [customer].[Customers] ([TerritoryCode]);
    END;

    /* ------------------------------------------------------------------
       customer.CustomerContacts
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[customer].[CustomerContacts]', N'U') IS NULL
    BEGIN
        CREATE TABLE [customer].[CustomerContacts] (
            [CustomerContactId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_customer_CustomerContacts_PublicId DEFAULT (NEWSEQUENTIALID()),
            [CustomerId] BIGINT NOT NULL,
            [LegacyContactRowId] BIGINT NULL,
            [LegacyContactId] INT NULL,
            [DisplayName] NVARCHAR(255) NOT NULL,
            [EmailAddress] NVARCHAR(320) NULL,
            [NormalizedEmailAddress] NVARCHAR(320) NULL,
            [PositionTitle] NVARCHAR(255) NULL,
            [PhoneNumber] NVARCHAR(50) NULL,
            [MobileNumber] NVARCHAR(50) NULL,
            [IsPrimaryContact] BIT NOT NULL CONSTRAINT DF_customer_CustomerContacts_IsPrimaryContact DEFAULT (0),
            [IsActive] BIT NOT NULL CONSTRAINT DF_customer_CustomerContacts_IsActive DEFAULT (1),
            [PortalAccessStatusCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_customer_CustomerContacts_PortalAccessStatusCode DEFAULT (N'not-invited'),
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_CustomerContacts_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_CustomerContacts_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            CONSTRAINT PK_customer_CustomerContacts PRIMARY KEY CLUSTERED ([CustomerContactId]),
            CONSTRAINT UQ_customer_CustomerContacts_PublicId UNIQUE ([PublicId]),
            CONSTRAINT FK_customer_CustomerContacts_Customers FOREIGN KEY ([CustomerId]) REFERENCES [customer].[Customers] ([CustomerId]) ON DELETE NO ACTION,
            CONSTRAINT CK_customer_CustomerContacts_DisplayName_NotEmpty CHECK (LEN(LTRIM(RTRIM([DisplayName]))) > 0),
            CONSTRAINT CK_customer_CustomerContacts_PortalAccessStatusCode CHECK (
                [PortalAccessStatusCode] IN (N'not-invited', N'invited', N'active', N'suspended', N'declined')
            )
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[CustomerContacts]') AND name = N'UX_customer_CustomerContacts_LegacyContactRowId_NotNull')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_customer_CustomerContacts_LegacyContactRowId_NotNull
            ON [customer].[CustomerContacts] ([LegacyContactRowId])
            WHERE [LegacyContactRowId] IS NOT NULL;
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[CustomerContacts]') AND name = N'IX_customer_CustomerContacts_CustomerId_IsActive')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_CustomerContacts_CustomerId_IsActive
            ON [customer].[CustomerContacts] ([CustomerId], [IsActive]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[CustomerContacts]') AND name = N'IX_customer_CustomerContacts_NormalizedEmailAddress')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_CustomerContacts_NormalizedEmailAddress
            ON [customer].[CustomerContacts] ([NormalizedEmailAddress]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[CustomerContacts]') AND name = N'IX_customer_CustomerContacts_CustomerId_NormalizedEmail')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_CustomerContacts_CustomerId_NormalizedEmail
            ON [customer].[CustomerContacts] ([CustomerId], [NormalizedEmailAddress]);
    END;

    /* ------------------------------------------------------------------
       customer.CustomerContactUsers
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[customer].[CustomerContactUsers]', N'U') IS NULL
    BEGIN
        CREATE TABLE [customer].[CustomerContactUsers] (
            [CustomerContactUserId] BIGINT IDENTITY(1,1) NOT NULL,
            [CustomerContactId] BIGINT NOT NULL,
            [UserId] BIGINT NOT NULL,
            [IsPrimary] BIT NOT NULL CONSTRAINT DF_customer_CustomerContactUsers_IsPrimary DEFAULT (1),
            [IsActive] BIT NOT NULL CONSTRAINT DF_customer_CustomerContactUsers_IsActive DEFAULT (1),
            [LinkedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_CustomerContactUsers_LinkedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UnlinkedAtUtc] DATETIME2(7) NULL,
            [CreatedByUserId] BIGINT NULL,
            CONSTRAINT PK_customer_CustomerContactUsers PRIMARY KEY CLUSTERED ([CustomerContactUserId]),
            CONSTRAINT FK_customer_CustomerContactUsers_Contact FOREIGN KEY ([CustomerContactId]) REFERENCES [customer].[CustomerContacts] ([CustomerContactId]) ON DELETE NO ACTION,
            CONSTRAINT FK_customer_CustomerContactUsers_User FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT FK_customer_CustomerContactUsers_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT UQ_customer_CustomerContactUsers_Contact_User UNIQUE ([CustomerContactId], [UserId]),
            CONSTRAINT CK_customer_CustomerContactUsers_UnlinkedAtUtc CHECK (
                [UnlinkedAtUtc] IS NULL OR [UnlinkedAtUtc] >= [LinkedAtUtc]
            )
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[CustomerContactUsers]') AND name = N'UX_customer_CustomerContactUsers_ActivePrimary')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_customer_CustomerContactUsers_ActivePrimary
            ON [customer].[CustomerContactUsers] ([CustomerContactId])
            WHERE [IsPrimary] = 1 AND [IsActive] = 1;
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[CustomerContactUsers]') AND name = N'IX_customer_CustomerContactUsers_UserId_IsActive')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_CustomerContactUsers_UserId_IsActive
            ON [customer].[CustomerContactUsers] ([UserId], [IsActive]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[customer].[CustomerContactUsers]') AND name = N'IX_customer_CustomerContactUsers_ContactId_IsActive')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_customer_CustomerContactUsers_ContactId_IsActive
            ON [customer].[CustomerContactUsers] ([CustomerContactId], [IsActive]);
    END;

    /* ------------------------------------------------------------------
       migration.LegacyCustomerUserMappings
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[migration].[LegacyCustomerUserMappings]', N'U') IS NULL
    BEGIN
        CREATE TABLE [migration].[LegacyCustomerUserMappings] (
            [LegacyCustomerUserMappingId] BIGINT IDENTITY(1,1) NOT NULL,
            [LegacyWordPressUserId] BIGINT NULL,
            [LegacyUserLogin] NVARCHAR(60) NULL,
            [LegacyEmailAddress] NVARCHAR(320) NULL,
            [LegacyCustomerId] INT NULL,
            [LegacyContactRowId] BIGINT NULL,
            [CustomerId] BIGINT NULL,
            [CustomerContactId] BIGINT NULL,
            [UserId] BIGINT NULL,
            [MigrationStatusCode] NVARCHAR(30) NOT NULL,
            [MigrationNotes] NVARCHAR(1000) NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyCustomerUserMappings_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyCustomerUserMappings_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [MigratedAtUtc] DATETIME2(7) NULL,
            CONSTRAINT PK_migration_LegacyCustomerUserMappings PRIMARY KEY CLUSTERED ([LegacyCustomerUserMappingId]),
            CONSTRAINT FK_migration_LegacyCustomerUserMappings_Customer FOREIGN KEY ([CustomerId]) REFERENCES [customer].[Customers] ([CustomerId]) ON DELETE NO ACTION,
            CONSTRAINT FK_migration_LegacyCustomerUserMappings_Contact FOREIGN KEY ([CustomerContactId]) REFERENCES [customer].[CustomerContacts] ([CustomerContactId]) ON DELETE NO ACTION,
            CONSTRAINT FK_migration_LegacyCustomerUserMappings_User FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT CK_migration_LegacyCustomerUserMappings_StatusCode CHECK (
                [MigrationStatusCode] IN (
                    N'pending',
                    N'matched-contact',
                    N'created-user',
                    N'duplicate-email',
                    N'missing-email',
                    N'invalid-email',
                    N'manual-review',
                    N'skipped'
                )
            )
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[migration].[LegacyCustomerUserMappings]') AND name = N'IX_migration_LegacyCustomerUserMappings_LegacyWordPressUserId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_LegacyWordPressUserId
            ON [migration].[LegacyCustomerUserMappings] ([LegacyWordPressUserId]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[migration].[LegacyCustomerUserMappings]') AND name = N'IX_migration_LegacyCustomerUserMappings_LegacyEmailAddress')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_LegacyEmailAddress
            ON [migration].[LegacyCustomerUserMappings] ([LegacyEmailAddress]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[migration].[LegacyCustomerUserMappings]') AND name = N'IX_migration_LegacyCustomerUserMappings_MigrationStatusCode')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_MigrationStatusCode
            ON [migration].[LegacyCustomerUserMappings] ([MigrationStatusCode]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[migration].[LegacyCustomerUserMappings]') AND name = N'IX_migration_LegacyCustomerUserMappings_UserId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_UserId
            ON [migration].[LegacyCustomerUserMappings] ([UserId]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[migration].[LegacyCustomerUserMappings]') AND name = N'IX_migration_LegacyCustomerUserMappings_CustomerContactId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_CustomerContactId
            ON [migration].[LegacyCustomerUserMappings] ([CustomerContactId]);
    END;

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
