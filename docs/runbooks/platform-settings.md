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

The module manages finance, TriExpense, onboarding and NPD Request test recipients;
NPD Request role/queue/signatory addresses (the `NPR_*` settings; NPD Request is the
name people see, NPR the name in code); Postmark sender; RFQ mailboxes; customer-contact
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
- Web requests and the finance, RFQ reader, CableFit, NPD Request, onboarding and
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

## Which app settings still matter

Until the first save in an environment, its App Service settings (or `.env`) are
still the live source: the page shows them, and changing one in Azure works as
before. The first save captures the current values as that environment's
baseline. From then on:

| Setting | Still maintained as an app setting? |
|---|---|
| The notification addresses in the Notifications tab | No. The page and the captured baseline apply; editing the app setting has no effect. |
| `FOUNDRY_MODEL_DEPLOYMENT`, `RFQ_MAIL_CLASSIFIER_MODEL`, `CABLEFIT_RESEARCH_MODEL` | No, as above. |
| Python service models (`RFQ_READER_MODEL`, `RFQ_READER_NORMALIZER_MODEL`, `RFQ_NORMALIZER_MODEL`, `RFQ_TABLE_SHAPE_MODEL`, `RFQ_LLM_MODEL`, `RFQ_VISION_MODEL`, `RFQ_VISION_ESCALATION_MODEL`; the last three are Foundry deployment names, and a value with a `/` in it, an OpenRouter id from before the move, is ignored by the parser) | Yes, unless the task has a model chosen in the page. The services keep their own setting otherwise. |
| Finance AI models | Never were app settings: code defaults, or the page. |
| Secrets, keys, endpoints, on/off switches and modes (`FOUNDRY_ENDPOINT`, `NPR_MAIL_MODE`, `RFQ_MAIL_READ_ENABLED`, ...) | Yes, always. They are deliberately not in the page. |
| Addresses the page does not cover: `NPR_MAIL_SENDER`, `FINANCE_TASK_MAIL_FROM`, `POSTMARK_FROM_NAME` | Yes. Candidates to add to the page. |

Once the page has been in use for a while, remove the app settings in the first
two rows from each environment, so nobody edits a value that no longer applies.
This is safe after the first save: the captured baseline no longer reads them.
Each `APP_ENV` has its own baseline, so development and local `.env` files keep
applying until someone saves in that environment.

## Adding a setting for a new app

Settings are typed, not a free key/value list, so a new one is a code change and a
deploy. No database change is needed: overrides are stored as JSON. After the
deploy the setting appears with the environment's current value as its baseline,
and from then on an administrator changes it in the page.

**A new AI task (one model choice)**

1. `src/PlatformSettings/PlatformSettingsService.php`: add the key to
   `PHP_TASKS` (PHP calls the model), `PYTHON_TASK_ENV` (a Python service
   setting, mapped to its environment variable) or `FINANCE_PURPOSES` (a finance
   AI purpose), and give it a baseline in `defaults()`: what the app uses today.
2. `PlatformModelCatalog::requirements()`: the provider (`foundry` or
   `openrouter`) and the capabilities it needs (`text`, `vision`, `structured`,
   `decisions`), so only compatible models can be chosen.
3. Make the app use it. PHP: add the key to the list in
   `Settings::withPlatformOverrides()` and read the `Settings` property, or pass
   `$settings->platformAiModels['<key>']` to the client. Python: use the model the
   API sends with each request, as the RFQ parser (`rfq_parser/model_settings.py`),
   finance AI (`model`) and product finder (`runtimeModel`) do.
4. Web: add a label to the task list in `PlatformSettingsPage.tsx`. Without one the
   task still shows, under its raw key.

**A new notification address or mailbox**

1. A `Settings` property for it (default `''`), added to
   `PlatformSettingsService::RECIPIENTS` and to the address list in
   `Settings::withPlatformOverrides()`. The app reads the property. A new address
   needs no app setting: its baseline is blank and the page sets it.
2. Web: add a label to the notifications list in `PlatformSettingsPage.tsx`.

**For either**

- If the app runs as a script or WebJob, build its container with
  `ContainerFactory::create(..., hydratePlatformSettings: true)`, once per pass or
  job. A worker that runs often and must leave `tc` asleep also passes
  `refreshPlatformSettings: false` (see "Runtime cache").
- Test the baseline, the validation (unknown key, incompatible model, invalid
  address) and that a saved value reaches the app.
- One key appears in up to four places: the service, the catalog, `Settings` and
  the web page. A missed one fails quietly: a missing label shows the raw key, and
  a key missing from `withPlatformOverrides()` is saved but never used by PHP.

## Non-browser verification

Run the platform settings endpoint/service/catalog/runtime-cache tests, existing configuration
and AI client tests, Python finance/RFQ runtime tests, and the web settings and
navigation tests. Run PHPStan on changed PHP sources and the web typecheck/build.
Use stubs or in-memory repositories; the local `.env` can point to production.
