# Platform Settings

The web admin route `/admin/settings` manages AI model choices and notification
addresses. All five API operations require the actual `ADMINISTRATOR` role and
a passkey or Microsoft Entra session. App permissions alone do not grant access.

## Rollout

1. Review and apply `database/scripts/development/create-platform-settings-schema.sql`
   to the target Azure SQL database through the normal migration process. This
   creates settings and history tables without changing any current configuration.
2. Deploy the API and its Python services together, then deploy the web app. The
   internal AI request contracts now carry optional server-selected models.
3. Open Platform Settings as an administrator and review the baseline values for
   the intended environment before saving. The first save captures the baseline
   alongside the overrides. No migration script reads secrets or copies addresses
   from a developer checkout into production.

Rows are keyed by `APP_ENV`, including history and version checks. Development and
production using the same database remain separate when they use different
`APP_ENV` values. Deployment slots sharing both `APP_ENV` and a database share
settings; give an isolated test slot its own environment value or database.

No production migration or deployment is performed by the implementation tests.
The in-memory repository is for tests and development fixtures, not durable settings.

## AI

Each task has three modes: its baseline, an explicit compatible model, or the
platform default. Only tasks explicitly set to inherit the platform default
follow changes to it. Register new model identifiers in the catalog using the
existing provider connections; keys, endpoints and credentials remain in secure
deployment configuration. Catalog capabilities are administrator declarations,
not live provider discovery. The backend checks provider and required capabilities
before accepting assignments. Registration does not provision a model deployment.

Coverage includes the shared Foundry assistant/log-note model, CableFit research,
RFQ mail classification, RFQ parser/reader/OCR stages, product finder, and finance
AI purposes. The AR decisions purpose requires the decisions capability, while
the RFQ reader uses its existing Azure endpoint and deployment credentials.

PHP requests load one configuration snapshot at startup. Finance, RFQ reader and
CableFit loops refresh between passes. Work already running retains its snapshot.
Snapshots come from the application cache (see "Runtime cache"), not a database
query each time. PHP passes explicit sidecar model choices over existing internal connections.
Python uses request-local state, not mutations of process environment variables.

Python tasks without explicit central choices retain their sidecar defaults.
Their displayed baseline is derived from the API environment and matching code
fallbacks. If a local Python service has a separate environment file, verify its
model values before selecting or registering them centrally. Production services
should use consistent deployment settings. Secrets are never returned by the API.

## Notifications

The module manages finance, TriExpense, onboarding and NPR test recipients; NPR
role/queue/signatory addresses; Postmark sender; RFQ mailboxes; customer-contact
accounts mailbox; and finance AR/AP mailboxes. Existing delivery enable flags,
off/test/live modes and mailbox authorization stay in force. Setting an address
does not grant Graph access or enable a disabled sender.

The first save freezes PHP model and notification baselines so reset does not
silently follow a later environment edit. Blank values and recipient lists are
preserved. New operational addresses can be entered here without adding App
Service environment variables.

## History and failures

Saves include the last loaded version. Concurrent edits return HTTP 409 rather
than overwriting another administrator's changes. Updates, resets and restoration
of a historical version create new audit entries in the same transaction.

Before the schema is installed, reads expose baseline settings. Saving requires
the schema. Health endpoints do not load central settings at bootstrap.

Runtime entry points use `ContainerFactory::create(...,
hydratePlatformSettings: true)`. Offline tools and test containers remain lazy by
default. Any future operational worker using models or notification addresses
must opt in and refresh between jobs or batches.

## Runtime cache

`tc` is serverless and pauses when idle, so requests and workers do not read the
settings from it each time. The runtime overrides are kept in the existing
application cache (`APP_CACHE_DRIVER`; `file` under `var/cache/app` on the `core`
and `api` slots), under `platform-settings:runtime:<APP_ENV>`.

- A save, reset or restore writes the new overrides to the cache straight away.
  If that write fails, the cached copy is dropped so readers go back to the database.
- Web requests and the finance, RFQ reader, CableFit, NPR, onboarding and
  TriExpense jobs use the copy and check the database again once it is more than
  5 minutes old (`PlatformSettingsRuntimeCache::REFRESH_SECONDS`).
- The RFQ mail reader never checks a copy it has: it runs every minute and must
  leave `tc` asleep. It reads the database only when there is no copy, after its
  off and paused checks, and exits 75 if the database is resuming.
- If a check fails, the last good copy is used and the failure is logged
  (`[platform-settings] reading the database failed`). With no copy, the error
  propagates: jobs must not silently revert to old mail recipients.

Slots have separate file systems. A save reaches its own slot at once; another
slot sharing the database and `APP_ENV` picks it up on its next 5-minute check.
Saves made through `tricab.ai` land on the `core` slot, which also runs the WebJobs.
If the RFQ mail reader must see a change made elsewhere sooner, clear its copy on
`core` by deleting the cache entry or saving again from `tricab.ai`.

## Non-browser verification

Run the platform settings endpoint/service/catalog/runtime-cache tests, existing configuration
and AI client tests, Python finance/RFQ runtime tests, and the web settings and
navigation tests. Run PHPStan on changed PHP sources and the web typecheck/build.
Use stubs or in-memory repositories; the local `.env` can point to production.
