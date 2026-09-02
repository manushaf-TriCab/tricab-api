"""The divergence guard, run as part of the suite.

The plan asks for "a CI check that fails when they diverge". There is no CI
pipeline in this repository yet — no `.github/workflows`, nothing — so a
workflow file would be a check that never runs. This puts the guard in the test
suite instead, which is the thing developers and `composer check` actually
execute, and it can move to a pipeline unchanged when one exists.

The closed-subgraph half runs everywhere. Copy equality needs an
`rfq-parser-mvp` checkout, which only a developer machine has, so it is skipped
without one — visibly, so a machine that lost the checkout does not read as
having passed.
"""

from __future__ import annotations

import os
from pathlib import Path

import pytest

import sys

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "tools"))

from check_rules_sync import check_closed_subgraph, check_copy_equality  # noqa: E402


def test_the_rules_package_imports_nothing_outside_itself():
    """The boundary is real, not aspirational.

    `tricab_cable_rules` is the closed import subgraph of
    `resolver.match_line`. If someone adds an import of the RFQ parser's
    pipeline, mail ingest or LLM fallback, the package has quietly grown a
    dependency on the parser and stops being movable — which is the whole point
    of extracting it.
    """
    failures = check_closed_subgraph()
    assert failures == [], "\n".join(failures)


@pytest.mark.skipif(
    not os.environ.get("RFQ_MATCHER_PATH")
    or not Path(os.environ["RFQ_MATCHER_PATH"]).expanduser().is_dir(),
    reason="no rfq-parser-mvp checkout; set RFQ_MATCHER_PATH to check for drift",
)
def test_the_rules_have_not_drifted_from_the_rfq_parser():
    """Two copies of live rules, with nothing keeping them honest, is how the
    19-style / 114-style divergence happened. Until Phase 7 removes the second
    copy, this is what keeps them equal."""
    matcher = Path(os.environ["RFQ_MATCHER_PATH"]).expanduser()
    failures = check_copy_equality(matcher)
    assert failures == [], "\n".join(failures)
