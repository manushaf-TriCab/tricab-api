"""The finder's view of `tricab_cable_rules`.

This was `matcher_bridge`, and it earned that name: it put the RFQ parser's
directory on `sys.path` from `RFQ_MATCHER_PATH` and imported `matcher.*` across
that gap. The path was an absolute one on a single developer's Mac, so the
finder could not run anywhere else — the deployment blocker Phase 3a exists to
clear. The rules now live in `core/python/tricab_cable_rules/`, beside this
package and in the same artifact, so the import is an ordinary one.

What stays is the narrowing: the finder uses a handful of the rules package's
operations, and naming them here keeps that surface visible. The degradation
also stays, for a different reason than before. It is no longer "the matcher may
be missing" — it ships in the same directory — but "the catalogue may not load",
which is a real failure mode: the CSV and workbooks are read at first use, and a
truncated or absent export should leave the finder answering in parameter mode
rather than refusing to start.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from functools import lru_cache
from typing import Any

import tricab_cable_rules as rules

from .config import CONFIG

log = logging.getLogger(__name__)


@dataclass
class RulesStatus:
    available: bool
    detail: str
    products: int = 0
    styles: int = 0

    def public(self) -> dict[str, Any]:
        return {
            "available": self.available,
            "detail": self.detail,
            "products": self.products,
            "styles": self.styles,
        }


class RulesUnavailable(RuntimeError):
    pass


@lru_cache(maxsize=1)
def _load() -> tuple[Any, RulesStatus]:
    """Load the catalogue exactly once."""
    catalog_cfg = CONFIG.catalog
    missing = [
        str(p)
        for p in (catalog_cfg.product_csv, catalog_cfg.style_xlsx)
        if p is None or not p.exists()
    ]
    if missing:
        return None, RulesStatus(
            available=False,
            detail=f"Catalogue files not found: {', '.join(missing)}",
        )

    try:
        catalog = rules.load_catalog(
            catalog_cfg.product_csv,
            catalog_cfg.style_xlsx,
            catalog_cfg.marine_xlsx
            if catalog_cfg.marine_xlsx and catalog_cfg.marine_xlsx.exists()
            else None,
        )
    except Exception as exc:  # noqa: BLE001 - reported, not raised
        log.warning("Catalogue load failed: %s", exc)
        return None, RulesStatus(
            available=False, detail=f"Could not load the catalogue: {exc}"
        )

    return catalog, RulesStatus(
        available=True,
        detail="ok",
        products=len(catalog.products),
        styles=len(catalog.styles),
    )


def status() -> RulesStatus:
    return _load()[1]


def catalog() -> Any:
    cat, st = _load()
    if cat is None:
        raise RulesUnavailable(st.detail)
    return cat


# ── the operations the finder actually needs ─────────────────────────────────


def extract_fields(text: str) -> dict[str, Any]:
    """Regex-only field extraction. No network, no LLM."""
    known = set(catalog().products_by_code)
    return rules.extract_entities(text, known)


def rank_styles(normalized_text: str, fields: dict[str, Any]) -> list[tuple[Any, float]]:
    return rules.rank_styles(normalized_text, fields, catalog())


def derive_fields_from_style(fields: dict[str, Any], style: Any) -> dict[str, Any]:
    return rules.derive_fields_from_style(fields, style)


def find_products(
    fields: dict[str, Any], style_candidates: list[tuple[Any, float]]
) -> list[Any]:
    return rules.find_products(fields, catalog(), style_candidates)


def match_line(text: str) -> dict[str, Any]:
    """Full resolution of one line — the text path's backbone."""
    return rules.match_line(text, catalog())


def match_line_with_overrides(text: str, overrides: dict[str, Any]) -> dict[str, Any]:
    """Resolve a line with specific fields forced.

    The finder uses this to hand the rules a cross-section it worked out from
    the electrical duty, leaving every other decision — sheath, config, armour,
    conductor, colour — to the selection rules themselves. That is what keeps
    the finder and the RFQ parser from disagreeing about the same cable.
    """
    return rules.match_line(text, catalog(), field_overrides=overrides or None)


def build_code(fields: dict[str, Any]) -> str:
    return rules.build_code(fields)


def parse_code(code: str) -> dict[str, Any]:
    return rules.parse_code(code)


def describe_code(parsed: dict[str, Any], style_meta: dict[str, Any] | None = None) -> str:
    return rules.describe_code(parsed, style_meta)


def product_by_code(code: str) -> Any | None:
    return catalog().products_by_code.get(code.upper())


def style_by_code(code: str) -> Any | None:
    return catalog().styles.get(code.upper())
