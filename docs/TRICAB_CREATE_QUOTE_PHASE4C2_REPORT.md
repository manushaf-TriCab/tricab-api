# TRICAB_CREATE_QUOTE_PHASE4C2_REPORT

## A. Repository checkpoint

| Item | Value |
|------|-------|
| Expected HEAD (start) | `4f81cc2f23c7890564818da669d0df6790c57d50` |
| Phase 4C2 tip | `ac10a64904b800353b02ed1f72b55f181e3d8b47` |
| Excluded | `.gitignore`, unrelated `bin/*` |
| Push | **NONE** |
| Quote INSERT | **NONE** |
| `POST /api/v1/quotes` | **NOT REGISTERED** |
| Write flag end state | **`false`** |

## B. Blocker classification

| Blocker | Class |
|---------|-------|
| Dedicated write credentials unset | **CREDENTIAL** / **CONFIG** |
| Azure idempotency migration | **DB MIGRATION** (applied non-prod) |
| MySQL `quote_write_correlation_dev` | **DB MIGRATION** (blocked on write credentials) |
| Live operator resolver wiring | **CODE** (implemented) |
| First-write actor → descript proof | **MANUAL / DATA** (Thilan not in descript) |
| Product → cata/catdt/col/pm mapper | **CODE** (implemented for simple cable) |
| Write flag intentionally off | **CONFIG** (preserve until Phase 4C) |

## C. Write credential readiness

| Item | Status |
|------|--------|
| `QUOTES_ORDERS_LEGACY_WRITE_*` | **UNSET** |
| Reuse `LEGACY_TC_*` | **Forbidden** |
| Provisioning | Create dedicated non-prod MySQL writer with INSERT/UPDATE on `tqoth_dev`/`tqoti_dev`/`tqote_dev` + DDL/SELECT on `quote_write_correlation_dev`; store in local `.env` / non-prod secret store as WRITE_* only |

**Do not invent credentials.**

## D. Non-mutating connection probe

**Not executed** — no WRITE credentials available.

## E. Azure idempotency migration

**Applied: YES**  
**Target: NON-PROD** (`APP_ENV=development`, server `tricab-mel`, db `tc`)

Via `bin/ensure-quote-write-idempotency-schema.php` → `create-quote-write-idempotency-schema.sql`.

## F. Azure idempotency verification

Against live `AzureSqlQuoteWriteIdempotencyStore` with marked test keys:

| Check | Result |
|-------|--------|
| acquire → IN_PROGRESS | OK |
| same key + different fingerprint → conflict | OK |
| complete → COMPLETED + quoteId | OK |
| DI store class | `AzureSqlQuoteWriteIdempotencyStore` |

## G. MySQL correlation migration

**Applied: NO**  
**Table:** `quote_write_correlation_dev`  
**Exists (RO metadata):** **0**

`bin/ensure-quote-write-correlation-dev.php` refuses without WRITE_* → **MYSQL DEV CORRELATION APPLY REQUIRED** (after credentials).

Operational `quote_write_correlation` was **not** created.

## H. Correlation verification

Schema/SQL path ready in code (`PdoQuoteWriteCorrelationRepository` inside same TX as header/lines). Live table + SELECT probe blocked on G.

## I. Operator resolver implementation

- `DescriptStaffQuoteLegacyOperatorIdResolver` — MySQL `descript` `desc_type=36`
- `AzureLinkedDescriptQuoteLegacyOperatorIdResolver` — Azure `LegacyStaffUserMappings.LegacyUserLogin` then descript
- DI wired; portal (`customerGenerated`) returns null (out of first-write scope)
- Missing mapping → prepare blocker `MISSING_LEGACY_OPERATOR_ID` → **zero SQL**

## J. First-write actor mapping

**NOT PROVEN**

Intended ADMINISTRATOR `thilanh@tricab.com`:

- no `descript` `desc_type=36` hit for email/local-part candidates
- no `migration.LegacyStaffUserMappings` row for normalized email
- no staff name match for Thilan/Lakshitha in descript

## K. Product legacy mapper

`SimpleCableQuoteLineLegacyMappingResolver` (first-write cable scope only).

## L. cata mapping

`cata` = authoritative `variantCode` (not display title). Accessory-heuristic codes rejected.

## M. catdt mapping

Simple cable with length → **1615**. Length-less / accessory → null (unsupported).

## N. col mapping

**4008** = descript colour **Black** (`desc_type=40`, `desc_code=BK`).

Proven as **QuoteManagement create hardcode** for all quote line inserts (not Azure jacket colour). First-write uses this proven create default.

## O. pm mapping

**1501** = descript pack matrix **METRES** (`desc_type=15`, `desc_code=M`).

**PM IS NOT MARGIN: YES**

Regression test: margin 45% does not become `pm`.

## P. qty/qotqty mapping

Simple cable (no ext): both = `packCount × unitsOrLength` (legacy `getNoUnits()` twice). Proven against RO samples where qty=qotqty.

## Q. Price/cost mapping

Unchanged: server reprice → `nprc`/`net`/`cprc`/`cval` only.

## R. First-write product

Representative simple cable code for fixture mapping: e.g. `XL-PAXN/1C25OR` (or any length-bearing non-accessory variantCode). Final SKU selection at Phase 4C execute after pricing confirms RESOLVED.

## S. First-write customer

Unchanged requirement: active non-prod customer with legacy id + AUD. Not mutated.

## T. Validity/GST

+2 days **PROVISIONALLY PROVEN**. GST exclusive — no change.

## U. Cart markSubmitted

Still called after idempotency complete/recover. Cart has no quote FK; quoteId retained in Azure idempotency + MySQL correlation. Failure isolated (tested in 4C1).

## V. Recovery path

Wired: IN_PROGRESS + correlation hit → complete + return existing; no second persist. Live correlation probe pending table apply.

## W. Preflight service

`QuoteWritePreflightService` (+ unit tests). Internal/CLI use.

## X. Exact first-write preflight result

| Check | Status |
|-------|--------|
| WRITE FLAG | **OFF** (intentional) |
| TARGET `_dev` | NOT_READY (suffix unset until enable session) |
| WRITE_CREDENTIALS | **NOT_READY** |
| IDEMPOTENCY | **READY** (Azure store + table applied) |
| CORRELATION | **NOT_READY** |
| ACTOR QUOTE.CREATE | READY (ADMINISTRATOR seed) |
| OPERATOR | **NOT_READY** (Thilan mapping unproven) |
| LINE_MAPPING | **READY** (simple cable rules) |
| PM_NOT_MARGIN | **READY** |

`readyExceptWriteFlag`: **false**

## Y. Tests/results

```
./vendor/bin/phpunit --filter 'QuoteOrder|QuoteWrite|SaveQuote|Idempotency|QuoteLegacy|QuoteWritePreflight'
Tests: 82, Assertions: 286, Failures: 0
```

Related filter `CustomerPricing|Cart|MeEndpoint|QuoteOrder`: Tests: 111, Assertions: 421, Failures: 0.

## Z. POST /quotes

**NOT REGISTERED**

## AA. tqoth_dev writes

**0**

## AB. tqoti_dev writes

**0**

## AC. tqote_dev writes

**0**

## AD. Operational quote writes

**0**

## AE. Production changes

**NONE**

## AF. Web/mobile changes

**NONE**

## AG. Commit SHA

`ac10a64904b800353b02ed1f72b55f181e3d8b47`

## AH. Remaining blockers

1. Provision dedicated non-prod `QUOTES_ORDERS_LEGACY_WRITE_*` credentials  
2. Apply `quote_write_correlation_dev` (`php bin/ensure-quote-write-correlation-dev.php`)  
3. Prove first-write ADMINISTRATOR → `descript.desc_id` (Thilan currently unmapped; choose an actor who exists in descript type 36, or add staff mapping)  
4. Keep write flag OFF until Phase 4C controlled enable  

---

# READY FOR FIRST NON-PROD WRITE:

**NO**

Concrete remaining blockers: **AH.1–AH.3** (AH.4 is intentional gate).
