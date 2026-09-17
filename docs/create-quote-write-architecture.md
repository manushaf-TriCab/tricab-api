# Create Quote write architecture (Phase 4B)

## Decision

**Hybrid:** Azure Commerce Cart (draft) → tricab-api Save Quote → transactional write to legacy MySQL `tc.tqoth` / `tqoti` / `tqote`.

MySQL `AUTO_INCREMENT` quote id remains authoritative. No Azure quote-number sequence. No Azure operational quote store.

## Flow

```
Web ─┐
     ├→ tricab-api → Commerce Cart (Azure)
Mobile┘               ↓
                 Save Quote (future Phase 4C)
                     ↓
              QUOTE.CREATE + customer eligibility
                     ↓
               server pricing (no client totals)
                     ↓
             PersistQuoteDraft (validated command)
                     ↓
          QuoteWriteRepository
                     ↓
             MySQL transaction
                     ↓
          tqoth / tqoti / tqote
                     ↓
              quote number (lastInsertId)
                     ↓
              existing GET read APIs
```

No client connects to MySQL.

## Feature flag

| Key | Default |
|-----|---------|
| `QUOTES_ORDERS_LEGACY_WRITE_ENABLED` | **false** |

Write credentials are **separate** from `LEGACY_TC_*` RO bridge:

- `QUOTES_ORDERS_LEGACY_WRITE_DSN` (or HOST/DATABASE/PORT)
- `QUOTES_ORDERS_LEGACY_WRITE_USER`
- `QUOTES_ORDERS_LEGACY_WRITE_PASSWORD`
- `QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX` — required when enabled: `_dev` or `""`

When the flag is false, `DisabledQuoteWriteRepository` is wired and throws `QuoteWriteDisabledException` before any SQL.

## HTTP

**No write routes** in Phase 4B. Only:

- `GET /api/v1/quotes-orders`
- `GET /api/v1/quotes/{id}`
- `GET /api/v1/orders/{id}`

## Authorization boundary

Repository accepts only an already-authorized `PersistQuoteDraft`. It does **not** decide customer eligibility. Do not use `QUOTE.READ` / `staffSearchAll` for create.

## Idempotency (Phase 4C)

Prefer Azure-side store (see `docs/quote-write-idempotency-schema-proposal.md`). Do not add columns to `tqoth`.

## Transaction

```
BEGIN
  INSERT tqoth → lastInsertId
  foreach line: INSERT tqoti → lastInsertId
    foreach ext: INSERT tqote
  UPDATE tqoth.tnet
COMMIT
```

Failure → `ROLLBACK`.
