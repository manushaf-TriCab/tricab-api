# TRICAB_CREATE_QUOTE_PHASE4B_REPORT

## A. Repository checkpoint

| Item | Value |
|------|-------|
| HEAD (before 4B) | `78391d4` feat(quotes): enforce legacy quote visibility and identity mapping |
| Prior | `a61379c` feat(quotes): add read-only quotes and orders foundation |
| Excluded from commit | `.gitignore`, `bin/phase4j-*`, `bin/tricab-fix-local-api.command` |
| Push | **NONE** |

## B. Write architecture

Hybrid preparation only:

Commerce Cart (Azure) → future SaveQuoteService → `PersistQuoteDraft` → `QuoteWriteRepository` → MySQL TX → `tqoth`/`tqoti`/`tqote` → AUTO_INCREMENT quote id.

Default wiring: `DisabledQuoteWriteRepository` (throws before SQL).

## C. Read/write connection separation

| Path | Connection | Guard |
|------|------------|-------|
| Read | `LegacyReadOnlyConnection` + `LEGACY_TC_*` | `LegacyReadOnlySqlGuard` **unchanged** |
| Write | `LegacyQuoteWriteConnection` / `PdoLegacyQuoteWriteConnection` + `QUOTES_ORDERS_LEGACY_WRITE_*` | Write flag + dedicated credentials |

No silent fallback from RO credentials to write config.

## D. Feature flag

`QUOTES_ORDERS_LEGACY_WRITE_ENABLED` default **false**.

When true: requires WRITE DSN/USER/PASSWORD and explicit `QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX` (`_dev` or `""`). Fail-closed otherwise.

## E. QuoteWriteRepository

- Interface: `TriCab\Core\QuoteOrder\Contract\QuoteWriteRepository::persistDraft()`
- Implementations: `DisabledQuoteWriteRepository`, `TransactionalLegacyQuoteWriteRepository`
- Wired in `ContainerFactory` — disabled when flag false

## F. Persistence command

`PersistQuoteDraft` (+ `PersistQuoteDraftLine`, `PersistQuoteDraftExtendedLine`) — server-derived only; not an HTTP DTO. Validates before map/SQL. Total = `bcadd` sum of line nets.

## G. tqoth mapping

| Field | Classification | Source |
|-------|----------------|--------|
| dt | PROVEN | command (`1611` proven default documented) |
| st | DERIVED | draft status 1705/1710 |
| de/tme | DERIVED | `createdAt` → Australia/Melbourne |
| cod/sa/mtt/ptm | DEFAULTED | application must supply proven values |
| sa_op/cus/cus_op/cus_gen | DERIVED | command |
| cy | DERIVED | currency mapper |
| tnet | DERIVED | sum of line nets (updated after lines) |
| tcval | DEFAULTED | command (legacy create uses 0) |
| dvalid/cref | DERIVED | command (validity policy not baked in) |

## H. tqoti mapping

`qot,itm,cata,catdt,col,qty,pm,nprc,net,cprc,cval,qotqty,ref` from validated command. Line numbers = 1..N command order.

## I. tqote mapping

Optional `qoti,pk,qty,comments`. No rows when extended list empty. Pack/reel parity still PARTIAL (Phase 4D).

## J. Currency/status mapping

- `LegacyQuoteCurrencyMapper`: AUD/NZD/USD ↔ cyrte ids; unknown fails before SQL
- `LegacyQuoteStatusMapper`: draft 1705/1710; final 1703/1711 (finalize not implemented)

## K. Transaction implementation

`BEGIN` → INSERT header → lines (+ ext) → UPDATE `tnet` → `COMMIT`; any failure → `ROLLBACK`.

## L. Quote ID allocation

MySQL `AUTO_INCREMENT` + `lastInsertId()` only.

## M. Line numbering

Deterministic 1..N inside the create transaction (no global MAX query).

## N. Money/decimal handling

`bcmath` via `bcadd` scale 2 for money; scale 4 for qty/length/margin strings.

## O. Date/time handling

Melbourne conversion centralized in `PersistQuoteDraftMapper`. Repository receives `createdAt` / `validUntil` — no +2/+7 policy in repository.

## P. Idempotency architecture

- Interface `QuoteWriteIdempotencyStore` + `Null` / `InMemory` implementations
- Schema proposal only: `docs/quote-write-idempotency-schema-proposal.md` (**not applied**)
- Azure-side preferred; no legacy column

## Q. Audit/logging

`QuoteWriteAuditLogger` — operation, success, quoteId, error class. Null default. No credentials/pricing dumps.

## R. Customer authorization boundary

Repository does not authorize. Future service: actor → `QUOTE.CREATE` → eligibility → pricing → command. `Module::PERMISSION_CREATE` documented; not wired to HTTP.

## S. Test database strategy

In-memory transactional harness only. No live/operational `tc` writes. Production tables never targeted by tests.

## T. Transaction failure tests

Covered: success; header/line/ext/total/commit failures → empty committed ops; disabled/unknown currency/invalid command → zero SQL.

## U. Read regression

`./vendor/bin/phpunit --filter 'QuoteOrder|QuoteWrite'` — all green. GET routes unchanged. `LegacyReadOnlySqlGuard` not modified.

## V. Business blockers

| Item | Classification |
|------|----------------|
| CREATE customer eligibility | **BLOCKS 4C** |
| Pack/reel parity | **BLOCKS 4D** / PARTIAL |
| +2 vs +7 validity | **BUSINESS CONFIRMATION** (not baked in repo) |
| GST/tax presentation | **BUSINESS CONFIRMATION** |
| Cut/delivery/discount | **BLOCKS FINALIZE/UI** (not auto-generated) |
| Write credential provisioning | **BLOCKS 4C** live enablement |

## W. Files changed

Core write domain/infra, Settings/flag, Container wiring, Pdo connection, tests, docs. No web/mobile.

## X. Tests/results

```
./vendor/bin/phpunit --filter 'QuoteOrder|QuoteWrite'
Tests: 50, Assertions: 180, Failures: 0
```

## Y. HTTP write routes

**NONE** (POST /api/v1/quotes etc. → 404)

## Z. Database writes to operational tc

**NONE**

## AA. Production changes

**NONE** (flag default false)

## AB. Web changes

**NONE**

## AC. Mobile changes

**NONE**

## AD. Emails

**NONE**

## AE. Commit SHA

(see git after local commit)
