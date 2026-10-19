# Authentication infrastructure design (deferred)

Status: **design only**. Do not implement until Azure SQL Identity tables are available.

## Request flow

```text
Authorization: Bearer <access_token>
        │
        ▼
AuthenticationMiddleware
        │  extract Bearer token
        ▼
MicrosoftEntraIdentityProvider
        │  validate JWT (issuer, audience, signature, expiry)
        │  map claims → VerifiedIdentity
        ▼
VerifiedIdentity  (TriCab\Core)
        │
        ▼
ResolveAuthenticatedUserHandler  (TriCab\Core)
        │
        ├── UserRepository::findByExternalIdentity(...)
        │         │
        │         ▼
        │   AzureSqlUserRepository  (API infrastructure)
        │         │
        │         ▼
        │   Azure SQL Identity tables
        │
        ├── null            → IdentityNotLinked → 401/403 JSON
        ├── pending/disabled → AuthenticationNotAllowed → 403 JSON
        └── active User
                │
                ▼
        AuthenticatedUser  (TriCab\Core DTO)
                │
                ▼
request attribute / DI scope
                │
                ▼
GetAuthenticatedUserAction
                │
                ▼
JSON response (framework-owned)
```

## Proposed files

Namespace root: `TriCab\Api\` under `tricab-api/src/`.

```text
tricab-api/
├── public/
│   └── index.php                          # Slim front controller (later)
├── config/
│   ├── container.php                      # PHP-DI (or chosen container) bindings
│   ├── settings.php                       # env-backed settings (API only)
│   └── routes.php                         # Slim route map
└── src/
    ├── Infrastructure/
    │   ├── Identity/
    │   │   ├── AzureSqlUserRepository.php
    │   │   └── MicrosoftEntraIdentityProvider.php
    │   └── Persistence/
    │       └── AzureSqlConnectionFactory.php   # PDO/sqlsrv factory (API only)
    ├── Http/
    │   ├── Middleware/
    │   │   └── AuthenticationMiddleware.php
    │   ├── Action/
    │   │   └── GetAuthenticatedUserAction.php
    │   └── Responder/
    │       └── JsonErrorResponder.php          # map Core exceptions → HTTP
    └── Auth/
        └── AuthenticatedUserAttribute.php      # request attribute key helper
```

### Component responsibilities

| Component | Responsibility | Must not |
|-----------|----------------|----------|
| `MicrosoftEntraIdentityProvider` | Validate Entra access token; return `VerifiedIdentity` | Touch Azure SQL; know about roles/permissions tables |
| `AuthenticationMiddleware` | Read `Authorization` header; call provider; call `ResolveAuthenticatedUserHandler`; attach `AuthenticatedUser` to request | Parse business rules beyond calling Core |
| `AzureSqlUserRepository` | Implement `TriCab\Core\Identity\Contract\UserRepository` against Identity tables | Validate JWTs; read env directly if factory/settings already inject connection |
| `GetAuthenticatedUserAction` | Read `AuthenticatedUser` from request; return JSON projection | Re-validate tokens; query SQL |
| `config/container.php` | Bind Core handler + API adapters | Leak into Core package |

### Suggested DI bindings

```text
UserRepository::class
  → AzureSqlUserRepository::class

ResolveAuthenticatedUserHandler::class
  → concrete (needs UserRepository)

MicrosoftEntraIdentityProvider::class
  → concrete (needs Entra tenant/audience/JWKS settings)

AuthenticationMiddleware::class
  → concrete (needs provider + ResolveAuthenticatedUserHandler)

GetAuthenticatedUserAction::class
  → concrete
```

### Expected Azure SQL lookups (when tables exist)

`AzureSqlUserRepository` should support:

1. `findByExternalIdentity` — join/link table on provider + subject + tenant → user
2. hydrate `User` with roles + permissions + status
3. `findById` / `findByEmail` — same hydrate path

Exact table/column names are deferred until the Identity schema is published.

### HTTP mapping (API-owned)

| Core / application outcome | Suggested HTTP |
|----------------------------|----------------|
| Missing/invalid Bearer token | `401` |
| Entra token invalid/expired | `401` |
| `IdentityNotLinked` | `403` (or `401` if product prefers unauthenticated) |
| `AuthenticationNotAllowed` | `403` |
| Success on `GET /me` (or similar) | `200` + `AuthenticatedUser` JSON |

### Boundary reminder

- **Core** owns: VOs, `User`, `ExternalIdentity`, `UserRepository` contract, `VerifiedIdentity`, `AuthenticatedUser`, `ResolveAuthenticatedUserHandler`.
- **API** owns: Slim, middleware, Entra SDK/JWKS validation, Azure SQL/PDO, DI, JSON responses, env/config.

## Implementation gate

Do **not** create the files above until:

1. Azure SQL Identity tables (users, external identities, roles, permissions) are available
2. Entra app registration (tenant, audience, JWKS) is known for the API environment

## Last login timestamp

User Management reads `identity.Users.LastSuccessfulLoginAt`. A successful
`GET /api/v1/me` now records the time the app verifies the signed-in account,
including restored sessions, for Microsoft and guest authentication. Ordinary
API calls and failed verification do not update it. Concurrent older writes
cannot move the timestamp backwards, and a recording failure does not deny login.

Before this fix, authentication did not populate this field. A null value means
**Not recorded**, not proof that the person has never signed in. Historical login
times are not backfilled. No schema migration is needed; values begin appearing
after deployment when people next open or refresh the signed-in app.
