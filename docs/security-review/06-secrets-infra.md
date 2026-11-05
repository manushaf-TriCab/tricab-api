# 06 — Secrets, Configuration, CI/CD, Infrastructure and Dependencies

Scope: `/home/user/tricab-api` (80 commits) and `/home/user/tricab-web` (51 commits). Read-only review on 2026-11-05. Nothing was changed, committed or checked out.

## Method and coverage

- Dumped `git log -p --all --full-history` for both repos (237 MB and 41 MB of patches) and regex-scanned every added line for: PEM private keys, JWTs, AWS/GitHub/OpenAI/Anthropic/Google/GOCSPX/Slack/SendGrid/Stripe tokens, Azure AD client secrets (`xxx8Q~`), SAS `sig=`, `AccountKey=`, `DefaultEndpointsProtocol`, connection-string passwords, `url://user:pass@`, `SECRET|PASSWORD|API_KEY|TOKEN=` assignments, `sqlcmd -P`, `mysql -p`, `Bearer …`, publish profiles and `userPWD`. Also listed every file path ever committed (deleted files and sensitive names such as `.env*`, `*.p8`, `*.pfx`, `*.pem`, `*.key`), and checked every historical version of both `.env.example` files.
- **Coverage limit (Info):** both clones are **shallow** (`.git/shallow`). API history is grafted at `0939bb8`, `6165e41` and `f7140fe` (2026-10-22 to 2026-10-27). Web history is grafted at `a3ad78c` and `a51fedf` (2026-10-23). The scan covers the full tree snapshot at each graft root plus every later commit. It does **not** cover the commits before the grafts (roughly API PRs #1–#106 and the earlier web history). **Run gitleaks or trufflehog against the full, unshallowed origin repositories before release.**
- Ran `composer audit --locked` (API), `npm audit --omit=dev` and a full `npm audit` (web, lockfile-only). There is no `core/composer.lock`, so core is covered by the API lock. Inspected the Python requirement files by hand.

**Headline:** no live credential (API key, client secret, password, SAS token, private key or JWT) was found in either working tree or in the available history. Every secret-bearing setting is read from the environment, and `.env`, `.env.*`, `*.p8` and `.azure-cli-*` are git-ignored. The main risks are in CI/CD credential handling, missing HTTP security headers, production-config guardrails and unverified TLS to the legacy MySQL server.

---

## Findings

### F1 — Production deploy uses a long-lived publish profile that any repository writer can probably extract (High, confidence Medium)

- **Location:** `tricab-api/.github/workflows/deploy-production.yml:204-208` (`azure/webapps-deploy@v3` with `publish-profile: ${{ secrets.AZURE_WEBAPP_PUBLISH_PROFILE }}`, app `tc-web`, slot `core`, which serves https://tricab.ai). Related lines: `:37` and `:67` (`environment: production` on the jobs that also run on branch dispatches) and `docs/deployment/production-pipeline.md` ("Dispatch with `deploy=false` … `--ref perf/deployment-pipeline`").
- **Evidence:** The publish profile is a static SCM/Kudu basic-auth credential. It does not expire and is not bound to a branch or workflow. The docs tell people to dispatch the workflow from feature branches, and those runs enter the `production` environment in the `website-revision` and `build` jobs. That only works if the `production` environment has **no "main only" deployment-branch rule**. In that case any collaborator with push access can edit the workflow on a branch, dispatch it and print `secrets.AZURE_WEBAPP_PUBLISH_PROFILE` (masking is trivially bypassed). If the secret is stored at repository level instead, any same-repo `pull_request` run (`pull-request-checks.yml`) can read it too. The deploy itself is gated only by `github.ref == 'refs/heads/main'`. There is no evidence of required reviewers on the environment. The newer `tricab-ai` path already uses OIDC with SCM basic auth off (`:240-300`), which shows the safer pattern is available.
- **Impact:** One compromised developer account or malicious insider can push arbitrary code to production (`tc-web`), bypassing review and branch protection. The App Service holds Graph, SQL, Postmark, OpenRouter and Odoo credentials, so this becomes full data access.
- **Fix:** Move `tc-web` to OIDC (`azure/login` with a federated credential whose subject is `repo:tricab/tricab-api:environment:production`). Disable SCM/FTP basic-auth publishing on `tc-web`. Delete and **reset the publish profile**: run `az webapp deployment list-publishing-profiles` and then reset the credentials, because the current one should be treated as exposed to everyone who has had write access. Restrict the `production` environment to `main` and add required reviewers. Move branch-validation runs to a non-production environment that holds no secrets.

### F2 — Third-party GitHub Actions are pinned to mutable tags in jobs that hold production credentials (Medium, confidence High)

- **Location:** `deploy-production.yml:89` (`shivammathur/setup-php@v2`), `:204` and `:299` (`azure/webapps-deploy@v3`), `:291` (`azure/login@v2`); `.github/actions/setup-api/action.yml:16`. First-party `actions/*@v4|v5|v6` are also tag-pinned throughout both repos (`tricab-web/.github/workflows/pr-checks.yml` too).
- **Impact:** If any of these actions is compromised or a tag is re-pointed (as in the tj-actions/changed-files incident of March 2025), the attacker runs code in the build job, which has the docgen/cablegen deploy keys and `TRICAB_WEB_READ_TOKEN`. In the deploy job they also get the publish profile, and in the staging job an OIDC token that can **swap into production**.
- **Fix:** Pin every `uses:` to a full commit SHA, with the tag in a comment. Enable Dependabot for `github-actions`. Consider allow-listing actions at org level.

### F3 — No HTTP security headers on the SPA or the API; MSAL tokens stored in localStorage (Medium, confidence High)

- **Location:** `tricab-api/deploy/app-service/default:36-39`, which says: "No security headers are set anywhere in this file". The whole `server {}` block has no `add_header` for CSP, HSTS, X-Frame-Options/frame-ancestors, X-Content-Type-Options, Referrer-Policy or Permissions-Policy. `tricab-web/index.html` has no CSP meta tag and loads Google Fonts plus an inline script. The API adds `nosniff` and `CSP: sandbox` only on file-download actions (for example `src/Http/Action/Finance/Ap2/Ap2FileResponse.php:26`). `tricab-web/src/auth/msalConfig.ts:91` sets `cacheLocation: BrowserCacheLocation.LocalStorage`.
- **Impact:** There is no defence in depth against XSS. Any injection, including through the two `dangerouslySetInnerHTML` sites or a compromised npm dependency, can read the Entra access/refresh tokens from localStorage and act as staff. Admin and finance pages can be clickjacked. Without HSTS there is first-visit SSL-strip exposure for tricab.ai.
- **Fix:** Add `always`-flagged headers at server level and repeat them in every location that has its own `add_header` (nginx inheritance rule):
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `X-Frame-Options: DENY` plus `frame-ancestors 'none'`
  - a CSP with `default-src 'self'`; `script-src 'self'` (hash the theme bootstrap script); `style-src 'self' https://fonts.googleapis.com`; `font-src https://fonts.gstatic.com`; `connect-src 'self' https://login.microsoftonline.com https://*.google.com https://appleid.apple.com`; `frame-src` for MSAL, Google and Apple; `object-src 'none'`; `base-uri 'self'`.

  Also consider `sessionStorage` for MSAL. Turn on "HTTPS Only" and minimum TLS 1.2 on both App Services (not visible from the repo).

### F4 — Production guardrails depend on an exact, unvalidated `APP_ENV` string; `APP_DEBUG`, `sql-login` and `TrustServerCertificate` are allowed in production (Medium, confidence High)

- **Location:** `tricab-api/src/Config/Settings.php:910` (`$appEnv = self::string($env,'APP_ENV','production')`, with no lower-casing and no allow-list), `:690-698` (`isDevelopment()`/`isProduction()` use strict `===`), `:1447` (`APP_DEBUG` with no production check), `:941` (`DB_AUTH_MODE` defaults to `sql-login`, not `managed-identity`), `:1477` (`DB_TRUST_SERVER_CERTIFICATE` allowed in production), and `src/Bootstrap/ContainerFactory.php:3556-3558`.
- **Evidence:** The production default (missing `APP_ENV`) is correct. However, `APP_ENV=Production`, `prod` or `staging` silently enables every non-production default:
  - `USER_REPOSITORY=memory` (`:913-915`)
  - `GUEST_EMAIL_PROVIDER=development` (`:966-968`)
  - passkey fake-ceremony and RP checks relaxed (`:1157`, `:1776`)
  - `FINANCE_AR_SAMPLE_FILES` allowed
  - and in that memory mode, `StaffPricingContextTokenCodec` falls back to the **predictable** HMAC key `sha256('tricab-dev-pricing-context|'.$appEnv)`, which makes forged staff-pricing context tokens possible.

  Separately, `APP_DEBUG=true` in production makes `JsonExceptionMiddleware.php:69` return raw exception messages (SQL errors, paths) and Slim's `addErrorMiddleware($settings->appDebug, …)` (`AppBuilder.php:370`) return stack traces for errors thrown in Bearer/DevAuth/CORS middleware. `DevelopmentAuthenticationMiddleware` (X-Dev-Entra-* header identity injection) is correctly limited to the exact string `development` together with `DEV_AUTH_ENABLED=true` (`Settings.php:700-703`).
- **Impact:** A single typo in an App Service setting turns off authentication and storage safety rails, or leaks internals.
- **Fix:** Normalise `APP_ENV` and allow only `development|test|staging|production`, throwing on anything else. Treat every non-development value as production-like for security defaults. Refuse `APP_DEBUG=true`, `DB_TRUST_SERVER_CERTIFICATE=true` and `DB_AUTH_MODE` values other than `managed-identity` when `APP_ENV=production`. Remove the hard-coded pricing-token key fallback and always require a dedicated secret of at least 32 bytes.

### F5 — TLS certificate verification disabled for the legacy MySQL connections (HR payroll, RFQ, staff migration) (Medium, confidence High)

- **Location:**
  - `tricab-api/src/Hr/Employees/MysqlHrEmployeeSource.php:35-36`
  - `src/Rfq/Legacy/MysqlLegacyRfqSource.php:44-45`
  - `src/Product/Migration/ReadOnlyMysqlConnectionFactory.php:49-60`, which sets `ATTR_SSL_CA => ''` and `ATTR_SSL_VERIFY_SERVER_CERT => false` **even when** `sslmode` is `verify_ca` or `verify_identity`.
- **Evidence:** The target is `tc-mysql-live.mysql.database.azure.com` (`docs/product-pricing-live-tc-validation.md:415`). The HR employee sync runs daily as a production WebJob (`deploy/app-service/webjobs/hr-employee-sync/run.sh`) and pulls payroll-export rows (addresses, mobiles, DOB, pay data, per `tests/Hr/Employees/EmployeeMapperTest.php:73`).
- **Impact:** An attacker in a network position to intercept the connection can impersonate the MySQL server and capture the legacy DB credentials (`LEGACY_TC_*`), read employee PII and inject falsified HR or RFQ rows into the platform.
- **Fix:** Ship the DigiCert Global Root G2 / Microsoft RSA Root CA 2017 bundle (or use the OS store path such as `/etc/ssl/certs/ca-certificates.crt`) as `ATTR_SSL_CA`, and set `ATTR_SSL_VERIFY_SERVER_CERT => true`. Honour `verify_identity` properly.

### F6 — `symfony/serializer` v8.1.9 has three open advisories and is reachable pre-auth through passkey JSON (Medium, confidence Medium)

- **Location:** `tricab-api/composer.lock` (`symfony/serializer v8.1.9`, a transitive dependency of `web-auth/webauthn-lib 5.3.9`). It is used at `src/Identity/Passkey/WebauthnLibPasskeyCeremony.php:221-223`, which deserialises client-supplied credential JSON with `WebauthnSerializerFactory`.
- **Evidence:** `composer audit` reports:
  - **CVE-2026-118515**: UploadedFile built from request data can point at any server file
  - **GHSA-pxj9-jh84-vhm5**: DataUriNormalizer opens non-lowercase data URIs as local files (arbitrary file read)
  - **GHSA-hqp2-7rr8-hf26**: ObjectNormalizer dotted-key traversal bypasses `#[Ignore]`

  All are fixed in 8.1.11. Whether the vulnerable normalizers are registered in the Webauthn serializer chain could not be confirmed, because `vendor/` is not installed.
- **Impact:** It is possibly an unauthenticated arbitrary file read (for example `.env`-equivalent files or `/proc/self/environ`, which hold all app secrets) through the passkey login endpoint, depending on which normalizers are registered.
- **Fix:** Run `composer update symfony/serializer` to get at least 8.1.11, redeploy, and add `composer audit` as a blocking CI step.

### F7 — Python runtime dependencies are unpinned and resolved fresh at every release build (Low, confidence High)

- **Location:** `tricab-api/core/python/requirements.txt` (`Flask`, `gunicorn`, `python-dotenv`, `openpyxl`, `pdfplumber`, `python-docx`, `xlrd` have no versions; `anthropic>=1.15,<2`) and `cablebuilder-sync/requirements.txt` (`>=` only). The build script vendors whatever pip resolves (`scripts/build-app-service-release.sh`, comment "requirements.txt is unpinned").
- **Impact:** These services parse untrusted RFQ PDF, DOCX, XLS and mail attachments in production. A malicious or broken upstream release goes straight to production without review, and builds cannot be reproduced. `xlrd` (legacy .xls parser, unmaintained) and `pdfplumber`/`pdfminer` get untrusted files. `core/python/quotegen_inspector/requirements.txt` is properly `==`-pinned, so that pattern already exists in the repo.
- **Fix:** Use a hash-pinned lock (`pip-compile --generate-hashes` or `uv lock`) for both requirement sets, install with `--require-hashes`, and add `pip-audit` to CI.

### F8 — Azure CLI profile directory `.azure-cli-local/` was committed (Low, confidence High)

- **Location:** 32 files under `tricab-api/.azure-cli-local/` (`az.json`, `az.sess`, `azureProfile.json`, `config`, `commands/*.log`, `logs/telemetry.log`, …). They are present in commits `0939bb8` through `a06d203` (2026-10-22 to 2026-10-26, 13 commits including `a0d1761`, `b2a3545`, `febc027`, `2de42a0`) and removed before `17c6059`. `.gitignore` now ignores them.
- **Evidence:** `az.sess` and `az.json` are `{}`. There is **no** `msal_token_cache.*`, `accessTokens.json` or `service_principal_entries.json`. The command logs show `account get-access-token` and `webapp config appsettings list` failing with "Please run 'az login'". `azureProfile.json` holds only an installationId (`25f8…`). The telemetry log reveals the developer's local path `/Users/thilanh/Documents/Projects/tricab-core/…`.
- **Impact:** No credential leaked. It shows that this directory was being used to pull App Service settings and SQL tokens from inside the repo working tree. If a logged-in session had been committed instead, it would have been a tenant-level token leak.
- **Fix:** No rotation needed. Keep the ignore rule. Add a pre-commit secret scanner (gitleaks) and GitHub push protection. Optionally purge the directory from history when the history is next rewritten.

### F9 — Internal staff directory and real-person data committed (PII in repo) (Low, confidence High)

- **Locations:**
  - `tricab-api/resources/npr/sales-roster.json`: 50 staff entries with email, display name, branch, job title, office and source row, plus the source path `/Users/manushaf/Desktop/TriCab/rfq/tricab_sales_by_branch.csv`. It ships inside the release under `app/resources`, which nginx denies, so it is not web-reachable.
  - `bin/onboarding-assign-admins.php:66-69`: 4 named staff, e.g. `el***@tricab.com => Eloise D…`.
  - `bin/promote-thilan-super-admin.php:52` and many `bin/*.php`: hard-coded admin identity `th***@tricab.com`.
  - `core/python/rfq_parser/quote_import.py:82-83`: comment containing what looks like a real customer contact name and two mobile numbers (`0415 …`, `0421 …`).
  - `tests/Fixtures/DocumentGeneration/tcord.ocr.txt`: OCR of an order document with ABN/ACN, staff usernames and a customer name and address. These appear to be pseudonymised ("Morgan Reid", `0400 000 000`), but they need confirming.
  - Commit messages also name staff (e.g. `0939bb8`, "Ugyen Phuntsho's roster email").
- **Impact:** Staff names, roles and emails give a ready-made phishing and pretexting list for the finance and AP workflows. The private repos limit the exposure, but every fork, CI log and contractor sees it. It also has Privacy Act (APP 11) data-minimisation implications.
- **Fix:** Load the roster from the database or Entra groups, or keep it in a private config store. Strip real phone numbers from code comments. Confirm the fixtures are synthetic. Make the admin bootstrap scripts take the email as a required argument.

### F10 — Infrastructure identifiers and topology disclosed in repo (Info/Low, confidence High)

- **Tenant ID and app IDs:** the real Entra tenant ID `f0ec…` is in `tricab-api/.env.example:49` and `tricab-web/vite.config.ts` (test env), together with the SPA client ID `40f8…` and API app ID `705c…`. These values are public by design in an SPA bundle, so this is Info.
- **Azure resource names:**
  - SQL server `tricab-mel.database.windows.net` (46 references) and DB `tc`
  - resource group `tricab-mel`
  - storage `tricabv2`, `tricabdevstore`
  - Foundry `tricab-ai2.services.ai.azure.com`
  - legacy MySQL `tc-mysql-live.mysql.database.azure.com`
  - apps `tc-web`, `tricab-ai`, `tricab-*-staging`, plus the full default hostname `tc-web-core-e7b7…azurewebsites.net` (`scripts/build-app-service-release.sh:14`)
- **Developer paths:** `bin/tricab-fix-local-api.command` hard-codes `/Users/thilanh/…` and `HOME`. It contains **no credentials** and only calls `az account get-access-token --query expiresOn`. `bin/azure-nonprod-discovery.sh` is read-only `az` listing with no credentials.
- **Fix:** Acceptable for private repos. Make sure the SQL server and MySQL firewalls allow only App Service outbound IPs or private endpoints, so that knowing the hostnames is not useful. Keep storage and Foundry public-network access disabled where possible.

### F11 — Deployment helper files are publicly served from wwwroot; version banners not suppressed (Low, confidence High)

- **Location:** `tricab-api/deploy/app-service/default:269-272` (`location / { try_files $uri $uri/ /index.html; }`). It denies `/app`, `/default`, `/startup.sh`, dotfiles and `*.php`. It does **not** deny the following files, which the release script copies into wwwroot (`scripts/build-app-service-release.sh`, "Staging the Azure front controller" and WebJob sections):
  - `/nginx-compression.conf`
  - `/App_Data/jobs/continuous/*/run.sh`
  - `/App_Data/jobs/continuous/*/settings.job`
- **Other gaps:** there is no `server_tokens off;`. `startup.sh` writes a PHP ini (`zz-tricab.ini`) but never sets `expose_php=Off`, `display_errors=Off` or `log_errors=On` explicitly, so it relies on the image defaults (`X-Powered-By: PHP/8.5.x` is likely). `/health` publishes the exact API and web commit SHAs (useful for targeting).
- **Impact:** Minor information disclosure (job schedules, internal paths, loopback ports, feature flags).
- **Fix:** Add `location ^~ /App_Data/ { deny all; }` and `location = /nginx-compression.conf { deny all; }`, or better, invert to an allow-list. Set `server_tokens off;`. Append `expose_php=Off`, `display_errors=Off` and `display_startup_errors=Off` to `zz-tricab.ini`.

### F12 — CI secret hygiene: PR runs get deploy keys, and the website PAT is persisted to disk during `npm ci` (Low, confidence Medium)

- **Location:** `tricab-api/.github/workflows/pull-request-checks.yml:30-44` passes `DOCGEN_DEPLOY_KEY`, `CABLEGEN_DEPLOY_KEY` and `TRICAB_WEB_READ_TOKEN` to jobs that execute PR-controlled code (`composer install` scripts, PHPUnit, `npm ci` lifecycle scripts). In `deploy-production.yml:42-47`, `:75-80` and `frontend-checks.yml:33-38`, `actions/checkout` with `token: TRICAB_WEB_READ_TOKEN` keeps the default `persist-credentials: true`, so the token sits in `tricab-web/.git/config` while `npm ci` runs third-party install scripts.
- **Notes:** The trigger is `pull_request`, not `pull_request_target`, so fork PRs get no secrets (good). There is no `${{ github.event.* }}` interpolation into `run:` anywhere, and `website_ref` only feeds `with: ref:` (good). `permissions: contents: read` is set at the top level, and `id-token: write` only on the staging job (good).
- **Impact:** A malicious same-repo PR or a compromised npm or composer dependency can steal read access to the private `tricab-website-v2`, `docgen` and `cablegen` repos. `TRICAB_WEB_READ_TOKEN` looks like a long-lived PAT.
- **Fix:** Add `persist-credentials: false` to every checkout. Replace the PAT with a GitHub App installation token scoped to `contents:read` on one repo. Consider `npm ci --ignore-scripts` in CI.

### F13 — The staging environment's OIDC identity can swap into production (Low/Info, confidence High)

- **Location:** `deploy-production.yml:240-402`. The `deploy-staging` job runs under `environment: staging` and, when `vars.TRICAB_AI_SWAP_ENABLED=true`, runs `az webapp deployment slot swap … --target-slot production` on `tricab-ai`.
- **Impact:** Protection rules on the `staging` environment effectively guard production for `tricab-ai`. The federated identity needs the Website Contributor role on the whole site, not only the staging slot.
- **Fix:** Give the swap step its own `production`-environment job with required reviewers, or at least apply the same protection rules to `staging`. Scope the federated credential subject to `environment:staging` and the RBAC to the minimum.

### F14 — Development-only dependency vulnerabilities (Low, confidence High)

- **Location:** `tricab-web/package-lock.json`.
- **Evidence:** `npm audit --omit=dev` finds **0 vulnerabilities** in the production dependency tree. The full audit finds:
  - `vite 8.2.1` (High: dev-server cross-origin script execution, `server.fs` bypasses, launch-editor command injection)
  - `undici 8.11.2` via jsdom (High: TLS validation bypass, SRI bypass, DoS; tests only)
  - `vitest`/`@vitest/mocker 4.1.11` (Moderate: code injection via mock registration)
- **Impact:** This affects developer machines (the Vite dev server listens on all interfaces, `vite.config.ts` `server.host: true`) and CI, not production users.
- **Fix:** Run `npm audit fix` (vite ≥ 8.2.3, vitest/@vitest/mocker ≥ 4.1.12, undici ≥ 8.12.2). Consider `server.host: 'localhost'` unless LAN access is needed.

### F15 — Hard-coded local SQL Server SA password (Info)

- **Location:** `tricab-api/database/scripts/development/rebuild-local-db.ps1:39` (`$SaPassword = 'TriC…'`), used for a local Docker `mssql` container.
- **Impact:** This is for local use only. It becomes a risk if the same container is ever exposed or the password is reused.
- **Fix:** Read the password from an environment variable or generate one per run. Make sure no shared or non-production SQL instance uses it.

---

## Secrets scan results (masked)

| Pattern class | Hits in history | Verdict |
|---|---|---|
| PEM / PKCS private keys, `.p8`, `.pfx`, `.pem` files | 0 (only string checks for `BEGIN PRIVATE KEY` in code) | clean |
| JWT (`eyJ…`) | 0 | clean |
| OpenAI/OpenRouter `sk-` | 13, all `sk-dumm…` test placeholders (`core/python/tests/finance_ai/*`) | clean |
| Azure AD client secret, SAS, `AccountKey=` | 0 | clean |
| Connection-string passwords | 46, all `getenv`/`$env[...]` lookups or test strings (`hunt…`) | clean |
| GitHub / Slack / Google / Anthropic / AWS tokens | 0 | clean |
| `.env.example` (all historical versions, both repos) | only empty values or `<PLACEHOLDER>` | clean |
| Azure CLI profile (`.azure-cli-local`) | 32 files, no tokens | see F8 |
| Publish profile / deploy keys | referenced only as `secrets.*` | see F1 |

Files called out in the brief: `bin/tricab-fix-local-api.command` (no credentials, F10), `bin/azure-nonprod-discovery.sh` (no credentials), `bin/*.php` (all credentials come from the environment; emails are hard-coded, F9), `PROMPTS/` (prompt text only), `reports/` (catalogue gap CSV/JSON plus `export-readonly.php`, which reads its config from the environment; no PII seen), `data/cable-calculator` (product data), `docs/` (hostnames only, F10), test fixtures (synthetic, except as noted in F9).

## Public exposure of data directories

- **tricab-web/public/** is copied into the release root and served publicly. It holds product images and videos, `certificates/DNV_logo…webp` and `finance-files/tri-task-import-template.xlsx`. The template is empty apart from headers; its document metadata names the author "Nick Han". This is acceptable.
- **tricab-web/data/** and **tricab-web/var/** are not under `public/`, are not imported by `src/`, and are not bundled.
- **tricab-web/src/data/contacts.ts** and `quoteDocumentSeller.ts` are bundled. They contain only role mailboxes and branch phone numbers, which are public by design.
- **tricab-api/data/**, **reports/**, **docs/**, **tests/**, **bin/** (except the 9 WebJob runner scripts) and **.env** are **not** included in the release ZIP. `src/`, `resources/`, `PROMPTS/`, `vendor/` and the WebJob `bin/` scripts are placed under `app/`, which nginx denies (`location ^~ /app/ { deny all; }`), and every `*.php` URL returns 404. `public/media/` is served at `/media/` (images only).
- Source maps: `vite.config.ts` does not set `build.sourcemap`, so the Vite default (`false`) applies and no `.map` files ship.
- `VITE_*` variables embedded in the bundle: ENTRA client, tenant and scope, GOOGLE and APPLE client IDs and redirect URIs, API base URL, and feature flags (`PASSKEY_ENABLED`, `MAIL_FIXTURES`, `INBOX_MODULE`, `CUSTOMER_CONTACT_E2E_MODE`, `PUBLIC_PRODUCT_MOCK_FALLBACK`). **None of these are secrets.** The production build sets only the six public IDs (`deploy-production.yml:136-142`). `externalIntegrations.ts:25` reads `import.meta.env[envKey]` dynamically for `VITE_ALIFLEX_URL`, `VITE_CRM_URL` and `VITE_ERP_URL`, which are URLs only. `VITE_CUSTOMER_CONTACT_E2E_MODE` is not tied to `DEV`, so a build with it set would send `X-Dev-Entra-*` headers. The API ignores them outside `development`, but consider gating it on `import.meta.env.DEV` as well.

## SPA hosting

The SPA has no separate hosting, Static Web App or `staticwebapp.config.json`. The API's `deploy-production.yml` checks out `tricab/tricab-website-v2@main`, builds it with `--web-origin same-origin`, and packages it at the root of the same App Service ZIP. The nginx `default` file shown above serves it. The web repo's own workflow (`pr-checks.yml`) only lints, builds and tests (no secrets, `contents: read`). `.deploy-kick` is a timestamp file (`2026-09-22T04:14:30Z`) whose only purpose is to make a push to `main` trigger a deploy. Anyone who can push to `main` can deploy.

## What looks correct

- No live credentials found in any tree or in the available history. Secrets are environment-sourced everywhere, `.env` and key files are ignored, and `.env.example` files use placeholders only.
- `pull_request` is used (not `pull_request_target`), there is no untrusted `${{ github.event.* }}` in `run:`, workflow `permissions: contents: read` is set, OIDC is used for `tricab-ai`, and deploy is gated on `main`, a re-run guard and health-SHA verification.
- Private Composer packages are pinned to commit SHAs in `composer.lock` (`tricab/docgen@f133…`, `tricab/cablegen@06ee…`) and fetched with per-repo read-only deploy keys. GitHub SSH host keys are verified from `api.github.com/meta`.
- Production defaults in `Settings.php`:
  - `APP_ENV` defaults to production
  - in production the code refuses `USER_REPOSITORY=memory`, `GUEST_EMAIL_PROVIDER=development`, `GUEST_OTP_DEV_REVEAL`, `BLOB_AUTH_MODE=memory`, `FOUNDRY_AUTH_MODE=api-key` and `CUSTOMER_CONTACT_E2E_MODE`, and requires HTTPS URLs
  - passkey RP and origin are locked to tricab.com
  - `DB_ENCRYPT=true` and `TrustServerCertificate=false` by default
  - `APP_DEBUG=false` by default
  - the guest JWT secret must be at least 32 bytes when the Azure SQL repository is used
- Dev header authentication (`X-Dev-Entra-*`) is active only when `APP_ENV=development` and `DEV_AUTH_ENABLED=true`.
- `JsonExceptionMiddleware` returns generic messages unless `APP_DEBUG`.
- CORS uses an exact-match allow-list with no wildcard.
- The JWKS clients verify TLS peers.
- In nginx, `/app`, `/startup.sh`, `/default`, dotfiles and `*.php` are denied. Release symlinks are refused. Composer runs with `--no-dev --classmap-authoritative`, and vendor tests, docs and `.git` are pruned.
- Python services (finder, RFQ parser, finance AI) bind to `127.0.0.1` only, with no nginx route. Finance AI and the RFQ parser require internal tokens.
- `docker/quotegen-inspector/Dockerfile` runs as a non-root user (uid 10001), uses an exact-version base (`python:3.11.14-slim-bookworm`; pinning by digest is optional), has `==`-pinned requirements and no baked credentials, and its docs say to run it with `--network none`.
- `npm audit --omit=dev` is clean, and `composer audit` has only the `symfony/serializer` finding (F6).
