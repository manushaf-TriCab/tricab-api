"""The RFQ parser's guards, run as part of the suite (same reasoning as `test_rules_sync.py`).

Two halves run everywhere: the parser imports nothing it should not, and every
generated module still says it is generated. The third needs the pinned source
checkout, so it is skipped without one — visibly, so a machine that lost the
checkout does not read as having passed.
"""

from __future__ import annotations

import ast
import os
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "tools"))

import sync_rfq_parser  # noqa: E402

# Third-party packages the parser may import, each in requirements.txt.
ALLOWED_THIRD_PARTY = {"openpyxl", "pdfplumber", "docx", "xlrd", "flask"}
PLATFORM_OWNED = {"__init__", "corrections", "_unported", "app"}


def _imports(path: Path) -> set[str]:
    names: set[str] = set()
    for node in ast.walk(ast.parse(path.read_text(encoding="utf-8"))):
        if isinstance(node, ast.Import):
            names.update(alias.name.split(".")[0] for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.level == 0 and node.module:
            names.add(node.module.split(".")[0])
    return names


def test_the_parser_imports_only_itself_the_rules_and_declared_dependencies():
    """No store, mailbox, agent or 4js module can creep back in unnoticed.

    The parser's boundary is what lets the API own persistence and the quote
    handoff. A `matcher` import would mean a generated file escaped its rewrite; a
    new third-party import is a dependency the App Service does not install.
    """
    allowed = set(sys.stdlib_module_names) | {"rfq_parser", "tricab_cable_rules", "__future__"} | ALLOWED_THIRD_PARTY
    failures = [
        f"{path.name} imports {name}"
        for path in sorted((ROOT / "rfq_parser").glob("*.py"))
        for name in sorted(_imports(path) - allowed)
    ]
    assert failures == [], "\n".join(failures)


def test_every_generated_module_says_it_is_generated():
    for path in sorted((ROOT / "rfq_parser").glob("*.py")):
        if path.stem in PLATFORM_OWNED:
            continue
        assert path.stem in sync_rfq_parser.PARSER_MODULES or path.stem == "pipeline", (
            f"{path.name} is neither generated nor declared platform-owned"
        )
        assert path.read_text(encoding="utf-8").startswith("# Generated from the RFQ parser's"), path.name


@pytest.mark.skipif(
    not os.environ.get("RFQ_MATCHER_PATH")
    or not Path(os.environ["RFQ_MATCHER_PATH"]).expanduser().is_dir(),
    reason="no RFQ parser checkout; set RFQ_MATCHER_PATH to check rfq_parser against it",
)
def test_rfq_parser_is_what_the_pinned_source_generates():
    assert sync_rfq_parser.main(["--matcher", os.environ["RFQ_MATCHER_PATH"]]) == 0
