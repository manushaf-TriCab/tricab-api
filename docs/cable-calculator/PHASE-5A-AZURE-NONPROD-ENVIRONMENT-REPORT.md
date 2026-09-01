# TRICAB AZURE NON-PRODUCTION ENVIRONMENT REPORT

Date: 2026-09-01  
Phase: **5A — Create / restore Azure non-prod runtime**  
**Verdict: BLOCKED_AZURE_ACCESS**

---

## A. Safety

| Item | Value |
| --- | --- |
| Production touched | **NO** |
| DB writes | **NO** |
| Legacy writes | **NO** |
| IS_PRODUCTION | **NO** (local `APP_ENV=development`; no Azure mutation attempted) |

Azure subscription cache (read-only): **tricab** / `bff9ac28-f4e6-4172-a926-5b55e3de0b05`  
Tenant: TRICAB (AUSTRALIA) PTY LTD / `tricab.com`  
User: thilanh@tricab.com

---

## B. Discovery

### Repository / workflow scan

| Source | Finding |
| --- | --- |
| tricab-web | No `.github/workflows`, no Dockerfile, no Azure IaC |
| tricab-api | No deploy workflows; App Service docs in README / `php-azure-sql-environment.md` |
| Phase 5 report | No staging slot deployed for TriCalc stack |
| Legacy audit / IDE | `dev.tricab.com` PhpStorm deploy mapping (legacy WordPress path) |
| Prior host probe | `uat.tricab.com`, `dev.tricab.com` → **403 Site Disabled** |
| Code/test references | `tricab-web-staging.azurewebsites.net`, `tricab-api-staging.azurewebsites.net`, `tricab-web.azurewebsites.net` (passkey config tests — not verified live) |

### Live Azure ARM discovery (this session)

**Blocked.** `az group list`, `az webapp list`, and token refresh fail:

```
ProxyError: CONNECT tunnel failed: 403 Forbidden → login.microsoftonline.com
```

Cursor agent network cannot complete Azure Resource Manager calls even with cached `az account show`.

Helper added for operator local run:

```bash
bash tricab-api/bin/azure-nonprod-discovery.sh
```

### Existing App Services / slots / UAT

**Not enumerated** — ARM access blocked from this environment.

---

## C. Selected Target

| Field | Value |
| --- | --- |
| Resource group | **TBD** (requires successful `azure-nonprod-discovery.sh`) |
| App Service | **TBD** |
| Slot | **TBD** |
| Hostname | **TBD** |
| Custom hostname | Candidate: `uat.tricab.com` if safely restorable (currently disabled historically) |
| App Service Plan | **TBD** — prefer reuse of existing non-prod plan |

No Azure resource was created or modified in Phase 5A.

---

## D. Deployment Architecture

**Recommended model (from codebase intent):**

| Layer | Approach |
| --- | --- |
| Web | Vite static build (`npm run build`) served from App Service |
| API | PHP 8.3 + Slim (`tricab-api/public`) on same App Service or paired non-prod API app |
| Routing | **Model B preferred:** single public UAT host; browser calls **same-origin** `/api/v1/...` via reverse proxy (nginx/IIS ARR), not a separate public API URL baked into the bundle |
| Core | Bundled via Composer path package `tricab/tricab-core` inside API artifact |

Do **not** bake `127.0.0.1:8080` or `localhost:8080` into production bundles. Use relative API base or UAT origin.

---

## E. Runtime (intended for UAT)

| Setting | Intended value |
| --- | --- |
| PHP | 8.3 + `pdo_sqlsrv`, sqlsrv |
| APP_ENV | `staging` or approved non-prod equivalent (not `development` on public UAT) |
| CABLE_CALCULATOR_ENGINE | `native` |
| DB_AUTH_MODE | `managed-identity` on App Service |
| DEV_AUTH_ENABLED | **false** on internet-accessible UAT |
| Dataset | `resources/cable-calculator/v1/` packaged with API |

---

## F. Source Versions (local working tree — not yet deployed)

| Repo | Branch | Commit | Calculator-related state |
| --- | --- | --- | --- |
| tricab-web | feature/visual-product-page-editor | `c74abed` | Modified calculator UI + docs (uncommitted) |
| tricab-api | feature/legacy-migration-framework | `b38ead3` | Large untracked calculator engine/API/tests/dataset (uncommitted) |
| tricab-core | main | `6dfb529` | Untracked `src/CableCalculator/` (uncommitted) |

**Per Phase 5A §14:** do not deploy untracked working-tree state. Commit + push to explicit non-prod branch before deploy.

---

## G. Dataset (approved baseline — ready to package)

| File | Rows | SHA256 |
| --- | ---: | --- |
| ratings.json | 557 | `8c9725037531fb90fc8cadd084e3a8dc65e741a99511756ae1f4d8b19f813704` |
| cableSpecs.json | 9338 | `e63cac808caf7472f349b2cee5e78d65d0ba18e45ef97be6fb382282d6a8c178` |

Location: `tricab-api/resources/cable-calculator/v1/manifest.json`

---

## H. Health

| Check | Result |
| --- | --- |
| Web (Azure) | **NOT RUN** |
| API (Azure) | **NOT RUN** |
| Calculator endpoint | **NOT RUN** |

Local pre-deploy gate reconfirmed: **NativeOracleParityTest 108/108 PASS**.

---

## I. Calculator Smoke (1 A / 100 m)

Azure: **NOT RUN**

Local native baseline (approved):

| Band | xa | earth | cr | vd | active | earth |
| --- | ---: | ---: | ---: | ---: | --- | --- |
| 90 XL | 6 | 2.5 | 49 | 0.13% | XL-PAXN/1C6OR | LP-XXXD/1C2.5EA |
| 110 KL | 6 | 2.5 | 61 | 0.13% | KL-PAXN/1C6OR | LP-XXXD/1C2.5EA |

Result: **N/A on Azure**

---

## J. Product Resolution

| Item | Status |
| --- | --- |
| Catalogue lookup on Azure | **NOT TESTED** |
| Engineering independent of catalogue | **YES** locally (`ProductResolutionIndependenceTest` PASS) |
| ERROR vs NOT_MIGRATED fix | Local resolver now maps PDO/query failures → `CATALOGUE_UNAVAILABLE` / `RESOLVER_ERROR`; missing code → `NOT_MIGRATED` |

---

## K. Security

| Item | Status |
| --- | --- |
| Production exposure | None attempted |
| Public standalone API | Not recommended; use same-origin `/api` |
| Secrets in repo | None added; `.env` not deployed |
| DEV_AUTH on UAT | Must remain **disabled** |

---

## L. Rollback

| Item | Value |
| --- | --- |
| Previous deployment | N/A — no Phase 5A deploy |
| Rollback method | App Service deployment history / slot swap (once target exists) |

---

## M. Remaining Tasks (before resuming full Phase 5)

1. **Run** `tricab-api/bin/azure-nonprod-discovery.sh` from a host with working `az login` (outside Cursor proxy if needed).
2. **Identify or restore** non-prod App Service (prefer existing disabled UAT over new infra).
3. **Commit & push** calculator changes on explicit branches:
   - tricab-core: native engine + presenter + resolver contracts
   - tricab-api: HTTP layer, dataset, tests, `CABLE_CALCULATOR_ENGINE=native`
   - tricab-web: TriCalc UI parity + product resolution display
4. **Create non-prod deploy workflow** (`workflow_dispatch`) targeting discovered App Service only.
5. **Configure App Service:**
   - `CABLE_CALCULATOR_ENGINE=native`
   - `APP_ENV=staging`
   - `DB_AUTH_MODE=managed-identity`
   - `DEV_AUTH_ENABLED=false`
   - PHP 8.3 + sqlsrv
6. **Deploy** web build with same-origin API routing (`VITE_API_BASE_URL` = UAT origin or empty relative).
7. **Run** health + 1 A / 100 m smoke on Azure hostname.
8. **Resume** full Phase 5 validation suite.

---

## N. Verdict

**BLOCKED_AZURE_ACCESS**

The calculator and approved engineering baseline are ready locally. Phase 5A cannot create, restore, or validate an Azure non-production runtime from this agent environment because Azure Resource Manager calls are blocked (token refresh / proxy 403).

---

## STOP

No production deploy. No Phase 5 full validation.  
**Next operator instruction after unblock:** re-run discovery, deploy to confirmed non-prod target, then **RESUME TRICAB CABLE CALCULATOR PHASE 5 AZURE VALIDATION**.
