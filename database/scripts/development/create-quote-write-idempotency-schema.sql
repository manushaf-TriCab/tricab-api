-- Phase 4C0 — Quote write idempotency (Azure SQL)
-- STATUS: NOT APPLIED. Do not run against shared environments without approval.
-- Filename: database/scripts/development/create-quote-write-idempotency-schema.sql

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'quote')
BEGIN
    EXEC(N'CREATE SCHEMA quote');
END
GO

IF OBJECT_ID(N'quote.QuoteWriteIdempotency', N'U') IS NULL
BEGIN
    CREATE TABLE quote.QuoteWriteIdempotency (
        IdempotencyId           UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT PK_quote_QuoteWriteIdempotency PRIMARY KEY
            DEFAULT NEWSEQUENTIALID(),
        ActorKey                NVARCHAR(128) NOT NULL,
        Operation               NVARCHAR(64) NOT NULL,
        IdempotencyKey          NVARCHAR(128) NOT NULL,
        RequestHash             CHAR(64) NOT NULL,
        State                   NVARCHAR(32) NOT NULL,
        PlatformOperationId     UNIQUEIDENTIFIER NOT NULL,
        QuoteId                 NVARCHAR(32) NULL,
        CartPublicId            UNIQUEIDENTIFIER NULL,
        ReconciliationFingerprint CHAR(64) NULL,
        FailureReason           NVARCHAR(256) NULL,
        CreatedAtUtc            DATETIME2(3) NOT NULL
            CONSTRAINT DF_quote_QuoteWriteIdempotency_CreatedAtUtc DEFAULT SYSUTCDATETIME(),
        UpdatedAtUtc            DATETIME2(3) NOT NULL
            CONSTRAINT DF_quote_QuoteWriteIdempotency_UpdatedAtUtc DEFAULT SYSUTCDATETIME(),
        CONSTRAINT CK_quote_QuoteWriteIdempotency_State
            CHECK (State IN (N'IN_PROGRESS', N'COMPLETED', N'FAILED')),
        CONSTRAINT UX_quote_QuoteWriteIdempotency_Actor_Op_Key
            UNIQUE (ActorKey, Operation, IdempotencyKey),
        CONSTRAINT UX_quote_QuoteWriteIdempotency_PlatformOperationId
            UNIQUE (PlatformOperationId)
    );

    CREATE INDEX IX_quote_QuoteWriteIdempotency_State_Updated
        ON quote.QuoteWriteIdempotency (State, UpdatedAtUtc);
END
GO
