# Create Quote write architecture (Phase 4B → 4C1)

## Decision

**Hybrid:** Azure Commerce Cart (draft) → tricab-api Save Quote → transactional write to legacy MySQL `tc.tqoth` / `tqoti` / `tqote`.

MySQL `AUTO_INCREMENT` quote id remains authoritative.

## Flow

```
Web ─┐
     ├→ tricab-api → Commerce Cart (Azure, OPEN)
Mobile┘               ↓
                 SaveQuoteService (prepared; HTTP not registered)
                     ↓
              QUOTE.CREATE + QuoteCreateCustomerEligibilityService
                     ↓
               force server reprice (diagnostic costs)
                     ↓
             PersistQuoteDraft + PlatformOperationId
                     ↓
          QuoteWriteIdempotencyStore (Azure; migration NOT applied)
                     ↓
          QuoteWriteRepository (disabled by default)
                     ↓
     MySQL TX: tqoth → correlation → tqoti → tnet  (*_dev only)
                     ↓
          markCompleted → markSubmitted(cart)
                     ↓
              existing GET read APIs
```

## Phase 4C1 status

- `_dev` schema parity: **MATCH** (RO probe)
- `_dev` hard safety guard: **enforced**
- Idempotency store + MySQL correlation design: **implemented** (migrations **NOT applied**)
- Crash recovery + cart failure isolation: **tested in-memory**
- `pm` = pack-matrix id (not margin): **fixed**
- `POST /api/v1/quotes`: **NOT registered**
- `QUOTES_ORDERS_LEGACY_WRITE_ENABLED`: **false**
- First non-prod write: **NOT READY** (see Phase 4C1 report)
