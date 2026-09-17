# Create Quote write architecture (Phase 4B + 4C0)

## Decision

**Hybrid:** Azure Commerce Cart (draft) → tricab-api Save Quote → transactional write to legacy MySQL `tc.tqoth` / `tqoti` / `tqote`.

MySQL `AUTO_INCREMENT` quote id remains authoritative.

## Flow

```
Web ─┐
     ├→ tricab-api → Commerce Cart (Azure, OPEN)
Mobile┘               ↓
                 SaveQuoteService (Phase 4C0 prepared; HTTP not registered)
                     ↓
              QUOTE.CREATE + QuoteCreateCustomerEligibilityService
                     ↓
               force server reprice (diagnostic costs)
                     ↓
             PersistQuoteDraft (validated command)
                     ↓
          QuoteWriteIdempotencyStore (Azure; migration NOT applied)
                     ↓
          QuoteWriteRepository (disabled by default)
                     ↓
             MySQL transaction → quote number
                     ↓
              existing GET read APIs
```

## Phase 4C0 status

- Eligibility + SaveQuoteService + validity policy + header defaults + idempotency design: **done**
- `POST /api/v1/quotes`: **NOT registered**
- `QUOTES_ORDERS_LEGACY_WRITE_ENABLED`: **false**
- First non-prod write: **NOT READY** (see Phase 4C0 report blockers)
