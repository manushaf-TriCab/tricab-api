/*
================================================================================
  Phase 12.7 — Restore [product].[SpecificationUnits] (additive / idempotent)

  Root cause addressed: SCHEMA_NOT_APPLIED
  Phase 11 defined the table in create-product-schema.sql, but the validated
  non-production Azure database never received this object. Phase 2C pricing
  UOM FKs also depend on it.

  Safe to re-run. No DROP. No legacy MySQL changes. No Product deletes.
  DEVELOPMENT / non-production only. Review before apply.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'[product].[SpecificationUnits]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[SpecificationUnits] (
        [SpecificationUnitId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_SpecificationUnits_PublicId DEFAULT (NEWSEQUENTIALID()),
        [UnitCode] NVARCHAR(50) NOT NULL,
        [UnitName] NVARCHAR(200) NOT NULL,
        [Symbol] NVARCHAR(50) NOT NULL,
        [Description] NVARCHAR(1000) NULL,
        [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_SpecificationUnits_DisplayOrder DEFAULT (0),
        [IsActive] BIT NOT NULL CONSTRAINT DF_product_SpecificationUnits_IsActive DEFAULT (1),
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationUnits_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationUnits_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_product_SpecificationUnits PRIMARY KEY CLUSTERED ([SpecificationUnitId]),
        CONSTRAINT UQ_product_SpecificationUnits_PublicId UNIQUE ([PublicId]),
        CONSTRAINT UQ_product_SpecificationUnits_UnitCode UNIQUE ([UnitCode]),
        CONSTRAINT CK_product_SpecificationUnits_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([UnitName]))) > 0),
        CONSTRAINT CK_product_SpecificationUnits_Symbol_NotEmpty CHECK (LEN(LTRIM(RTRIM([Symbol]))) > 0),
        CONSTRAINT FK_product_SpecificationUnits_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_SpecificationUnits_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_SpecificationUnits_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
    PRINT N'Created [product].[SpecificationUnits].';
END
ELSE
BEGIN
    PRINT N'[product].[SpecificationUnits] already present — no create.';
END
GO
