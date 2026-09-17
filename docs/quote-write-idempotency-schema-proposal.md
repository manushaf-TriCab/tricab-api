# Quote write idempotency — design (Phase 4C0)

**Migration applied:** NO  
**File (unapplied):** `database/scripts/development/create-quote-write-idempotency-schema.sql`

## Store

Azure SQL `quote.QuoteWriteIdempotency` — not legacy MySQL.

Unique: `(ActorKey, Operation, IdempotencyKey)`  
Also unique: `PlatformOperationId`

States: `IN_PROGRESS` | `COMPLETED` | `FAILED`

## Semantics

| Case | Result |
|------|--------|
| Same actor+op+key + same request hash + COMPLETED | Return stored `QuoteId` |
| Same key + different request hash | 409 conflict |
| Concurrent acquire | One IN_PROGRESS winner |
| FAILED | Allow retry with same key+hash (re-acquire policy TBD in 4C) |

## Cross-database failure window

Azure and MySQL cannot share a local transaction.

Sequence:

1. Azure `tryAcquire` → IN_PROGRESS (+ `PlatformOperationId`, `ReconciliationFingerprint`)
2. MySQL TX commit → quote id
3. Azure `markCompleted(quoteId)`

**Crash between 2 and 3:** retry must not create a second quote.

### Recovery design (required before first write)

1. Pre-generate `PlatformOperationId` (UUID) before MySQL write.
2. Persist Azure IN_PROGRESS with `ReconciliationFingerprint` = hash(legacyCustomerId, currency, totalNet, lineCount, productCodes).
3. On retry while IN_PROGRESS:
   - Prefer RO lookup of recent draft quotes matching fingerprint within a short time window (non-prod `_dev` only initially), OR
   - Manual operator reconciliation using PlatformOperationId + fingerprint.
4. If exactly one MySQL match → `markCompleted` with that quote id (no second insert).
5. If zero matches → safe to re-run MySQL persist.
6. If multiple matches → hard fail (manual).

**Without applied Azure table + fingerprint reconciliation probe, first write is BLOCKED.**

Optional future hardening (not in 4C0): durable outbox / coordinator. Do not ALTER `tqoth` for correlation.
