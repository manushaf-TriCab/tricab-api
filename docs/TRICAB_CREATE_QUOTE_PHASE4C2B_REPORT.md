# TRICAB_CREATE_QUOTE_PHASE4C2B_REPORT

## A. Repository checkpoint

| Item | Value |
|------|-------|
| Start HEAD | `c20f6865c2742d594bc2e9bbc9e9fca2be77661e` |
| Excluded | `.gitignore`, unrelated `bin/*` |
| Push | **NONE** |
| Quote INSERT | **NONE** |
| `POST /api/v1/quotes` | **NOT REGISTERED** |
| Write flag end state | **`false`** |

## B. Dedicated writer provisioning

**NOT READY**

| Finding | Detail |
|---------|--------|
| In-repo named writer identity | **None** |
| Local `.env` WRITE_* | **Unset** |
| Reuse `LEGACY_TC_*` | **Forbidden** (config does not fall back) |
| Storage pattern | Local `.env` + non-prod App Service application settings (same pattern as other env secrets; no Key Vault WRITE doc found) |
| tc-web | Uses `_dev` suffix on non-www hosts via QuoteManagement — **no** `QUOTES_ORDERS_LEGACY_WRITE_*` bridge in this API |

### Admin provisioning instructions (no passwords)

Create a **dedicated non-production MySQL user** (do not reuse `LEGACY_TC_*` / `rfq_app` RO).

Set in local `.env` and non-prod App Service settings (never commit secrets):

```text
QUOTES_ORDERS_LEGACY_WRITE_ENABLED=false
QUOTES_ORDERS_LEGACY_WRITE_HOST=<same non-prod MySQL host as intended legacy DB>
QUOTES_ORDERS_LEGACY_WRITE_PORT=3306
QUOTES_ORDERS_LEGACY_WRITE_DATABASE=tc
QUOTES_ORDERS_LEGACY_WRITE_USER=<dedicated writer>
QUOTES_ORDERS_LEGACY_WRITE_PASSWORD=<secret>
QUOTES_ORDERS_LEGACY_WRITE_SSL_MODE=required
QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX=_dev
```

Or equivalent:

```text
QUOTES_ORDERS_LEGACY_WRITE_DSN=mysql:host=...;port=3306;dbname=tc;charset=utf8mb4
```

Then apply correlation DDL:

```bash
php bin/ensure-quote-write-correlation-dev.php
```

## C. Runtime privilege scope

**Prefer split identities:**

| Identity | Purpose |
|----------|---------|
| Migration/admin | Temporary DDL: `CREATE TABLE` for `quote_write_correlation_dev` |
| Runtime writer | Day-to-day quote write |

**Recommended runtime GRANT (non-prod `_dev` only):**

| Object | Privileges |
|--------|------------|
| `tqoth_dev` | SELECT, INSERT, UPDATE |
| `tqoti_dev` | SELECT, INSERT |
| `tqote_dev` | SELECT, INSERT |
| `quote_write_correlation_dev` | SELECT, INSERT |

Do **not** grant broad database privileges to the runtime writer.

## D. Non-mutating connection probe

**NOT RUN** — WRITE credentials unavailable.

(RO bridge confirmed `DATABASE()=tc`, `tqoth_dev`/`tqoti_dev`/`tqote_dev` exist.)

## E. Correlation table

**NOT APPLIED**

`quote_write_correlation_dev` exists=**0** (RO metadata).

→ **MYSQL DEV CORRELATION APPLY REQUIRED** after WRITE_* (or admin DDL) credentials are provisioned.

Operational `quote_write_correlation` was **not** created.

## F. Correlation schema verification

N/A (not applied). Reviewed DDL remains:

`database/scripts/development/create-quote-write-correlation-mysql.sql`  
→ PK(`platform_operation_id`), UNIQUE(`quote_id`), InnoDB, utf8mb4, `created_at`.

## G. Correlation lookup probe

N/A (table absent). Runtime wiring still Null without WRITE_*.

## H. Actor candidates

RO discovery (`ADMINISTRATOR` + `QUOTE.CREATE` on role + live `QuoteLegacyOperatorIdResolver`):

| Masked email | Status | Operator mapping |
|--------------|--------|------------------|
| `th***@tricab.com` (Thilan) | ACTIVE | **NONE** (no descript / no LegacyStaffUserMappings) |
| `ag***@tricab.com` | ACTIVE | **PROVEN** desc_id=`3604` (email local → descript type 36; ~1932 historical quotes) |
| `ma***@tricab.com` | ACTIVE | PROVEN |
| `ro***@tricab.com` | ACTIVE | PROVEN |
| `sa***@tricab.com` | ACTIVE | PROVEN |
| `tr***@tricab.com` | ACTIVE | PROVEN |

ADMIN role includes `QUOTE.CREATE` + `CUSTOMER.READ` (price-as capability).

## I. Selected first-write actor

**Selected:** active ADMINISTRATOR `ag***@tricab.com`  
- Azure `UserId`=7  
- `PublicId`=`E7575FE8-072F-420F-831D-ABBF696FB3F3`  
- Prefer this over modifying Thilan identity/legacy data.

Thilan is **not** required for first write. If Thilan must be used later: add approved `migration.LegacyStaffUserMappings` + matching `descript` desc_type=36 login — **do not invent desc_id**; stop for approval before insert.

## J. Operator mapping

**PROVEN** (selected actor)

Mechanism: `AzureLinkedDescriptQuoteLegacyOperatorIdResolver`  
→ email local-part uniquely equals `descript.desc_code` where `desc_type=36`  
→ `desc_id=3604`

Live resolver class confirmed in DI.

## K. Selected customer

**NOT READY**

Azure `customer.Customers` currently exposes only seed rows (`MEL-ELEC-001` / `SYD-IND-001` / `BNE-ENR-001`, legacy ids 900001–900003) which **do not exist** in legacy `tc.cust` (legacy cust count ≈ 29003).

Need an active Azure customer whose `LegacyCustomerId` exists in `tc.cust` (AUD/AU preferred) before Phase 4C cart build.

## L. Selected product

**Legacy mapping candidate:** `XL-PAXN/1C25OR`  
**Azure catalogue:** `product.ProductCodes` / `ProductVariants` have **0** `XL-PAXN*` / slash primary codes in this non-prod DB.

So:

- Legacy line semantics: **PROVEN** (see M)
- Commerce cart SKU availability: **NOT READY** until a catalogue SKU exists whose authoritative code maps to a simple-cable cata (or cart carries that code)

## M. Legacy line mapping

| Field | Value |
|-------|-------|
| cata | `XL-PAXN/1C25OR` (authoritative code; not title) |
| catdt | `1615` |
| col | `4008` (legacy create hardcode / Black; matches RO samples) |
| pm | `1501` (METRES pack-matrix) |

**PM IS NOT MARGIN: YES**

qty/qotqty: RO samples show equal totals (e.g. 400/400); mapper uses `packCount × length` for simple cable.

## N. Pricing readiness

**BLOCKED** for end-to-end cart fixture (no Azure catalogue SKU + no legacy-linked customer).  
Legacy line value mapping path remains server-reprice-only when a cart exists.

## O. Cart fixture

**NOT READY**

Do not create a persistent cart in this phase. Phase 4C must first:

1. Provision WRITE_* + correlation  
2. Resolve Azure customer↔legacy cust  
3. Resolve Azure variant/code for simple cable  
4. Create OPEN cart with one line + reprice  

Exact cart creation remains the existing Commerce cart API/workflow (no quote submit).

## P. Full preflight

With current env (flag OFF, WRITE_* unset, Null correlation, selected actor + XL fixture customerPublicId placeholder):

| Field | Value |
|-------|-------|
| infrastructureReady | **false** |
| domainReady | **false** (customer/catalogue fixture gaps; operator for selected actor would be READY when wired live) |
| writeEnabled | **false** |
| readyForControlledEnablement | **false** |

**Expected final target (after blockers cleared):**  
`true / true / false / true`

Preflight result model updated to expose:

`infrastructureReady`, `domainReady`, `writeEnabled`, `readyForControlledEnablement`  
(`WRITE_FLAG` status = `OFF_INTENTIONALLY` when disabled).

## Q. First-write runbook

**CREATED: YES**  
**Path:** `docs/TRICAB_CREATE_QUOTE_PHASE4C_FIRST_WRITE_RUNBOOK.md`

## R. Abort conditions

Documented in runbook. Any abort → **ZERO quote INSERT**. Includes wrong suffix/DB, unresolved operator, unauthorized customer, TBC/unresolved cost, unsupported line, correlation/idempotency failure, multi-line/special/tqote unexpected.

## S. Tests/results

```text
./vendor/bin/phpunit --filter 'QuoteOrder|QuoteWrite|SaveQuote|Idempotency|QuoteLegacy|QuoteWritePreflight'
Tests: 83, Assertions: 301, Failures: 0

./vendor/bin/phpunit --filter 'CustomerPricing|Cart|MeEndpoint'
Tests: 36, Assertions: 162, Failures: 0

Combined broader Identity-inclusive filter earlier: 392 tests, 1306 assertions, 0 failures (1 skipped)
```

## T. POST /quotes

**NOT REGISTERED**

## U–W. `_dev` quote writes

**tqoth_dev / tqoti_dev / tqote_dev: 0**

## X. Operational quote writes

**0**

## Y. Write flag end state

**false**

## Z. Production changes

**NONE**

## AA. Web/mobile changes

**NONE**

## AB. Commit SHA

`6ea582319c2a6aeed9b4653580989e9e64aff820`
## AC. Remaining blockers

1. Provision dedicated `QUOTES_ORDERS_LEGACY_WRITE_*` (NOT READY)  
2. Apply `quote_write_correlation_dev` (MYSQL DEV CORRELATION APPLY REQUIRED)  
3. Azure customer with `LegacyCustomerId` present in `tc.cust`  
4. Azure catalogue SKU / cart line whose code maps to simple-cable cata (e.g. `XL-PAXN/1C25OR` absent from Azure ProductCodes)  
5. Keep write flag OFF until Phase 4C enable session  

Actor operator mapping is **no longer** a blocker for the selected ADMINISTRATOR.

---

# READY FOR CONTROLLED FIRST NON-PROD WRITE:

**NO**

Concrete remaining blockers: **AC.1–AC.4** (AC.5 intentional).
