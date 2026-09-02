"""Electrical sizing — ported from the legacy Cable Calculator's `functions/math.php`.

Lookup tables are transcribed verbatim; they are TriCab's own rating data and
must not be re-derived. The algorithms are ported faithfully **except** where
the legacy implementation is demonstrably wrong. Every such place is marked
`FIX:` with the reason, so a reviewer can diff this against the PHP and account
for each difference.

Units, which the PHP left implicit:
  * cross-section  mm^2
  * length         metres (resistance and reactance are for the whole run)
  * current        amperes
  * temperature    degrees Celsius
  * volt drop      percent of nominal voltage
  * fault rating   kiloamperes
"""

from __future__ import annotations

import math
from typing import Final

# ─────────────────────────────────────────────────────────────────────────────
# Lookup tables (math.php)
# ─────────────────────────────────────────────────────────────────────────────

# Inductance, mH/km — math.php:36-128
INDUCTANCE_CU: Final[dict[float, float]] = {
    0.5: 0.528, 0.75: 0.507, 1: 0.471, 1.5: 0.438, 2.5: 0.428, 4: 0.387,
    6: 0.368, 10: 0.336, 16: 0.314, 25: 0.303, 35: 0.288, 50: 0.279,
    70: 0.269, 95: 0.262, 120: 0.259, 150: 0.258, 185: 0.257, 240: 0.254,
    300: 0.25, 400: 0.247, 500: 0.246, 630: 0.244, 800: 0.215,
}

INDUCTANCE_AL: Final[dict[float, float]] = {
    0.5: 0.502, 0.75: 0.482, 1: 0.447, 1.5: 0.416, 2.5: 0.407, 4: 0.368,
    6: 0.35, 10: 0.319, 16: 0.298, 25: 0.287, 35: 0.275, 50: 0.264,
    70: 0.26, 95: 0.254, 120: 0.252, 150: 0.251, 185: 0.249, 240: 0.247,
    300: 0.243, 400: 0.241, 500: 0.238, 630: 0.236, 800: 0.235, 1000: 0.233,
}

# Axial spacing for multi-core geometry, mm — math.php
SPACING_MM: Final[dict[float, float]] = {
    1.5: 2.997, 2.5: 3.609, 4.0: 4.017, 6.0: 4.629, 10: 5.649, 16: 6.669,
    25: 8.408, 35: 9.836, 50: 11.471, 70: 13.310, 95: 15.350, 120: 17.290,
    150: 19.540, 185: 21.687, 240: 24.239, 300: 26.690, 400: 30.673,
}

# Resistivity [copper, aluminium] — math.php:247-294
RESISTIVITY: Final[dict[float, tuple[float, float]]] = {
    0.5: (0.0195, 0.03), 0.75: (0.0195, 0.03), 1: (0.0195, 0.03),
    1.5: (0.01995, 0.03), 2.5: (0.01995, 0.03), 4: (0.0198, 0.03),
    6: (0.0198, 0.03), 10: (0.0191, 0.03), 16: (0.01936, 0.03),
    25: (0.0195, 0.03), 35: (0.01939, 0.03038), 50: (0.0193, 0.03205),
    70: (0.01904, 0.03101), 95: (0.01957, 0.0304), 120: (0.01932, 0.03036),
    150: (0.01935, 0.0309), 185: (0.01961, 0.03034), 240: (0.019224, 0.03),
    300: (0.01923, 0.03), 400: (0.01944, 0.03112), 500: (0.0192, 0.03025),
}

RESISTIVITY_DEFAULT: Final[tuple[float, float]] = (0.0191, 0.03)

TEMP_COEFFICIENT: Final[float] = 0.00393  # per degC, referenced to 20 degC

# Earth/CPC sizing — math.php:153-218. Keyed by (active material, earth material).
# Values map active CSA -> earth CSA. `cf` is the fallback power-to-earth ratio.
_EARTH_CU_AL: Final[dict[float, float]] = {
    1000: 500, 800: 400, 630: 185, 500: 185, 400: 185, 300: 185, 240: 150,
    185: 120, 150: 95, 120: 70, 95: 50, 70: 50, 50: 35, 35: 25, 25: 16,
    16: 16, 10: 10, 6: 6,
}
_EARTH_AL_CU: Final[dict[float, float]] = {
    1000: 185, 800: 150, 630: 120, 500: 95, 400: 95, 300: 70, 240: 50,
    185: 35, 150: 25, 120: 25, 95: 16, 70: 10, 50: 10, 35: 6, 25: 6,
    16: 4, 10: 4, 6: 2.5,
}
_EARTH_AL_AL: Final[dict[float, float]] = {
    1000: 300, 800: 240, 630: 185, 500: 150, 400: 150, 300: 120, 240: 95,
    185: 70, 150: 50, 120: 50, 95: 35, 70: 25, 50: 25, 35: 16, 25: 10,
    16: 6, 10: 4, 6: 2.5,
}
_EARTH_CU_CU: Final[dict[float, float]] = {
    1000: 300, 800: 240, 630: 120, 500: 120, 400: 120, 300: 120, 240: 95,
    185: 70, 150: 50, 120: 35, 95: 25, 70: 25, 50: 16, 35: 10, 25: 6,
    16: 6, 10: 4, 6: 2.5,
}

EARTH_TABLES: Final[dict[tuple[bool, bool], tuple[dict[float, float], float]]] = {
    # (active is aluminium, earth is aluminium): (table, conversion factor)
    (False, True): (_EARTH_CU_AL, 2.0),
    (True, False): (_EARTH_AL_CU, 6.0),
    (True, True): (_EARTH_AL_AL, 3.33),
    (False, False): (_EARTH_CU_CU, 4.0),
}

MIN_ALUMINIUM_EARTH_MM2: Final[float] = 25.0

# Short-circuit k-constants — db.php:557-565.
# [Cu@160, Cu@250, Al@160, Al@250]. Style LP uses the 160 column, all others 250.
FAULT_CONSTANTS: Final[dict[int, tuple[float, float, float, float]]] = {
    130: (63.6, 121, 42, 79.6),
    125: (68.90, 123, 45.5, 81.5),
    110: (83.2, 132, 55, 87.1),
    90: (99.9, 143, 66, 94.5),
    85: (104, 146, 68.6, 96.3),
    80: (108, 149, 71.1, 98.1),
    75: (111, 151, 73.6, 99.9),
    70: (115, 154, 76, 102),
    65: (119, 157, 78.4, 104),
    60: (122, 159, 80.8, 105),
    55: (126, 162, 83.1, 107),
    50: (129, 165, 85.5, 109),
    45: (133, 168, 87.7, 111),
    40: (136, 170, 90, 113),
    35: (140, 173, 92.3, 114),
    30: (143, 176, 94.5, 116),
    25: (146, 179, 96.8, 118),
}

# Multi-run derating — db.php:592, 730-753. Index is the run count.
DERATE_BURIED_CONDUIT: Final[tuple[float, ...]] = (
    1.0, 1.0, 0.90, 0.83, 0.79, 0.75, 0.73, 0.71, 0.70, 0.68,
)
DERATE_BURIED_TOUCHING_SINGLE: Final[tuple[float, ...]] = (
    1.0, 1.0, 0.78, 0.66, 0.61, 0.56, 0.53, 0.50, 0.49, 0.47, 0.46, 0.44, 0.43,
)
DERATE_BURIED_TOUCHING_MULTI: Final[tuple[float, ...]] = (
    1.0, 1.0, 0.81, 0.70, 0.63, 0.59, 0.55, 0.52, 0.50, 0.48, 0.47, 0.45, 0.44,
)

# Standard conduit sizes [nominal, internal] mm — math.php:339-398
CONDUIT_SIZES: Final[tuple[tuple[int, float], ...]] = (
    (16, 12.5), (20, 16.1), (25, 21.1), (32, 27.5), (40, 34.9), (50, 44.1),
    (63, 58.8), (80, 80), (100, 100), (125, 125), (150, 150), (200, 200),
)


# ─────────────────────────────────────────────────────────────────────────────
# Conductor geometry and resistance
# ─────────────────────────────────────────────────────────────────────────────


def equivalent_diameter(csa: float) -> float:
    """Diameter of a solid conductor of the same cross-section, mm."""
    return 2 * math.sqrt(csa / math.pi)


def inductance(csa: float, od: float = 0.0, multi: bool = False, is_al: bool = False) -> float:
    """Inductance in mH/km.

    Geometric when an overall diameter is known (the normal path — `od` comes
    from `cable_specs`), table lookup otherwise.
    """
    if od > 0:
        spacing = SPACING_MM.get(csa, 0.07 * csa)
        effective_od = spacing * 0.9 if multi else od
        dia = equivalent_diameter(csa)
        if dia <= 0 or effective_od <= 0:
            return 0.0
        return 0.2 * math.log(2 * effective_od / dia) + 0.0514

    table = INDUCTANCE_AL if is_al else INDUCTANCE_CU
    if csa in table:
        return table[csa]
    return INDUCTANCE_CU.get(csa, 0.0)


def reactance(
    csa: float,
    length: float,
    od: float = 0.0,
    freq: float = 50.0,
    multi: bool = False,
    is_al: bool = False,
) -> float:
    """Reactance in ohms for the whole run.

    FIX: the legacy `get_fault_loop()` called `reactance($xa, $length, $od,
    $is_multi, $is_al)` — passing the multi flag into the `$freq` slot and the
    aluminium flag into `$multi`. Frequency became 0 or 1, so every fault-loop
    reactance was wrong by roughly a factor of 50. Callers here use keywords.
    """
    if not freq:
        freq = 50.0
    return length * 2 * math.pi * freq * inductance(csa, od, multi, is_al) / 1_000_000


def dc_res(csa: float, temp: float, length: float, is_al: bool = False) -> float:
    """DC resistance in ohms for the whole run.

    FIX: the legacy code passed `intval($xa)` from `gen_cable_details()` and
    `get_fault_loop()` but the raw value from `volt_drop()`. Truncation sent
    1.5 mm^2 to key 1 and 2.5 mm^2 to key 2 — the latter missing the table
    entirely and silently taking the default resistivity. The same cable got two
    different resistances depending on the code path. No truncation here.
    """
    if csa <= 0:
        return 0.0
    rho_cu, rho_al = RESISTIVITY.get(csa, RESISTIVITY_DEFAULT)
    rho = rho_al if is_al else rho_cu
    return (rho * (1 + TEMP_COEFFICIENT * (temp - 20)) * length) / csa


def _field_res(resistance: float, freq: float = 50.0) -> float:
    if resistance == 0:
        return 0.0
    return ((8 * math.pi * freq / resistance) * 1e-7) ** 2


def skin_effect(resistance: float, freq: float = 50.0) -> float:
    x = _field_res(resistance, freq)
    return x / (192 + 0.8 * x)


def proximity(csa: float, resistance: float, gap: float = 0.0, freq: float = 50.0) -> float:
    """Proximity-effect factor.

    Transcribed as shipped, including the inner term that reuses `field` where
    IEC 60287 uses the proximity value. Changing it would silently move every
    voltage-drop answer away from what the calculator has been quoting, so it is
    preserved deliberately and flagged here instead.
    """
    if not csa:
        return 0.0
    dia = equivalent_diameter(csa)
    prox = _field_res(resistance, freq)
    if gap == 0:
        gap = 2 * dia
    field = (dia / gap) ** 2
    inner = field / (192 + 0.8 * field) + 0.27
    if inner == 0:
        return 0.0
    return (prox / (192 + 0.8 * prox)) * field * (0.312 * field + 1.18 / inner)


def ac_res(resistance: float, skin: float, prox: float, multi: bool = False) -> float:
    """AC resistance in ohms. Multi-core carries a 1.02 multiplier."""
    multiplier = 1.02 if multi else 1.0
    return multiplier * resistance * (1 + skin + prox)


def z_imp(resistance: float, react: float, pf: float = 0.85) -> float:
    """Effective impedance at a given power factor."""
    angle = math.acos(max(-1.0, min(1.0, pf)))
    return resistance * math.cos(angle) + react * math.sin(angle)


# ─────────────────────────────────────────────────────────────────────────────
# Temperature and rating
# ─────────────────────────────────────────────────────────────────────────────


def op_temp(ambient: float, load: float, max_load: float, rating: float) -> float:
    """Conductor operating temperature under actual load."""
    if max_load <= 0:
        return ambient
    return ambient + (rating - ambient) * (load / max_load) ** 2


def rerate(rate: float, table_ambient: float, max_temp: float, actual_ambient: float) -> float:
    """Re-rate a table current for the actual ambient temperature.

    The legacy signature named these `($rate, $ambient, $maxtemp, $rated_temp)`
    but `find_cable` passed the table ambient third-from-last and the user's
    ambient last, i.e. the names were inverted. Renamed here to what they are.
    """
    denominator = 149 if max_temp == 110 else 91
    factor = round(1 + (table_ambient - actual_ambient) / denominator, 2)
    return round(rate * factor)


def volt_drop(
    temp: float,
    csa: float,
    load: float,
    length: float,
    od: float = 0.0,
    voltage: float = 400,
    phase: int = 3,
    multi: bool = False,
    pf: float = 0.85,
    is_al: bool = False,
) -> float:
    """Voltage drop as a percentage of nominal voltage."""
    if voltage <= 0:
        return 0.0

    resistance = dc_res(csa, temp, length, is_al)
    resistance_ac = ac_res(
        resistance, skin_effect(resistance), proximity(csa, resistance), multi
    )
    react = reactance(csa, length, od, freq=50.0, multi=multi, is_al=is_al)
    impedance = z_imp(resistance_ac, react, pf)

    if phase == 5:  # DC — resistive only
        drop = (2 * load * resistance) / (voltage / 100)
    elif phase == 4:  # single phase
        drop = (load * 2 * impedance) / (voltage / 100)
    else:  # three phase
        drop = (load * math.sqrt(3) * impedance) / (voltage / 100)

    return round(drop, 2)


def derating_factor(install: int, runs: int, single_core: bool) -> float:
    """Multi-run derating for the installation methods that define one.

    Methods 1-6 carry no multi-run derating in the source data.
    """
    if runs <= 1:
        return 1.0

    if install == 7:  # buried in touching conduit
        if runs < len(DERATE_BURIED_CONDUIT):
            return DERATE_BURIED_CONDUIT[runs]
        return (1.0072 * runs) ** -0.1780

    if install == 9:  # buried touching
        table = (
            DERATE_BURIED_TOUCHING_SINGLE if single_core else DERATE_BURIED_TOUCHING_MULTI
        )
        if runs < len(table):
            return table[runs]
        return table[-1]

    if install == 8 and runs > 6:  # IEC
        return 0.85

    return 1.0


# ─────────────────────────────────────────────────────────────────────────────
# Earth / CPC sizing
# ─────────────────────────────────────────────────────────────────────────────


def earth_size(
    active_csa: float,
    is_al_active: bool = False,
    is_al_earth: bool = False,
) -> float:
    """Earth conductor CSA for a given active CSA, mm^2.

    FIX: the legacy `earth()` had two exit paths that returned different
    quantities — the direct path returned an earth size from the table, the
    fallback path returned a conductor-size *key*. Both paths here return an
    earth CSA.
    """
    if active_csa <= 0:
        return 0.0

    table, cf = EARTH_TABLES[(is_al_active, is_al_earth)]

    def floor_aluminium(value: float) -> float:
        return max(value, MIN_ALUMINIUM_EARTH_MM2) if is_al_earth else value

    # Below the smallest tabulated size, the earth matches the active up to 2.5.
    if active_csa < 6:
        return floor_aluminium(2.5 if active_csa >= 2.5 else active_csa)

    for csa in sorted(table, reverse=True):
        if active_csa >= csa:
            return floor_aluminium(table[csa])

    # Larger than anything tabulated — fall back to the ratio.
    return floor_aluminium(max(active_csa / cf, 2.5))


# ─────────────────────────────────────────────────────────────────────────────
# Fault rating
# ─────────────────────────────────────────────────────────────────────────────


def fault_constant(temp: float, is_al: bool, style: str) -> float | None:
    """Look up the short-circuit k-constant.

    FIX: the legacy lookup returned an empty string for any temperature not in
    the table, and its fallback loop (`for i = op_temp; i < 7`) was unreachable
    for every realistic temperature. An empty k silently produced a fault rating
    of zero. This returns None so the caller can report the gap instead.
    """
    row = FAULT_CONSTANTS.get(int(temp))
    if row is None:
        return None
    cu_160, cu_250, al_160, al_250 = row
    if is_al:
        return al_160 if style == "LP" else al_250
    return cu_160 if style == "LP" else cu_250


def fault_rating(
    csa: float,
    runs: int,
    temp: float,
    duration_s: float,
    style: str,
    is_al: bool = False,
) -> float | None:
    """Short-circuit withstand rating in kA, or None if no k-constant applies."""
    k = fault_constant(temp, is_al, style)
    if k is None or duration_s <= 0:
        return None
    return round(runs * k * csa / (1000 * math.sqrt(duration_s)), 3)


# ─────────────────────────────────────────────────────────────────────────────
# Spacing
# ─────────────────────────────────────────────────────────────────────────────


def required_space(
    od: float, install: int, runs: int, single_core: bool, earths: int = 1
) -> dict[str, object]:
    """Tray width, or the smallest standard conduit that fits.

    FIX: the legacy function returned the string "not found" when nothing fit.
    This returns a structured result so a caller can tell "no conduit is large
    enough" from a number.
    """
    if od <= 0:
        return {"kind": "unknown", "value": None, "detail": "no overall diameter"}

    method = install in (3, 4, 7, 9, 5)
    if not method:  # tray
        if single_core:
            width = math.ceil(2 * od * (2 * runs - 1))
        else:
            width = math.ceil(od * runs + (runs - 1) * od)
        return {"kind": "tray", "value": width, "detail": "tray width, mm"}

    buried = 1.1 if install in (3, 4, 7, 9) else 1.0
    if single_core:
        needed = math.ceil(od * (2.8 if earths == 0 else 3.2) * buried)
    else:
        needed = math.ceil(od * 1.3 * buried)

    for nominal, internal in CONDUIT_SIZES:
        if internal - needed > 0:
            return {"kind": "conduit", "value": nominal, "detail": "nominal conduit, mm"}

    return {
        "kind": "conduit",
        "value": None,
        "detail": f"no standard conduit fits {needed} mm",
    }
