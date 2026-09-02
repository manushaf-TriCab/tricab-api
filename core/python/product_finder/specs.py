"""Which ampacity source the sizer reads, and the shape both of them satisfy.

Two implementations, one interface:

* :class:`~product_finder.json_specs_repo.JsonSpecsRepository` — the pinned
  snapshots at ``resources/cable-calculator/v1/``. Self-contained, no VPN, and
  the same rows PHP's ``NativeCableCalculatorEngine`` reads. **The default.**
* :class:`~product_finder.specs_repo.SpecsRepository` — live legacy MySQL.
  Still the only source of ``tc.price``, so a laptop on the VPN can size with
  real costs where the App Service cannot.

``PRODUCT_FINDER_SIZING_SOURCE`` picks:

``json`` (default)
    Always the snapshots. What the App Service runs, and what makes the sizing
    answer reproducible from the artifact alone.
``mysql``
    Always live MySQL. Sizing is unavailable when it is not configured, rather
    than quietly falling back — if you asked for live data, a snapshot answer
    dressed up as one is worse than an honest "unavailable".
``auto``
    MySQL when it is configured and reachable, snapshots otherwise.
"""

from __future__ import annotations

import logging
from typing import Any, Protocol, Sequence, runtime_checkable

from .config import CONFIG
from .json_specs_repo import JsonSpecsRepository
from .specs_repo import SpecRow, SpecsRepository

log = logging.getLogger(__name__)


@runtime_checkable
class SpecsSource(Protocol):
    """What the sizer needs from an ampacity source."""

    @property
    def available(self) -> bool: ...

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
    ) -> list[SpecRow]: ...

    def lookup_spec(
        self, style: str, active_cores: int, cross_section: float
    ) -> SpecRow | None: ...

    def price_for_mask(
        self, mask: str, region: str = "australia"
    ) -> dict[str, Any] | None: ...


def build_specs_source(source: str | None = None) -> SpecsSource:
    choice = (source or CONFIG.sizing_source or "json").strip().lower()

    if choice == "mysql":
        return SpecsRepository()

    if choice == "auto":
        mysql = SpecsRepository()
        if mysql.available:
            return mysql
        log.info("Legacy MySQL is not configured; sizing from the pinned snapshots.")
        return JsonSpecsRepository()

    if choice != "json":
        log.warning(
            "Unknown PRODUCT_FINDER_SIZING_SOURCE %r; using the pinned snapshots.",
            choice,
        )
    return JsonSpecsRepository()


SPECS: SpecsSource = build_specs_source()


def describe_source(specs: SpecsSource | None = None) -> dict[str, Any]:
    """What the health endpoint says about where sizing numbers come from."""
    specs = specs or SPECS
    if isinstance(specs, JsonSpecsRepository):
        manifest = specs.manifest()
        return {
            "source": "snapshot",
            "available": specs.available,
            "datasetVersion": manifest.get("datasetVersion"),
            "capturedAt": manifest.get("capturedAt"),
            "ratingRows": manifest.get("ratingRows"),
            "cableSpecsRows": manifest.get("cableSpecsRows"),
            # Said out loud because "no price" is otherwise indistinguishable
            # from "this cable is unpriced".
            "pricing": False,
        }
    return {"source": "legacy-mysql", "available": specs.available, "pricing": True}
