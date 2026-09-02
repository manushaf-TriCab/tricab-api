#!/usr/bin/env python3
"""Fail when `tricab_cable_rules` and the RFQ parser's `matcher/` have diverged.

Until Phase 7 folds the RFQ parser into this repository, the selection rules
exist twice: here, and in `rfq-parser-mvp/matcher/`. Two copies of live rules
with nothing keeping them honest is precisely how the 19-style / 114-style
divergence arose, so this exists to make a drift visible the moment it lands.

It runs two independent checks:

1. **Closed subgraph** (always runs, needs no RFQ checkout). Every module in the
   package imports only from the package itself and the standard library. That
   property is what makes this a boundary rather than a directory; if someone
   adds `from .pipeline import ...` the package has quietly grown a dependency
   on the parser and this fails.

2. **Copy equality** (runs when `--matcher` or `RFQ_MATCHER_PATH` points at a
   parser checkout). Each shared module must be byte-identical to the parser's,
   after applying the three declared patches in PATCHES below. Anything else --
   a rule tweaked in one copy and not the other -- fails. Adding a fourth patch
   is a deliberate act that has to be written down here.

Without a parser checkout, check 2 is skipped rather than passed: it reports
SKIPPED and says so, so a CI job that silently lost the checkout does not read
as green.

    python3 tools/check_rules_sync.py --matcher /path/to/rfq-parser-mvp
"""

from __future__ import annotations

import argparse
import ast
import hashlib
import os
import sys
from pathlib import Path

PACKAGE_DIR = Path(__file__).resolve().parent.parent / "tricab_cable_rules"

# The shared modules, in dependency order. This list *is* the boundary: a module
# added here is a claim that both consumers need it.
SHARED_MODULES = (
    "normalize",
    "accessories",
    "cable_code",
    "accessory_text",
    "extractor",
    "data",
    "resolver",
)

# Data the package carries. `accessory_engine_snapshot.json` is deliberately
# absent -- 7 MB to validate accessory codes a cable finder never asks for; see
# `load_specs`, which treats a missing snapshot as "cannot validate".
SHARED_DATA = (
    "data/cable_rules/families.json",
    "data/cable_rules/earth_maps.json",
    "data/catalog/tpro_ext_current_cables.csv",
    "data/catalog/current_cables.xlsx",
    "data/catalog/marine_offshore.xlsx",
    "data/catalog/accessory_styles.json",
)

# Where the parser keeps each shared data file, relative to its checkout root.
MATCHER_DATA_SOURCE = {
    "data/cable_rules/families.json": "matcher/data/cable_rules/families.json",
    "data/cable_rules/earth_maps.json": "matcher/data/cable_rules/earth_maps.json",
    "data/catalog/tpro_ext_current_cables.csv": "data/catalog/tpro_ext_current_cables.csv",
    "data/catalog/current_cables.xlsx": "data/catalog/current_cables.xlsx",
    "data/catalog/marine_offshore.xlsx": "data/catalog/marine_offshore.xlsx",
    "data/catalog/accessory_styles.json": "data/catalog/accessory_styles.json",
}

# The complete, declared difference between the two copies. Each entry is
# (module, parser text, package text) and every one is a packaging concern --
# where files sit and how the package names itself -- never a rule.
#
# The parser keeps its catalogue at the repo root (`matcher/../data/catalog`);
# the package carries its own inside itself, so the two path constants lose one
# `.parent`. And `load_specs` reached for `matcher.accessories` by absolute
# name, which cannot resolve under any other package name.
PATCHES: tuple[tuple[str, str, str], ...] = (
    (
        "accessory_text",
        'REGISTER_PATH = Path(__file__).resolve().parent.parent / "data" / "catalog" / "accessory_styles.json"',
        'REGISTER_PATH = Path(__file__).resolve().parent / "data" / "catalog" / "accessory_styles.json"',
    ),
    (
        "accessory_text",
        'SNAPSHOT_PATH = Path(__file__).resolve().parent.parent / "data" / "catalog" / "accessory_engine_snapshot.json"',
        'SNAPSHOT_PATH = Path(__file__).resolve().parent / "data" / "catalog" / "accessory_engine_snapshot.json"',
    ),
    (
        "accessory_text",
        "    from matcher.accessories import parse_accessory_code",
        "    from .accessories import parse_accessory_code",
    ),
)


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check_closed_subgraph() -> list[str]:
    """Every package module imports only the package and the standard library."""
    failures: list[str] = []
    allowed = set(SHARED_MODULES)

    for name in SHARED_MODULES:
        path = PACKAGE_DIR / f"{name}.py"
        if not path.exists():
            failures.append(f"{name}: module is missing from the package")
            continue

        tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
        for node in ast.walk(tree):
            if isinstance(node, ast.ImportFrom):
                # Relative import: level > 0. `from .accessories import x` has
                # module="accessories"; `from . import x` has module=None.
                if node.level:
                    target = (node.module or "").split(".")[0]
                    if target and target not in allowed:
                        failures.append(
                            f"{name}:{node.lineno}: relative import of "
                            f"'{target}', which is not a shared module"
                        )
                    continue
                root = (node.module or "").split(".")[0]
                if root == "matcher":
                    failures.append(
                        f"{name}:{node.lineno}: absolute import from 'matcher' -- "
                        "the package cannot depend on the RFQ parser"
                    )
            elif isinstance(node, ast.Import):
                for alias in node.names:
                    if alias.name.split(".")[0] == "matcher":
                        failures.append(
                            f"{name}:{node.lineno}: imports 'matcher' -- "
                            "the package cannot depend on the RFQ parser"
                        )

    for relative in SHARED_DATA:
        if not (PACKAGE_DIR / relative).exists():
            failures.append(f"data file is missing from the package: {relative}")

    return failures


def check_copy_equality(matcher_root: Path) -> list[str]:
    """Each shared module equals the parser's, after the declared patches."""
    failures: list[str] = []

    for name in SHARED_MODULES:
        ours = PACKAGE_DIR / f"{name}.py"
        theirs = matcher_root / "matcher" / f"{name}.py"
        if not theirs.exists():
            failures.append(f"{name}: not found in the parser at {theirs}")
            continue

        expected = theirs.read_text(encoding="utf-8")
        for module, before, after in PATCHES:
            if module != name:
                continue
            if before not in expected:
                failures.append(
                    f"{name}: declared patch no longer applies -- the parser no "
                    f"longer contains {before!r}. The patch list in "
                    "check_rules_sync.py is stale; re-derive it."
                )
                continue
            expected = expected.replace(before, after)

        if ours.read_text(encoding="utf-8") != expected:
            failures.append(
                f"{name}: diverged from the parser. Run:\n"
                f"    diff <(python3 tools/check_rules_sync.py --emit {name} "
                f"--matcher {matcher_root}) {ours}"
            )

    for relative in SHARED_DATA:
        ours = PACKAGE_DIR / relative
        theirs = matcher_root / MATCHER_DATA_SOURCE[relative]
        if not theirs.exists():
            failures.append(f"{relative}: not found in the parser at {theirs}")
            continue
        if not ours.exists():
            continue  # already reported by the subgraph check
        if _sha256(ours) != _sha256(theirs):
            failures.append(f"{relative}: content differs from the parser's copy")

    return failures


def emit(module: str, matcher_root: Path) -> str:
    """The parser's module with the declared patches applied — what ours should be."""
    text = (matcher_root / "matcher" / f"{module}.py").read_text(encoding="utf-8")
    for name, before, after in PATCHES:
        if name == module:
            text = text.replace(before, after)
    return text


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--matcher",
        default=os.environ.get("RFQ_MATCHER_PATH", ""),
        help="Path to an rfq-parser-mvp checkout. Copy equality is skipped without one.",
    )
    parser.add_argument(
        "--emit",
        metavar="MODULE",
        help="Print the parser's module with the declared patches applied, and exit.",
    )
    args = parser.parse_args()

    matcher_root = Path(args.matcher).expanduser() if args.matcher else None

    if args.emit:
        if matcher_root is None or not matcher_root.is_dir():
            print("--emit needs --matcher", file=sys.stderr)
            return 2
        sys.stdout.write(emit(args.emit, matcher_root))
        return 0

    failures = check_closed_subgraph()
    print(
        f"closed subgraph: {'FAILED' if failures else 'ok'} "
        f"({len(SHARED_MODULES)} modules, {len(SHARED_DATA)} data files)"
    )

    if matcher_root is None or not matcher_root.is_dir():
        print(
            "copy equality: SKIPPED -- no RFQ parser checkout. Pass --matcher or "
            "set RFQ_MATCHER_PATH. This check is not passing, it is not running."
        )
    else:
        copy_failures = check_copy_equality(matcher_root)
        print(f"copy equality: {'FAILED' if copy_failures else 'ok'} (vs {matcher_root})")
        failures.extend(copy_failures)

    for failure in failures:
        print(f"  - {failure}", file=sys.stderr)

    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
