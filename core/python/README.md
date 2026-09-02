# core/python — the cable-selection rules, and the finder that reads them

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

## Two copies, until Phase 7

`tricab_cable_rules` and `rfq-parser-mvp/matcher/` are the same rules twice over.
That is acceptable **only because Phase 7 removes the second copy**; a permanent
second copy is not. Until then:

```bash
python3 tools/check_rules_sync.py --matcher /path/to/rfq-parser-mvp
```

Byte-identical except for three declared packaging patches — where the catalogue
sits, and one absolute `matcher.` import that cannot resolve under another
package name. Anything else fails. It also runs as
`tests/test_rules_sync.py`, so it fires with the suite.

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
```

**Keep the virtualenv out of this tree.** Composer's path repository copies
`core/` wholesale into `vendor/tricab/tricab-core/` — `docs/`, `phpunit.xml` and
`.gitignore` are already duplicated there — so a venv under `core/python` is
copied on every `composer install` and again into the release zip.
`build-app-service-release.ps1` refuses to build if it finds one.

Deployment, App Service layout and the sizing-data caveat:
[`docs/product-finder/DEPLOYMENT.md`](../../docs/product-finder/DEPLOYMENT.md).
