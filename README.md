# TriCab API

Slim 4 authentication slice for the TriCab Platform Rebuild. Core lives in-repo at `core/` and is consumed as the Composer path package `tricab/tricab-core`.

## Boundaries

- **API owns:** Slim, PSR-7, DI, dotenv, Entra token validation adapters, persistence adapters (memory / Azure SQL), JSON responses
- **Core owns:** domain entities, value objects, `ResolveAuthenticatedUserHandler`, `UserRepository` contract

## Modes

| Mode | Behaviour |
|------|-----------|
| `APP_ENV=development` + `DEV_AUTH_ENABLED=true` | Development middleware supplies `VerifiedIdentity` from `X-Dev-Entra-*` headers (with defaults) |
| `USER_REPOSITORY=memory` | In-memory seeded user (default for tests) |
| `USER_REPOSITORY=azure-sql` | `AzureSqlUserRepository` via PDO `pdo_sqlsrv` |
| production / Entra JWT | `MicrosoftEntraJwtValidator` placeholder refuses unverified tokens until JWKS validation is configured |

## Setup

```bash
git clone https://github.com/tricab/tricab-api.git
cd tricab-api
cp .env.example .env
composer install
composer dump-autoload
php -S localhost:8080 -t public public/router.php
```

A separate `tricab-core` checkout is not required. Domain and application code is at `core/`.

Use `public/router.php` with `php -S`. Without it, paths such as `PRODUCT.READ` are treated as missing files and CORS preflight returns 404.

### Azure SQL prerequisites

- Microsoft ODBC Driver 18 for SQL Server
- PHP extensions: `pdo_sqlsrv` (and typically `sqlsrv`)
- For `DB_AUTH_MODE=entra-cli` (local): Azure CLI installed and logged in (`az login`)
- For `DB_AUTH_MODE=managed-identity` (Azure App Service): system-assigned (or user-assigned) identity; do not use `entra-cli` on App Service
- **Do not** use a Microsoft 365 user password as `DB_PASSWORD`

```env
USER_REPOSITORY=azure-sql
# Local: entra-cli. Azure App Service: managed-identity. sql-login only when explicitly required.
DB_AUTH_MODE=entra-cli
DB_DRIVER=sqlsrv
DB_HOST=tricab-mel.database.windows.net
DB_PORT=1433
DB_DATABASE=tc
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERTIFICATE=false
# Only when DB_AUTH_MODE=sql-login:
# DB_USERNAME=
# DB_PASSWORD=
```

`managed-identity` acquires a short-lived Azure SQL access token from App Service `IDENTITY_ENDPOINT` + `IDENTITY_HEADER`, with IMDS (`http://169.254.169.254`) only when those variables are absent. Resource: `https://database.windows.net/`. Do not use Azure CLI for this mode.

`entra-cli` acquires a short-lived Azure SQL access token via:

```bash
az account get-access-token --resource https://database.windows.net/ --output json
```

The token is cached in `ApplicationCache` (APCu when available) until `expires_on - 5 minutes`. It is never written to `.env`, never logged, and never stored on disk. `APP_CACHE_DRIVER=array` is tests/fallback only and does not survive HTTP requests.

Optional SQL login (already supported; do not commit passwords; do not use in Azure production):

```env
DB_AUTH_MODE=sql-login
DB_USERNAME=
DB_PASSWORD=
```

Request timing (development default on): `APP_PERFORMANCE_LOGGING=true` writes `[perf]` lines to STDERR and `var/log/request-performance.log`.

## Azure SQL Serverless cost control

Serverless bills primarily while the database is **resumed**, not per query. Interactive development (`/me`, lookups, catalogue, editor) keeps it awake. Auto-pause delay is often 60 minutes.

- Do **not** add keep-alive polling to hide cold start.
- Use `GET /health` for frequent liveness probes. Do **not** probe `GET /health/database` on an interval in non-prod.
- Disconnect SSMS / Azure query editor sessions when finished.
- First request after pause can take seconds (resume). Later requests should be hundreds of milliseconds when SQL is warm.
- Production should use `DB_AUTH_MODE=managed-identity`, not `entra-cli`.
- Check auto-pause delay and min vCores in the Azure Portal (not in this repo).

External identity lookup key: `ProviderCode` + `TenantIdentifier` + `SubjectIdentifier`.

## Guest OTP email delivery

Provider selection (`GUEST_EMAIL_PROVIDER`):

| Value | Behaviour |
|-------|-----------|
| `development` (default in non-production) | No external send. OTP held in memory for tests. |
| `microsoft-graph` | Sends mail via Microsoft Graph (`/{RFQ_MAILBOX}/sendMail`) using client credentials |
| `postmark` | Sends transactional email via Postmark HTTPS API |

```env
GUEST_EMAIL_PROVIDER=development
GUEST_OTP_DEV_REVEAL=false

POSTMARK_SERVER_TOKEN=
POSTMARK_FROM_EMAIL=
POSTMARK_FROM_NAME=TriCab
POSTMARK_MESSAGE_STREAM=outbound

GRAPH_TENANT_ID=
GRAPH_CLIENT_ID=
GRAPH_CLIENT_SECRET=
RFQ_MAILBOX=
GUEST_OTP_EMAIL_LOGO_URL=
```

### Microsoft Graph

1. Register an app with application permission `Mail.Send` (admin consent).
2. Set `GUEST_EMAIL_PROVIDER=microsoft-graph`.
3. Provide `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`, and `RFQ_MAILBOX` (sender UPN).
4. Token endpoint: `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with scope `https://graph.microsoft.com/.default`.
5. Container creation fails fast if Graph is selected without credentials.
6. OTP, access tokens, client secrets, and Authorization headers are never logged.

### Postmark

1. Create a Postmark server and copy the **Server API token**.
2. Verify the From address / sender signature.
3. Set `GUEST_EMAIL_PROVIDER=postmark` with `POSTMARK_SERVER_TOKEN` and `POSTMARK_FROM_EMAIL`.
4. Container creation fails fast if Postmark is selected without credentials.

### Development mode

- Does **not** call Graph, Postmark, or any external provider.
- Does **not** write OTPs to application logs by default.
- Set `GUEST_OTP_DEV_REVEAL=true` only for local manual debugging (prints a clearly marked OTP line to STDERR).
- **Never** enable `GUEST_OTP_DEV_REVEAL` in production — Settings rejects it when `APP_ENV=production`.
- **Never** set `GUEST_EMAIL_PROVIDER=development` in production — Settings rejects it.

Plaintext OTPs are never persisted to Azure SQL (only password hashes) and are never returned by `RequestAndSendGuestOtpService`.

## Guest access tokens

Guest sessions use application-issued HS256 JWTs (no refresh tokens).

```env
GUEST_JWT_ISSUER=https://api.tricab.local
GUEST_JWT_AUDIENCE=tricab-api
GUEST_JWT_SECRET=
GUEST_JWT_TTL_SECONDS=86400
GUEST_JWT_CLOCK_SKEW_SECONDS=30
```

Generate a secret (do not commit the value):

```bash
php -r "echo base64_encode(random_bytes(64)), PHP_EOL;"
```

Behaviour:

- Token lifetime is **24 hours** (`86400` seconds). After expiry, guests request a new OTP.
- Only the SHA-256 hash of the JWT is stored in `identity.GuestSessions.SessionTokenHash`.
- Roles and permissions are loaded from Azure SQL after validation — never trusted from JWT claims.
- `POST /api/v1/auth/guest/logout` revokes the database session via `RevokeGuestSessionService`.
- Employees continue to authenticate with Microsoft Entra; Bearer middleware routes by issuer hint then fully validates with the matching provider.
- `USER_REPOSITORY=azure-sql` requires a guest JWT secret (≥ 32 bytes) at container creation.

## Guest authentication HTTP API

Guest routes are registered when `GUEST_JWT_*` is configured. Employees should use Microsoft login; guests use email OTP.

| Method | Path | Auth |
|--------|------|------|
| `POST` | `/api/v1/auth/guest/request-otp` | Public |
| `POST` | `/api/v1/auth/guest/verify-otp` | Public |
| `POST` | `/api/v1/auth/guest/logout` | Guest Bearer only |
| `GET` | `/api/v1/me` | Entra or guest Bearer |

### Rules

- OTP expires after **5 minutes**, is **single use**, and is never returned by the API.
- Guest session lasts **24 hours**. After expiry the guest must request a new OTP.
- **No refresh token** is issued.
- Logout revokes the guest session (idempotent). Entra tokens receive `403 AUTH_GUEST_SESSION_REQUIRED`.
- Challenge attempt limit remains enforced (5 attempts).

### Intended future rate limits (not enforced yet)

- 3 OTP requests per email per 15 minutes
- 10 OTP requests per IP per 15 minutes
- optional temporary IP blocking after repeated failures

Integration point: `TriCab\Api\Http\Auth\GuestAuthRateLimiter`.

### Request OTP

```bash
curl -sS -X POST http://localhost:8080/api/v1/auth/guest/request-otp \
  -H 'Content-Type: application/json' \
  -d '{"email":"guest@example.com"}'
```

Success `202`:

```json
{
  "success": true,
  "data": {
    "challengeId": "<public UUID>",
    "email": "g***t@example.com",
    "expiresAtUtc": "2026-07-31T07:30:00+00:00",
    "expiresInSeconds": 300
  }
}
```

### Verify OTP

```bash
curl -sS -X POST http://localhost:8080/api/v1/auth/guest/verify-otp \
  -H 'Content-Type: application/json' \
  -d '{"challengeId":"<uuid>","email":"guest@example.com","otp":"123456"}'
```

`otp` must be a JSON string of exactly six digits (not a number).

Success `200`:

```json
{
  "success": true,
  "data": {
    "accessToken": "<raw JWT>",
    "tokenType": "Bearer",
    "expiresIn": 86400,
    "expiresAtUtc": "2026-08-01T07:30:00+00:00",
    "user": {
      "publicId": "<user UUID>",
      "emailAddress": "guest@example.com",
      "displayName": "guest@example.com",
      "status": "active",
      "roles": ["GUEST"],
      "permissions": ["ACCOUNT.LOGIN"]
    }
  }
}
```

### Logout

```bash
curl -sS -X POST http://localhost:8080/api/v1/auth/guest/logout \
  -H 'Authorization: Bearer <guest-access-token>'
```

Success `200`: `{ "success": true }`

### Current user

```bash
curl -sS http://localhost:8080/api/v1/me \
  -H 'Authorization: Bearer <entra-or-guest-token>'
```

Guest responses include `roles: ["GUEST"]` and `permissions: ["ACCOUNT.LOGIN"]`. `userId` remains for Entra compatibility.

## Health

```bash
curl http://localhost:8080/health
curl http://localhost:8080/health/database
```

## Authenticated development test (memory repository)

```bash
curl \
  -H "X-Dev-Entra-Tenant: development-tenant" \
  -H "X-Dev-Entra-Subject: development-thilan" \
  -H "X-Dev-Entra-Email: thilanh@tricab.com" \
  http://localhost:8080/api/v1/me
```

## Quality

```bash
composer validate --strict
composer test
composer analyse
```

Azure SQL live integration tests (optional; requires Azure CLI login when `DB_AUTH_MODE=entra-cli`):

```bash
RUN_AZURE_INTEGRATION_TESTS=true vendor/bin/phpunit --group azure-sql
```
