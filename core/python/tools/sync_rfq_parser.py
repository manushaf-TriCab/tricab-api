#!/usr/bin/env python3
"""Build `rfq_parser/` from the pinned RFQ parser source, or check that it still matches.

Until cutover the RFQ parser keeps running in production and keeps taking fixes,
so the source checkout stays the authority for extraction, matching and pricing.
This tool is what keeps the platform's copy honest while both exist: every
generated module is the source module with its imports pointed at this tree,
plus the patches declared below and nothing else.

    python3 tools/sync_rfq_parser.py --matcher /path/to/rfq/wt-main-verified --write
    python3 tools/sync_rfq_parser.py --matcher /path/to/rfq/wt-main-verified          # check

Without `--matcher` (or `RFQ_MATCHER_PATH`) the check reports SKIPPED rather than
passing, like `check_rules_sync.py`, so a CI job that lost the checkout does not
read as green.

**What is generated.** `_source.py`, naming the source commit (the checkout
must have no uncommitted changes under `matcher/` or `tests/`); the parser
modules in `PARSER_MODULES`, byte-for-byte after the import rewrite and any
`MODULE_PATCHES`; `pipeline.py`, cut at the 4js push (Phase 5) and
patched by `PIPELINE_PATCHES`; `reader_intake.py`, the spans of `mail_ingest.py`
in `READER_INTAKE_SPANS`; the `DATA_FILES`; and the source tests in `TESTS`,
patched by `TEST_PATCHES`. **What is not.** `__init__.py`, `corrections.py`, `_unported.py`,
`app.py`, `contract.py` and `wsgi` wiring are the platform's own and are never overwritten.

**Import rewrite.** `matcher.<m>` becomes `tricab_cable_rules.<m>` for the seven
shared rule modules (see `check_rules_sync.py`) and `rfq_parser.<m>` for the rest.
In tests, `from app import catalog` becomes `from rfq_parser.pipeline import catalog`:
the tests reach the catalogue through the old Flask app only because it re-exported it.

**When the source moves.** Re-run with `--write`. If a declared patch no longer
matches, the tool stops and names it; fix the patch, never the generated file.
At cutover the source stops being the authority, this tool is deleted, and the
generated files become ordinary platform code.
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent
PACKAGE_DIR = HERE / "rfq_parser"
TESTS_DIR = HERE / "tests" / "rfq_parser"

RULE_MODULES = frozenset({"normalize", "accessories", "cable_code", "accessory_text", "extractor", "data", "resolver"})

# Copied whole (plus any `MODULE_PATCHES`). Together with the cut pipeline and
# `reader_intake`, the closed import graph of `pipeline.match_items`,
# `file_parser.parse_file` and `reader_intake.read_one_file` once stores, mail, the
# agent and 4js are left out. The reader modules (plan 3.3/3.5) are the terra reader,
# its deterministic re-checks, the translator and vision OCR.
PARSER_MODULES = (
    "attachment_reader",
    "attachment_validate",
    "cable_notation",
    "cost_api",
    "currency",
    "file_parser",
    "llm_fallback",
    "normalizer",
    "product_search",
    "quantity_check",
    "quote_import",
    "quote_readiness",
    "reader_coverage",
    "reader_normalizer",
    "reel_quantity",
    "schedule_arithmetic",
    "schedule_source",
    "splitter",
    "table_shape",
    "table_units",
    "vision_ocr",
    "xls_convert",
)

# (module, why, old, new): exact replacements in a copied module, applied to the source
# text before the import rewrite. Each must match exactly once.
MODULE_PATCHES: tuple[tuple[str, str, str, str], ...] = (
    (
        "attachment_reader",
        "no .env: the platform configures the reader through app settings only",
        "from dotenv import load_dotenv\n",
        "",
    ),
    (
        "attachment_reader",
        "no .env: the platform configures the reader through app settings only",
        'load_dotenv(Path(__file__).resolve().parent.parent / ".env")\n',
        "",
    ),
    (
        "quote_import",
        "no matcher.db: the source looks the printed 4js customer up in its own store; the"
        " platform keeps the printed details and PHP resolves the customer",
        "            from matcher.db import find_customer_by_label as resolve_customer\n",
        "            def resolve_customer(**_kwargs):\n                return None\n",
    ),
    (
        "reader_coverage",
        "the coverage baselines ship inside rfq_parser/data",
        'BASELINES = Path(__file__).resolve().parent.parent / "data/catalog/rfq_coverage_baselines.json"',
        'BASELINES = Path(__file__).resolve().parent / "data" / "rfq_coverage_baselines.json"',
    ),
)

# Data files the parser reads, copied byte for byte (source <p> -> rfq_parser/<target>).
DATA_FILES = (
    ("data/catalog/rfq_coverage_baselines.json", "data/rfq_coverage_baselines.json"),
)

# `reader_intake` is cut from the source's `matcher/mail_ingest.py`: the one function
# that reads one file into work items (`read_one_file`, which the source's mail path and
# portal reader worker both call) and what it needs, without Graph, replies, invoices or
# the 4js push that share that module. Each (start, end) span is copied from `start` up
# to, not including, `end` (None: to the end of the file); both markers occur once.
READER_INTAKE_SPANS: tuple[tuple[str, str | None], ...] = (
    ("# Document types parse_file understands; anything else (images) goes to vision OCR.\n", "QUOTE_LINES_CSV_COLUMNS = [\n"),
    ("def vision_rows_to_items(rows: list[dict]) -> list[dict]:\n", "def extract_items_from_attachments(\n"),
    ("def _default_vision_ocr():\n", None),
)
READER_INTAKE_PRELUDE = (
    '"""One file into work items: the source\'s `mail_ingest.read_one_file`, cut out whole.\n'
    "\n"
    "The source reads every file through this function, from the mailbox and from the\n"
    "portal's background re-read alike; the platform's reader jobs call it too, so the\n"
    "three entry points read one file one way. See `tools/sync_rfq_parser.py`.\n"
    '"""\n'
    "\n"
    "from __future__ import annotations\n"
    "\n"
    "import os\n"
    "from pathlib import Path\n"
    "from tempfile import TemporaryDirectory\n"
    "from typing import Any, Callable\n"
    "\n"
    "from matcher import attachment_reader\n"
    "from matcher.file_parser import parse_file\n"
    "\n"
    "\n"
)

HEADER = (
    "# Generated from the RFQ parser's matcher/{name}.py by tools/sync_rfq_parser.py.\n"
    "# Change the source or the tool's declared patches, never this file.\n"
)

# Everything from this marker on is the 4js push, which is plan Phase 5 and belongs to
# the platform's quote handoff, not the parser.
PIPELINE_CUT = "# --- 4js push ------------------------------------------------------------------\n"

# (why, start marker, end marker, replacement): the source text from `start` up to (not
# including) `end` is replaced. Both markers must occur exactly once. Applied to the
# source text before the import rewrite, so they are written in the source's own terms.
PIPELINE_PATCHES: tuple[tuple[str, str, str, str], ...] = (
    (
        "stores, margins and .env are the platform API's, not the parser's",
        "from dotenv import load_dotenv\n",
        "\nfrom matcher.cable_code import parse_code\n",
        "",
    ),
    (
        "default margins read the ERP for the 4js push (Phase 5)",
        "from matcher.default_margin import default_margins_for_customer\n",
        "from matcher.quote_readiness import (\n",
        "",
    ),
    (
        "the review log is replaced by per-request corrections; the store backend is gone",
        "from matcher.log_store import ReviewLogStore\n",
        "from matcher.reel_quantity import extract_pipe_per_reel_quantity\n",
        "from matcher import normalizer, reader_normalizer\n"
        "from rfq_parser import corrections\n"
        "from rfq_parser._unported import agent\n",
    ),
    (
        "the catalogue ships inside tricab_cable_rules; no .env, no workspace fallback",
        "# ROOT is the project directory (this file lives in <project>/matcher/).\n",
        "\nclass _LazyCatalog:\n",
        "# The catalogue ships inside tricab_cable_rules. PRODUCT_CSV / STYLE_XLSX / MARINE_XLSX\n"
        "# still override it; an empty value means unset, as in the source.\n"
        "PRODUCT_CSV = Path(os.environ.get(\"PRODUCT_CSV\") or _rules_data / \"tpro_ext_current_cables.csv\")\n"
        "STYLE_XLSX = Path(os.environ.get(\"STYLE_XLSX\") or _rules_data / \"current_cables.xlsx\")\n"
        "MARINE_XLSX = Path(os.environ.get(\"MARINE_XLSX\") or _rules_data / \"marine_offshore.xlsx\")\n",
    ),
    (
        "no stores and no 4js settings: the API owns persistence and the handoff",
        "# `or` rather than a get() default: these arrive from .env, where an unused setting is\n",
        "def to_float(value, default=None):\n",
        "\n\n",
    ),
    (
        "learned corrections arrive with the request (rfq_parser.corrections)",
        "        learned = log_store.correction_for(entry.text)\n",
        "        if (learned and learned[\"product_code\"] in catalog.products_by_code\n",
        "        learned = corrections.correction_for(entry.text)\n",
    ),
)

# Where the catalogue lives, added after the imports it depends on.
PIPELINE_PRELUDE_ANCHOR = "from matcher.splitter import split_compound_line\n"
PIPELINE_PRELUDE = (
    "\n"
    "import tricab_cable_rules as _rules\n"
    "\n"
    "_rules_data = Path(_rules.__file__).resolve().parent / \"data\" / \"catalog\"\n"
)

# The source tests brought across, and why each one is here.
TESTS = (
    "test_accessory_text",
    "test_cable_code",
    "test_colour_price_fallback",
    "test_contradiction_codes",
    "test_currency",
    "test_extractor",
    "test_field_provenance",
    "test_file_parser",
    "test_marine",
    "test_material_column",
    "test_resolver",
    "test_sales_row_severity",
    "test_splitter",
    "test_summary_cuts",
    "test_table_shape",
    "test_voltage_gate",
    # The reader (plan 3.3/3.5): terra's reads, their deterministic re-checks, the
    # translator and vision OCR. `test_h1104` stays behind: its fixture is a real
    # customer schedule, and customer files are not committed here. `test_reader_flags`
    # stays too: it tests the mail path's auto-push gate (plans 5/6), not the reader.
    "test_attachment_reader",
    "test_attachment_validate",
    "test_image_reader",
    "test_reader_normalizer",
    "test_text_reader",
    "test_text_validation",
    "test_vision_ocr",
    "test_xls_convert",
)

# Fixture files the tests read, copied byte for byte (source tests/fixtures/<p> ->
# tests/rfq_parser/fixtures/<p>).
FIXTURES = (
    "xls/legacy-schedule.xls",
    "go_live/draft-proof-redacted.json",
)

_BROWSER = (
    "The old app's browser code. The platform web app replaces it and carries its own "
    "check that it speaks the server's vocabulary."
)

# (test module, why, old, new): exact replacements, each must match at least once.
# Every one is about where a file sits or what no longer exists here, never about what
# the parser does.
TEST_PATCHES: tuple[tuple[str, str, str, str], ...] = (
    (
        "test_resolver",
        "mail_ingest (the mail path's flag reasons, plans 5/6) is not brought across; the"
        " readiness half of this test still runs",
        "        from rfq_parser import mail_ingest\n",
        "",
    ),
    (
        "test_resolver",
        "mail_ingest is not brought across (see above)",
        "        self.assertEqual(\n"
        "            mail_ingest._flag_reason(tps), \"left off the quote: TriCab does not sell TPS cable\"\n"
        "        )\n",
        "",
    ),
    (
        "test_cable_code",
        "the catalogue ships inside tricab_cable_rules",
        'CATALOG_CSV = Path(__file__).resolve().parent.parent / "data" / "catalog" / "tpro_ext_current_cables.csv"',
        'CATALOG_CSV = Path(__file__).resolve().parents[2] / "tricab_cable_rules" / "data" / "catalog" / "tpro_ext_current_cables.csv"',
    ),
    (
        "test_contradiction_codes",
        "the pipeline is rfq_parser/pipeline.py",
        "(ROOT / 'matcher' / 'pipeline.py')",
        "(ROOT.parent / 'rfq_parser' / 'pipeline.py')",
    ),
    (
        "test_contradiction_codes",
        _BROWSER,
        "    def test_the_browsers_fallback_prefixes_still_match_the_servers(self):",
        f"    @unittest.skip({_BROWSER!r})\n    def test_the_browsers_fallback_prefixes_still_match_the_servers(self):",
    ),
    (
        "test_sales_row_severity",
        "fixtures sit beside these tests",
        "ROOT / 'tests/fixtures/go_live/draft-proof-redacted.json'",
        "ROOT / 'rfq_parser/fixtures/go_live/draft-proof-redacted.json'",
    ),
    (
        "test_sales_row_severity",
        _BROWSER,
        "class TheBrowserSpeaksTheSameVocabulary(unittest.TestCase):",
        f"@unittest.skip({_BROWSER!r})\nclass TheBrowserSpeaksTheSameVocabulary(unittest.TestCase):",
    ),
    (
        "test_extractor",
        "these tests assume the live cost API calls the cable a stock item; answer that deterministically",
        '        return match_source_line(text, {"kind": "test"}, allow_llm_fallback=False)[0]\n',
        "        import json\n"
        "        from unittest import mock\n"
        "        from rfq_parser import pipeline\n"
        "\n"
        "        # What the cost API answered for KL-PAXN/1C120OR on 2026-09-28: a stocked cable,\n"
        "        # cut to length. Offline, the source rounds these lines to whole 50 m spools.\n"
        "        stock = json.dumps({\"success\": True, \"data\": {\"calculatedCostPerMeter\": 26.23, \"status\": \"2304\",\n"
        "                            \"defaultCost\": 26.23, \"defaultLength\": 6000}}).encode()\n"
        "        with mock.patch.object(pipeline.cost_api, \"_http_get\", return_value=stock):\n"
        '            return match_source_line(text, {"kind": "test"}, allow_llm_fallback=False)[0]\n',
    ),
    (
        "test_summary_cuts",
        "fixtures sit beside these tests",
        "ROOT / 'tests/fixtures/go_live/draft-proof-redacted.json'",
        "ROOT / 'rfq_parser/fixtures/go_live/draft-proof-redacted.json'",
    ),
    (
        "test_vision_ocr",
        "the source's Flask app is not here; the OCR functions are tested directly",
        "from app import app\n",
        "",
    ),
    (
        "test_vision_ocr",
        "the source's Flask app is not here; the OCR functions are tested directly",
        '    @patch("app.extract_table_with_qwen")\n    def test_endpoint_accepts_image_upload(self, extract):',
        "    @unittest.skip(\"The source's vision-ocr route; the platform reads images through reader jobs.\")\n"
        '    @patch("app.extract_table_with_qwen")\n    def test_endpoint_accepts_image_upload(self, extract):',
    ),
    (
        "test_image_reader",
        "the mail path's auto-push gate is not in rfq_parser",
        "def test_both_push_gates_hold_an_image_row(stub):",
        "@pytest.mark.skip(reason=\"The mail path's auto-push gate is not here; the platform's is LineApprovalPolicy (ungrounded rows need approval).\")\ndef test_both_push_gates_hold_an_image_row(stub):",
    ),
    (
        "test_text_reader",
        "the mail path's auto-push gate is not in rfq_parser",
        "def test_ungrounded_rows_are_marked_so_they_cannot_auto_push(stub, monkeypatch):",
        "@pytest.mark.skip(reason=\"The mail path's auto-push gate is not here; the platform's is LineApprovalPolicy (ungrounded rows need approval).\")\ndef test_ungrounded_rows_are_marked_so_they_cannot_auto_push(stub, monkeypatch):",
    ),
    (
        "test_reader_normalizer",
        "the agent is not ported",
        "def test_the_translator_wins_over_the_agent(monkeypatch, stub):",
        "@pytest.mark.skip(reason='The Foundry agent is not ported (production runs it off; see rfq_parser._unported).')\ndef test_the_translator_wins_over_the_agent(monkeypatch, stub):",
    ),
    (
        "test_reader_normalizer",
        "the agent is not ported",
        "def test_a_row_with_no_reading_is_counted_against_the_translator(monkeypatch, stub):",
        "@pytest.mark.skip(reason='The Foundry agent is not ported (production runs it off; see rfq_parser._unported).')\ndef test_a_row_with_no_reading_is_counted_against_the_translator(monkeypatch, stub):",
    ),
    (
        "test_reader_normalizer",
        "the source CLI is not here",
        "def test_health_reports_the_switch_where_production_can_see_it(monkeypatch):",
        "@pytest.mark.skip(reason=\"The source CLI's health action; the platform reports reader state on /api/health.\")\ndef test_health_reports_the_switch_where_production_can_see_it(monkeypatch):",
    ),
    (
        "test_summary_cuts",
        "the 4js push is not in rfq_parser (plan Phase 5 carries this check)",
        "    def test_the_pushed_line_comment_is_still_capped_at_200(self):",
        "    @unittest.skip('The 4js push is plan Phase 5; the platform handoff must keep the 200-character comment cap.')\n"
        "    def test_the_pushed_line_comment_is_still_capped_at_200(self):",
    ),
)


def _rewrite_imports(text: str) -> str:
    def module(name: str) -> str:
        return ("tricab_cable_rules." if name in RULE_MODULES else "rfq_parser.") + name

    text = re.sub(r"\bfrom matcher\.(\w+) import\b", lambda m: f"from {module(m.group(1))} import", text)
    # Relative imports inside `matcher/` resolve the same way: a rule module lives in the
    # rules package now, so `.normalize` cannot stay relative.
    text = re.sub(r"^([ \t]*)from \.(\w+) import\b", lambda m: f"{m.group(1)}from {module(m.group(2))} import", text, flags=re.M)
    text = re.sub(r"\bimport matcher\.(\w+) as\b", lambda m: f"import {module(m.group(1))} as", text)

    def from_package(m: re.Match[str]) -> str:
        indent, names = m.group(1), [n.strip() for n in m.group(2).split(",")]
        lines = []
        for entry in names:
            base = entry.split(" as ")[0].strip()
            package = "tricab_cable_rules" if base in RULE_MODULES else "rfq_parser"
            lines.append(f"{indent}from {package} import {entry}")
        return "\n".join(lines)

    text = re.sub(r"^([ \t]*)from matcher import ([\w ,]+)$", from_package, text, flags=re.M)
    # String targets of monkeypatch / mock.patch ("matcher.pipeline.cost_api").
    text = re.sub(r"([\"'])matcher\.(\w+)", lambda m: f"{m.group(1)}{module(m.group(2))}", text)
    return text


def _replace_between(text: str, why: str, start: str, end: str, replacement: str) -> str:
    for marker in (start, end):
        count = text.count(marker)
        if count != 1:
            raise SystemExit(f"pipeline patch '{why}': marker occurs {count} times, expected once:\n  {marker!r}")
    head, rest = text.split(start, 1)
    _, tail = rest.split(end, 1)
    return head + replacement + end + tail


def build_pipeline(source: str) -> str:
    if source.count(PIPELINE_CUT) != 1:
        raise SystemExit("pipeline: the 4js push marker is missing or repeated; update PIPELINE_CUT.")
    text = source.split(PIPELINE_CUT, 1)[0].rstrip("\n") + "\n"
    for why, start, end, replacement in PIPELINE_PATCHES:
        text = _replace_between(text, why, start, end, replacement)
    if text.count(PIPELINE_PRELUDE_ANCHOR) != 1:
        raise SystemExit("pipeline: prelude anchor missing; update PIPELINE_PRELUDE_ANCHOR.")
    text = text.replace(PIPELINE_PRELUDE_ANCHOR, PIPELINE_PRELUDE_ANCHOR + PIPELINE_PRELUDE)
    return _rewrite_imports(text)


def build_module(name: str, source: str) -> str:
    for module, why, old, new in MODULE_PATCHES:
        if module != name:
            continue
        count = source.count(old)
        if count != 1:
            raise SystemExit(f"module patch '{why}' ({name}): text occurs {count} times, expected once:\n  {old!r}")
        source = source.replace(old, new)
    return _rewrite_imports(source)


def build_reader_intake(source: str) -> str:
    parts = [READER_INTAKE_PRELUDE]
    for start, end in READER_INTAKE_SPANS:
        for marker in (start, end):
            if marker is not None and source.count(marker) != 1:
                raise SystemExit(f"reader_intake: marker occurs {source.count(marker)} times, expected once:\n  {marker!r}")
        tail = source.split(start, 1)[1]
        body = start + (tail.split(end, 1)[0] if end is not None else tail)
        parts.append(body.rstrip("\n") + "\n\n\n")
    return _rewrite_imports("".join(parts).rstrip("\n") + "\n")


def build_test(name: str, source: str) -> str:
    text = _rewrite_imports(source).replace("from app import catalog", "from rfq_parser.pipeline import catalog")
    for module, why, old, new in TEST_PATCHES:
        if module != name:
            continue
        if old not in text:
            raise SystemExit(f"test patch '{why}' ({name}): text not found:\n  {old!r}")
        text = text.replace(old, new)
    return text


def source_commit(matcher_root: Path) -> str:
    """The commit the generated files come from. Refuses a checkout with uncommitted
    changes to anything it reads: the files would then match no commit, and every
    result the service stamps with this commit would name the wrong code."""
    def git(*args: str) -> str:
        return subprocess.run(
            ["git", "-C", str(matcher_root), *args], capture_output=True, text=True, check=True
        ).stdout.strip()

    try:
        commit = git("rev-parse", "HEAD")
        dirty = git("status", "--porcelain", "--", "matcher", "tests")
    except (OSError, subprocess.CalledProcessError) as exc:
        raise SystemExit(f"rfq_parser sync: {matcher_root} must be a git checkout ({exc})") from exc
    if dirty:
        raise SystemExit(
            "rfq_parser sync: the source checkout has uncommitted changes under matcher/ or tests/;\n"
            "commit them first so the generated code names a real commit:\n" + dirty
        )
    return commit


def expected_files(matcher_root: Path) -> dict[Path, str | bytes]:
    out: dict[Path, str | bytes] = {}
    out[PACKAGE_DIR / "_source.py"] = (
        "# Generated from the RFQ parser's checkout by tools/sync_rfq_parser.py.\n"
        "# Change the source or the tool's declared patches, never this file.\n"
        '"""The source commit every generated module in this package came from."""\n'
        "\n"
        f'SOURCE_COMMIT = "{source_commit(matcher_root)}"\n'
    )
    for name in PARSER_MODULES:
        source = (matcher_root / "matcher" / f"{name}.py").read_text(encoding="utf-8")
        out[PACKAGE_DIR / f"{name}.py"] = HEADER.format(name=name) + build_module(name, source)
    pipeline = (matcher_root / "matcher" / "pipeline.py").read_text(encoding="utf-8")
    out[PACKAGE_DIR / "pipeline.py"] = HEADER.format(name="pipeline") + build_pipeline(pipeline)
    mail_ingest = (matcher_root / "matcher" / "mail_ingest.py").read_text(encoding="utf-8")
    out[PACKAGE_DIR / "reader_intake.py"] = HEADER.format(name="mail_ingest") + build_reader_intake(mail_ingest)
    for source_path, target in DATA_FILES:
        out[PACKAGE_DIR / target] = (matcher_root / source_path).read_bytes()
    for relative in FIXTURES:
        out[TESTS_DIR / "fixtures" / relative] = (matcher_root / "tests" / "fixtures" / relative).read_bytes()
    for name in TESTS:
        source = (matcher_root / "tests" / f"{name}.py").read_text(encoding="utf-8")
        out[TESTS_DIR / f"{name}.py"] = HEADER.replace("matcher/{name}.py", "tests/{name}.py").format(name=name) + build_test(name, source)
    return out


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--matcher", default=os.environ.get("RFQ_MATCHER_PATH"), help="RFQ parser checkout")
    parser.add_argument("--write", action="store_true", help="regenerate the files instead of checking them")
    args = parser.parse_args(argv)

    if not args.matcher:
        print("rfq_parser sync: SKIPPED (no --matcher / RFQ_MATCHER_PATH)")
        return 0
    root = Path(args.matcher).resolve()
    if not (root / "matcher" / "pipeline.py").exists():
        print(f"rfq_parser sync: not an RFQ parser checkout: {root}", file=sys.stderr)
        return 2

    files = {path: text.encode("utf-8") if isinstance(text, str) else text for path, text in expected_files(root).items()}
    if args.write:
        for path, data in files.items():
            path.parent.mkdir(parents=True, exist_ok=True)
            if not path.exists() or path.read_bytes() != data:
                path.write_bytes(data)
                print(f"wrote {path.relative_to(HERE)}")
        return 0

    failures = [
        str(path.relative_to(HERE))
        for path, data in files.items()
        if not path.exists() or path.read_bytes() != data
    ]
    if failures:
        print(f"rfq_parser sync: FAILED (vs {root})")
        for failure in failures:
            print(f"  - {failure} differs from what the source generates; run with --write, or fix the patch")
        return 1
    print(f"rfq_parser sync: ok ({len(files)} files vs {root})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
