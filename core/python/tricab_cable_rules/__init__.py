"""TriCab cable-selection rules — the one implementation.

This package is the single home for the rules that turn a cable requirement into
a TriCab product code: the nine-segment code grammar, the style register, field
extraction, style ranking and line resolution. Two services consume it — the
product finder (``product_finder``, alongside this package) and, after Phase 7,
the RFQ parser. Neither carries its own copy.

**What is here, and why exactly this.** The modules below are the *closed*
import subgraph of ``resolver.match_line``: nothing in them imports anything else
from the RFQ parser, reads an environment variable, opens a socket or touches a
database. That property is what makes the boundary real rather than aspirational,
and it is checked — see ``core/python/tools/check_rules_sync.py``::

    normalize        no dependencies
    accessories      no dependencies
    cable_code       no dependencies        reads data/cable_rules/*.json
    accessory_text   no dependencies        reads data/catalog/accessory_styles.json
    extractor        accessories, cable_code, normalize
    data             no dependencies        reads data/catalog/*.csv, *.xlsx
    resolver         all of the above

**What is not here.** RFQ-specific work stays with the parser: mail ingest, file
and attachment reading, the LLM fallback, draft and log stores, pricing, the
agent loop, the pipeline. ``resolver.py`` is 2,523 lines and a good part of it is
RFQ-line handling — quantities, lengths, directives — but it is not separable
from cable selection without forking the file, and a fork is exactly the failure
this package exists to prevent. So the whole resolver comes across, and the RFQ
parser's specific work stays in the modules that wrap it.

**Accessories.** ``accessories`` and ``accessory_text`` are carried because
``extractor`` and ``resolver`` import them unconditionally, not because the
finder wants accessory answers. The 722-style register ships; the 7 MB
``accessory_engine_snapshot.json`` does not, so accessory codes read here are
unvalidated — a state ``load_specs`` already treats as first class ("cannot
validate"), never as a good code.

**Until Phase 7 there are two copies** of these modules: this one and the RFQ
parser's ``matcher/``. ``tools/check_rules_sync.py`` fails when they diverge
beyond three declared, enumerated patches. That guard is acceptable only because
Phase 7 removes the second copy; a permanent second copy is not.
"""

from __future__ import annotations

from .cable_code import (
    ENGINE_VERSION,
    build_code,
    describe_code,
    fmt_csa,
    parse_code,
)
from .data import Product, ProductCatalog, Style, load_catalog
from .extractor import extract_entities
from .normalize import canonical_colour, canonical_number, normalize_text
from .resolver import (
    derive_fields_from_style,
    find_products,
    match_line,
    rank_styles,
)

# The catalogue files that ship with this package. `load_catalog` takes paths
# rather than finding them itself, so that a caller can point at a newer export
# without editing the package; these are the defaults it should use when it has
# no reason to.
from pathlib import Path

CATALOG_DIR = Path(__file__).resolve().parent / "data" / "catalog"
DEFAULT_PRODUCT_CSV = CATALOG_DIR / "tpro_ext_current_cables.csv"
DEFAULT_STYLE_XLSX = CATALOG_DIR / "current_cables.xlsx"
DEFAULT_MARINE_XLSX = CATALOG_DIR / "marine_offshore.xlsx"

__all__ = [
    "ENGINE_VERSION",
    "CATALOG_DIR",
    "DEFAULT_MARINE_XLSX",
    "DEFAULT_PRODUCT_CSV",
    "DEFAULT_STYLE_XLSX",
    "Product",
    "ProductCatalog",
    "Style",
    "build_code",
    "canonical_colour",
    "canonical_number",
    "derive_fields_from_style",
    "describe_code",
    "extract_entities",
    "find_products",
    "fmt_csa",
    "load_catalog",
    "match_line",
    "normalize_text",
    "parse_code",
    "rank_styles",
]
