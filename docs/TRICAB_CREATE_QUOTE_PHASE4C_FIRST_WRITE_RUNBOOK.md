# TriCab Create Quote — Phase 4C First-Write Runbook

**Status:** DO NOT EXECUTE until explicit approval.  
**Target:** non-production only (`QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX=_dev`).  
**HTTP:** `POST /api/v1/quotes` remains unregistered — execute via `SaveQuoteService` / CLI harness only.

---

## Prerequisites (must all be true before step 8)

| Check | Expected |
|-------|----------|
| Azure idempotency store | READY (applied + verified) |
| `quote_write_correlation_dev` | EXISTS + SELECT probe empty for sentinel UUID |
| Dedicated `QUOTES_ORDERS_LEGACY_WRITE_*` | Configured (never `LEGACY_TC_*`) |
| Table suffix | exactly `_dev` |
| Actor | ADMINISTRATOR with `QUOTE.CREATE` + proven `descript.desc_id` |
| Customer | Active Azure customer with legacy `tc.cust` parity |
| Cart | OPEN, one simple cable line, server pricing RESOLVED |
| Write flag | still `false` until step 8 |
| Preflight | `infrastructureReady=true`, `domainReady=true`, `writeEnabled=false`, `readyForControlledEnablement=true` |

---

## Ordered execution

1. **Confirm HEAD** of `tricab-api` matches the approved Phase 4C tip.
2. **Confirm Azure idempotency** table/store still healthy (non-mutating acquire/conflict/complete against *test* keys only, or schema probe).
3. **Confirm correlation table** `tc.quote_write_correlation_dev` exists; lookup nonexistent PlatformOperationId → no row.
4. **Confirm actor** — selected first-write ADMINISTRATOR resolves via `QuoteLegacyOperatorIdResolver` to a positive `descript.desc_id` (desc_type=36). Record desc_id (not secrets).
5. **Confirm customer** — active, actor eligible (`QUOTE.CREATE` + `CUSTOMER.READ`/`PRICE_AS`), legacy customer id present in `tc.cust`, AUD/supported currency, no special fee dependency.
6. **Confirm cart** — OPEN Azure commerce cart owned by actor context, customer match, **exactly one** simple cable line, length present, no accessories/TBC/ZZ*/ext packs. Server reprice RESOLVED for sell + cost.
7. **Run preflight** — `QuoteWritePreflightService` for the exact fixture. Require `readyForControlledEnablement=true` with write flag still OFF.
8. **Temporarily set** `QUOTES_ORDERS_LEGACY_WRITE_ENABLED=true` (and ensure suffix `_dev` + WRITE_* present). Reload process/config.
9. **Execute ONE** `SaveQuoteService` call with a fresh Idempotency-Key / PlatformOperationId.
10. **Immediately capture** returned quote id.
11. **Verify `tqoth_dev`** — one new header row; `sa_op` = proven operator; customer/currency fields match; `tnet` = sum of line nets (GST exclusive).
12. **Verify `tqoti_dev`** — one line; `cata`/`catdt`/`col`/`pm`/`qty`/`qotqty`/`nprc`/`net`/`cprc`/`cval` match expected; **pm ≠ margin**.
13. **Verify `tqote_dev` count** for that quote id = **0**.
14. **Verify correlation row** — `platform_operation_id` ↔ `quote_id` present.
15. **Verify Azure idempotency** — state COMPLETED, same quoteId.
16. **Verify cart** — submitted / not OPEN (markSubmitted after commit).
17. **Retry SAME Idempotency-Key** (same fingerprint).
18. **Prove same quote id** returned (no second persist).
19. **Prove row counts unchanged** on `tqoth_dev` / `tqoti_dev` / correlation for that operation.
20. **Turn write flag false** — `QUOTES_ORDERS_LEGACY_WRITE_ENABLED=false`; confirm DisabledQuoteWriteRepository again.
21. **Rollback test separately** — isolated failure injection only (never against the successful evidence quote as cleanup-as-safety). Prefer a dedicated fail-injection harness.
22. **Final verification** — flag OFF; POST /quotes still absent; evidence quote retained on `_dev` unless environment practice requires cleanup.

---

## Hard abort conditions (ZERO quote INSERT)

Abort **before** any INSERT if any of:

- `QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX` ≠ `_dev`
- write connection `DATABASE()` not the intended non-prod legacy DB
- operator unresolved / `MISSING_LEGACY_OPERATOR_ID`
- customer unauthorized / inactive / missing legacy id
- pricing TBC or cost unresolved
- line mapper unsupported / accessory / length-less
- correlation table unavailable or unexpected existing correlation for this PlatformOperationId
- idempotency store unavailable / fingerprint conflict unexpectedly
- fixture has more than one line
- special line / ZZDISC / ZZCUT / ZZDEL300 / TBC generated
- `tqote` unexpectedly required for this simple cable
- write credentials missing or appear to be RO `LEGACY_TC_*` reuse

On abort: leave flag OFF (or restore OFF), do not retry blindly.

---

## Safety notes

- Do **not** rely on “delete the test quote afterwards” as the primary safety strategy.
- Prefer retaining the clearly identified `_dev` evidence quote.
- Never apply to operational `tqoth`/`tqoti`/`tqote` (empty suffix) in Phase 4C.
- Never register `POST /api/v1/quotes` in this phase.
