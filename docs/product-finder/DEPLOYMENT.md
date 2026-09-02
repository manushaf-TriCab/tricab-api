# Product finder — running the Python service inside the App Service

The cable-selection rules are Python and live in `core/python/`. They deploy in
the same zip as the PHP application, run as a persistent process on
`127.0.0.1:8001`, and PHP reaches them over that loopback socket. There is no
second App Service, no container, and no new Azure resource.

```
core/python/
  tricab_cable_rules/   the rules — code grammar, style register, ranking, resolution
  product_finder/       the service that reads them
  tests/                the regression suite (development only)
  tools/                the divergence guard (development only)
  wsgi.py               gunicorn entry point
  requirements.txt      what the App Service installs
```

## Layout on the instance

| Path | What | Why there |
|---|---|---|
| `/home/site/wwwroot/app/python` | the code | Staged from `core/python` by the release script. `app/` is denied by nginx, so nothing here is servable. |
| `/home/data/python/venv` | the virtualenv | **Outside `wwwroot`.** A zip deploy replaces `wwwroot` wholesale, so a venv under it is rebuilt on every release. `/home` is the persistent share, so this survives deploys and is shared across instances. `tc-web` puts its venv at `/home/site/wwwroot/rfq-parser/.venv`; do not copy that. |
| `/home/LogFiles/product-finder/` | startup, access and error logs | Reachable from Kudu without a redeploy. |

## Startup command

Set the Linux App Service startup command to:

```text
bash /home/site/wwwroot/startup.sh
```

This replaces the previous one-liner. `startup.sh` still applies the nginx
config first — that is unchanged and must keep happening — then creates the venv
if `requirements.txt` has changed, then launches gunicorn under a restart loop.

**One-time install.** There is none. The first startup after a deploy builds the
venv itself and records `sha256(requirements.txt)` at
`/home/data/python/venv/.requirements.sha256`; later startups skip the install
unless that hash changes. On a multi-instance plan one instance takes a
`mkdir`-based lock and the others wait rather than racing pip against a shared
Azure Files directory.

To rebuild it by hand from Kudu:

```bash
rm -rf /home/data/python/venv
# then restart the App Service
```

## What supervises what

Gunicorn's master supervises its workers. **Nothing supervises the master** — an
OOM kill or a bad start leaves the finder simply gone until the next App Service
restart, which can be days. So the launch sits inside a backed-off restart loop
in `startup.sh` (2s, doubling to 60s), which is what actually keeps it up.

## Deliberately no nginx location

The finder is not routed publicly. Catalogue visibility is enforced in PHP by
`AssistantCatalogueVisibilityResolver`, which fails closed to `PUBLIC` and grants
`STAFF` only to an Entra identity holding `PRODUCT.READ`. A public route to the
Python process would be a second, unguarded door to the same data — the process
has no authentication of its own, because it is only ever reachable from the
instance. `Settings::from()` enforces the other half of that: booting with
`PRODUCT_FINDER_ENABLED=true` and a non-loopback `PRODUCT_FINDER_BASE_URL`
throws rather than starting.

`/startup.sh` sits at the web root because the App Service reads it from there,
so `deploy/app-service/default` denies it explicitly — `location /` would
otherwise serve it as a static file, and it names the venv path, the log
directory and the bind address.

## App settings

| Setting | Default | Notes |
|---|---|---|
| `PRODUCT_FINDER_ENABLED` | `false` | Off until the Python service is actually running. |
| `PRODUCT_FINDER_BASE_URL` | `http://127.0.0.1:8001` | Loopback only; enforced at boot. |
| `PRODUCT_FINDER_TIMEOUT_SECONDS` | `20` | Longer than the shared HTTP default: the first request loads an 18,994-product catalogue. |
| `PRODUCT_FINDER_WORKERS` | `2` | Read by `startup.sh`. Each worker holds its own catalogue, ≈150 MB. |
| `PRODUCT_FINDER_SIZING_SOURCE` | `json` | `json` \| `mysql` \| `auto`. Leave `json` — see below. |

## Nothing here can fail the site

If Python is missing, the install fails, or the process dies, the API still
answers. `HttpProductFinderClient` never throws:
`POST /api/v1/product-finder` returns 200 with `status: unavailable` and a
`warnings` entry, the same shape a disabled finder produces. That is one
degraded path to reason about rather than three, and it is the same
primary/fallback posture as `FallbackAssistantResponseProvider`.

**The risk worth naming:** the Linux PHP App Service image is not guaranteed to
ship `python3`. `startup.sh` checks and logs an explicit error to
`/home/LogFiles/product-finder/startup.log` rather than failing quietly, but if
that error fires the finder cannot run in this image and the deployment needs a
different answer. Check that log after the first deploy — a finder that is
quietly absent looks exactly like a finder that has nothing to say.

## Sizing data

The finder sizes from `resources/cable-calculator/v1/` — the same SHA-256 pinned
snapshots PHP's `NativeCableCalculatorEngine` reads — not from legacy MySQL,
which is unreachable from the App Service anyway.

**That is not a neutral swap, and it needs a decision.** The snapshot is a
capture of the cable calculator's runtime database, and it has diverged from
live `tc.cable_specs`:

| | live `tc-mysql-live` | snapshot |
|---|---|---|
| `cable_specs` rows | 9,820 | 9,338 |
| rows only in one | 604 (22 styles) | 122 (8 styles) |
| `rating` rows | 557 | 557 (identical) |

Over a 1,920-case sweep of ambient / operating / config / core count /
installation / conductor / DC / load, **548 cases (29%) choose a different
cross-section**, across 113 styles. For example V2 three-core at 50 A picks
16 mm² from the snapshot and 35 mm² from live, because the two datasets do not
hold the same constructions. See the Phase 3 notes in
`ai-product-discovery/PLAN.md`.

Two further gaps, both from the capture rather than the port:

* `cable_specs.br` (bend radius) is not a column in the snapshot, so it reports
  as 0. It is only ever displayed, never computed with.
* `tc.price` is not captured, so "minimum cost" falls back to smallest conductor
  area with a warning. Phase 1 concluded live price and stock should come from
  `TRICAB_COST_API_URL` rather than any snapshot.

`PRODUCT_FINDER_SIZING_SOURCE=mysql` uses live MySQL instead, for a developer on
the VPN. It cannot be the App Service's source.

## No ODBC driver

The finder does not open Azure SQL. It never did for a result — the only Azure
SQL code was a health probe — so `pyodbc`, `azure-identity` and the
`msodbcsql18` native driver are all gone from this service's install. PHP owns
that connection and already publishes `/health/database`.

## Local development

```bash
cd core/python
python3 -m venv /path/outside/this/tree/venv     # not under core/
/path/outside/this/tree/venv/bin/pip install -r requirements-dev.txt

PYTHONPATH=. .../venv/bin/python -m flask --app product_finder.app run --port 8001
```

**Keep the venv out of `core/`.** Composer's path repository copies `core/`
wholesale into `vendor/tricab/tricab-core/` — `docs/`, `phpunit.xml` and even
`.gitignore` are already duplicated there — so a venv under `core/python` is
copied on every `composer install` and again into the release zip. The release
script refuses to build if it finds one.

Then in `tricab-api/.env`:

```ini
PRODUCT_FINDER_ENABLED=true
PRODUCT_FINDER_BASE_URL=http://127.0.0.1:8001
```

`product-finder/preview.html` still drives the service directly as a harness;
point `PRODUCT_FINDER_PREVIEW` at its directory if it is not two levels above
the repository.

## Keeping the rules honest

Until Phase 7 moves the RFQ parser in, `tricab_cable_rules` and the parser's
`matcher/` are two copies of the same live rules:

```bash
python3 core/python/tools/check_rules_sync.py --matcher /path/to/rfq-parser-mvp
```

It checks that the package's modules import nothing outside themselves and the
standard library, and that each is byte-identical to the parser's after three
declared packaging patches. Without a parser checkout it reports the second
check as SKIPPED rather than passing it.
