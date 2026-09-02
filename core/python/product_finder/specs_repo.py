"""Queries against the legacy `tc` database.

Ported from `functions/db.php`. The legacy code built every statement by string
concatenation; these are parameterised. The one unavoidable interpolation is the
ampacity column name — `tc.rating` stores one column per installation method
rather than one row — and that name is resolved through
`constants.rating_column()`, which only ever returns a value from a fixed set.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Sequence

from .constants import rating_column
from .db import LEGACY_DB, DatabaseUnavailable, LegacyDatabase

METRIC_MT: int = 1301

# Cost API region -> `tc.price.dbco`
DBCO_BY_REGION: dict[str, str] = {
    "australia": "2507",
    "usa": "2517",
    "asia": "2519",
}


@dataclass(frozen=True)
class SpecRow:
    """One candidate construction: a `cable_specs` row joined to its rating."""

    cable_style: str
    cross_section: float
    active_cores: int
    core_config: str
    earth_count: int
    earth_cross_section: float
    overall_diameter: float
    width: float
    weight_kg_per_km: float
    bend_radius: float
    current: float
    ambient: int
    operating: int
    conductor_type: str

    @property
    def effective_diameter(self) -> float:
        """`od` where present, else `width` — flat cables carry only the latter."""
        return self.overall_diameter or self.width

    def public(self) -> dict[str, Any]:
        return {
            "style": self.cable_style,
            "crossSection": self.cross_section,
            "activeCores": self.active_cores,
            "coreConfig": self.core_config,
            "earthCount": self.earth_count,
            "earthCrossSection": self.earth_cross_section,
            "overallDiameter": self.effective_diameter,
            "weightKgPerKm": self.weight_kg_per_km,
            "bendRadius": self.bend_radius,
            "tableCurrent": self.current,
            "tableAmbient": self.ambient,
            "operatingTemp": self.operating,
            "conductorType": self.conductor_type,
        }


def _as_float(value: Any, default: float = 0.0) -> float:
    if value is None:
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def _as_int(value: Any, default: int = 0) -> int:
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


class SpecsRepository:
    """Read-only access to `cable_specs`, `rating` and `price`."""

    def __init__(self, db: LegacyDatabase | None = None) -> None:
        self._db = db or LEGACY_DB

    @property
    def available(self) -> bool:
        return self._db.configured

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
        """Constructions matching the electrical criteria, smallest CSA first.

        `min_current` filters in SQL so the smallest adequate size surfaces
        first. Pass None to list every size (used when the customer has pinned a
        cross-section and we only need to verify it).
        """
        if not styles:
            return []

        column = rating_column(install)
        conductor = "A" if is_aluminium else "C"
        style_placeholders = ", ".join(["%s"] * len(styles))

        # IEC DC single-core reads its rating from the dc=1 rows where present.
        if dc:
            current_expr = "IF(dcr.iec IS NOT NULL AND dcr.iec <> '', dcr.iec, r.`{col}`)"
            dc_join = """
            LEFT JOIN tc.rating dcr
                   ON dcr.size      = r.size
                  AND dcr.dc        = 1
                  AND dcr.corecount = r.corecount
                  AND dcr.config    = r.config
                  AND dcr.ambient   = r.ambient
                  AND dcr.operating = r.operating
                  AND dcr.mt        = r.mt
            """
        else:
            current_expr = "r.`{col}`"
            dc_join = ""

        current_sql = current_expr.format(col=column)
        conductor_test = "=" if is_aluminium else "<>"

        sql = f"""
            SELECT
                s.cable_style,
                CAST(s.xa AS DECIMAL(10,3)) AS xa,
                s.nc, s.cc, s.ne, s.xae,
                s.od, s.width, s.wght, s.br,
                r.ambient, r.operating, r.ct,
                {current_sql} AS current_a
            FROM tc.cable_specs s
            JOIN tc.rating r
              ON r.size = s.xa
            {dc_join}
            WHERE r.ambient        = %s
              AND r.operating      = %s
              AND r.config         = %s
              AND r.corecount      = %s
              AND r.ct             = %s
              AND r.dc             = 0
              AND s.mt             = %s
              AND s.nc             = %s
              AND s.cable_style IN ({style_placeholders})
              AND s.ct {conductor_test} 'a'
        """

        params: list[Any] = [
            ambient, operating, config, rating_cores, conductor,
            METRIC_MT, active_cores, *styles,
        ]

        if min_current is not None:
            sql += f" AND {current_sql} >= %s"
            params.append(min_current)

        sql += " ORDER BY CAST(s.xa AS DECIMAL(10,3)) ASC"

        rows = self._db.fetch_all(sql, tuple(params))
        return [
            SpecRow(
                cable_style=str(row.get("cable_style") or "").strip(),
                cross_section=_as_float(row.get("xa")),
                active_cores=_as_int(row.get("nc")),
                core_config=str(row.get("cc") or "").strip(),
                earth_count=_as_int(row.get("ne")),
                earth_cross_section=_as_float(row.get("xae")),
                overall_diameter=_as_float(row.get("od")),
                width=_as_float(row.get("width")),
                weight_kg_per_km=_as_float(row.get("wght")),
                bend_radius=_as_float(row.get("br")),
                current=_as_float(row.get("current_a")),
                ambient=_as_int(row.get("ambient")),
                operating=_as_int(row.get("operating")),
                conductor_type=str(row.get("ct") or "").strip(),
            )
            for row in rows
        ]

    # ── physical specs for a known construction ──────────────────────────────

    def lookup_spec(
        self, style: str, active_cores: int, cross_section: float
    ) -> SpecRow | None:
        """The `cable_specs` row for one construction, ignoring ratings.

        Used to attach OD, weight and bend radius to a product the matcher chose
        without any sizing step.
        """
        sql = """
            SELECT
                s.cable_style,
                CAST(s.xa AS DECIMAL(10,3)) AS xa,
                s.nc, s.cc, s.ne, s.xae, s.od, s.width, s.wght, s.br, s.ct
            FROM tc.cable_specs s
            WHERE s.cable_style = %s
              AND s.nc = %s
              AND CAST(s.xa AS DECIMAL(10,3)) = %s
              AND s.mt = %s
            LIMIT 1
        """
        row = self._db.fetch_one(sql, (style, active_cores, cross_section, METRIC_MT))
        if not row:
            return None
        return SpecRow(
            cable_style=str(row.get("cable_style") or "").strip(),
            cross_section=_as_float(row.get("xa")),
            active_cores=_as_int(row.get("nc")),
            core_config=str(row.get("cc") or "").strip(),
            earth_count=_as_int(row.get("ne")),
            earth_cross_section=_as_float(row.get("xae")),
            overall_diameter=_as_float(row.get("od")),
            width=_as_float(row.get("width")),
            weight_kg_per_km=_as_float(row.get("wght")),
            bend_radius=_as_float(row.get("br")),
            current=0.0,
            ambient=0,
            operating=0,
            conductor_type=str(row.get("ct") or "").strip(),
        )

    # ── pricing ──────────────────────────────────────────────────────────────

    def price_for_mask(self, mask: str, region: str = "australia") -> dict[str, Any] | None:
        """Cost per metre from `tc.price`, newest row wins.

        The legacy `prod_price()` matched a code *mask* with LIKE, trimming the
        trailing two-character colour. Kept, because that is how the price book
        is keyed.
        """
        dbco = DBCO_BY_REGION.get(region.lower(), DBCO_BY_REGION["australia"])
        sql = """
            SELECT cost, uwt
            FROM tc.price
            WHERE code LIKE %s
              AND dbco = %s
            ORDER BY dc DESC
            LIMIT 1
        """
        row = self._db.fetch_one(sql, (f"{mask}%", dbco))
        if not row:
            return None
        return {
            "costPerMetre": _as_float(row.get("cost")),
            "unitWeight": _as_float(row.get("uwt")) / 1000,
            "dbco": dbco,
        }

    # ── catalogue cross-check ────────────────────────────────────────────────

    def specs_for_product(self, product_code: str) -> dict[str, Any] | None:
        """Join a catalogue product code to its physical specs.

        This is `sql/BaseQuery.sql`'s join, narrowed to one product. It hits for
        roughly 62% of catalogue products; a miss means the construction has no
        `cable_specs` row and therefore cannot be sized.
        """
        sql = """
            SELECT
                p.product_code, p.style, p.no_core, p.core, p.cross_core,
                s.nc, s.ne, s.xa, s.xae, s.od, s.wght, s.br
            FROM tc.tpro_ext p
            LEFT JOIN tc.cable_specs s
                   ON p.style      = s.cable_style
                  AND p.no_core    = (s.nc + s.ne)
                  AND p.cross_core = s.xa
                  AND p.core       = s.cc
            WHERE p.product_code = %s
            LIMIT 1
        """
        try:
            return self._db.fetch_one(sql, (product_code,))
        except DatabaseUnavailable:
            return None
