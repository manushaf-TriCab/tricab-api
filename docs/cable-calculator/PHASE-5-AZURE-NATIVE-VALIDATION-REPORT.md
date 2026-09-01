# TRICAB CABLE CALCULATOR — PHASE 5
# AZURE NATIVE END-TO-END VALIDATION REPORT

Date: 2026-09-01  
Operator approval: Azure non-production deployment authorised  
**Verdict: AZURE_NONPROD_TARGET_MISSING**

---

## A. Safety

| Item | Value |
| --- | --- |
| Environment | Local development + Azure subscription discovery attempt |
| APP_ENV | `development` (tricab-api `.env`) |
| IS_PRODUCTION | **NO** |
| Azure subscription | `tricab` / `bff9ac28-f4e6-4172-a926-5b55e3de0b05` |
| Tenant | TRICAB (AUSTRALIA) PTY LTD |
| Azure user | thilanh@tricab.com |
| Production changes | **None** |
| DB writes | **None** |
| Legacy writes | **None** |

Safety gate: production target not selected. No production deploy attempted.

---

## B. Azure Targets

### Discovery performed

1. Repository inspection: **no** `.github/workflows`, no App Service Bicep/Terraform, no Dockerfile for tricab-web/tricab-api deploy in these repos.
2. Prior staging report (`tricab-api/docs/cable-calculator-staging-validation-report.json`): explicitly recorded *“no Azure App Service staging slot deployed in this run”*.
3. Historical non-prod host check ([Application execution](90a3f7e6-43f5-43ff-8a5f-a4260c2ab2ad)): `uat.tricab.com` / `dev.tricab.com` → **403 Site Disabled**; default `*.azurewebsites.net` hostnames did not resolve as usable TriCalc targets.
4. This session: Azure CLI account cache readable; live `az webapp list` / host probes blocked by agent network (proxy CONNECT 403 to Microsoft/login and public hosts). Could not enumerate live App Services from this environment.

| Target | Result |
| --- | --- |
| Web App Service | **Not found / not usable** |
| Web slot | N/A |
| Web hostname | N/A |
| API App Service | **Not found / not usable** |
| API slot | N/A |
| API hostname | N/A |

Azure SQL non-prod (`tricab-mel` / `tc`) exists for Product Module data access from local API, but that is **not** a deployed TriCalc web/API App Service runtime.

**Per Phase 5 §4: do not create or guess a production-like target.**

---

## C. Deployed Versions

No Azure deployment performed.

Local working copies at Phase 5 stop:

| Repo | Commit | Note |
| --- | --- | --- |
| tricab-web | `c74abed` | feature/visual-product-page-editor (local calculator UI changes present) |
| tricab-api | `b38ead3` + local Phase 4/5 calculator commits unpushed as applicable | path package `core/` mirrored to vendor |
| tricab-core | `6dfb529` + local calculator engine/presenter/resolver updates | synced into `tricab-api/core` |

---

## D. Native Runtime

| Item | Local (approved baseline) | Azure non-prod |
| --- | --- | --- |
| Selected engine | `NativeCableCalculatorEngine` when `CABLE_CALCULATOR_ENGINE=native` | **Not deployed** |
| engineVersion | `native-v1` | N/A |
| datasetVersion | `legacy-tc-2026-08` | N/A |
| parityBaseline | `LOCAL_LEGACY_PARITY_BASELINE` | N/A |
| LegacyCliTriCalcEngine required | No (native path) | N/A |
| calculate-json.php required | No (native path) | N/A |
| WordPress required | No | N/A |
| Legacy MySQL required | No | N/A |
| tc-web filesystem required | No (native path) | N/A |

**NATIVE_RUNTIME_INDEPENDENT:** **NOT PROVEN ON AZURE** (no Azure host). Locally proven for native mode.

---

## E. Dataset Integrity

Approved packaged dataset (local artifact to be deployed when target exists):

| Item | Value |
| --- | --- |
| ratings rows | 557 |
| ratings hash | `8c9725037531fb90fc8cadd084e3a8dc65e741a99511756ae1f4d8b19f813704` |
| cableSpecs rows | 9338 |
| cableSpecs hash | `e63cac808caf7472f349b2cee5e78d65d0ba18e45ef97be6fb382282d6a8c178` |
| manifest match | YES vs `resources/cable-calculator/v1/manifest.json` |

Azure runtime hash verification: **NOT RUN** (no deploy).

---

## F. Canonical 1 A / 100 m Case

### Azure API
**NOT RUN** — no Azure API hostname.

### Local native (approved baseline, reconfirmed)

90°C XL: xa=6, earth=2.5, cr=49, vd=0.13, loop=1432.1, frA=0.86, XL-PAXN/1C6OR, LP-XXXD/1C2.5EA  
110°C KL: xa=6, earth=2.5, cr=61, vd=0.13, loop=1432.1, frA=0.79, KL-PAXN/1C6OR, LP-XXXD/1C2.5EA  
Drop Voltage: 0.52 V  

Azure Result: **N/A**

---

## G. 200 A Anchor

Azure: **NOT RUN**  
Local approved expectation remains:

- 90 XL: xa=70, earth=25, cr=241, vd=0.29, XL-PAXN/1C70OR, LP-XXXN/1C25EA  
- 110 KL: xa=50, earth=16, cr=233, vd=0.41, KL-PAXN/1C50OR, LP-XXXN/1C16EA  

---

## H. Full Azure Regression

| Metric | Value |
| --- | ---: |
| Total | 0 (Azure) |
| Passed | 0 |
| Failed | 0 |
| Blocked | 108 (no Azure endpoint) |

**Pre-deploy local oracle gate:** NativeOracleParityTest **108/108 PASS** (re-run this session). No `BLOCKED_LOCAL_PARITY_REGRESSION`.

---

## I. Spot / Boundary Tests

| Suite | Azure | Local |
| --- | --- | --- |
| Spot loads/voltages | NOT RUN | Previously 13/13 PASS (Phase 4) |
| Boundary / parallel | NOT RUN | Existing fixture suite local-only |

---

## J. Browser E2E

| Item | Value |
| --- | --- |
| Actual Azure UI tested | **NO** |
| API request confirmed | NO |
| Screenshot evidence | NONE |
| Result | **NOT PERFORMED** |

Limitation stated explicitly: no Azure non-prod TriCalc UI/API target available to this agent; browser E2E cannot be claimed.

---

## K. Product Resolution

| Metric | Value |
| --- | --- |
| Unique calculator codes | **17,995 unique** (proven: entries == unique set size) |
| Active unique | 17,928 |
| Earth unique | 67 |
| Resolved (authoritative non-prod DB) | **NOT MEASURED** — prior 0/17995 was `lookupMode=no-product-db` |
| Not migrated | Pending live DB audit |
| Ambiguous / Inactive / errors | Pending live DB audit |

Coverage JSON remains a **candidate code inventory**, not Product Module completeness proof until Azure non-prod DB lookup is run online.

---

## L. Current Catalogue ERROR Root Cause

Observed local UI statuses like `ERROR` for:

- XL-PAXN/1C6OR  
- KL-PAXN/1C6OR  
- LP-XXXD/1C2.5EA  

**Root cause:** With `USER_REPOSITORY=azure-sql`, PDO is present, so resolver attempted `[product].[ProductCodes]` exact lookup. Query failure (typically missing/unavailable catalogue objects or SQL exception) was previously mapped to bare `ERROR`.

**Classification after Phase 5 clarification fix (local, non-engineering):**

| Condition | Status |
| --- | --- |
| Exact code absent | `NOT_MIGRATED` |
| PDO null / DB unreachable / invalid object | `CATALOGUE_UNAVAILABLE` |
| Unexpected resolver failure | `RESOLVER_ERROR` |
| Multiple products | `AMBIGUOUS` |
| Inactive record | `INACTIVE` |

UI now shows neutral “Product catalogue record not yet available” / “Catalogue lookup unavailable” — does **not** imply calculation failure.

Engineering product codes unchanged.

---

## M. Product Resolver Isolation

Local tests (`ProductResolutionIndependenceTest`):

| Case | Result |
| --- | --- |
| Resolver null/disabled | Calculation COMPLETE; statuses `CATALOGUE_UNAVAILABLE` |
| Resolver fake RESOLVED vs unavailable | Engineering `bands` **identical** |
| Result | **PASS** (local) |

Azure isolation: **NOT RUN**.

---

## N. Error Handling

Azure validation cases: **NOT RUN**.  
Local contract: 422 `CABLE_CALCULATION_VALIDATION` for Erh=0 remains in place. No estimate fallback on native path.

---

## O. Performance

Azure latency: **NOT MEASURED** (no host).

---

## P. Concurrency

Azure concurrency smoke: **NOT RUN**.

---

## Q. Observability

Not validated on Azure. Local API response includes `engineVersion`, `datasetVersion`, `parityBaseline`, `calculationStatus`, `productResolution`.

---

## R. Remaining Features

| Feature | Classification |
| --- | --- |
| Earth UI | PARTIAL (mode field present; not full legacy Earth UI) |
| Preview | PARTIAL (`isPreview` supported) |
| History | NOT_IMPLEMENTED |
| Compare | PLACEHOLDER / PARTIAL (inputs exist; legacy compare behaviour not Phase 5) |
| Options endpoint | NOT_IMPLEMENTED (only `POST .../calculate` action present) |

---

## S. Runtime Independence

| Dependency | Azure native path |
| --- | --- |
| WordPress | Not proven on Azure (no deploy) |
| Legacy CLI | Not proven on Azure |
| Legacy MySQL | Not proven on Azure |
| Local filesystem | Not proven on Azure |

Local native path is independent of those; Azure packaging/runtime proof is blocked by missing target.

---

## T. Rollback Readiness

| Item | Value |
| --- | --- |
| Previous Azure version | N/A — no Phase 5 deploy |
| Rollback method | N/A until App Service/slot exists (typical: swap slot / redeploy prior commit) |

---

## U. Remaining Risks

1. **No Azure App Service non-prod host** for tricab-web / tricab-api TriCalc stack.
2. Historical UAT/dev hosts disabled / not usable for this product.
3. Agent environment currently cannot refresh Azure ARM tokens (proxy 403) to re-list resources live.
4. Product Module exact-code coverage against live non-prod DB still unmeasured.
5. Until Azure deploy exists, `NATIVE_RUNTIME_INDEPENDENT=YES` cannot be granted under Phase 5 rules.

---

## V. VERDICT

**AZURE_NONPROD_TARGET_MISSING**

### Success-gate checklist

- [ ] Actual Azure NON-PRODUCTION environment tested  
- [x] Production untouched  
- [ ] Correct web/API/core builds deployed  
- [ ] Native engine proven as Azure runtime  
- [ ] Dataset hashes verified on Azure  
- [ ] No WordPress/legacy CLI/MySQL/tc-web runtime dependency on Azure  
- [ ] 108/108 Azure API fixtures  
- [ ] Browser E2E on Azure  
- [x] Local 108/108 pre-deploy parity still PASS  
- [x] Catalogue ERROR classification clarified (local)  
- [x] Product resolver isolation PASS (local)

---

## STOP

No production deploy.  
No Phase 6 started.

**Next required operator action:** provide or create a **named, existing non-production** Azure App Service (or equivalent) for tricab-api + tricab-web, then re-run Phase 5 from §6 onward.
