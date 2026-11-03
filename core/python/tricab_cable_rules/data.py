from __future__ import annotations

import csv
import hashlib
import json
import os
import pickle
import re
import tempfile
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

# openpyxl is imported inside _load_styles, not here: it pulls numpy and costs
# ~0.2s, and load_catalog() below skips style parsing entirely on a cache hit.
# Under the PHP bridge that import was being paid on every request.


# The E/N series, S7 and V7 were retired on 2026-11-02 (not on the AU website). S2/S3/S4/S6
# stay: they are the SWA twins of V2/V3/V4/V6, sold though unpublished. P3/P4 (Temporary
# Power) and T3/T4 (Ship Loaders & Cranes) are the MV ranges added at the same time.
MV_STYLE_RE = re.compile(r"^(?:V[1-46]|S[2346]|P[34]|T[34])$")


def _split_style_sheath(product_code: str, style: str, sheath: str) -> tuple[str, str]:
    """Repair the rows where the tpro export collapsed style+sheath into one value.

    A product code opens with ``<style><sheath>-``, and a style family is always two
    characters (see cable_code.family_group). For 160 of the ~19,000 catalog rows the
    export put the whole three-character zone1 in the ``style`` column and left
    ``sheath`` empty -- 159 of them ``UPA-XXXD/...`` (style ``UPA``, sheath blank)
    against the 345 correctly-split ``UP-`` rows.

    That matters because UP is the marine switchboard/panel wire and the marine earth
    default, whose canonical form is UPA: ``derive_fields_from_style`` fills sheath
    ``A`` from the style's design example, ``sheath`` is a hard filter in
    ``violates_hard_constraints``, and ``products_by_style`` is keyed on this column --
    so all 159 UPA products were both unreachable by style lookup and excluded by the
    sheath gate. Sibling ranges (BVA-, DVA-, RSA-, ...) are split correctly already.

    Deliberately narrow: only fires when the style column is exactly the code's own
    three-character zone1 AND sheath is empty, so a genuine two-character style is
    never touched. The underlying export is still worth fixing at source.
    """
    if len(style) == 3 and not sheath:
        zone1 = product_code.split("-", 1)[0].strip().upper()
        if zone1 == style.upper():
            return style[:2], style[2]
    return style, sheath


def clean_product_code(value: Any) -> str:
    return (
        str(value or "")
        .replace("\u00a0", "")
        .replace("¬†", "")
        .strip()
    )


@dataclass(frozen=True)
class Product:
    product_code: str
    style: str
    sheath: str
    config_core: str
    armour: str
    conductor_type: str
    no_core: str
    core: str
    cross_core: str
    colour: str
    desc2: str = ""
    weight: str = ""

    def public(self) -> dict[str, str]:
        return {
            "product_code": self.product_code,
            "style": self.style,
            "sheath": self.sheath,
            "config_core": self.config_core,
            "armour": self.armour,
            "conductor_type": self.conductor_type,
            "no_core": self.no_core,
            "core": self.core,
            "cross_core": self.cross_core,
            "colour": self.colour,
            "desc2": self.desc2,
            "weight": self.weight,
        }


@dataclass
class Style:
    code: str
    default_colour: str
    design_example: str
    title: str
    application: str
    conductor: str
    insulation: str
    bedding: str
    armour: str
    braiding: str
    nylon: str
    sheathing: str
    standards: str
    temperature: str
    voltage: str
    default_sheath_code: str = ""
    default_config: str = ""
    default_armour_code: str = ""
    default_conductor_code: str = "N"
    cable_type: str = "Power"
    keywords: set[str] = field(default_factory=set)
    # Marine/offshore range only (data/catalog/marine_offshore.xlsx). `screening` is
    # the HQ.screening column -- the collective vs individual+collective screen spec
    # that separates RC/RS/ES/FJ from EW/EG/FC/FL -- and `application_label` is the
    # workbook's own one-line application ("Offshore Braid Armour Coll. Scr.
    # Instrumentation"), which is the authority the marine style matrix is keyed on.
    screening: str = ""
    application_label: str = ""
    marine: bool = False
    marine_family: str = ""  # "shipboard" | "offshore" | "nek"
    # Structured construction signals derived from the workbook columns, used as
    # hard gates so a wrong-but-catalog-valid style is rejected (see resolver).
    voltage_u: int = 0  # phase-to-phase rated voltage in volts (the U of U0/U); 0 = unknown
    voltages: frozenset = frozenset()  # both U0 and U in volts, for tolerant matching
    sheath_lszh: bool = False
    sheath_pvc: bool = False
    has_armour: bool = False
    has_screen: bool = False

    @property
    def haystack(self) -> str:
        return " ".join(
            [
                self.code,
                self.title,
                self.application,
                self.conductor,
                self.insulation,
                self.bedding,
                self.armour,
                self.braiding,
                self.nylon,
                self.sheathing,
                self.standards,
                self.temperature,
                self.voltage,
                self.screening,
                self.application_label,
            ]
        ).lower()

    def public(self) -> dict[str, Any]:
        return {
            "code": self.code,
            "title": self.title,
            "application": self.application,
            "default_colour": self.default_colour,
            "default_config": self.default_config,
            "default_armour_code": self.default_armour_code,
            "default_conductor_code": self.default_conductor_code,
            "cable_type": self.cable_type,
            "temperature": self.temperature,
            "voltage": self.voltage,
            "voltage_u": self.voltage_u,
            "default_sheath_code": self.default_sheath_code,
            "sheath_lszh": self.sheath_lszh,
            "sheath_pvc": self.sheath_pvc,
            "has_armour": self.has_armour,
            "has_screen": self.has_screen,
            "marine": self.marine,
            "marine_family": self.marine_family,
        }


class ProductCatalog:
    def __init__(self, product_csv: Path, style_xlsx: Path, marine_xlsx: Path | None = None):
        self.product_csv = Path(product_csv)
        self.style_xlsx = Path(style_xlsx)
        # TriCab maintains the marine/offshore range in its own workbook rather than in
        # current_cables.xlsx, so it is loaded as a second style source (see
        # scripts/build_marine_styles.py). Optional: a missing file just means no marine
        # styles, which is the pre-2026-08-18 behaviour.
        self.marine_xlsx = Path(marine_xlsx) if marine_xlsx else None
        self.products = self._load_products()
        self.products_by_code = {p.product_code.upper(): p for p in self.products}
        self.products_by_style: dict[str, list[Product]] = defaultdict(list)
        for product in self.products:
            self.products_by_style[product.style].append(product)

        self.styles = self._load_styles()

    def _load_products(self) -> list[Product]:
        products: list[Product] = []
        with self.product_csv.open("r", newline="", encoding="utf-8-sig") as handle:
            reader = csv.DictReader(handle)
            for row in reader:
                code = clean_product_code(row.get("product_code"))
                style, sheath = _split_style_sheath(
                    code, (row.get("style") or "").strip(), (row.get("sheath") or "").strip()
                )
                products.append(
                    Product(
                        product_code=code,
                        style=style,
                        sheath=sheath,
                        config_core=(row.get("config_core") or "").strip(),
                        armour=(row.get("armour") or "").strip(),
                        conductor_type=(row.get("conductor_type") or "").strip(),
                        no_core=(row.get("no_core") or "").strip(),
                        core=(row.get("core") or "").strip(),
                        cross_core=(row.get("cross_core") or "").strip(),
                        colour=(row.get("colour") or "").strip(),
                        desc2=(row.get("desc2") or "").strip(),
                        weight=(row.get("weight") or "").strip(),
                    )
                )
        return products

    def _load_styles(self) -> dict[str, Style]:
        """Styles from current_cables.xlsx, then the marine/offshore workbook.

        Both sheets share the same column layout (scripts/build_marine_styles.py
        re-shapes the marine one to match), so one row reader serves both. Marine rows
        are loaded second and never overwrite an existing code -- none of the 25 marine
        codes collides with current_cables.xlsx today, and if TriCab ever reuses one,
        the mainstream range must keep it rather than silently changing meaning.
        """
        styles: dict[str, Style] = {}
        for path, marine in ((self.style_xlsx, False), (self.marine_xlsx, True)):
            if path is None or not Path(path).exists():
                continue
            for style in self._read_style_sheet(Path(path), marine=marine):
                styles.setdefault(style.code, style)
        self._reconcile_default_conductors(styles)
        return styles

    def _reconcile_default_conductors(self, styles: dict[str, Style]) -> None:
        """Drop a default conductor code that no product of that style actually has.

        `conductor` is a hard filter in violates_hard_constraints and the resolver
        derives it from the style when the RFQ doesn't state one, so a default the
        catalog never stocks excludes the whole range from its own match. That is how
        naming rubber -- KC's own sheath material -- moved a flexible control line OFF
        KC: "rubber" lifts KC to the top of the ranking, KC's register default conductor
        is D (tinned), and all 118 KC products are N, so every one of them was gated out
        and the answer fell through to CF (Flexible LSZH Control), a different family.

        Same class as the marine DVA-PATA aluminium design example handled in
        _enrich_marine_style, but read off the catalog rather than special-cased: the
        design example / conductor prose is one exemplar, the products are the range.
        Only fires where the register default has ZERO catalog rows -- a style that
        genuinely stocks both N and D keeps whatever the register says.
        """
        for code, style in styles.items():
            products = self.products_by_style.get(code)
            if not products:
                continue
            default = (style.default_conductor_code or "").upper()
            counts: Counter[str] = Counter(
                (p.conductor_type or "").upper() for p in products if (p.conductor_type or "").strip()
            )
            if not counts or (default and counts.get(default)):
                continue
            style.default_conductor_code = counts.most_common(1)[0][0]

    def _read_style_sheet(self, path: Path, marine: bool = False) -> list[Style]:
        from openpyxl import load_workbook

        wb = load_workbook(path, data_only=True, read_only=True)
        ws = wb[wb.sheetnames[0]]
        rows = list(ws.iter_rows(values_only=True))
        headers = [str(value).strip() if value is not None else "" for value in rows[0]]
        styles: list[Style] = []

        for values in rows[1:]:
            row = {
                headers[idx]: str(value).strip() if value is not None else ""
                for idx, value in enumerate(values)
                if idx < len(headers)
            }
            code = row.get("Series", "").upper()
            if not code:
                continue
            if not re.fullmatch(r"[A-Z][A-Z0-9]?", code):
                continue

            style = Style(
                code=code,
                default_colour=row.get("Default Clr", "").upper(),
                design_example=row.get("Design (Example)", ""),
                title=row.get("Title", ""),
                application=row.get("Application", ""),
                conductor=row.get("Conductor", ""),
                insulation=row.get("Insulation", ""),
                bedding=row.get("Bedding", ""),
                armour=row.get("Armour", ""),
                braiding=row.get("Braiding", ""),
                nylon=row.get("Nylon", ""),
                sheathing=row.get("Sheathing", ""),
                standards=row.get("Standards", ""),
                temperature=row.get("Temperature", ""),
                voltage=row.get("Voltage", ""),
                screening=row.get("Screening", ""),
                application_label=row.get("Application Label", ""),
                marine=marine,
            )
            self._enrich_style(style)
            if marine:
                self._enrich_marine_style(style)
            styles.append(style)

        wb.close()
        return styles

    def _enrich_marine_style(self, style: Style) -> None:
        """Family and cable type for a marine row, keyed on its application label.

        The label ("Offshore Braid Armour Fire Res.Coll. Scr. Instrumentation") is the
        one column that states the full intent, so it -- not free-text inference -- is
        the authority. `infer_cable_type` cannot be trusted here: "where mechanical or
        EMC protection is required" appears in the application prose of ten marine
        styles, which would type them all as VSD/VFD.
        """
        label = style.application_label.lower()
        if "nek" in style.standards.lower() or re.search(r"\b(?:ru p18|bu p17|p1/p8|p5/p12)\b", label):
            style.marine_family = "nek"
        elif "offshore" in label and "shipboard" not in label:
            style.marine_family = "offshore"
        else:
            # Includes UP, whose label reads "Shipboard & Offshore Switchboard/Panel
            # Wire": it is the panel wire for both families, so it sits with shipboard
            # and the resolver treats it as family-agnostic.
            style.marine_family = "shipboard"

        if "switchboard" in label or "panel wire" in label:
            style.cable_type = "Building Wire"
        elif "vfd" in label or "vsd" in label:
            style.cable_type = "VSD/VFD"
        elif "fire res" in label:
            # Matches the mainstream convention, where the fire-rated instrument style
            # WH is typed "Fire Resistant" while its siblings are "Instrumentation".
            style.cable_type = "Fire Resistant"
        elif "instrumentation" in label or "instr." in label:
            style.cable_type = "Instrumentation"
        else:
            style.cable_type = "Power"

        # Conductor comes from the range spec, not the design example. _enrich_style reads
        # the example's conductor code, but a marine example is one exemplar product and
        # some are unrepresentative: DVA-PATA/5C150BK is the ALUMINIUM variant, which set
        # DV's default to "A" -- and since `conductor` is a hard filter in
        # violates_hard_constraints, that excluded all 750 copper DV products from every
        # match. The whole range is "finely stranded tinned/plain annealed copper", and
        # tinned (D) is both the marine norm and the catalog plurality.
        conductor_text = style.conductor.lower()
        if "tinned" in conductor_text:
            style.default_conductor_code = "D"

        style.keywords = build_keywords(style)
        # Every marine style is IEC 60092 shipboard/offshore; make the range findable by
        # the words customers actually write, not just those the workbook happens to use.
        style.keywords.update({"marine", "shipboard", "offshore", style.marine_family})

    def _enrich_style(self, style: Style) -> None:
        zone1_match = re.search(r"\b([A-Z][A-Z0-9]?[A-Z0-9]*)-", style.design_example.upper())
        if zone1_match:
            zone1 = zone1_match.group(1)
            if zone1.startswith(style.code):
                style.default_sheath_code = zone1[len(style.code):]

        match = re.search(r"-([A-Z0-9]+?)/", style.design_example.upper())
        if match:
            middle = match.group(1)
            if len(middle) >= 3:
                style.default_config = middle[:2]
                style.default_armour_code = middle[-2]
                style.default_conductor_code = middle[-1]

        if not style.default_conductor_code:
            conductor_text = style.conductor.lower()
            if "aluminium" in conductor_text or "aluminum" in conductor_text:
                style.default_conductor_code = "A"
            elif "tinned" in conductor_text:
                style.default_conductor_code = "D"
            else:
                style.default_conductor_code = "N"

        style.cable_type = infer_cable_type(style)
        style.keywords = build_keywords(style)

        # Construction gates (see resolver.style_violates_construction).
        style.voltages = parse_voltages(style.voltage)
        style.voltage_u = max(style.voltages) if style.voltages else 0
        sheath = style.sheathing.lower()
        style.sheath_lszh = any(
            token in sheath for token in ("lszh", "hffr", "hfs-110", "ls0z", "lshf", "low smoke", "halogen free")
        )
        style.sheath_pvc = "pvc" in sheath
        style.has_armour = bool(style.armour.strip()) or "armour" in style.haystack
        # Every instrumentation cable is screened (collective or individual). Some
        # R-series rows only carry the abbreviation "Scr." in the title (not the word
        # "screen") and a generic application, so detect that too -- otherwise an
        # explicit "screen"/"OS"/"IOAS" request hard-gates them out and the line falls
        # back to ungated styleless products (e.g. E1).
        style.has_screen = (
            bool(style.braiding.strip())
            or "screen" in style.haystack
            or "scr." in style.haystack
            or "laminate" in style.haystack
            or style.cable_type == "Instrumentation"
        )


# --- catalog cache --------------------------------------------------------------
# Bump when the shape of Product/Style changes in a way a stale pickle would not
# survive. The key also folds in this module's own mtime, which covers ordinary
# edits during development; this constant is for belt and braces after a deploy
# that ships identical timestamps.
# 2: Style gained the marine columns (screening/application_label/marine/marine_family)
#    and _load_products began repairing collapsed style+sheath rows (2026-08-18).
_CATALOG_CACHE_VERSION = 2


def load_catalog(product_csv: Path, style_xlsx: Path, marine_xlsx: Path | None = None) -> ProductCatalog:
    """A :class:`ProductCatalog`, unpickled from a local cache when one is current.

    Building the catalog parses a ~1.1MB CSV and an xlsx, and the xlsx alone
    costs an ``import openpyxl`` (~0.2s -- it pulls numpy). The PHP bridge starts
    a fresh interpreter for every request, so it paid that on each one, for a
    result that is identical until the source files change. Unpickling costs
    ~25ms and skips openpyxl entirely.

    The cache lives in the system temp dir rather than beside the CSV because on
    App Service the app directory is a network share. Any problem reading it --
    stale, truncated, written by a different build -- is just a miss, and we
    build the catalog the slow way.

    Set ``RFQ_CATALOG_CACHE=0`` to disable.
    """
    if os.environ.get("RFQ_CATALOG_CACHE", "").strip().lower() in {"0", "false", "off", "no"}:
        return ProductCatalog(product_csv, style_xlsx, marine_xlsx)

    path = _catalog_cache_path(product_csv, style_xlsx, marine_xlsx)
    if path is None:
        return ProductCatalog(product_csv, style_xlsx, marine_xlsx)

    cached = _read_catalog_cache(path)
    if cached is not None:
        return cached

    catalog = ProductCatalog(product_csv, style_xlsx, marine_xlsx)
    _write_catalog_cache(path, catalog)
    return catalog


def _catalog_cache_dir() -> Path | None:
    """A private directory for the pickle, or None if we cannot get one.

    The entry is unpickled, so it must not be somewhere another user can write:
    on POSIX the directory is created 0700 and rejected unless we own it and it
    is a real directory rather than a symlink.
    """
    directory = Path(tempfile.gettempdir()) / "rfq-catalog-cache"
    try:
        directory.mkdir(mode=0o700, exist_ok=True)
        info = os.lstat(directory)
    except OSError:
        return None
    if not os.path.isdir(directory) or os.path.islink(directory):
        return None
    if hasattr(os, "geteuid") and info.st_uid != os.geteuid():
        return None
    return directory


def _catalog_cache_path(product_csv: Path, style_xlsx: Path, marine_xlsx: Path | None = None) -> Path | None:
    directory = _catalog_cache_dir()
    if directory is None:
        return None
    parts = [str(_CATALOG_CACHE_VERSION), str(pickle.HIGHEST_PROTOCOL)]
    # Source files plus this module: a catalog built by different code must not
    # be read back as if it matched.
    sources = [Path(product_csv), Path(style_xlsx), Path(__file__)]
    if marine_xlsx is not None:
        # Absent is a valid state (no marine styles), but it must key differently from
        # present, or adding the workbook would be served a stale marine-less cache.
        marine_path = Path(marine_xlsx)
        parts.append(f"marine={marine_path.resolve()}|{marine_path.exists()}")
        if marine_path.exists():
            sources.append(marine_path)
    for source in sources:
        try:
            info = source.stat()
        except OSError:
            return None
        parts.append(f"{source.resolve()}|{info.st_mtime_ns}|{info.st_size}")
    digest = hashlib.sha1("\n".join(parts).encode("utf-8")).hexdigest()[:16]
    return directory / f"catalog-{digest}.pickle"


def _read_catalog_cache(path: Path) -> ProductCatalog | None:
    try:
        with path.open("rb") as handle:
            catalog = pickle.load(handle)
    except (OSError, pickle.UnpicklingError, EOFError, AttributeError, ImportError, ValueError):
        return None
    return catalog if isinstance(catalog, ProductCatalog) else None


def _counts_path(path: Path) -> Path:
    return path.with_suffix(".counts.json")


def catalog_counts(
    product_csv: Path, style_xlsx: Path, marine_xlsx: Path | None = None
) -> dict[str, int] | None:
    """How many products and styles the cached catalog holds, without loading it.

    `/api/health` reported these two numbers, and reading them off the catalog forced
    the whole thing to materialise: 301ms of unpickling, per request, under a bridge
    that starts a new interpreter every time -- to render one line of status text.
    The counts are written beside the pickle when it is built, so this is one small
    file read. None means no sidecar (nothing has built the cache yet, or it is
    disabled), and the caller decides whether the real catalog is worth loading.
    """
    if os.environ.get("RFQ_CATALOG_CACHE", "").strip().lower() in {"0", "false", "off", "no"}:
        return None
    path = _catalog_cache_path(product_csv, style_xlsx, marine_xlsx)
    if path is None:
        return None
    try:
        with _counts_path(path).open("r", encoding="utf-8") as handle:
            counts = json.load(handle)
    except (OSError, json.JSONDecodeError):
        return None
    if not isinstance(counts, dict):
        return None
    try:
        return {"products": int(counts["products"]), "styles": int(counts["styles"])}
    except (KeyError, TypeError, ValueError):
        return None


def _write_catalog_cache(path: Path, catalog: ProductCatalog) -> None:
    try:
        handle, temp_path = tempfile.mkstemp(dir=str(path.parent), suffix=".tmp")
        try:
            with os.fdopen(handle, "wb") as writer:
                pickle.dump(catalog, writer, protocol=pickle.HIGHEST_PROTOCOL)
            os.replace(temp_path, path)
        except OSError:
            os.unlink(temp_path)
            raise
    except OSError:
        # A read-only or full temp dir must never stop the app from starting.
        return

    # Sidecar for callers that only want the sizes. Keyed to the same digest as the
    # pickle, so a rebuilt catalog cannot be described by an older count.
    try:
        handle, temp_path = tempfile.mkstemp(dir=str(path.parent), suffix=".tmp")
        try:
            with os.fdopen(handle, "w", encoding="utf-8") as writer:
                json.dump({"products": len(catalog.products), "styles": len(catalog.styles)}, writer)
            os.replace(temp_path, _counts_path(path))
        except OSError:
            os.unlink(temp_path)
            raise
    except OSError:
        pass

    # Every earlier build's entry is dead the moment this one lands.
    for stale in list(path.parent.glob("catalog-*.pickle")) + list(
        path.parent.glob("catalog-*.counts.json")
    ):
        if stale not in (path, _counts_path(path)):
            try:
                stale.unlink()
            except OSError:
                continue


_STYLE_VOLTAGE_RE = re.compile(r"(\d+(?:\.\d+)?)\s*(?:/\s*(\d+(?:\.\d+)?))?\s*(kv|v)\b", re.I)


def parse_voltages(text: str) -> frozenset:
    """Both rated voltages (U0 and U, in volts) from a workbook voltage string such
    as ``600/1000 V`` -> {600, 1000} or ``19000/33000 V`` -> {19000, 33000}. Keeping
    both lets an RFQ that states either number (``600V`` or ``1kV``) match the family.
    Empty when no voltage is present (e.g. earth-only styles)."""
    match = _STYLE_VOLTAGE_RE.search(text or "")
    if not match:
        return frozenset()
    scale = 1000 if match.group(3).lower() == "kv" else 1
    volts = set()
    for group in (match.group(1), match.group(2)):
        if group is None:
            continue
        try:
            volts.add(int(round(float(group) * scale)))
        except ValueError:
            pass
    return frozenset(volts)


def infer_cable_type(style: Style) -> str:
    text = style.haystack
    title_application = f"{style.title} {style.application}".lower()
    if MV_STYLE_RE.match(style.code):
        return "MV Power"
    if any(token in title_application for token in ["solar", "photovoltaic", " pv "]):
        return "Solar"
    if "welding" in text:
        return "Welding"
    # Title/application only: the E-RUBBER jacket prose on the heavy-duty ranges (WT, SC,
    # AR, ...) says "Submersible in fresh or sea water", which is a sheath property.
    if any(token in title_application for token in ["submersible", "subaquatic", "pump"]):
        return "Submersible"
    if any(token in text for token in ["vsd", "vfd", "variable speed", "variable frequency"]):
        return "VSD/VFD"
    # "EMC" alone is not a drive signal: KE/KH say "instrumentation cable ... where
    # mechanical or EMC protection is required" and are instrumentation (TSD, 2026-11-02).
    if "emc" in text and "instrumentation" not in title_application:
        return "VSD/VFD"
    if any(token in text for token in ["fire resistant", "fire rated", "ws52w", "circuit integrity", "ewis"]):
        return "Fire Resistant"
    if any(token in text for token in ["instrument", "signal", "alarm", "screened", "collective screen"]):
        return "Instrumentation"
    if "control" in text:
        return "Control"
    if any(token in text for token in ["building wire", "earth wire", "switchboard", "panel wire"]):
        return "Building Wire"
    if any(token in text for token in ["battery", " dc ", "ups", "telecommunications"]):
        return "Battery/DC"
    return "Power"


def build_keywords(style: Style) -> set[str]:
    # Ratings written "125°C" live in the title/application prose and are marketing
    # (CA/CC/CF/CN/EH say 125°C but the Temperature column says 110; TSD, 2026-11-02).
    # The Temperature column is written "110 deg C" and keeps its tokens.
    text = re.sub(r"\d+\s*°\s*c", " ", style.haystack)
    keywords: set[str] = {style.code.lower()}
    for token in re.findall(r"[a-z0-9][a-z0-9+\-/]{1,}", text):
        if len(token) > 2 and token not in {"and", "for", "the", "with", "wire", "cable"}:
            keywords.add(token)

    if "low smoke" in text or "lszh" in text or "hffr" in text or "ls0h" in text:
        keywords.update({"lszh", "hffr", "low smoke", "halogen free"})
    if "swa" in text or "awa" in text or "armour" in text:
        keywords.update({"swa", "awa", "armoured", "armored"})
    if "tinned copper wire braid" in text:
        keywords.update({"tcwb", "braid", "screen"})
    if "fire" in text or "ws52w" in text:
        keywords.update({"fire", "fire rated", "ws52w"})
    return keywords
