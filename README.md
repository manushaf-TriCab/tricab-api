# TriCab API

Slim 4 authentication slice for the TriCab Platform Rebuild. Consumes `tricab/tricab-core` via a local Composer path repository (symlink).

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
cd /Users/thilanh/Documents/Projects/tricab-core/tricab-api
cp .env.example .env
composer install
composer dump-autoload
php -S localhost:8080 -t public
```

### Azure SQL prerequisites

- Microsoft ODBC Driver 18 for SQL Server
- PHP extensions: `pdo_sqlsrv` (and typically `sqlsrv`)
- Approved database identity (SQL login, service principal, managed identity, or Entra access-token flow)
- **Do not** use a Microsoft 365 user password as `DB_PASSWORD`

```env
USER_REPOSITORY=azure-sql
DB_DRIVER=sqlsrv
DB_HOST=tricab-mel.database.windows.net
DB_PORT=1433
DB_DATABASE=tc
DB_USERNAME=<secure-user>
DB_PASSWORD=<secure-secret>
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERTIFICATE=false
```

External identity lookup key: `ProviderCode` + `TenantIdentifier` + `SubjectIdentifier`.

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

Azure SQL live integration tests (optional):

```bash
RUN_AZURE_SQL_INTEGRATION_TESTS=true vendor/bin/phpunit --group azure-sql
```
