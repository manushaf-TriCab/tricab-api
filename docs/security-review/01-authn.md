# TriCab API: authentication and session review (pre-release)

Scope: `/home/user/tricab-api`, read-only. Covered: middleware chain, Entra JWT, guest JWT and sessions, email OTP, Google, Apple (web and native), passkeys, trusted devices, act-as, dev-auth gating, identity provisioning and linking, rate limiting, CORS.
Method: I read every code path from the route through to persistence and ran `composer audit --locked`. Nothing was modified. `vendor/` is not installed, so I could not read library internals (firebase/php-jwt 7.2.1, web-auth/webauthn-lib 5.3.9). Statements about library behaviour come from the libraries' documented behaviour and are marked as such.

Effective global middleware order (Slim is LIFO): Performance -> Error -> (E2E) -> CacheInvalidation -> CORS -> DevAuth -> Bearer -> JsonException -> Routing -> BodyParsing -> ActAs -> CustomerActorStaffRouteGuard -> route middleware (RequirePermission / RequirePlatformAdministrator) -> action. (`src/Bootstrap/AppBuilder.php:330-380`)

---

## Summary table

| # | Title | Severity | Confidence |
|---|-------|----------|------------|
| A1 | Staff and administrator accounts can be signed into with email OTP, Google, Apple or a mailed passkey link. The "corporate address = Entra only" binding never takes effect, and LOW assurance still unlocks almost every permission | High | Confirmed (code); exploit needs control of a staff mailbox or a Google/Apple account on that address |
| A2 | Trusted-device registration is open to Entra and act-as sessions. It mints a 90-day credential that escapes the act-as time box and audit, and survives Entra disable or Conditional Access | Medium | Confirmed |
| A3 | Auth rate limits are keyed on `REMOTE_ADDR`, which behind App Service is very likely a shared front-end address. Result: one attacker can lock out OTP and passkey sign-in for everyone, and there is no real per-client throttle. Counters are also non-atomic and per-instance | Medium | Likely / Needs-verification (REMOTE_ADDR value on App Service Linux) |
| A4 | OTP challenge read-modify-write races: parallel guesses get past the 5-attempt cap, and a late failed attempt can un-consume a used challenge | Low | Confirmed (code); exploitability limited |
| A5 | Account enumeration through timing (and a 503 on delivery failure) on request-otp and passkey setup-link | Low | Likely |
| A6 | Trusted-device "previous credential" grace is re-extended on every use, with no reuse detection. A stolen previous credential lives for the whole 90-day TTL and locks out the real device | Low | Confirmed |
| A7 | Production guardrails require the exact value `APP_ENV === 'production'`. Any other value silently turns them off. Dev auth is allowed against Azure SQL | Low | Confirmed (config-dependent) |
| A8 | Entra validator returns raw library and JWKS error text to clients; no `azp`/`appid` allowlist; `ENTRA_REQUIRED_SCOPE=''` disables the only check that keeps ID tokens and app-only tokens out | Low | Confirmed |
| A9 | Apple native: no nonce (token replay), `email_verified` defaults to true, and the feature is on by default. Google: no nonce | Low | Confirmed |
| A10 | Guest JWT identity is hard-coded to EMAIL_OTP: passkey sessions can never reach SUBSTANTIAL, and Google/Apple sessions are recorded as `email-otp`. Passkey enrolment itself is bootstrapped from an emailed link | Info (design risk) | Confirmed |
| A11 | Act-as logs only state-changing requests. Reads of the target's data while switched (HR, onboarding PII, finance) leave no per-action trail | Low | Confirmed |
| A12 | Passkey hygiene: setup token not consumed at verify, equal counters accepted, revoking a passkey leaves its sessions live, any subdomain origin accepted (library default) | Info | Confirmed / Likely |
| A13 | JWKS file caches sit in the app tree; unknown `kid` never forces a refresh | Info | Confirmed |
| A14 | `symfony/serializer` 8.1.9 has three open advisories and is reachable only through webauthn deserialisation of unauthenticated JSON | Low | Needs-verification |
| A15 | CORS defaults to `http://localhost:5173` when unset; `APP_DEBUG` is not blocked in production | Info | Confirmed |

---

## A1. Staff and admin accounts can be signed into by low-assurance, email-bound providers; corporate binding never takes effect (High, Confirmed in code)

**Evidence**

- `core/src/Identity/Application/Service/IdentityResolutionService.php:76-80`: the provider-binding check runs only when a purpose has been recorded:
  ```php
  if ($match->purpose !== null
      && !ProviderBindingPolicy::allows($identity->provider(), $match->purpose)) {
      throw UserNotRegistered::create();
  }
  ```
- `src/Identity/Persistence/AzureSqlUserProvisioningRepository.php:166-209, 241-247`: when no `UserEmailAddresses` row exists, `fallbackOwners()` matches on the primary `Users.EmailAddress` with `purpose = null`.
- `database/scripts/development/create-identity-user-email-addresses.sql:80-99`: the backfill inserts every existing user with `Purpose = NULL`.
- No code anywhere writes `Purpose = 'corporate'`. `grep` for `corporate` finds only the SQL comment. The only writer of a purpose is `src/Onboarding/Persistence/AzureSqlOnboardingAccountDirectory.php:77-91`, and it writes `personal`. `createUser()` writes no address row at all (comment at line 29-33 of the same file).
- So `ProviderBindingPolicy` (`core/src/Identity/Domain/Service/ProviderBindingPolicy.php`), with its rule "CORPORATE => [MICROSOFT_ENTRA] only", never applies to any staff or admin address.
- OTP eligibility (`src/Identity/Persistence/AzureSqlGuestLoginEligibilityLookup.php`) asks only: does the user exist, are they active, do they hold `ACCOUNT.LOGIN` through any role. EMPLOYEE and ADMINISTRATOR carry `ACCOUNT.LOGIN` (`src/Identity/Persistence/InMemoryUserManagementStore.php:255-268`; production seed needs verifying).
- `core/src/Identity/Domain/Service/PermissionAssurancePolicy.php:85`: `self::REQUIRED_ASSURANCE[$code] ?? AuthenticationAssurance::LOW`. Only about 25 codes (USER.*, ROLE.*, PERMISSION.*, ONBOARDING admin, five RFQ codes, PRICE_AS, PRICING.OVERRIDE, MIGRATION.ADMIN) need SUBSTANTIAL. Platform-admin routes and act-as need SUBSTANTIAL (`RequirePlatformAdministratorMiddleware.php:54-62`). Every other permission (finance, AP/AR, HR, NPR, customers, products, TriExpense admin including module role assignment, mail, conductor, and so on) is reachable at LOW.
- `src/Identity/Authentication/GuestJwtValidator.php:120` attaches the full, unscoped `AuthenticatedUser`. `RequirePermissionMiddleware.php:89` then gates only the route's single code on assurance.

**Exploit scenarios**

1. An attacker with read access to a staff mailbox (phished OWA session, mail-forwarding rule, delegate or shared-mailbox access, a leaked app password for a legacy protocol) calls `POST /api/v1/auth/guest/request-otp {email: staff@tricab.com}`, reads the code, and calls `verify-otp`. They get a 24-hour guest JWT for the staff user without Entra MFA, Conditional Access, device compliance or sign-in risk policy. That JWT reaches every LOW-gated staff API, including finance, HR and customer data.
2. A person who once created a consumer Google or Apple ID on their `@tricab.com` address keeps that account after leaving or after Entra disablement. If their platform user is still `active` (offboarding disables Entra but not the TriCab row), `POST /api/v1/auth/google` with that token links (`linkExternalIdentity`) and signs in. The code's own comment (`ProviderBindingPolicy.php` header) says the corporate rule exists to prevent exactly this.
3. The same mailbox control allows `POST /api/v1/auth/guest/passkey/setup-link`, which registers a passkey on the staff account: persistent access.

**Fix**

- Classify addresses. Backfill `Purpose='corporate'` for every address on a TriCab-owned domain (tricab.com plus any other tenant-verified domains) and for every user who has an active `microsoft_entra` external identity. Make `createUser` and `CreateUserAction` always write an address row with an explicit purpose.
- Fail closed. Treat `purpose === null` as corporate (Entra-only) for users holding EMPLOYEE or ADMINISTRATOR, or block OTP, Google, Apple and passkey-setup for any user with a staff role unless an administrator has explicitly opted them in.
- Raise the default floor. Make staff roles' permissions require SUBSTANTIAL (or HIGH) by default, and list the LOW permissions explicitly (ACCOUNT.LOGIN, ONBOARDING.SELF.*, customer-portal codes) instead of the reverse.
- Have identity lifecycle sync (Entra disable) also disable the TriCab user.

---

## A2. Trusted-device credentials can be minted from Entra and act-as sessions (Medium, Confirmed)

**Evidence**

- `src/Bootstrap/AppBuilder.php:397-399`: `POST /api/v1/auth/devices/register` is guarded only by `RequirePermission('ACCOUNT.LOGIN')`. Nothing requires the caller to be a guest-token session.
- `src/Http/Action/TrustedDevice/RegisterTrustedDeviceAction.php:41,75`: it registers for whatever `AuthenticatedUserAttribute` holds.
- `src/Http/Middleware/ActAsMiddleware.php:102`: while an act-as session is active, `AuthenticatedUserAttribute` is the target. The act-as path exemption covers only `/api/v1/admin/act-as*`.
- `core/src/Identity/Application/Service/RegisterTrustedDeviceService.php`: TTL is 90 days (configurable up to 365). The response includes the raw credential.
- `ExchangeTrustedDeviceSessionService` (`POST /api/v1/auth/devices/session`, no auth) exchanges the credential for a fresh 24-hour guest session as the user and rotates it. That can repeat for 90 days.

**Exploit scenarios**

- *Act-as escape.* An administrator starts a 30-minute, reason-logged act-as on user X, then sends `POST /api/v1/auth/devices/register` with `X-Act-As-Session` and gets a credential for X. From then on they call `/auth/devices/session` and act as X for up to 90 days. There is no act-as session, no `ActingAdmin` marker, no act-as action log and no expiry. Every action is attributed to X. This breaks the documented guarantees of act-as ("time-boxed", "every state-changing request ... writes one row"). The target can be any non-administrator, including finance or HR staff with permissions the admin may not hold.
- *Entra revocation bypass.* Any staff member with a normal Entra session can register a device for themselves. If HR or IT later disables the Entra account, resets the password or applies Conditional Access, the device credential keeps working until the TriCab user row is disabled. Combine this with A1.

**Fix**

- Reject device registration when `ActingAdminAttribute` is present. More generally, have ActAsMiddleware refuse every `/api/v1/auth/*` route and any credential-minting route.
- Allow registration only from a guest session issued in the same app context. Check `ValidatedGuestTokenAttribute` and a mobile-client indicator, or require a fresh OTP or passkey step-up.
- Bind trusted devices and their sessions to the issuing provider. Revoke all trusted devices and guest sessions when a user's status, roles or Entra link changes.

---

## A3. Rate limiting keyed on `REMOTE_ADDR`: probably a shared address behind App Service, and non-atomic (Medium, Likely / Needs-verification)

**Evidence**

- `src/Http/Request/GuestAuthRequestMetadata.php:21`: client IP is `$_SERVER['REMOTE_ADDR']` only, with `X-Forwarded-For` deliberately ignored.
- `deploy/app-service/default`: plain `include fastcgi_params`. There is no `set_real_ip_from`/`real_ip_header`. On App Service Linux the container's nginx receives connections from the platform front end, so `REMOTE_ADDR` is usually an internal front-end address (commonly reported as 169.254.x.x), not the client. **Verify on the live slot by logging `REMOTE_ADDR` and `X-Forwarded-For`/`X-Client-IP`.**
- `src/Http/Auth/WindowedGuestAuthRateLimiter.php:54-62` (the passkey limiter is the same at `WindowedPasskeyAuthRateLimiter.php`): the limits are 10 request-otp per IP per 15 minutes, 20 verify-otp per IP, and for passkeys 10 setup-link per IP and 20 verify or ceremony per IP. Counting is a non-atomic `get` followed by `set`, in APCu, per instance. With the file fallback (`FilesystemApplicationCache`) it is also non-atomic.

**Exploit scenario**

If every client shares one `REMOTE_ADDR`, an unauthenticated attacker sends 10 `request-otp` calls (with any email) every 15 minutes, and nobody on the platform can request an OTP. Twenty bogus `verify-otp` calls block all OTP verification. Twenty passkey `authentication/options` calls block all passkey sign-ins. The attack costs about two requests a minute. Conversely, if the address really is per client, scaling out multiplies the limits, and concurrent bursts pass `get` before any `set` lands (see A4).

**Fix**

- Derive the client IP from the platform header (`X-Client-IP`, or the first hop of `X-Forwarded-For`), and trust it only when `REMOTE_ADDR` is in the App Service front-end range. In nginx that means `set_real_ip_from` plus `real_ip_header X-Forwarded-For` and `real_ip_recursive on`.
- Use an atomic shared counter: Redis `INCR`+`EXPIRE`, or `apcu_inc` with `apcu_add` as a minimum, plus a shared store across instances.
- Add per-account limits that cannot be used to lock out third parties (for example exponential backoff per email rather than a hard global cap).

---

## A4. OTP challenge races: attempt counter lost updates and un-consumption (Low, Confirmed in code)

**Evidence**

- `core/src/Identity/Application/Service/VerifyGuestOtpService.php:47-59`: load challenge, `password_verify` (bcrypt, tens of ms), then `registerFailedAttempt()` or `consume()`, then `save()`. There is no transaction or row lock.
- `src/Identity/Persistence/AzureSqlEmailOtpChallengeRepository.php:187-193`: the update writes absolute values:
  `SET [AttemptCount] = :attemptCount, [ConsumedAtUtc] = :consumedAtUtc, [InvalidatedAtUtc] = :invalidatedAtUtc WHERE [EmailOtpChallengeId] = :id`
  There is no `AND AttemptCount = :expected` and no `ConsumedAtUtc IS NULL` guard.

**Scenarios**

- N parallel wrong guesses on one challenge each read `AttemptCount = k`, so the counter rises by about 1 instead of N. Effective guesses per challenge become roughly the number of concurrent FPM workers (40 per instance after `startup.sh`) instead of 5. The per-challenge limit of 20 in A3 is itself non-atomic. Against a 6-digit code this lifts success odds from 5e-6 to roughly 1e-4 per challenge. That is small, but a strict increase, and it can be repeated across many staff emails (A1).
- A wrong guess racing a correct one can write `ConsumedAtUtc = NULL` after the success, so a consumed code becomes valid again within its 5-minute TTL. This mainly matters for replay of an observed code.

**Fix**

Do this in a single conditional statement: `UPDATE ... SET AttemptCount = AttemptCount + 1 OUTPUT inserted.AttemptCount WHERE id=:id AND ConsumedAtUtc IS NULL AND InvalidatedAtUtc IS NULL AND ExpiresAtUtc > SYSUTCDATETIME() AND AttemptCount < MaxAttempts`, before running `password_verify`. Consume with `... WHERE ConsumedAtUtc IS NULL` and check that one row was affected. Apply the same pattern to passkey challenges (`CompletePasskeyAuthenticationService` consumes after verifying, with no conditional update) and to trusted-device rotation (`AzureSqlTrustedDeviceRepository` has an unconditional `CredentialVersion` write).

---

## A5. Account enumeration on request-otp and passkey setup-link (Low, Likely)

**Evidence**

- `src/Http/Action/RequestGuestOtpAction.php:83-93`: for an ineligible address it returns a fake 202 immediately after one SELECT.
- For an eligible address, `RequestGuestOtpService.php:50-67` invalidates prior challenges (UPDATE), bcrypt-hashes the code (`PasswordOtpHasher`) and INSERTs. `RequestAndSendGuestOtpService.php:50` then makes a synchronous HTTPS call to Postmark or Graph. The gap is easily 100 ms or more.
- If delivery fails for a registered address the response is `503 AUTH_OTP_DELIVERY_FAILED` (`GuestAuthErrorMapper.php:87-104`). Unknown addresses always get 202.
- `RequestGuestPasskeySetupLinkService` has the same pattern: token INSERT plus synchronous email only for eligible users.

**Scenario**

An attacker times `request-otp` for a list of addresses and learns which belong to TriCab users (staff, customers). That feeds A1. It is bounded by the 3-per-email limit, which is enough for one probe per address.

**Fix**

Queue the email and the challenge creation asynchronously, or pad responses to a constant time (for example `usleep` up to a fixed floor). Return 202 even when delivery fails, and alert out of band.

---

## A6. Trusted-device previous-credential grace allows indefinite reuse and has no theft detection (Low, Confirmed)

**Evidence**

`core/src/Identity/Domain/Entity/TrustedDevice.php:245-286`. When the matched credential is `previous`, `rotateCredential(preserveMatchedPrevious: true)` keeps the old hash and **re-extends** `previousCredentialValidUntilUtc` by 24 hours (`:277`), while also issuing a new current credential (`ExchangeTrustedDeviceSessionService.php:86-90`).

**Scenario**

An attacker copies a device credential (backup, malware, log). After the real device rotates once, the attacker's copy is "previous". Each exchange by the attacker refreshes its grace and replaces the current credential, which logs the real device out. The attacker keeps access until the device TTL expires (90 days). Nothing revokes the device on a reuse.

**Fix**

Treat a second use of a `previous` credential, or a use after a newer credential has been used, as theft: revoke the device and all sessions it issued. Never extend the grace window on a `previous` match.

---

## A7. Production guardrails require exact `APP_ENV === 'production'`; dev auth is allowed against Azure SQL (Low, Confirmed, depends on configuration)

**Evidence**

- `src/Config/Settings.php:910`: `$appEnv = self::string($env, 'APP_ENV', 'production')`. The default for a missing or empty value is production, which is good. The value is not normalised or allow-listed.
- `Settings.php:690-702`: `isDevelopment()` is `=== 'development'`, `isProduction()` is `=== 'production'`, and `isDevelopmentAuthActive()` is `isDevelopment() && devAuthEnabled`. **Dev auth fails closed:** only the exact value `development` plus `DEV_AUTH_ENABLED=true` enables it. Header parsing excludes `HTTP_*` from config (`Environment.php:117`). `.env` is not shipped in the release zip (`scripts/build-app-service-release.sh:158-169`). The X-Dev-Entra-* headers are read only in `DevelopmentAuthenticationMiddleware`. I found no path that lets them be honoured in production.
- Every other guardrail checks for `production` exactly. With `APP_ENV=staging`, `Production` or `prod` (a staging value appears in `docs/cable-calculator/PHASE-5A-...md:193`, and the deploy workflow mentions a staging slot):
  - `GUEST_EMAIL_PROVIDER` defaults to `development`, so OTPs are never emailed (`:961-978`).
  - `GUEST_OTP_DEV_REVEAL=true` is accepted and writes OTPs to the error log (`:1022`, `DevelopmentGuestOtpEmailSender`).
  - `USER_REPOSITORY` defaults to `memory` (`:913`).
  - Passkeys default to enabled and the RP/origin checks are skipped (`:1157`, `:1776`).
  - The passkey cookie loses `Secure` (`ContainerFactory.php:4571`).
  - `BLOB_AUTH_MODE=memory` and `FOUNDRY_AUTH_MODE=api-key` are allowed.
- Dev auth has no `USER_REPOSITORY !== azure-sql` guard. The documented local setups run `APP_ENV=development` against the shared Azure SQL `tricab-mel/tc` (`docs/phase13.6-...md:3`). A developer instance with `DEV_AUTH_ENABLED=true` reachable from the network (`php -S 0.0.0.0`, a tunnel) authenticates **every** request without headers as `DEV_AUTH_DEFAULT_EMAIL=thilanh@tricab.com` (`Settings.php:1451`), a real administrator, against real data.

**Fix**

- Allow-list `APP_ENV` to `{production, staging, development, test}` and refuse to boot on anything else.
- Apply production guardrails to everything except `development` and `test`, that is "non-dev means strict".
- Refuse `DEV_AUTH_ENABLED` when `USER_REPOSITORY=azure-sql` or when the DB host is not local.
- Remove the real-person default email.

---

## A8. Entra validator error disclosure and audience/client hardening gaps (Low, Confirmed)

**Evidence**

- `src/Identity/Authentication/MicrosoftEntraJwtValidator.php:74-81` wraps raw `UnexpectedValueException` and `RuntimeException` messages from php-jwt and the JWKS fetch. `BearerTokenAuthenticationMiddleware.php:147-151` returns `$exception->getMessage()` to the caller. Examples are php-jwt's `"kid" invalid, unable to lookup correct key` and "Wrong number of segments". The 501 at `:93-97` likewise reveals that Entra is unconfigured.
- There is no check on `azp`/`appid`. Any client app in the tenant that a user consents to (including consent-phishing apps) can obtain an `access_as_user` token for this API and use it fully.
- `assertRequiredScope` (`:225-250`) returns early when `ENTRA_REQUIRED_SCOPE` is empty. That would also admit ID tokens (if `ENTRA_AUDIENCE` equals the SPA client id) and app-only tokens. The default (`access_as_user`) is safe.
- `resolveVerifiedEmail` (`:127-137`) trusts `preferred_username`/`upn`/`email`/`unique_name` as "verified email" for first-time linking by email. Within a single tenant these are admin-controlled, but `email` is the mutable `mail` attribute. With A1's null-purpose fallback, a tenant admin or a misconfigured B2B guest whose `mail` attribute equals another user's address can resolve to that user.

**Fix**

- Return a generic `Access token is invalid` and log the detail server-side.
- Add an allow-list of `azp`/`appid` values (the SPA client id and the mobile client ids). Fail at boot if `ENTRA_REQUIRED_SCOPE` is empty in production.
- Link by `oid` only. Disable email-based linking for Entra, or restrict it to `upn` on verified tenant domains.

What is good here: signature verification uses JWKS pinned to the configured tenant (`login.microsoftonline.com/{tenant}/discovery/v2.0/keys`, TLS verified). php-jwt 7.x binds key to algorithm, so `alg=none` and HS/RS confusion are rejected (library behaviour). `iss` is exact-matched to the tenant (v1 and v2), with no multi-tenant acceptance. `tid` is checked with `hash_equals`. Graph audiences are rejected. `exp` and `nbf` are enforced with no leeway.

---

## A9. Apple native and Google tokens are not nonce-bound; Apple native is lenient (Low, Confirmed)

- `AppleOidcIdTokenValidator::validateNative` (`src/Identity/Authentication/AppleOidcIdTokenValidator.php:76-99`) checks no nonce. A missing or unparseable `email_verified` becomes `true` (`:84`, `:92`). The flow is **enabled by default** because `APPLE_NATIVE_CLIENT_ID` defaults to `com.tricab.TriCab` (`Settings.php:1461`; `isAppleNativeAuthConfigured` only needs the guest JWT settings).
- `GoogleOidcIdTokenValidator` has no nonce either.
- Scenario: an Apple or Google ID token for TriCab's audience that leaks (client logs, a proxy, a malicious SDK) can be replayed to `/api/v1/auth/apple/native` or `/api/v1/auth/google` within its lifetime to mint a 24-hour TriCab session.
- Fix: require a server-issued nonce (as the web Apple flow already does through the `state` JWT) for native Apple and for Google. Fail closed on a missing `email_verified`. Make native Apple opt-in. Also note that `/auth/google` and `/auth/apple*` call `IssueGuestSessionService` without `authProvider`, so those sessions are labelled `email-otp` (`AuthenticateGoogleAction.php:44-48`), which hurts audit accuracy.

---

## A10. Guest JWT identity is hard-coded to EMAIL_OTP; passkey assurance is never realised and its enrolment is mailbox-bootstrapped (Info, design risk)

- `src/Identity/Authentication/GuestVerifiedIdentityFactory.php:20`: `provider: ExternalProvider::EMAIL_OTP` for every guest JWT, whatever the `auth_provider` claim says (passkey, trusted-device, google, apple).
- As a result, passkey sign-in yields LOW, not the SUBSTANTIAL that `AuthenticationAssurance` documents. Platform-admin and identity administration are unreachable without Entra. This fails closed, which is good.
- Risk: anyone who "fixes" this mapping makes SUBSTANTIAL reachable by anyone who can read the user's mailbox. Passkey enrolment needs only the emailed setup link (`RequestGuestPasskeySetupLinkService`, `VerifyGuestPasskeySetupTokenService`), with no existing-factor step-up. That would open USER.ROLE.ASSIGN and similar to an email-only attacker (A1).
- Fix: keep the hard-coding, or, if passkeys are to count as SUBSTANTIAL, require enrolment from an existing SUBSTANTIAL or HIGH session (Entra, or an administrator-issued in-person enrolment).

---

## A11. Act-as reads are not audited per action (Low, Confirmed)

`src/Http/Middleware/ActAsMiddleware.php:57,97`: only POST, PUT, PATCH and DELETE are logged. GET requests made while switched (HR records, onboarding medical, bank and immigration data, finance) leave only the start and end audit rows. Fix: log every switched request, or at least GETs on sensitive modules. Other act-as checks were reviewed and are good; see the "Looks good" list.

---

## A12. Passkey hygiene (Info)

- `VerifyGuestPasskeySetupTokenService.php:38-61` does not consume the setup token. A link can be verified repeatedly within its 15-minute TTL, creating several registration sessions. The token is consumed only on completion (`CompletePasskeyRegistrationService.php:117-121`), and sessions already created stay usable for 5 minutes. Fix: consume the token when it is verified.
- `SyncedPasskeyCounterChecker.php:22` accepts an equal non-zero counter. WebAuthn requires strictly increasing. Fix: `<=`.
- Revoking a passkey (`ManageUserPasskeysService::revoke`) does not revoke guest sessions or trusted devices it issued.
- `completeRegistration`/`completeAuthentication` take `$host` from the request `Origin` header after an allow-list check. The library's origin step (webauthn-lib default when `setAllowedOrigins` is not configured) accepts the rpId **and any subdomain** in `clientDataJSON.origin`. A subdomain takeover on `*.tricab.com` would therefore be accepted. Fix: call `CeremonyStepManagerFactory::setAllowedOrigins($rp->allowedOrigins)`.
- Production RP is forced to `tricab.com` / `https://www.tricab.com` (`Settings.php:1776-1806`), while the deploy workflow says the app is served on `tricab.ai`. Passkeys will fail in production. That is functional, not a security issue.

## A13. JWKS cache placement and refresh (Info)

`EntraJwksClient.php:158-168` (and the Google and Apple equivalents) cache JWKS as JSON in `<app>/var/cache/*` under `/home/site/wwwroot` and trust the file without integrity checks. Any file-write primitive elsewhere in the app becomes token forgery for every provider. An unknown `kid` never triggers a refetch, so Entra key rollover can cause up to one hour of 401s. Fix: cache outside the web root (or in APCu), and refetch once on an unknown `kid`, rate-limited.

## A14. Dependency advisories on the passkey input path (Low, Needs-verification)

`composer audit --locked` reports three advisories against `symfony/serializer` v8.1.9: CVE-2026-118515 (UploadedFile from request data), DataUriNormalizer local file read, and ObjectNormalizer dotted-key `#[Ignore]` bypass. Its only use is `WebauthnSerializerFactory` deserialising **unauthenticated** passkey JSON (`WebauthnLibPasskeyCeremony::parseCredential`). Whether the vulnerable normalizers are registered by webauthn-lib's factory could not be checked (`vendor/` absent). Fix: upgrade to ≥ 8.1.11.

## A15. Defaults (Info)

- `CORS_ALLOWED_ORIGINS` defaults to `http://localhost:5173` in every environment (`Settings.php:1156`). Production is same-origin, so this is harmless, but set it explicitly or to empty in production.
- `APP_DEBUG` is not rejected when `APP_ENV=production` (`Settings.php:1447`, `AppBuilder.php:371`): stack traces would be exposed.

---

## Looks good (coverage notes)

- **Dev auth gating:** exact `APP_ENV=development` plus `DEV_AUTH_ENABLED=true` (`Settings.php:700`). Missing `APP_ENV` defaults to `production`. HTTP headers cannot reach config (`Environment.php` skips `HTTP_*`). `.env` is not packaged. Dev auth skips when a Bearer token is present. The X-Dev-Entra CORS headers are only added in E2E mode, which refuses production and Azure SQL.
- **Entra JWT:** tenant-pinned JWKS over verified TLS; issuer pinned per tenant; `tid` compared with `hash_equals`; Graph audience rejected; delegated `scp` required by default; subject is `oid` (allow-listed to `oid`/`sub`); no alg confusion (php-jwt 7 binds key to alg).
- **Composite routing:** the guest/Entra choice comes from the unverified `iss`, but each path does full validation and never falls back (`CompositeBearerTokenValidator`).
- **Guest JWT:** HS256 with a secret of at least 32 bytes; iss, aud and lifetime capped at 24 hours; bound to a server-side session through the SHA-256 token hash, session id, jti, sub and email; user status re-checked on every request; logout revokes server-side; permissions re-read from the DB on every request.
- **OTP:** `random_int` 6 digits; bcrypt hash; 5-minute TTL; 5 attempts (subject to the A4 race); challengeId required and bound to email and purpose; earlier challenges invalidated on a new request; uniform 202 for unknown addresses; codes never returned or logged in production.
- **Identity resolution:** never auto-creates users or grants roles (no JIT provisioning). Duplicate email gives `IdentityAmbiguous`. An existing provider subject mismatch gives a conflict. A linked subject must still match one of the user's addresses.
- **Google:** iss and aud allow-list (web, iOS, Android); `email_verified` required. **Apple web:** stateless HMAC `state` binds a SHA-256 nonce, and the ID token nonce is checked with `hash_equals`; iss and aud checked; email verified.
- **Passkeys:** UV required at registration and assertion; resident key; challenge 32 random bytes with TTL, single-use, bound to purpose and to the registration session; duplicate credential rejected; userHandle checked by the library; ownership enforced for list, rename and revoke; registration cookie HttpOnly and Secure in production; fake ceremony impossible on Azure SQL or outside development.
- **Trusted devices:** 256-bit credential, SHA-256 at rest, installation-id binding, revocation and expiry; eligibility re-checked on exchange; generic error for not-found and invalid; revoke scoped to the owner.
- **Act-as:** start requires ADMINISTRATOR plus SUBSTANTIAL (in practice Entra) through the route; caller re-validated (role, staff eligibility, assurance) on every request; session bound to the admin; 30-minute expiry; administrator targets refused at start and on every request; act-as endpoints ignore the header, so no chaining; customers sending the header get 403; target judged by the staff-route guard; start and end audited.
- **Staff route guard:** URL-decoded and case-insensitive prefix match on both the path and the route pattern.
- **CORS:** exact allow-list with `hash_equals`; no reflection of arbitrary Origin; `null` origin not allowed; `Vary: Origin` set; credentials only for allowed origins.
- **Logout:** idempotent and revokes by session id or token hash. Only guest sessions can be revoked; Entra sessions get a 403.

## Not covered or out of scope (cross-references for other reviewers)

- Per-module authorization outside `/api/v1/admin/*` (TriExpense admin `TRIEXPENSE.ACCESS` gating role assignment, finance, HR, NPR): `CustomerActorStaffRouteGuardMiddleware` does not cover those prefixes, and their in-service permission checks use the unscoped user.
- Platform group sync (Entra group to role mapping) was not reviewed for JIT role grants.
- `tricab-web` token storage was not reviewed.
