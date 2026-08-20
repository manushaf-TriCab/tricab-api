# PHP Azure SQL environment requirements

## API root

`/Users/thilanh/Documents/Projects/tricab-core/tricab-api`

## Repository switch

```env
USER_REPOSITORY=memory      # default for local tests
USER_REPOSITORY=azure-sql   # AzureSqlUserRepository via PDO
```

## Required environment variables

```env
USER_REPOSITORY=azure-sql
DB_DRIVER=sqlsrv
DB_HOST=tricab-mel.database.windows.net
DB_PORT=1433
DB_DATABASE=tc
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERTIFICATE=false
```

`DB_AUTH_MODE`:

| Value | Where | How |
|---|---|---|
| `entra-cli` | Local development | `az account get-access-token --resource https://database.windows.net/` |
| `managed-identity` | Azure App Service | App Service `IDENTITY_ENDPOINT` + `IDENTITY_HEADER`, IMDS fallback. Resource `https://database.windows.net/` |
| `sql-login` | Only when explicitly required | `DB_USERNAME` / `DB_PASSWORD` (not a Microsoft 365 password) |

Unknown `DB_AUTH_MODE` values are rejected. There is no silent fallback to `sql-login`.

Azure App Service should set `DB_AUTH_MODE=managed-identity`. Do not set `entra-cli` on App Service. Do not set `DB_USERNAME` / `DB_PASSWORD` for managed identity.

Database authentication must use an approved SQL database identity, service principal, managed identity, or supported Microsoft Entra access-token flow.

Do **not** place a Microsoft 365 password in configuration.

## Required local dependencies

- PHP 8.3+
- Composer
- Microsoft ODBC Driver 18 for SQL Server
- `pdo_sqlsrv` (and typically `sqlsrv`)

## Expected PDO connection format

```php
new PDO(
    'sqlsrv:Server=tcp:' . $host . ',' . $port . ';Database=' . $database .
    ';Encrypt=yes;TrustServerCertificate=no;LoginTimeout=' . $loginTimeoutSeconds,
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
```

`LoginTimeout` is `DB_LOGIN_TIMEOUT_SECONDS` (default 20, bounds 5–60). It applies only to PDO connection establishment, not to query timeouts.

## Cross-request cache (APCu)

`APP_CACHE_DRIVER=apcu` stores Entra SQL tokens, product lookups, and public catalogue
panel payloads in APCu so they survive HTTP requests on the same app instance.

- Do **not** use `array` for non-prod HTTP verification. It is request/container local.
- Do **not** assume PHP static properties or PHP-FPM workers make `array`/statics reliable.
- Redis is not used.
- Tokens are never written to disk or logged.

### php -S / CLI

APCu is disabled for CLI by default. Start the API with:

```bash
php -d apc.enable_cli=1 -S 127.0.0.1:8080 -t public
```

Do not silently change the global `php.ini`. If APCu is missing, install the `apcu`
extension (Homebrew `conf.d` drop-in is fine). Setting `APP_CACHE_DRIVER=apcu` when
APCu is not usable fails at boot with a clear error.

### Azure App Service / PHP-FPM

App Service and PHP-FPM are **not** CLI. Do **not** set `apc.enable_cli` there.

Verify from App Service log stream / SSH (not a public HTTP endpoint):

1. APCu extension loaded (`extension_loaded('apcu')`).
2. `apc.enabled=1`.
3. Startup log: `[cache] driver=apcu cross_request=1`.
4. `[perf] ... cache_driver=apcu` on a request.

PHP-FPM checklist:

```ini
; php.ini / App Service PHP configuration
extension=apcu
apc.enabled=1
; do NOT set apc.enable_cli — that flag is only for php -S / CLI / phpdbg
```

If APCu is unavailable:

- `APP_CACHE_DRIVER=apcu` → boot fails with a clear `InvalidArgumentException`.
- unset / `auto` → falls back to `array` (`cross_request=0`). Tokens and catalogue
  cache will not survive requests. Logs will say `driver=array cross_request=0`.

Do **not** expose APCu diagnostics on `/health` or any public route.

## Azure SQL serverless resume

Non-prod Azure SQL is serverless (`GP_S_Gen5_2`). Auto-pause is allowed.

```env
DB_LOGIN_TIMEOUT_SECONDS=20
DB_CONNECT_RETRY_ENABLED=true
DB_CONNECT_RETRY_MAX_ATTEMPTS=2
DB_CONNECT_RETRY_DELAY_MS=1500
```

`DB_CONNECT_RETRY_MAX_ATTEMPTS=2` means 1 initial PDO create + at most 1 retry.
Retry applies only to classified connection-establishment timeouts (HYT00 / login
timeout, and documented Azure 40613). Queries are never retried. Writes are never
retried. Authentication and configuration failures are not retried.

Do **not** add keepalive, disable auto-pause, poll `/health/database`, or pre-warm.

### Optional explicit SQL login

`DB_AUTH_MODE=sql-login` is supported only when set explicitly. If a dedicated **non-production** SQL user
already exists, it avoids Azure CLI token overhead locally. Do not create a SQL user
from this app, do not commit passwords, and do not use SQL login in production.

## Health

```bash
# Liveness — no SQL. Use this for App Service / Docker / uptime probes.
curl http://localhost:8080/health

# On-demand readiness — opens Azure SQL. Do not poll this on serverless non-prod.
curl http://localhost:8080/health/database
```

Portal / App Service health paths are not in this repository. If a probe currently hits `/health/database`, switch it to `/health` in Azure (manual).

## Implementation note

Azure SQL adapters live only in the API layer. TriCab Core remains framework-independent and defines repository contracts + domain behaviour only.
