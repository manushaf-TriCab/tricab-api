# core/python — the cable-selection rules, and the finder and RFQ parser that read them

Domain logic belongs in core. That is read here as a statement about **location**
— one home, one version, one pull request, shipped in the same App Service zip —
not about language. The selection rules are Python because they are already
written, tested and live-verified in Python, and re-implementing them in PHP
would create a second implementation of the same rules. That is precisely how
the 19-style / 114-style divergence between `CableStyleResolver` and the RFQ
parser arose.

```
tricab_cable_rules/   the rules. Two services import this; neither copies it.
product_finder/       requirement in, sized product code out.
tests/                the regression suite, including the ten defect fixes.
tools/                check_rules_sync.py — the guard against a second copy.
wsgi.py               gunicorn entry point.
```

## `tricab_cable_rules`

Seven modules — `normalize`, `accessories`, `cable_code`, `accessory_text`,
`extractor`, `data`, `resolver` — chosen because they are the **closed import
subgraph** of `resolver.match_line`: nothing in them imports anything else from
the RFQ parser, reads an environment variable, opens a socket or touches a
database. That property is what makes the boundary real, and
`tools/check_rules_sync.py` enforces it rather than trusting it.

`resolver.py` is 2,523 lines and a good part of it is RFQ-line handling. It came
across whole anyway: it is not separable from cable selection without forking
the file, and a fork is the failure this package exists to prevent. What stayed
with the parser is everything that wraps it — mail ingest, attachment reading,
the LLM fallback, draft and log stores, pricing, the agent loop, the pipeline.

`__init__.py` names the operations both consumers use. Import from the package
root; the module layout is an implementation detail.

## Two copies, until the RFQ cutover

The live RFQ parser (`rfq/wt-main-verified`, pinned at `1942f91`) keeps running
production and keeps taking fixes until the platform's RFQ module replaces it, so
its `matcher/` stays the authority for the rules and the parser code alike. Two
guards keep this tree equal to it:

```bash
python3 tools/check_rules_sync.py --matcher /path/to/rfq/wt-main-verified    # the rules
python3 tools/sync_rfq_parser.py  --matcher /path/to/rfq/wt-main-verified    # rfq_parser (--write to regenerate)
```

`check_rules_sync.py`: the rule modules are byte-identical except for three
declared packaging patches, and data files match (text compared without regard
to line endings). `sync_rfq_parser.py`: every generated `rfq_parser` module is
the source module with its imports rewritten, plus the patches the tool declares.
Both run in the suite (`tests/test_rules_sync.py`, `tests/test_rfq_parser_sync.py`)
when `RFQ_MATCHER_PATH` names a checkout, and report SKIPPED rather than passing
without one.

At cutover the source stops being the authority: both tools are deleted, and
**that deletion is the signal the second copy is gone.**

## `rfq_parser`

The production parser's extraction, matching and pricing (plan Phase 3,
`platform/RFQ-IMPLEMENTATION-PLAN.md`), generated from the pinned source — see the
package docstring for what is and is not here. It runs as its own private
service (`rfq_wsgi.py`, `127.0.0.1:8002`) beside the finder, started by
`startup.sh` only when `RFQ_PARSER_ENABLED=true`.

Email: `rfq_parser/mail_envelope.py` is the `rfq-mail-envelope.v1` contract and `POST /v1/parse` takes it as `email`
([`docs/rfq-mail-envelope.md`](../../docs/rfq-mail-envelope.md)). The mail reading itself is generated into
`rfq_parser/mail_ingest.py` from the source's `matcher/mail_ingest.py`; what of that file is not brought across is
listed in `rfq_parser/_unported.py`.

`tools/compare_rfq_parser.py` runs a folder of real RFQs through the source and
this package with the same deterministic cost API and diffs every field. On
28 Sep 2026 the 119 inputs of `rfq/sample rfq` (108 files plus the text files as
pasted lines) gave 4,520 identical rows. The corpus is customer data; the tool
prints only differences.

## `product_finder`

The prototype from `platform/product-finder/`, moved here and rewired. Two
things changed beyond the import paths:

**It no longer inserts a path.** `finder/config.py` used to `sys.path.insert`
against `RFQ_MATCHER_PATH`, which in `.env.example` was an absolute path on one
developer's Mac. The service could not run anywhere else. It imports
`tricab_cable_rules` directly now and the setting is gone.

**It sizes from the pinned snapshots.** `product_finder/specs.py` chooses
between `JsonSpecsRepository` (`resources/cable-calculator/v1/`, the default)
and `SpecsRepository` (live legacy MySQL over the VPN), on
`PRODUCT_FINDER_SIZING_SOURCE`. The snapshots make the service self-contained
and are the same rows PHP's `NativeCableCalculatorEngine` reads — but they have
diverged from live `tc.cable_specs`, and 29% of sizing cases pick a different
cross-section as a result. That is measured and written up in
[`../../docs/product-finder/DEPLOYMENT.md`](../../docs/product-finder/DEPLOYMENT.md);
read it before assuming the two sources are interchangeable.

The finder also no longer opens Azure SQL. It never did for a result — the only
Azure SQL code was a health probe — so `pyodbc`, `azure-identity` and the
`msodbcsql18` native driver are gone.

## Running it

```bash
python3 -m venv /somewhere/outside/this/tree/venv
/somewhere/.../venv/bin/pip install -r requirements-dev.txt
PYTHONPATH=. /somewhere/.../venv/bin/python -m pytest
PYTHONPATH=. /somewhere/.../venv/bin/python -m flask --app product_finder.app run --port 8001
PYTHONPATH=. /somewhere/.../venv/bin/python -m rfq_parser.app          # 127.0.0.1:8002
```

**Keep the virtualenv out of this tree.** Composer's path repository copies
`core/` wholesale into `vendor/tricab/tricab-core/` — `docs/`, `phpunit.xml` and
`.gitignore` are already duplicated there — so a venv under `core/python` is
copied on every `composer install` and again into the release zip.
`build-app-service-release.ps1` refuses to build if it finds one.

Deployment, App Service layout and the sizing-data caveat:
[`docs/product-finder/DEPLOYMENT.md`](../../docs/product-finder/DEPLOYMENT.md).

## `cable_routes`

The engine behind the Cable routes module (`docs/cable-routes/CONTRACT.md` is the contract). It reads the six D365
extract CSVs, works out how each finished cable is made (machine steps, BOM tree, material per unit), and writes a
SQLite store that the API serves read-only. Standard library only, Python 3.12.

```bash
cd core/python
CABLE_ROUTES_SOURCE_DIR=/path/to/d365-extract python3 -m cable_routes build
python3 -m cable_routes status
```

| Variable | Default | Meaning |
|---|---|---|
| `CABLE_ROUTES_SOURCE_DIR` | none | Folder with the extract. Required (or `--source DIR`). `~` is expanded |
| `CABLE_ROUTES_DB_PATH` | `var/cable-routes/cable-routes.sqlite` | The store; relative paths resolve against the API root. Gitignored |

Precedence: flag, process environment, `<API root>/.env`, default. The store is rebuilt in full into a temp file and
swapped in atomically, so a failed build leaves the old one. Exit codes: 0 ok, 2 bad usage or configuration, 3 a
required file or column is missing (the message names it), 1 anything else.

`schema.sql` is the one definition of the store; the PHP tests build their fixture from it. `seed/` holds copies of the
poster CSVs from `planning/workflow/` plus `machine-limits.csv`. Tests: `tests/cable_routes/`; the real-data module
runs only when `CABLE_ROUTES_SOURCE_DIR` points at an extract.

## Quotegen file inspector (`quotegen_inspector/`)

Lets an AI agent look at the *original* RFQ files (EML/MSG, XLSX/XLS, PDF, DOCX, images):
structure, bounded reads, and page renders. Every result carries `truncated`, `next_cursor`
and `unreadable`. It will later run as a custom container in Azure Container Apps Dynamic
Sessions; today it runs locally. Plan: `platform/QUOTEGEN-AI-WORKSPACE-PLAN.md` §4, §6.

Run the functions or the server directly (dev):

```sh
pip install -r quotegen_inspector/requirements.txt
QG_TOKEN=dev python -m quotegen_inspector.server        # 0.0.0.0:8080 (PORT to change)
```

Routes (bearer token from `QG_TOKEN`; no token set means 503, `/health` is open):
`POST /files`, `GET /files`, `GET /files/{id}`, `POST /inspect {fileId, tool, args}`,
`POST /render {fileId, page|sheet+range, scale, region}` (PNG), `POST /execute {code, timeoutSeconds}`.

Container (build from the repo root; `soffice` for DOCX/XLSX page rendering is only in the image):

```sh
docker build --platform linux/amd64 -f docker/quotegen-inspector/Dockerfile \
  -t quotegen-inspector:0.1.0 core/python
docker run -d --name qg-insp --network none --memory 1g --cpus 2 --read-only \
  --tmpfs /session:uid=10001,size=512m --tmpfs /tmp:size=256m \
  -e QG_TOKEN=dev quotegen-inspector:0.1.0
docker exec qg-insp python -c "import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:8080/health').read())"
```

`--network none` gives the container no network at all, so a published port (`-p`) does not
reach it, on Docker Desktop as well as Linux. Talk to it from inside with `docker exec`
(pipe a client script to `docker exec -i qg-insp python -`). Checked 31 Oct 2026 on
Docker Desktop 29.8 (amd64 under emulation on Apple silicon): cold start to healthy 3.4 s,
DOCX and XLSX page renders through LibreOffice about 1.3 s, a 2 GB allocation in `/execute`
refused, an endless loop killed at its timeout, outbound connections fail.

Tests: `pytest tests/test_quotegen_inspector.py tests/test_quotegen_inspector_server.py`
(fixtures are generated in `tests/quotegen_fixtures.py`, no customer files).
Benchmark: `python tools/benchmark_quotegen_inspector.py "<dir>" [--url http://127.0.0.1:8090 --token dev]`.
Limits are `QG_*` environment variables (see `quotegen_inspector/limits.py`).
Macros are never run and external workbook links are never followed.
