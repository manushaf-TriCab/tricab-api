"""Settings: the committed seed, an optional override file, validation and the hash (contract 3)."""

from __future__ import annotations

import hashlib
import json
import math
import re
from datetime import date
from pathlib import Path

from .config import MACHINES, YIELD_STEPS, ConfigError

SEED = Path(__file__).resolve().parent / "seed" / "settings.json"
TIME_RE = re.compile(r"^([01]\d|2[0-3]):[0-5]\d$")
WIRE_16 = ("NX-1W1.6", "DX-1W1.6")
KEYS = ("refill_weeks", "safety_weeks", "rate_window", "demand", "r86_min_weeks", "rod_warn_weeks", "yields",
        "batch_sizes", "min_levels", "shifts", "efficiency", "shutdown")


def _read(path: Path) -> dict:
    try:
        data = json.loads(Path(path).read_text(encoding="utf-8-sig"))
    except OSError as e:
        raise ConfigError(f"settings file cannot be read: {Path(path).name!r}") from e
    except ValueError as e:
        raise ConfigError(f"settings file is not valid JSON: {Path(path).name!r}: {e}") from e
    if not isinstance(data, dict):
        raise ConfigError(f"settings file must hold a JSON object: {Path(path).name!r}")
    return data


def _num(key, v, *, minimum=0.0, strict=False):
    ok = isinstance(v, (int, float)) and not isinstance(v, bool) and math.isfinite(v)
    if not ok or (v <= minimum if strict else v < minimum):
        raise ConfigError(f"settings {key}: {v!r} must be a number {'>' if strict else '>='} {minimum:g}")
    return v


def _hhmm(s: str) -> int:
    return int(s[:2]) * 60 + int(s[3:])


def split_intervals(days, intervals) -> dict[int, list[tuple[int, int]]]:
    """{weekday: [(start_min, end_min)]} after the midnight split of contract 4.6 (end 24:00 = 1440)."""
    out: dict[int, list[tuple[int, int]]] = {}
    for d in days:
        for s, e in intervals:
            s, e = _hhmm(s), _hhmm(e)
            if e > s:
                out.setdefault(d, []).append((s, e))
            else:
                out.setdefault(d, []).append((s, 1440))
                if e > 0:
                    out.setdefault((d + 1) % 7, []).append((0, e))
    return out


def _validate(s: dict) -> None:
    _num("refill_weeks", s["refill_weeks"])
    _num("safety_weeks", s["safety_weeks"])
    if s["rate_window"] not in ("13w", "26w", "52w"):
        raise ConfigError(f"settings rate_window: {s['rate_window']!r} must be one of 13w, 26w, 52w")
    if s["demand"] not in ("r86-order", "r86-invoice", "issues"):
        raise ConfigError(f"settings demand: {s['demand']!r} must be one of r86-order, r86-invoice, issues")
    _num("r86_min_weeks", s["r86_min_weeks"], strict=True)
    _num("rod_warn_weeks", s["rod_warn_weeks"], strict=True)

    y = s["yields"]
    if not isinstance(y, dict):
        raise ConfigError(f"settings yields: {y!r} must be an object")
    for step, v in y.items():
        if step not in YIELD_STEPS:
            raise ConfigError(f"settings yields: unknown step {step!r} (steps: {', '.join(YIELD_STEPS)})")
        if not (isinstance(v, (int, float)) and not isinstance(v, bool) and 0 < v <= 1):
            raise ConfigError(f"settings yields.{step}: {v!r} must be a number in (0, 1]")

    for key in ("batch_sizes", "min_levels"):
        d = s[key]
        if not isinstance(d, dict):
            raise ConfigError(f"settings {key}: {d!r} must be an object")
        for item, v in d.items():
            if not isinstance(item, str) or not item.strip():
                raise ConfigError(f"settings {key}: item {item!r} must be a non-empty string")
            if item not in WIRE_16:
                raise ConfigError(f"settings {key}: item {item!r} must be one of {', '.join(WIRE_16)}")
            _num(f"{key}.{item}", v)

    shifts = s["shifts"]
    if not isinstance(shifts, dict):
        raise ConfigError(f"settings shifts: {shifts!r} must be an object")
    for m, p in shifts.items():
        if m not in MACHINES:
            raise ConfigError(f"settings shifts: unknown machine {m!r}")
        if not isinstance(p, dict):
            raise ConfigError(f"settings shifts.{m}: {p!r} must be an object")
        days, ivs = p.get("days"), p.get("intervals")
        if (not isinstance(days, list) or not days or len(set(map(repr, days))) != len(days)
                or any(not isinstance(d, int) or isinstance(d, bool) or not 0 <= d <= 6 for d in days)):
            raise ConfigError(f"settings shifts.{m}.days: {days!r} must be a non-empty list of unique ints 0..6")
        if not isinstance(ivs, list) or not ivs:
            raise ConfigError(f"settings shifts.{m}.intervals: {ivs!r} must be a non-empty list")
        for iv in ivs:
            if (not isinstance(iv, list) or len(iv) != 2 or not all(isinstance(t, str) and TIME_RE.match(t) for t in iv)):
                raise ConfigError(f"settings shifts.{m}.intervals: {iv!r} must be [\"HH:MM\", \"HH:MM\"]")
            if iv[0] == iv[1]:
                raise ConfigError(f"settings shifts.{m}.intervals: {iv!r} start equals end")
        for d, spans in split_intervals(days, ivs).items():
            spans.sort()
            for a, b in zip(spans, spans[1:]):
                if b[0] < a[1]:
                    raise ConfigError(f"settings shifts.{m}.intervals: intervals overlap on weekday {d}")

    eff = s["efficiency"]
    if not isinstance(eff, dict):
        raise ConfigError(f"settings efficiency: {eff!r} must be an object")
    for m, v in eff.items():
        if m not in MACHINES:
            raise ConfigError(f"settings efficiency: unknown machine {m!r}")
        if not (isinstance(v, (int, float)) and not isinstance(v, bool) and 0 < v <= 1):
            raise ConfigError(f"settings efficiency.{m}: {v!r} must be a number in (0, 1]")

    sd = s["shutdown"]
    if not isinstance(sd, list):
        raise ConfigError(f"settings shutdown: {sd!r} must be a list")
    for r in sd:
        if not isinstance(r, dict):
            raise ConfigError(f"settings shutdown: {r!r} must be an object with from and to")
        try:
            a, b = date.fromisoformat(r["from"]), date.fromisoformat(r["to"])
        except (KeyError, TypeError, ValueError):
            raise ConfigError(f"settings shutdown: {r!r} needs ISO dates in from and to") from None
        if a > b:
            raise ConfigError(f"settings shutdown: {r!r} has from after to")


def load(override: Path | None = None) -> tuple[dict, str, str]:
    """(effective settings, settings_source, settings_hash). Raises ConfigError (exit 2)."""
    seed = _read(SEED)
    eff = {k: v for k, v in seed.items() if not k.startswith("_")}
    source = "seed"
    if override is not None:
        over = _read(override)
        for k, v in over.items():
            if k.startswith("_"):
                continue
            if k not in KEYS:
                raise ConfigError(f"settings: unknown key {k!r}")
            if k == "yields":
                if not isinstance(v, dict):
                    raise ConfigError(f"settings yields: {v!r} must be an object")
                eff["yields"] = {**eff["yields"], **v}
            else:
                eff[k] = v
        source = Path(override).name
    _validate(eff)
    eff["yields"] = {step: float(eff["yields"].get(step, 1.0)) for step in YIELD_STEPS}
    canon = json.dumps(eff, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    return eff, source, hashlib.sha256(canon.encode()).hexdigest()[:16]
