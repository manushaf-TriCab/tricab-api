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
| `/home/site/wwwroot/app/python/_vendor` | the dependencies | Wheels resolved at build time for the image's CPython 3.11 on x86_64 and shipped in the zip. `startup.sh` puts this on `PYTHONPATH`. There is no virtualenv — see below. |
| `/home/LogFiles/product-finder/` | startup, access and error logs | Reachable from Kudu without a redeploy. |

## Startup command

Set the Linux App Service startup command to:

```text
bash /home/site/wwwroot/startup.sh
```

This replaces the previous one-liner. `startup.sh` still applies the nginx
config first — that is unchanged and must keep happening — then launches
gunicorn under a restart loop.

**There is no install step, and there must not be one.** The Linux PHP image
ships `python3` (3.11.2 at `/usr/bin/python3`) but *not* Debian's
`python3-venv`, so `python3 -m venv` fails outright:

```
The virtual environment was not created successfully because ensurepip is not
available.  On Debian/Ubuntu systems, you need to install the python3-venv
package using the following command.

    apt install python3.11-venv
```

An earlier revision of this document described building a venv on first
startup and caching it at `/home/data/python/venv`. That cannot work on this
image; the venv step failed in under a second and the finder never started,
while the API reported `sizing: unavailable` exactly as designed.

The release vendors the wheels instead. `scripts/build-app-service-release.sh`
resolves them for the target interpreter, not the build machine:

```bash
python3 -m pip install --target app/python/_vendor \
    -r core/python/requirements.txt \
    --platform manylinux2014_x86_64 --python-version 3.11 \
    --implementation cp --only-binary=:all:
```

`--only-binary=:all:` is required for pip to honour the platform triple.
MarkupSafe is the one dependency with a native extension; the build asserts
every `.so` in the tree is `x86_64-linux-gnu` before packaging, because a wheel
built for the wrong platform is silent until import time on the instance. The
console scripts under `_vendor/bin` are dropped — their shebangs point at the
build machine's python — so `startup.sh` runs `python3 -m gunicorn` with
`PYTHONPATH=_vendor:app/python`.

`requirements.txt` is unpinned, so the build records what it actually resolved
at `app/python/_vendor/VENDORED.txt`, and `startup.sh` logs that line. The
release zip is the only place those versions are fixed.

**If the image's Python ever moves off 3.11**, the vendored extension stops
importing. `startup.sh` compares the running interpreter against 3.11 and logs
a warning naming the version to rebuild with; change `PY_VERSION` at the top of
the build script and re-release.

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
| `PRODUCT_FINDER_WORKERS` | `2` | Read by `startup.sh`. Each worker holds its own catalogue, ≈150 MB, less than that since gunicorn runs with `--preload` and `TRICAB_WSGI_WARM=1` (the master loads the catalogue once and forks; copy-on-write sharing is partial because refcounts dirty pages). |
| `PRODUCT_FINDER_SIZING_SOURCE` | `json` | `json` \| `mysql` \| `auto`. Leave `json` — see below. |
| `PRODUCT_FINDER_DATASET_DIR` | probed | The pinned snapshots. `startup.sh` sets it to `/home/site/wwwroot/app/resources/cable-calculator/v1`; set it by hand only for a layout neither depth covers. |

### PHP runtime tuning

`startup.sh` also writes `conf.d/zz-tricab.ini` (OPcache, realpath cache) and `php-fpm.d/zz-tricab.conf` (pool size) before php-fpm starts, because the image runs `<startup command>; php-fpm;` in sequence. The effective CLI and FPM values are logged to `startup.log` as `effective PHP (cli|fpm)` and `effective php-fpm pool`.

### Why the dataset path needs stating

`json_specs_repo` locates the snapshots relative to its own file. In the
repository that is `core/python/product_finder/`, three levels below
`resources/`. The release stages `core/python` as `app/python`, which drops the
`core/` level, so the same walk lands one directory *above* `app/` — where
there is no `resources/`. The symptom is specific and misleading: matching
returns a product code normally while `sizing` reports itself unavailable.

The module now probes both depths and honours
`PRODUCT_FINDER_DATASET_DIR`, and `startup.sh` sets that variable explicitly
rather than leaving the release to a guess.

## Nothing here can fail the site

If Python is missing, the install fails, or the process dies, the API still
answers. `HttpProductFinderClient` never throws:
`POST /api/v1/product-finder` returns 200 with `status: unavailable` and a
`warnings` entry, the same shape a disabled finder produces. That is one
degraded path to reason about rather than three, and it is the same
primary/fallback posture as `FallbackAssistantResponseProvider`.

**The risk worth naming** was that the Linux PHP App Service image might not
ship `python3` at all. It does — 3.11.2 — so that is settled. What it omits is
`python3-venv`, which is what actually broke the first deployment and is why
the dependencies are now vendored rather than installed.

The live risk is narrower: the vendored wheels are pinned to CPython 3.11 and
x86_64, so a base-image upgrade that moves the interpreter breaks the import.
`startup.sh` logs a warning naming the version it saw. Check
`/home/LogFiles/product-finder/startup.log` after any deploy — a finder that is
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
