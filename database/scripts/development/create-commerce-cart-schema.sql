/*
  Phase 13.7A — commerce cart schema (NON-PRODUCTION)

  Idempotent. Soft-delete + RowVersion + PublicId + BIGINT FKs.
  Length = DECIMAL(18,3). No base-cost / PriceList / tmktm columns.

  Do not apply outside tricab-mel / tc / development.
*/

SET XACT_ABORT ON;
SET NOCOUNT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'commerce')
    BEGIN
        EXEC(N'CREATE SCHEMA [commerce]');
    END;

    IF OBJECT_ID(N'[commerce].[Carts]', N'U') IS NULL
    BEGIN
        CREATE TABLE [commerce].[Carts]
        (
            [CartId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL
                CONSTRAINT [DF_commerce_Carts_PublicId] DEFAULT (NEWSEQUENTIALID()),
            [OwnerUserId] BIGINT NOT NULL,
            [EffectiveCustomerId] BIGINT NULL,
            [PricingMode] NVARCHAR(40) NOT NULL,
            [CurrencyCode] NVARCHAR(3) NOT NULL,
            [Status] NVARCHAR(30) NOT NULL,
            [PricingFingerprint] NVARCHAR(64) NULL,
            [IsActive] BIT NOT NULL
                CONSTRAINT [DF_commerce_Carts_IsActive] DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_commerce_Carts_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_commerce_Carts_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [DeletedAtUtc] DATETIME2(7) NULL,
            [DeletedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,
            CONSTRAINT [PK_commerce_Carts] PRIMARY KEY CLUSTERED ([CartId]),
            CONSTRAINT [UQ_commerce_Carts_PublicId] UNIQUE ([PublicId]),
            CONSTRAINT [FK_commerce_Carts_OwnerUser]
                FOREIGN KEY ([OwnerUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_commerce_Carts_EffectiveCustomer]
                FOREIGN KEY ([EffectiveCustomerId]) REFERENCES [customer].[Customers] ([CustomerId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_commerce_Carts_CreatedBy]
                FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_commerce_Carts_UpdatedBy]
                FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_commerce_Carts_DeletedBy]
                FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT [CK_commerce_Carts_PricingMode]
                CHECK ([PricingMode] IN (N'SELF_CUSTOMER', N'STAFF_SELECTED_CUSTOMER')),
            CONSTRAINT [CK_commerce_Carts_Status]
                CHECK ([Status] IN (N'OPEN', N'SUBMITTED', N'ABANDONED'))
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = N'IX_commerce_Carts_Owner_Status_Active'
          AND object_id = OBJECT_ID(N'[commerce].[Carts]')
    )
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_commerce_Carts_Owner_Status_Active]
            ON [commerce].[Carts] ([OwnerUserId], [Status])
            WHERE [DeletedAtUtc] IS NULL;
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = N'IX_commerce_Carts_Customer_Status_Active'
          AND object_id = OBJECT_ID(N'[commerce].[Carts]')
    )
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_commerce_Carts_Customer_Status_Active]
            ON [commerce].[Carts] ([EffectiveCustomerId], [Status])
            WHERE [DeletedAtUtc] IS NULL;
    END;

    /* One OPEN cart per owner + effective customer + pricing mode + currency */
    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = N'UX_commerce_Carts_Open_Owner_Customer_Mode_Currency'
          AND object_id = OBJECT_ID(N'[commerce].[Carts]')
    )
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX [UX_commerce_Carts_Open_Owner_Customer_Mode_Currency]
            ON [commerce].[Carts] (
                [OwnerUserId],
                [EffectiveCustomerId],
                [PricingMode],
                [CurrencyCode]
            )
            WHERE [Status] = N'OPEN' AND [DeletedAtUtc] IS NULL;
    END;

    IF OBJECT_ID(N'[commerce].[CartLines]', N'U') IS NULL
    BEGIN
        CREATE TABLE [commerce].[CartLines]
        (
            [CartLineId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL
                CONSTRAINT [DF_commerce_CartLines_PublicId] DEFAULT (NEWSEQUENTIALID()),
            [CartId] BIGINT NOT NULL,
            [ProductVariantId] BIGINT NOT NULL,
            [PurchaseOptionKey] NVARCHAR(80) NULL,
            [Quantity] INT NOT NULL,
            [Length] DECIMAL(18,3) NULL,
            [UomCode] NVARCHAR(10) NULL,
            [Availability] NVARCHAR(20) NULL,
            [PricingStatus] NVARCHAR(40) NOT NULL,
            [UnitSellPrice] DECIMAL(18,6) NULL,
            [LineTotal] DECIMAL(18,2) NULL,
            [CurrencyCode] NVARCHAR(3) NULL,
            [MarginSource] NVARCHAR(40) NULL,
            [PricingFingerprint] NVARCHAR(64) NULL,
            [ResolvedAtUtc] DATETIME2(7) NULL,
            [IsActive] BIT NOT NULL
                CONSTRAINT [DF_commerce_CartLines_IsActive] DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_commerce_CartLines_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_commerce_CartLines_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [DeletedAtUtc] DATETIME2(7) NULL,
            [DeletedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,
            CONSTRAINT [PK_commerce_CartLines] PRIMARY KEY CLUSTERED ([CartLineId]),
            CONSTRAINT [UQ_commerce_CartLines_PublicId] UNIQUE ([PublicId]),
            CONSTRAINT [FK_commerce_CartLines_Cart]
                FOREIGN KEY ([CartId]) REFERENCES [commerce].[Carts] ([CartId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_commerce_CartLines_Variant]
                FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_commerce_CartLines_CreatedBy]
                FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_commerce_CartLines_UpdatedBy]
                FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT [FK_commerce_CartLines_DeletedBy]
                FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT [CK_commerce_CartLines_Quantity] CHECK ([Quantity] > 0),
            CONSTRAINT [CK_commerce_CartLines_Length] CHECK ([Length] IS NULL OR [Length] > 0),
            CONSTRAINT [CK_commerce_CartLines_MarginSource] CHECK (
                [MarginSource] IS NULL
                OR [MarginSource] IN (N'TMMKTM', N'STAFF_OVERRIDE', N'TBC', N'OTHER_APPROVED_SOURCE')
            )
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = N'IX_commerce_CartLines_Cart_Active'
          AND object_id = OBJECT_ID(N'[commerce].[CartLines]')
    )
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_commerce_CartLines_Cart_Active]
            ON [commerce].[CartLines] ([CartId])
            WHERE [DeletedAtUtc] IS NULL;
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = N'IX_commerce_CartLines_Variant_Active'
          AND object_id = OBJECT_ID(N'[commerce].[CartLines]')
    )
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_commerce_CartLines_Variant_Active]
            ON [commerce].[CartLines] ([ProductVariantId])
            WHERE [DeletedAtUtc] IS NULL;
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
