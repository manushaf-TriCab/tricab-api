# TRICAB_CREATE_QUOTE_PHASE4C1_REPORT

## A. Repository checkpoint

| Item | Value |
|------|-------|
| Base HEAD (start) | `5bac83d637a2ee12fdc236924a9aaeae570a95a6` |
| Excluded from commit | `.gitignore`, `bin/*` |
| Push | **NONE** |
| Quote INSERT executed | **NONE** |
| `POST /api/v1/quotes` | **NOT REGISTERED** |

## B. Role/permission matrix

| ROLE | LEGACY CREATE CAPABILITY | CUSTOMER.PRICE_AS | CUSTOMER.READ | QUOTE.READ | QUOTE.CREATE CURRENTLY | QUOTE.CREATE SHOULD EXIST? | EVIDENCE |
|------|--------------------------|-------------------|---------------|------------|------------------------|----------------------------|----------|
| ADMINISTRATOR | Yes (staff / TriCab Admin) | Via admin catalog | Yes (seed) | Yes (seed) | **Yes** (`seed-admin-business-module-permissions`) | **Yes** | Admin seed maps QUOTE.* to ADMINISTRATOR; memory catalog includes QUOTE.CREATE for Thilan smoke user |
| CUSTOMER_ADMIN | Yes (portal own contact) | N/A (own customer) | Yes | Yes | **Yes** (`seed-customer-roles-and-permissions`) | **Yes** | Portal create = linked customers only |
| CUSTOMER_USER | History only | No | Yes | Yes | **No** | **No** | Seed grants QUOTE.READ only |
| EMPLOYEE | Yes historically (`is_staff` / QuoteManagement create) | Often yes for price-as staff | Often yes | Often yes | **No** (not in EMPLOYEE seed) | **Eventually yes** for staff creators — **not blanket-grant in 4C1** | Legacy create path is staff/`isTricab`, not Identity role name; Identity EMPLOYEE lacks QUOTE.CREATE today |
| GUEST | No | No | No | No | No | No | No create path |

**Do not blanket-grant EMPLOYEE.** First write can use an ADMINISTRATOR (or CUSTOMER_ADMIN portal) actor that already has QUOTE.CREATE.

## C. QUOTE.CREATE change required

**NO** for Phase 4C first-write actor selection.

- Role change: none for ADMINISTRATOR / CUSTOMER_ADMIN
- Applied: **NO** (no Identity DB write)
- Optional later (not blocking first write if ADMINISTRATOR used): additive `EMPLOYEE` ← `QUOTE.CREATE` seed, non-prod only, with rollback = delete RolePermission row

## D. First-write actor

| Field | Value |
|------|-------|
| Preferred actor class | Non-prod **ADMINISTRATOR** with `QUOTE.CREATE` + `CUSTOMER.PRICE_AS`/`CUSTOMER.READ` |
| Memory smoke identity | `thilanh@tricab.com` (DevelopmentUserManagementCatalog) already lists QUOTE.CREATE |
| Azure non-prod | Use existing ADMINISTRATOR assignment — do **not** invent credentials |
| Operator | Must resolve to `descript.desc_id` for `tqoth.sa_op` (see F). Resolver still `NullQuoteLegacyOperatorIdResolver` in DI — **blocker** until proven id or controlled override for harness |

## E. First-write customer

| Requirement | Status |
|-------------|--------|
| Active non-prod customer with `legacyCustomerId` | Required at execute time (read-only select) |
| Supported currency | AUD (proven) / NZD / USD mappers exist |
| Avoid ZZCUT / ZZDEL300 / ZZDISC / TBC | Scope = one simple cable |
| Fixture customer | Chosen at Phase 4C execute from live non-prod customer directory — **not mutated in 4C1** |

## F. Legacy operator mapping

Proven from `QuoteManagement::createNewQuote` + RO samples:

| Field | Staff-created | Customer-created (portal) | Source |
|-------|---------------|---------------------------|--------|
| `sa` | Office id (default **7**) | 7 | Header default |
| `sa_op` | `$user->getUserId()` when `isTricab()` | `$user->getRepDetails()->getUserId()` | **descript.desc_id** (joined as rep) |
| `cus` | Customer cust.id | Customer cust.id | `cust` |
| `cus_op` | Recipient contact id | Portal contact id | `contacts.contact_id` |
| `cus_gen` | `null` | `1` | Flag |

RO samples: staff headers have `cus_gen=null` and `sa_op` ∈ descript; portal headers have `cus_gen=1` with `sa_op` = assigned rep descript id (e.g. AJITHT/FELICITYB).

Email/login does **not** map 1:1 without UserManagement/`descript` resolution. Azure Identity → `descript.desc_id` resolver remains **unproven in DI** (`NullQuoteLegacyOperatorIdResolver`).

## G. Legacy line parity matrix

Evidence: RO `tqoti` cable/accessory/ext/TBC samples + PersistQuoteDraftMapper.

| Field | LEGACY VALUE (examples) | MEANING | NEW SOURCE | TRANSFORM | PARITY |
|-------|-------------------------|---------|------------|-----------|--------|
| cata | `LP-XXXA/1C185EA`, `G08-…` | Product code | Variant / legacy product code | Direct | **MATCH** (when mapping resolver supplies code) |
| catdt | `1615` cable, `1660` accessory | Category type | Proven category map | Direct int | **PROVEN TRANSFORM** for cables; accessories out of first-write scope |
| col | `4008` | Colour descript | Variant colour | Direct | **PARTIAL** (often 4008 on cables; must come from mapping) |
| qty | packs / pack count | Pack count | Cart quantity | Decimal | **MATCH** |
| pm | `1501` cable, `1513` accessory | **Pack-matrix descript id** (NOT margin %) | `packMatrixId` on draft line | Direct | **PROVEN TRANSFORM** (code fixed in 4C1; was wrongly margin) |
| nprc | unit sell | Net unit sell | Server reprice | Money | **MATCH** when priced |
| net | line net | Line net GST-excl | qty×units×nprc | Money | **MATCH** |
| cprc / cval | cost unit / cost value | Cost | Diagnostic reprice | Money | **MATCH** when cost present |
| qotqty | length / units | Units or metres | Cart length | Decimal | **MATCH** for cable |
| ref | often empty | Line reference | Optional | Direct | **NOT REQUIRED FOR FIRST WRITE** |
| tqote | pk/qty/comments | Pack/reel extensions | Extended lines | Insert | **NOT REQUIRED FOR FIRST WRITE** (0 rows) |

## H. Supported first-write line scope

**ONE simple cable product** (`catdt=1615`):

- known product code + colour + `pm=1501`
- resolved sell + cost
- no pack/reel `tqote`
- no ZZCUT / ZZDEL300 / ZZDISC / TBC
- currency AUD (NZD/USD mapper-supported)

If Azure→legacy product mapping is missing at execute time → READY remains NO.

## I. _dev schema parity

RO metadata probe (`bin/phase4c1-readonly-dev-parity-probe.php`, SELECT/SHOW only):

| Pair | Cols | Type/Null/Default diffs | Engine | Indexes |
|------|------|-------------------------|--------|---------|
| tqoth / tqoth_dev | 22/22 | **0** | InnoDB/InnoDB | 1/1 |
| tqoti / tqoti_dev | 16/16 | **0** | InnoDB/InnoDB | 1/1 |
| tqote / tqote_dev | 5/5 | **0** | InnoDB/InnoDB | 1/1 |

AI counters differ (expected). `tqoth_dev` row count observed: 159.

**PARITY: MATCH**

## J. _dev side-effect assessment

**_DEV OPERATIONAL SIDE EFFECT RISK: LOW**

Evidence:

- Legacy `QuoteManagement` sets `devTable="_dev"` when host ≠ `www.tricab.com` — non-prod tc-web already targets `*_dev`
- NetSuite / 4JS import (`template-4js-import.php` → `netsuiteAPI(..., 'tqoth'|'tqoti')`) operates on operational table names, not `*_dev`
- Reporting templates inspected use other `*_dev` product tables; no evidence NetSuite consumes `tqoth_dev`

## K. Write credential readiness

| Config | Status |
|--------|--------|
| `QUOTES_ORDERS_LEGACY_WRITE_ENABLED` | unset / false (flag off) |
| WRITE DSN/HOST/DATABASE | **UNSET** in local `.env` |
| WRITE USER/PASSWORD | **UNSET** |
| `QUOTES_ORDERS_LEGACY_WRITE_TABLE_SUFFIX` | unset (must be `_dev` when enabling) |
| Reuse `LEGACY_TC_*` | **Forbidden** (config does not fall back) |

**Provisioning required** before first write. Credential probe not executed (no write credentials present). Secrets not printed.

## L. _dev hard safety guard

`NonProductionQuoteWriteSafety::assertDevTableSuffixRequired`:

- write enabled AND suffix ≠ `_dev` → fail closed
- empty suffix rejected for Phase 4C
- enforced in `TransactionalLegacyQuoteWriteRepository` + ContainerFactory write wiring + `SaveQuoteService`
- tests: empty suffix rejected

## M. Idempotency schema review

`database/scripts/development/create-quote-write-idempotency-schema.sql`

| Aspect | Assessment |
|--------|------------|
| Table | `quote.QuoteWriteIdempotency` |
| PK | `IdempotencyId` UNIQUEIDENTIFIER |
| Actor/Op/Key | UNIQUE (`ActorKey`,`Operation`,`IdempotencyKey`) |
| PlatformOperationId | UNIQUE |
| RequestHash / fingerprint | Present |
| State | IN_PROGRESS / COMPLETED / FAILED check |
| QuoteId / CartPublicId | Present |
| Timestamps | CreatedAtUtc / UpdatedAtUtc |
| Concurrency | Unique key → loser loads existing (store `tryAcquire`) |

**Safe additive.** Not applied.

## N. Idempotency migration

**Applied: NO**

Requires non-prod Azure apply approval.

Also required (new): MySQL `tc.quote_write_correlation_dev` — **NOT APPLIED**.

## O. Idempotency store

Implemented:

- `AzureSqlQuoteWriteIdempotencyStore` (Azure SQL)
- `InMemoryQuoteWriteIdempotencyStore` (tests)
- `NullQuoteWriteIdempotencyStore` (default DI — migration unapplied)

Operations: find / tryAcquire / markCompleted / markFailed / findByPlatformOperationId.

Not wired to HTTP.

## P. PlatformOperationId strategy

1. Generated in `SaveQuoteService` prepare (`UUID`)
2. Stored on Azure idempotency row at acquire
3. Written to MySQL `quote_write_correlation(_dev)` **in the same TX** as `tqoth` insert
4. **Not** written to `cref` / customer-visible comments

Without correlation table applied, crash window after MySQL commit remains unrecoverable in real MySQL.

## Q. Recovery probe

`QuoteWriteCorrelationRepository::findQuoteIdByPlatformOperationId`

- In-memory + PDO implementations
- Queried on retry when Azure state is IN_PROGRESS
- Maps to exactly one quote id (PK on platform_operation_id; unique quote_id)

## R. Cross-database crash recovery test

`SaveQuoteCrashRecoveryTest`:

1. acquire → MySQL persist+correlation → complete
2. force Azure back to IN_PROGRESS (simulate crash before complete)
3. retry same key → recovery probe → same quote id
4. **no second INSERT**
5. cart `markSubmitted` failure does not duplicate quote

**Mechanism implementable against real stores once migrations + write creds exist.**

## S. Validity rule

RO sample n=40 recent quotes: **40/40 = +2 days**.

Code: `ProvisionalPlusTwoDayQuoteValidityPolicy` matches `createNewQuote` (`strtotime("+2 day")`).

**Classification: PROVISIONALLY PROVEN CURRENT BEHAVIOUR**

(Older +7 path remains historical; no silent policy change.)

## T. GST finding

PDF/email: **“ALL PRICES EXCLUSIVE OF GST/VAT”** (`quote-pdf-format.php`, `email/quote.php`).

`tnet` / line `net` remain GST-exclusive. **Does NOT block Save Draft.** No tax logic change in 4C1.

## U. Cart markSubmitted

Wired after idempotency complete / recovery:

`MySQL confirmed → idempotency COMPLETED (or recovered) → tryMarkCartSubmitted`

Cart failure is swallowed; retry must not create a second quote (tested). Cart has no quote FK yet; quote id retained in Azure idempotency + MySQL correlation.

## V. SaveQuote state machine

```
VALIDATING → PRICING → IDEMPOTENCY_ACQUIRED → PERSISTING_LEGACY
  → LEGACY_COMMITTED → IDEMPOTENCY_COMPLETED → CART_SUBMITTED → COMPLETED
```

| Boundary | Retry semantics |
|----------|-----------------|
| Before LEGACY_COMMITTED | Safe to retry; markFailed allowed |
| LEGACY_COMMITTED, IDEMPOTENCY_COMPLETED failed | Probe correlation → complete; **never** second insert |
| IDEMPOTENCY_COMPLETED, CART_SUBMITTED failed | Return quote; retry markSubmitted only |

## W. First-write fixture

| Field | Value |
|-------|-------|
| reference | `PHASE4C1-DEV-FIXTURE` |
| line scope | 1 simple cable |
| expected status | draft (`1705` staff / `1710` portal) |
| expected lines | 1 |
| expected ext | 0 |
| expected TBC | none |
| suffix | `_dev` |
| **Executed** | **NO** |

## X. Expected _dev rows

| Table | Rows |
|-------|------|
| tqoth_dev | +1 |
| tqoti_dev | +1 |
| tqote_dev | +0 |
| quote_write_correlation_dev | +1 |

Verification (after Phase 4C write — **not run now**):

```sql
SELECT id,st,cus,sa_op,cy,cref,tnet,dvalid,cus_gen
FROM tc.tqoth_dev WHERE cref='PHASE4C1-DEV-FIXTURE' ORDER BY id DESC LIMIT 1;

SELECT * FROM tc.tqoti_dev WHERE qot = :quoteId;
SELECT * FROM tc.tqote_dev WHERE qoti IN (SELECT id FROM tc.tqoti_dev WHERE qot=:quoteId);
SELECT * FROM tc.quote_write_correlation_dev WHERE quote_id=:quoteId;
```

## Y. Tests/results

```
./vendor/bin/phpunit --filter 'QuoteOrder|QuoteWrite|SaveQuote|Idempotency'
Tests: 72, Assertions: 253, Failures: 0

Broader (CustomerPricing|Cart|MeEndpoint|…):
Tests: 107, Assertions: 410, Failures: 0
```

Covered: `_dev` suffix gate, empty/prod suffix reject, authz, idempotency acquire/replay/conflict, crash recovery, cart failure isolation, disabled flag zero SQL, no POST route (unchanged).

## Z. HTTP write route

**NONE** (expected)

## AA. Database quote writes

**NONE** (expected) — operational and `_dev`

## AB. Production changes

**NONE** (expected)

## AC. Web/mobile changes

**NONE** (expected)

## AD. Commit SHA

Local commit: `feat(quotes): resolve nonprod quote write blockers`  
Resolve exact SHA with `git log -1 --format=%H` on this branch (not pushed).

## AE. Remaining blockers

1. Non-prod **write credentials** not provisioned/validated
2. Azure **idempotency migration** not applied
3. MySQL **correlation_dev migration** not applied
4. Live **`QuoteLegacyOperatorIdResolver`** still Null (Azure user → descript.desc_id unproven in DI)
5. Live **product→cata/catdt/col/pm mapping** not wired beyond VariantCodeOnly test map
6. Explicit enable of `QUOTES_ORDERS_LEGACY_WRITE_ENABLED=true` + suffix `_dev` (deliberate)

---

# READY FOR FIRST NON-PROD WRITE:

**NO**

Blockers: AE.1–AE.6 above.

Do not perform the first write. Do not register POST /quotes. Do not push.
