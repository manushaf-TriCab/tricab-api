# Quote write idempotency — schema proposal (NOT APPLIED)

**Status:** proposal only for Phase 4C approval.  
**Phase 4B:** interface + in-memory store only. **Do not create this table yet.**

## Rationale

Expense reminders already use an Azure `IdempotencyKey` uniqueness pattern. Quote create should follow the same platform-side approach so retries do not insert two `tqoth` rows. Legacy MySQL remains SoR for quote numbers; idempotency metadata stays on Azure.

## Proposed Azure SQL

```sql
CREATE TABLE quote.QuoteWriteIdempotency (
    IdempotencyId       UNIQUEIDENTIFIER NOT NULL CONSTRAINT PK_quote_QuoteWriteIdempotency PRIMARY KEY DEFAULT NEWSEQUENTIALID(),
    ActorKey            NVARCHAR(128) NOT NULL,
    Operation           NVARCHAR(64) NOT NULL,   -- e.g. quotes.persistDraft
    IdempotencyKey      NVARCHAR(128) NOT NULL,
    RequestHash         CHAR(64) NOT NULL,       -- sha256 of canonical request body
    QuoteId             NVARCHAR(32) NOT NULL,   -- legacy tqoth.id as string
    CreatedAtUtc        DATETIME2(3) NOT NULL CONSTRAINT DF_quote_QuoteWriteIdempotency_CreatedAtUtc DEFAULT SYSUTCDATETIME(),
    CONSTRAINT UX_quote_QuoteWriteIdempotency_Actor_Op_Key
        UNIQUE (ActorKey, Operation, IdempotencyKey)
);
```

## Behaviour (Phase 4C)

| Case | Result |
|------|--------|
| Same actor + operation + key + same request hash | Return existing `QuoteId` |
| Same key + different request hash | Conflict (409) |
| Concurrent same key | One winner; loser observes existing row |
| Different key | New quote |

Email/finalize idempotency is separate later.
