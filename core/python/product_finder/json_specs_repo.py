"""Ampacity and construction data from the pinned JSON snapshots.

`SpecsRepository` reads legacy MySQL (`tc.cable_specs`, `tc.rating`, `tc.price`)
over the corporate VPN. That is fine on a laptop and wrong for the App Service:
it is a second database dependency for a service whose whole job is arithmetic
over 9,895 rows that already ship in this repository, SHA-256 pinned, at
`resources/cable-calculator/v1/`. Reading them here means the finder is
self-contained, and — more to the point — that the Python sizer and PHP's
`NativeCableCalculatorEngine` read one dataset rather than two.

**This reproduces the SQL, including the parts that are surprising.** The rows
are a verified capture of the same tables, so a faithful port is a port of the
join as MySQL actually evaluates it, not as it reads:

* ``r.size = s.xa`` looks like a string comparison and is not one. Every
  ``rating.size`` is ``'1.00'``-style and every ``cable_specs.xa`` is ``'1'``,
  ``'0.5'``, ``' 1/0'`` or ``'0.5*'``; the two column sets share **zero** exact
  strings. The join only ever returns rows because ``rating.size`` is numeric in
  MySQL, which coerces ``xa``. So the key here is the parsed number, and the
  values MySQL would coerce to 1 (``' 1/0'``) or 0.5 (``'0.5*'``) do the same.
* The DC branch self-joins ``rating`` on ``dcr.mt = r.mt``, and ``rating.mt`` is
  NULL on 491 of 557 rows. ``NULL = NULL`` is false, so that branch can only
  ever reach the 57 ``mt = 1301`` DC-eligible rows — every other row silently
  falls back to the AC column. Reproduced, not corrected: correcting it would
  change sizing answers that the prototype was live-verified against.

**What the snapshot does not carry**, and therefore neither does this:

* ``cable_specs.br`` — bend radius. Not a column in the capture at all, so
  ``SpecRow.bend_radius`` is 0.0 here. It is only ever displayed, never computed
  with, so the effect is a blank field rather than a wrong number. Adding ``br``
  to the capture is the fix; see the phase notes.
* ``tc.price`` — so `price_for_mask` returns None and the "minimum cost"
  strategy falls back to smallest conductor area with a warning it already
  emits. Phase 1 concluded that live price and stock should come from
  ``TRICAB_COST_API_URL`` rather than a snapshot anyway; this leaves that open
  rather than baking in a stale price book.
* ``tc.tpro_ext`` — so `specs_for_product` returns None. Nothing in the finder
  calls it.

Set ``PRODUCT_FINDER_SIZING_SOURCE=mysql`` to use the live legacy database
instead; ``json`` is the default and ``auto`` prefers MySQL when it is
configured.
"""

from __future__ import annotations

import json
import logging
import os
from bisect import bisect_left
from functools import lru_cache
from pathlib import Path
from typing import Any, Sequence

from .constants import rating_column
from .specs_repo import METRIC_MT, SpecRow, _as_float, _as_int

log = logging.getLogger(__name__)

_DATASET_SUFFIX = ("resources", "cable-calculator", "v1")


def _default_dataset_dir() -> Path:
    """Where the pinned snapshots live, in both layouts this code runs in.

    In the repository this file is ``core/python/product_finder/``, so the
    snapshots sit three levels up. The App Service release stages
    ``core/python`` as ``app/python`` — that drops the ``core/`` level, and the
    same three-level walk lands one directory above ``app/``, where there is no
    ``resources``. Sizing then reported itself unavailable while matching
    worked, which is a confusing way to discover a path bug.

    So probe both depths, and let ``PRODUCT_FINDER_DATASET_DIR`` override
    outright for a layout neither guess covers.
    """
    override = os.environ.get("PRODUCT_FINDER_DATASET_DIR", "").strip()
    if override:
        return Path(override)

    here = Path(__file__).resolve()
    repo_layout = here.parents[3].joinpath(*_DATASET_SUFFIX)
    release_layout = here.parents[2].joinpath(*_DATASET_SUFFIX)
    for candidate in (repo_layout, release_layout):
        if candidate.is_dir():
            return candidate

    # Nothing found: keep the repository path so the error names the expected
    # location rather than an incidental one.
    return repo_layout


DATASET_DIR = _default_dataset_dir()


def _mysql_number(value: Any) -> float | None:
    """What MySQL would coerce a string to when comparing it against a number.

    MySQL reads the longest numeric prefix and stops: ``'0.5*'`` is 0.5,
    ``' 1/0'`` is 1, ``'abc'`` is 0. Returning None for "no numeric prefix at
    all" rather than 0.0 is a deliberate divergence in one direction only —
    MySQL would match such a row against ``rating.size = 0``, and there is no
    ``rating`` row at size 0, so nothing is lost and a silent 0.0 cannot leak
    into a cross-section.
    """
    if value is None:
        return None
    text = str(value).strip()
    if not text:
        return None

    end = 0
    seen_digit = False
    seen_dot = False
    if end < len(text) and text[end] in "+-":
        end += 1
    while end < len(text):
        char = text[end]
        if char.isdigit():
            seen_digit = True
        elif char == "." and not seen_dot:
            seen_dot = True
        else:
            break
        end += 1

    if not seen_digit:
        return None
    try:
        return float(text[:end])
    except ValueError:
        return None


class JsonSpecsRepository:
    """`SpecsRepository`'s interface, served from the pinned dataset."""

    def __init__(self, dataset_dir: Path | None = None) -> None:
        self._dir = dataset_dir or DATASET_DIR

    @property
    def available(self) -> bool:
        return (self._dir / "ratings.json").is_file() and (
            self._dir / "cableSpecs.json"
        ).is_file()

    # ── the dataset ──────────────────────────────────────────────────────────

    @lru_cache(maxsize=1)  # noqa: B019 - one instance, process-lifetime dataset
    def _load(self) -> dict[str, Any]:
        ratings = json.loads((self._dir / "ratings.json").read_text(encoding="utf-8"))
        specs = json.loads((self._dir / "cableSpecs.json").read_text(encoding="utf-8"))

        # `rating`, indexed on everything find_candidates filters by, so a
        # candidate sweep is a dict lookup rather than a 557-row scan per style.
        ac: dict[tuple, list[dict[str, Any]]] = {}
        dc: dict[tuple, dict[str, Any]] = {}
        for row in ratings.get("rows") or []:
            size = _mysql_number(row.get("size"))
            if size is None:
                continue
            key = (
                _as_int(row.get("ambient")),
                _as_int(row.get("operating")),
                str(row.get("config") or "").strip(),
                _as_int(row.get("corecount")),
                str(row.get("ct") or "").strip(),
            )
            if str(row.get("dc") or "0").strip() == "1":
                # The DC self-join also keys on mt, which is NULL on most AC
                # rows; `_dc_current` re-checks that rather than losing it here.
                dc[(*key, size, row.get("mt"))] = row
            else:
                ac.setdefault((*key, size), []).append(row)

        # `cable_specs`, indexed by (style, nc) — the two equality filters that
        # narrow hardest — with each bucket sorted by cross-section so the
        # smallest adequate size comes out first without a re-sort.
        by_style: dict[tuple[str, int], list[dict[str, Any]]] = {}
        for row in specs.get("rows") or []:
            style = str(row.get("cable_style") or "").strip()
            if not style:
                continue
            size = _mysql_number(row.get("xa"))
            if size is None:
                continue
            row = dict(row, _size=size)
            by_style.setdefault((style.upper(), _as_int(row.get("nc"))), []).append(row)
        for bucket in by_style.values():
            bucket.sort(key=lambda r: r["_size"])

        return {"ac": ac, "dc": dc, "specs": by_style}

    # ── ampacity candidates ──────────────────────────────────────────────────

    def find_candidates(
        self,
        styles: Sequence[str],
        install: int,
        ambient: int,
        operating: int,
        config: str,
        rating_cores: int,
        active_cores: int,
        is_aluminium: bool,
        min_current: float | None = None,
        dc: bool = False,
    ) -> list[SpecRow]:
        if not styles:
            return []

        column = rating_column(install)
        conductor = "A" if is_aluminium else "C"
        data = self._load()

        rating_key = (ambient, operating, config, rating_cores, conductor)
        results: list[SpecRow] = []

        for style in styles:
            bucket = data["specs"].get((str(style).strip().upper(), active_cores))
            if not bucket:
                continue
            for spec in bucket:
                # `s.ct <> 'a'` for copper, `s.ct = 'a'` for aluminium. The
                # column is lower-case in the capture; the SQL compares against
                # a lower-case literal under a case-insensitive collation, so
                # fold rather than assume.
                spec_ct = str(spec.get("ct") or "").strip().lower()
                if is_aluminium:
                    if spec_ct != "a":
                        continue
                elif spec_ct == "a":
                    continue
                if _as_int(spec.get("mt")) != METRIC_MT:
                    continue

                for rating in data["ac"].get((*rating_key, spec["_size"]), ()):
                    current = self._current(data, rating, column, dc)
                    if current is None:
                        continue
                    if min_current is not None and current < min_current:
                        continue
                    results.append(self._row(spec, rating, current))

        results.sort(key=lambda r: r.cross_section)
        return results

    @staticmethod
    def _current(
        data: dict[str, Any], rating: dict[str, Any], column: str, dc: bool
    ) -> float | None:
        """`r.<column>`, or the DC IEC value where the self-join reaches one."""
        base = rating.get(column)
        if not dc:
            return _as_float(base) if base is not None else None

        # dcr.size = r.size AND dcr.corecount = r.corecount AND dcr.config =
        # r.config AND dcr.ambient = r.ambient AND dcr.operating = r.operating
        # AND dcr.mt = r.mt — that last one is NULL on 491 of 557 rating rows,
        # and NULL = NULL is false, so this misses for all of them.
        if rating.get("mt") is None:
            return _as_float(base) if base is not None else None

        key = (
            _as_int(rating.get("ambient")),
            _as_int(rating.get("operating")),
            str(rating.get("config") or "").strip(),
            _as_int(rating.get("corecount")),
            str(rating.get("ct") or "").strip(),
            _mysql_number(rating.get("size")),
            rating.get("mt"),
        )
        dc_row = data["dc"].get(key)
        # IF(dcr.iec IS NOT NULL AND dcr.iec <> '', dcr.iec, r.<column>)
        if dc_row is not None:
            iec = dc_row.get("iec")
            if iec is not None and str(iec).strip() != "":
                return _as_float(iec)
        return _as_float(base) if base is not None else None

    @staticmethod
    def _row(spec: dict[str, Any], rating: dict[str, Any], current: float) -> SpecRow:
        return SpecRow(
            cable_style=str(spec.get("cable_style") or "").strip(),
            cross_section=spec["_size"],
            active_cores=_as_int(spec.get("nc")),
            core_config=str(spec.get("cc") or "").strip(),
            earth_count=_as_int(spec.get("ne")),
            earth_cross_section=_as_float(spec.get("xae")),
            overall_diameter=_as_float(spec.get("od")),
            width=_as_float(spec.get("width")),
            weight_kg_per_km=_as_float(spec.get("wght")),
            bend_radius=0.0,  # `br` is not in the capture; see the module docstring.
            current=current,
            ambient=_as_int(rating.get("ambient")),
            operating=_as_int(rating.get("operating")),
            # `r.ct`, not `s.ct`. The SQL selects the *rating* row's conductor
            # type here — 'C'/'A' — while `lookup_spec` selects the spec row's
            # 'c'/'p'/'a'. Reading the spec's here instead is silent: same
            # letter, different case, and it only shows up against live rows.
            conductor_type=str(rating.get("ct") or "").strip(),
        )

    # ── physical specs for a known construction ──────────────────────────────

    def lookup_spec(
        self, style: str, active_cores: int, cross_section: float
    ) -> SpecRow | None:
        bucket = self._load()["specs"].get((str(style).strip().upper(), active_cores))
        if not bucket:
            return None
        sizes = [row["_size"] for row in bucket]
        index = bisect_left(sizes, cross_section)
        if index >= len(sizes) or sizes[index] != cross_section:
            return None
        spec = bucket[index]
        return SpecRow(
            cable_style=str(spec.get("cable_style") or "").strip(),
            cross_section=spec["_size"],
            active_cores=_as_int(spec.get("nc")),
            core_config=str(spec.get("cc") or "").strip(),
            earth_count=_as_int(spec.get("ne")),
            earth_cross_section=_as_float(spec.get("xae")),
            overall_diameter=_as_float(spec.get("od")),
            width=_as_float(spec.get("width")),
            weight_kg_per_km=_as_float(spec.get("wght")),
            bend_radius=0.0,
            current=0.0,
            ambient=0,
            operating=0,
            conductor_type=str(spec.get("ct") or "").strip(),
        )

    # ── not in the snapshot ──────────────────────────────────────────────────

    def price_for_mask(self, mask: str, region: str = "australia") -> dict[str, Any] | None:
        """None — `tc.price` is not captured. See the module docstring."""
        return None

    def specs_for_product(self, product_code: str) -> dict[str, Any] | None:
        """None — `tc.tpro_ext` is not captured, and nothing calls this."""
        return None

    # ── provenance ───────────────────────────────────────────────────────────

    def manifest(self) -> dict[str, Any]:
        path = self._dir / "manifest.json"
        if not path.is_file():
            return {}
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return {}

