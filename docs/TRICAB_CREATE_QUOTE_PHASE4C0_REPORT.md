# TRICAB_CREATE_QUOTE_PHASE4C0_REPORT

## A. Repository checkpoint

| Item | Value |
|------|-------|
| Expected HEAD | `192aabe` Phase 4B disabled write repository |
| Excluded | `.gitignore`, `bin/*` |
| Push | **NONE** |

## B. Legacy create customer eligibility

| Role | Rule (proven) |
|------|----------------|
| Staff / Admin / Mgmt | `is_staff` / TriCab login; picker territory-filtered in UI; **switch does not re-check territory/assigned rep** |
| Management | Country + territory dropdowns |
| Employee | Default own office territory in lookup |
| Portal | Own contact only; no switch UI |

Create ≠ History visibility (`getQuoteAccess`).

## C. New customer eligibility policy

`QuoteCreateCustomerEligibilityService`:

- Requires `QUOTE.CREATE` (never `QUOTE.READ`)
- **Portal:** linked `CustomerContactUsers` only
- **Staff:** `QUOTE.CREATE` + same commercial capability as price-as (`CUSTOMER.PRICE_AS` or `CUSTOMER.READ`)
- Not `staffSearchAll`
- Territory hardening: **future** (legacy switch also did not enforce)

## D. QUOTE.CREATE assignments

| Role | Seeded? |
|------|---------|
| `CUSTOMER_ADMIN` | Yes (`seed-customer-roles-and-permissions`) |
| `ADMINISTRATOR` | Yes (`seed-admin-business-module-permissions`) |
| `EMPLOYEE` | **No** — Identity RolePermission write required before staff create in Azure identity |
| `CUSTOMER_USER` | READ only |

**Do not apply Identity DB writes in 4C0.**

## E. Portal rules

Own linked `customerPublicId` only. Foreign id → denied (tested).

## F. Staff rules

`QUOTE.CREATE` + price-as capability + active customer exists. Reuses authoritative pricing selection permissions (not READ quote scope).

## G. Cart ownership/context

Cart keyed by `OwnerUserId` + effective customer + pricing mode + currency. Load via `getByPublicId(cartId, ownerUserId)`. Foreign cart → `CartAccessDenied`.

## H. Cart state requirements

Only **`OPEN`**, non-empty, matching customer/pricing context. Reject `SUBMITTED` / `ABANDONED` / empty.

## I. Repricing strategy

**B — force server reprice** with diagnostic costs immediately before building `PersistQuoteDraft`. Never trust client cost/margin/sell/total. Prefer fresh resolve over stale cart snapshot alone.

## J. Margin override handling

Override lives in staff HMAC pricing token (`StaffPricingContextTokenCodec`) + line `MarginSource=STAFF_OVERRIDE`. Save Quote re-resolves with token; unauthorized/expired override → blocker. No margin % on POST body.

## K. TBC save behaviour

TBC lines → draft save allowed; `totalStatus=pending`, net `0.00` (GET-compatible). Finalize remains forbidden later.

## L. Validity decision

**BUSINESS CONFIRMATION REQUIRED.**  
`ProvisionalPlusTwoDayQuoteValidityPolicy` (+2 days, mirrors `createNewQuote`). Older path +7. Repository still receives `validUntil` only.

## M. GST/tax finding

`tnet` / line `net` are **GST-exclusive**. GST is PDF/disclaimer only.  
**BLOCKS PDF/UI presentation confirmation; does NOT block SAVE DRAFT.**

## N. Header defaults

| Field | Legacy meaning | Create value | Status |
|-------|----------------|--------------|--------|
| cod | Company | 2507 | DEFAULTED_PROVEN |
| sa | Office | 7 | DEFAULTED_PROVEN |
| mtt | Metric | 1301 | DEFAULTED_PROVEN |
| ptm | Payment terms | 3303 | DEFAULTED_PROVEN |
| tcval | Total cost | 0 | DEFAULTED_PROVEN |

Catalogue in `QuoteHeaderDefaults`.

## O. SaveQuoteService flow

Auth → `QUOTE.CREATE` → eligibility → load cart → ownership/state → pricing context match → force reprice → legacy line mapping → operator id → build `PersistQuoteDraft` → idempotency (when writing) → `QuoteWriteRepository`.

Default `dryRun=true`. Write path still hits disabled repository / flag.

## P. Dry-run mapping result

`prepareFromSnapshots` / `CartQuoteDraftMapper` + `QuoteWriteDryRunService` produce header/lines/total without SQL (tested).

## Q. Idempotency store

Azure `quote.QuoteWriteIdempotency` with states IN_PROGRESS / COMPLETED / FAILED, `PlatformOperationId`, `RequestHash`, `ReconciliationFingerprint`.

## R. Idempotency migration

**Applied: NO**  
File: `database/scripts/development/create-quote-write-idempotency-schema.sql`

## S. Cross-database failure/recovery strategy

Not globally atomic. Recovery: fingerprint (+ optional RO `_dev` lookup) when Azure IN_PROGRESS after MySQL commit crash. **Without applied store + reconciliation probe, first write BLOCKED.**

## T. Future POST contract

```
POST /api/v1/quotes
Idempotency-Key: ...
{ "customerPublicId", "cartId", "reference", "contactPublicId": null }
```

Forbidden client fields: customerCode, currency, status, cost, margin, sellPrice, totals, salesRep, legacyStatusCode.

## U. Future response contract

Domain DTO: id, type, status, statusLabel, customer, reference, currency, items, total, totalStatus, validUntil, access.  
HTTP **201** first create; idempotent replay returns same body (status convention TBD to match platform).

## V. Cart-after-save behaviour

After confirmed MySQL persist: `CartRepository::markSubmitted` (preserve snapshot; do not delete). New OPEN cart created on next add. **Not wired until write enabled.**

## W. Write credential requirements

Non-prod only:

- `QUOTES_ORDERS_LEGACY_WRITE_ENABLED=true`
- WRITE DSN/USER/PASSWORD (not LEGACY_TC RO)
- `QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX=_dev` (enforced by `NonProductionQuoteWriteSafety`)

## X. _dev schema parity

**NOT VERIFIED in this phase** (no live RO metadata probe executed). Required before first write: compare tqoth/tqoti/tqote vs `*_dev` columns/types/indexes/engine/AI.

## Y. First-write plan

**Executed: NO**

Authorized actor + customer + single RESOLVED line → `_dev` only → 1 header + 1 line → draft → no email/finalize. Cleanup: delete `_dev` rows by quote id after verification.

## Z. Tests/results

```
./vendor/bin/phpunit --filter 'QuoteOrder|QuoteWrite|SaveQuote'
Tests: 61, Assertions: 212, Failures: 0

Broader related filter (CustomerPricing|Cart|Me|…):
Tests: 73, Assertions: 324, Failures: 0
```

## AA. Existing GET regression

GET routes unchanged; POST /quotes still 404.

## AB. Remaining blockers for Phase 4C

READY FOR FIRST NON-PROD WRITE: **NO**

Conditions still required:

1. EMPLOYEE (or staff) `QUOTE.CREATE` Identity assignment decision/apply
2. Azure idempotency migration applied + reconciliation probe
3. Write credentials + `_dev` schema parity verified
4. Proven `QuoteLineLegacyMappingResolver` (catdt/product code) — no guessing
5. Proven `QuoteLegacyOperatorIdResolver` (sa_op)
6. Validity business confirmation (policy swappable; provisional +2 ok for _dev only if accepted)
7. Cart `markSubmitted` wiring after success
8. Explicit approval to enable write flag on non-prod

## AC. HTTP write routes — NONE  
## AD. Operational database writes — NONE  
## AE. _dev database writes — NONE  
## AF. Production changes — NONE  
## AG. Web changes — NONE  
## AH. Mobile changes — NONE  
## AI. Emails — NONE  

## AJ. Commit SHA

(see local commit after Phase 4C0)
