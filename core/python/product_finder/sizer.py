"""The conductor sizing search — ported from `find_cable()` (db.php:538-859).

Walks run counts outward and cross-sections upward, returning the smallest
construction that satisfies current-carrying capacity, voltage drop and
operating temperature at once.

Structural differences from the original, all deliberate:

* The acceptance test uses ``>=`` on current. The legacy ``>`` rejected a cable
  rated at exactly the design load.
* "Minimum cost" minimises **price**. The legacy comparison minimised
  ``runs x cross-section``, which is total conductor area, not money — and used
  ``<=`` so the *highest* run count won on a tie.
* Three-phase multi-core searches 3C+E as well as 4C. The legacy hardwired four
  active cores, so a 3C+E cable could never be sized at all.
* Nominal voltage gates the search. The legacy passed ``$voltage`` only to the
  volt-drop formula, so an 11 kV request could be answered with a 600/1000 V
  style.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Sequence

from . import sizing
from .constants import (
    INSTALL_METHODS,
    MAX_RUNS,
    core_options,
    is_sdi,
    rating_config,
    rating_core_count,
    suggest_styles,
)
from .db import DatabaseUnavailable
from .specs import SPECS, SpecsSource
from .specs_repo import SpecRow


@dataclass(frozen=True)
class SizingRequest:
    """Everything the electrical search needs."""

    load_amps: float
    length_m: float
    voltage: int = 400
    phase: int = 3
    cable_type: int = 1
    install: int = 1
    ambient_c: float = 40
    operating_c: int = 90
    max_volt_drop_pct: float = 5.0
    power_factor: float = 0.85
    max_op_temp_c: float | None = None
    aluminium_active: bool = False
    aluminium_earth: bool = False
    fixed_runs: int | None = None
    strategy: str = "min-runs"  # "min-runs" | "min-cost"
    fault_duration_s: float = 1.0
    pinned_cross_section: float | None = None
    region: str = "australia"

    def resolved_max_op_temp(self, operating: float) -> float:
        """The ceiling on conductor temperature under load.

        When the caller does not set one, the limit is the cable's own rating —
        that is what `find_cable` itself defaults to (db.php:543).

        The legacy form also has a default of `ambient < 50 ? 50 : ambient + 10`
        (include-cable-calc.php:200-211), but that is a *UI field* default, not
        the engine's. Porting it here rejected every size whose conductor ran
        hotter than 50 degC, which oversizes badly: a 200 A / 10 m XLPE feeder
        came out at 185 mm2 instead of 70 mm2, because 70 mm2 runs at 74.7 degC
        — perfectly fine for a 90 degC cable.
        """
        if self.max_op_temp_c is not None:
            return self.max_op_temp_c
        return operating


@dataclass
class SizingCandidate:
    """One accepted construction, with the working shown."""

    spec: SpecRow
    runs: int
    derating: float
    derated_current: float
    total_capacity: float
    operating_temp: float
    volt_drop_pct: float
    earth_cross_section: float
    fault_rating_ka: float | None
    space: dict[str, Any]
    price_per_metre: float | None
    total_conductor_area: float

    def public(self) -> dict[str, Any]:
        return {
            "spec": self.spec.public(),
            "runs": self.runs,
            "deratingFactor": round(self.derating, 4),
            "deratedCurrentPerRun": round(self.derated_current, 1),
            "totalCapacityAmps": round(self.total_capacity, 1),
            "operatingTempC": round(self.operating_temp, 1),
            "voltDropPct": self.volt_drop_pct,
            "earthCrossSection": self.earth_cross_section,
            "faultRatingKa": self.fault_rating_ka,
            "space": self.space,
            "pricePerMetre": self.price_per_metre,
            "totalConductorArea": round(self.total_conductor_area, 2),
        }


@dataclass
class SizingResult:
    status: str  # "sized" | "no-solution" | "unavailable"
    candidate: SizingCandidate | None = None
    considered: int = 0
    warnings: list[str] = field(default_factory=list)
    detail: str = ""

    def public(self) -> dict[str, Any]:
        return {
            "status": self.status,
            "candidate": self.candidate.public() if self.candidate else None,
            "considered": self.considered,
            "warnings": self.warnings,
            "detail": self.detail,
        }


def _table_ambient(install: int, actual_ambient: float) -> int:
    """The `rating.ambient` bucket to query.

    The rating table stores buried figures under ambient 30/40 even though those
    rows actually describe 15 degC and 25 degC soil. `find_cable` compensated on
    the way in and reversed it before re-rating; both halves are kept here
    because the data still has that shape.
    """
    if install == 8:
        return 45
    if INSTALL_METHODS[install]["buried"]:
        return 30 if actual_ambient == 15 else 40
    return 40 if actual_ambient >= 35 else 30


def _soil_temperature(table_ambient: int) -> int:
    """Reverse of the buried bucket — what the row actually represents."""
    return {40: 25, 30: 15}.get(table_ambient, table_ambient)


class Sizer:
    def __init__(self, repo: SpecsSource | None = None) -> None:
        self._repo = repo or SPECS

    @property
    def available(self) -> bool:
        return self._repo.available

    def size(
        self,
        request: SizingRequest,
        styles: Sequence[str] | None = None,
    ) -> SizingResult:
        if not self._repo.available:
            return SizingResult(
                status="unavailable",
                detail=(
                    "Sizing needs the legacy MySQL database (tc.rating and "
                    "tc.cable_specs). Set TRICAB_DB_* in .env."
                ),
            )

        warnings: list[str] = []

        resolved_styles, _earth_style, operating = suggest_styles(
            request.cable_type, request.phase, request.operating_c
        )
        if styles:
            resolved_styles = tuple(styles)
        if operating != request.operating_c:
            warnings.append(
                f"Operating temperature set to {operating} degC — the selected "
                f"construction is only manufactured at that rating."
            )

        table_ambient = _table_ambient(request.install, request.ambient_c)
        config = rating_config(request.cable_type)
        rating_cores = rating_core_count(request.phase)
        max_op_temp = request.resolved_max_op_temp(operating)
        single_core = is_sdi(request.cable_type)
        dc = request.phase == 5 and 10 <= request.cable_type < 13

        try:
            accepted, considered = self._search(
                request=request,
                styles=resolved_styles,
                table_ambient=table_ambient,
                config=config,
                rating_cores=rating_cores,
                operating=operating,
                max_op_temp=max_op_temp,
                single_core=single_core,
                dc=dc,
            )
        except DatabaseUnavailable as exc:
            return SizingResult(status="unavailable", detail=str(exc))

        if not accepted:
            return SizingResult(
                status="no-solution",
                considered=considered,
                warnings=warnings,
                detail=(
                    "No construction met the current, voltage-drop and "
                    "temperature limits together. Try a longer run count, a "
                    "higher permitted volt drop, or a different installation "
                    "method."
                ),
            )

        chosen = self._choose(accepted, request, warnings)
        return SizingResult(
            status="sized",
            candidate=chosen,
            considered=considered,
            warnings=warnings,
        )

    # ── the search itself ────────────────────────────────────────────────────

    def _search(
        self,
        request: SizingRequest,
        styles: Sequence[str],
        table_ambient: int,
        config: str,
        rating_cores: int,
        operating: int,
        max_op_temp: float,
        single_core: bool,
        dc: bool,
    ) -> tuple[list[SizingCandidate], int]:
        accepted: list[SizingCandidate] = []
        considered = 0

        if request.fixed_runs:
            run_counts = [request.fixed_runs]
        elif request.pinned_cross_section is not None:
            # Verifying a size the customer stated, not choosing one. Searching
            # run counts here would answer a different question — it would keep
            # adding parallel runs until the stated size worked, so 2.5 mm2
            # "passes" a 400 A duty on 14 runs. Verify as specified: one run.
            run_counts = [1]
        else:
            run_counts = list(range(1, MAX_RUNS + 1))

        for runs in run_counts:
            if runs is None or runs < 1:
                continue

            load_per_run = request.load_amps / runs

            for active_cores in core_options(request.cable_type, request.phase):
                rows = self._repo.find_candidates(
                    styles=styles,
                    install=request.install,
                    ambient=table_ambient,
                    operating=operating,
                    config=config,
                    rating_cores=rating_cores,
                    active_cores=active_cores,
                    is_aluminium=request.aluminium_active,
                    # When a size is pinned we verify it rather than search.
                    min_current=None if request.pinned_cross_section else load_per_run,
                    dc=dc,
                )

                for row in rows:
                    considered += 1

                    if (
                        request.pinned_cross_section is not None
                        and abs(row.cross_section - request.pinned_cross_section) > 1e-6
                    ):
                        continue

                    candidate = self._evaluate(
                        row=row,
                        runs=runs,
                        request=request,
                        table_ambient=table_ambient,
                        operating=operating,
                        max_op_temp=max_op_temp,
                        single_core=single_core,
                    )
                    if candidate is not None:
                        accepted.append(candidate)
                        break  # smallest adequate size at this run count

                if accepted and request.strategy == "min-runs":
                    return accepted, considered

            if accepted and request.strategy == "min-runs":
                return accepted, considered

        return accepted, considered

    def _evaluate(
        self,
        row: SpecRow,
        runs: int,
        request: SizingRequest,
        table_ambient: int,
        operating: int,
        max_op_temp: float,
        single_core: bool,
    ) -> SizingCandidate | None:
        """Apply derating, temperature and volt-drop checks to one construction."""
        derating = sizing.derating_factor(request.install, runs, single_core)
        table_current = row.current * derating
        if table_current <= 0:
            return None

        load_per_run = request.load_amps / runs

        # Conductor temperature under the real load, at the real ambient.
        conductor_temp = sizing.op_temp(
            ambient=request.ambient_c,
            load=load_per_run,
            max_load=table_current,
            rating=operating,
        )
        if conductor_temp > max_op_temp or conductor_temp > operating:
            return None

        volt_drop = sizing.volt_drop(
            temp=conductor_temp,
            csa=row.cross_section,
            load=load_per_run,
            length=request.length_m,
            od=row.effective_diameter,
            voltage=request.voltage,
            phase=request.phase,
            multi=not single_core,
            pf=request.power_factor,
            is_al=request.aluminium_active,
        )
        if volt_drop > request.max_volt_drop_pct:
            return None

        # Re-rate against the temperature the row actually represents.
        represented_ambient = (
            _soil_temperature(table_ambient)
            if INSTALL_METHODS[request.install]["buried"]
            else table_ambient
        )
        derated_current = sizing.rerate(
            rate=table_current,
            table_ambient=represented_ambient,
            max_temp=operating,
            actual_ambient=request.ambient_c,
        )

        total_capacity = derated_current * runs
        # FIX: >= so a cable rated exactly at the design load is accepted.
        if total_capacity < request.load_amps:
            return None

        earth_csa = row.earth_cross_section or sizing.earth_size(
            active_csa=row.cross_section,
            is_al_active=request.aluminium_active,
            is_al_earth=request.aluminium_earth,
        )

        fault_ka = sizing.fault_rating(
            csa=row.cross_section,
            runs=runs,
            temp=operating,
            duration_s=request.fault_duration_s,
            style=row.cable_style,
            is_al=request.aluminium_active,
        )

        space = sizing.required_space(
            od=row.effective_diameter,
            install=request.install,
            runs=runs,
            single_core=single_core,
            earths=row.earth_count,
        )

        price = self._price(row, request, runs)

        return SizingCandidate(
            spec=row,
            runs=runs,
            derating=derating,
            derated_current=derated_current,
            total_capacity=total_capacity,
            operating_temp=conductor_temp,
            volt_drop_pct=volt_drop,
            earth_cross_section=earth_csa,
            fault_rating_ka=fault_ka,
            space=space,
            price_per_metre=price,
            total_conductor_area=runs * row.cross_section,
        )

    def _price(
        self, row: SpecRow, request: SizingRequest, runs: int
    ) -> float | None:
        """Cost per metre for the whole run set, or None when unpriced."""
        vsd_styles = {"KU", "XU", "EU", "FH", "EA", "DP"}
        screen = "Z" if row.cable_style in vsd_styles else "X"
        conductor = "A" if request.aluminium_active else "N"
        size = f"{row.cross_section:g}".replace("0.", ".")
        mask = f"{row.cable_style}-XX{screen}{conductor}/{row.active_cores}C{size}"

        try:
            priced = self._repo.price_for_mask(mask, request.region)
        except DatabaseUnavailable:
            return None
        if not priced:
            return None
        return priced["costPerMetre"] * runs

    def _choose(
        self,
        accepted: list[SizingCandidate],
        request: SizingRequest,
        warnings: list[str],
    ) -> SizingCandidate:
        """Pick between accepted candidates."""
        if request.strategy != "min-cost":
            # Fewest runs, then smallest conductor.
            return min(accepted, key=lambda c: (c.runs, c.spec.cross_section))

        priced = [c for c in accepted if c.price_per_metre is not None]
        if priced:
            # FIX: minimise money, not conductor area.
            return min(priced, key=lambda c: c.price_per_metre or 0.0)

        warnings.append(
            "No price found for any candidate, so 'minimum cost' fell back to "
            "the smallest total conductor area."
        )
        return min(accepted, key=lambda c: (c.total_conductor_area, c.runs))


SIZER = Sizer()
