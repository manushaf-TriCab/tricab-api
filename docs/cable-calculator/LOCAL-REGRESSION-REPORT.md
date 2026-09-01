# TRICAB CABLE CALCULATOR — LOCAL REGRESSION REPORT

Date: 2026-09-01  
Scope: Local regression only (no Azure / production deploy)

---

## A. Root Cause

**Product resolver DI isolation failure** during Phase 5 / 5A product-module wiring:

1. `AzureSqlCableCalculatorProductResolver` was constructed with an **eager** `$c->get(PDO::class)` while resolving the calculate action.
2. Paused Azure SQL (`40613`) + LoginTimeout/retry exceeded PHP `max_execution_time` (30s) → **fatal** in `AzureSqlPdoFactory` → empty/failed HTTP response.
3. React surfaced only the generic: “Unable to generate calculation…”.

Classification of original failure: **PRODUCT_RESOLVER_REGRESSION** (engineering engine/dataset/mapper were fine).

Secondary config hardening: calculator DI/`engineVersion` used `getenv()` only; immutable Dotenv loads into `$_ENV`/`$_SERVER` and does not `putenv`, so a fresh PHP process could miss `.env` `CABLE_CALCULATOR_ENGINE=native`.

---

## B. Failure Layer

| Layer | Role |
| --- | --- |
| Frontend | Symptom only (generic error) |
| API | Request died during DI / PDO connect |
| Mapper | Not the regression |
| Engine | Not the regression |
| Dataset | Not the regression |
| **Resolver** | **Root cause** (eager Azure SQL) |
| Configuration | Contributing (`getenv` vs `.env`) |

---

## C. Failed Request (pre-fix evidence)

| Item | Value |
| --- | --- |
| URL | `POST http://127.0.0.1:8080/api/v1/cable-calculator/calculate` |
| Frontend base | `VITE_API_BASE_URL=http://127.0.0.1:8080` (correct local target) |
| Pre-fix | OPTIONS 204, then fatal: `Maximum execution time of 30 seconds exceeded` in `AzureSqlPdoFactory.php` while opening PDO for product resolver |
| Safe API error | Uncatchable fatal → no JSON body (UI generic message) |

Post-fix same request: **HTTP 200**, `pdo_ms=0`, engineering complete.

---

## D. Fix

Smallest isolation + config fixes only (no formula / dataset / fixture changes):

1. **`ContainerFactory.php`**: default `CABLE_CALCULATOR_PRODUCT_RESOLVER=null` (`NullCableCalculatorProductResolver`); Azure SQL resolver uses **lazy** PDO callable.
2. **`AzureSqlCableCalculatorProductResolver.php`**: lazy `pdoProvider`; null PDO → `CATALOGUE_UNAVAILABLE` (no sizing impact).
3. **`Environment::value()`** + calculator DI/serializer: read `$_ENV`/`$_SERVER` as well as `getenv`.
4. **`.env`**: `CABLE_CALCULATOR_ENGINE=native`, `CABLE_CALCULATOR_PRODUCT_RESOLVER=null`.
5. **`useCableCalculator.ts`**: in `import.meta.env.DEV` only, surface safe codes (`CABLE_CALCULATION_VALIDATION`, `API_UNAVAILABLE`, HTTP status); production text stays friendly.

---

## E. 1 A / 100 m

| | Expected | Actual |
| --- | --- | --- |
| 90 XL | xa=6, earth=2.5, cr=49, vd=0.13, XL-PAXN/1C6OR | Match |
| 110 KL | xa=6, earth=2.5, cr=61, vd=0.13, KL-PAXN/1C6OR | Match |

Direct engine: **PASS**  
API (`8080` / fresh `8081`): **PASS**  
Browser (Vite → `127.0.0.1:8080`): **PASS** (“Calculations generated.”)

---

## F. 200 A Anchor

**PASS** — XL 70 mm² / 241 A / 2.85%; KL 70 mm² / 292 A / 2.84%.

---

## G. Parity

| | |
| --- | --- |
| Suite | `NativeOracleParityTest` |
| Total | 108 |
| Passed | 108 |
| Failed | 0 |

Expected: **108 / 108**

---

## H. Product Resolver Isolation

**PASS** — null / unavailable PDO → `CATALOGUE_UNAVAILABLE`; engineering unchanged (xa=6, cr=49).

---

## I. Verdict

**LOCAL_CALCULATOR_RESTORED**

---

## Runtime confirmation (safe)

| Key | Value |
| --- | --- |
| APP_ENV | development |
| CABLE_CALCULATOR_ENGINE | native |
| Resolved engine | NativeCableCalculatorEngine |
| CABLE_CALCULATOR_PRODUCT_RESOLVER | null |
| Dataset | `resources/cable-calculator/v1/` — 557 ratings, 9338 cableSpecs; hashes match manifest; `baselineLabel=LOCAL_LEGACY_PARITY_BASELINE` |
| API metadata | `engineVersion=native-v1`, `datasetVersion=legacy-tc-2026-08`, `parityBaseline=LOCAL_LEGACY_PARITY_BASELINE` |

Earth defaults still sent: `earthResistanceMode=auto`, `mftime=1`.

---

## STOP

Do not deploy Azure.  
Do not deploy production.  
Do not resume Phase 5 until this local result remains restored.
