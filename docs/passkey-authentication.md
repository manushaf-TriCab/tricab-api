# Guest passkey authentication (WebAuthn)

TriCab passkeys are an additional **identity** provider (`ProviderCode = passkey`).
They do not assign roles or permissions. After a credential is verified, TriCab
resolves `UserId` and issues the existing guest JWT/session.

Library: `web-auth/webauthn-lib` (Spomky-Labs). Chosen because it is the maintained
PHP 8.3 FIDO2 implementation and performs attestation/assertion cryptography.

## Sign counter / cloned authenticators

Synced platform passkeys (iCloud Keychain, Google Password Manager, etc.) often
report `signCount = 0`. TriCab does **not** reject a zero counter.

A clone is only suspected when the stored counter is greater than zero **and**
the incoming counter is greater than zero **and** the incoming value is lower
than the stored value. That case fails authentication (`AUTH_PASSKEY_COUNTER_INVALID`).

## Environment

```text
PASSKEY_ENABLED=true
PASSKEY_RP_ID=tricab.com
PASSKEY_RP_NAME=TriCab
PASSKEY_ALLOWED_ORIGINS=https://www.tricab.com,https://tricab.com
PASSKEY_PUBLIC_APP_BASE_URL=https://www.tricab.com
PASSKEY_SETUP_TOKEN_TTL_SECONDS=900
PASSKEY_REGISTRATION_CHALLENGE_TTL_SECONDS=300
PASSKEY_AUTH_CHALLENGE_TTL_SECONDS=300
```

Development defaults `PASSKEY_RP_ID=localhost` and origins from `CORS_ALLOWED_ORIGINS`.
Production rejects localhost and `*.azurewebsites.net` as the RP ID.

SPA flag: `VITE_PASSKEY_ENABLED=true`.

Apply `database/scripts/development/create-passkey-schema.sql` on Azure SQL.

## Native apps (future)

The same ceremonies are reused:

```text
POST /api/v1/auth/passkeys/registration/options
POST /api/v1/auth/passkeys/registration/complete
POST /api/v1/auth/passkeys/authentication/options
POST /api/v1/auth/passkeys/authentication/complete
```

Registration also accepts `X-TriCab-Passkey-Registration-Session` so native clients
and the localhost SPA (port 5173 → API 8080) are not dependent on cookies.

### iOS

Use AuthenticationServices `ASAuthorizationPlatformPublicKeyCredentialProvider`.
Configure Associated Domains / webcredentials for `tricab.com`.

### Android

Use Credential Manager. Configure Digital Asset Links for the TriCab Android
application against `https://tricab.com`.
