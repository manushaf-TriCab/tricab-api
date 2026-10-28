"""Where the source folder, store and settings live (flag, then process environment, then <API root>/.env), and constants."""

from __future__ import annotations

import os
import re
from pathlib import Path

ENV_DB = "CONDUCTOR_SCHEDULE_DB_PATH"
ENV_SOURCE = "CONDUCTOR_SCHEDULE_SOURCE_DIR"
ENV_SETTINGS = "CONDUCTOR_SCHEDULE_SETTINGS"
DEFAULT_DB = "var/conductor/conductor-schedule.sqlite"

# The 12 scheduled D365 machines, in stage order.
MACHINES = ("RB1", "TL1", "WD3", "WD4", "WD5", "BU1", "BU2", "BU4", "BU5", "BU6", "BU7", "ST1")
STAGE_ORDER = MACHINES
POSTER_NAME = {m: m for m in MACHINES} | {"RB1": "RB2", "TL1": "TN1"}
ROD_ITEM = "NX-1R8-NEX"
START_HOUR = 6
MIN_SHARE = 0.10
SPOOL_MAX_WIRES = 16
BATCH_MIN_ORDERS = 5
YIELD_STEPS = ("rod_break", "tinning", "wire_draw", "bunching", "conductor")
CAPACITY_WEEKS = 26
MAX_SCHEDULE_DAYS = 3650
WINDOWS = {"13w": 91, "26w": 182, "52w": 364}   # days; weekly rate = sum / weeks
KANBAN_DAYS = 364
STAGE_POOLS = ("15_WIR", "20_BUN", "25_FCON")
# Item prefix -> (material, mica) as R-86 groups copper (NX/DX confirmed; the mica prefixes are the register's proposals).
MATERIAL = {"NX": ("plain", 0), "NM": ("plain", 1), "MNX": ("plain", 1), "MNM": ("plain", 1),
            "DX": ("tinned", 0), "DM": ("tinned", 1), "MDX": ("tinned", 1), "MDM": ("tinned", 1)}
PLAIN_CODES = ("NX", "NM", "DX", "DM")        # preference for a bucket with no 52-week history
COPPER_FAMILIES = ("NX", "DX", "DM", "MNX")
COPPER_REVIEW = ("NM", "MNM", "VX", "VDX", "MDX", "MDM", "DXI", "TCWB", "PCWS")
CONDUCTOR_RE = re.compile(r"^(NX|NM|MNX|MNM|DX|DM|MDX|MDM)-1C(\d*\.?\d+)$")
VX_RE = re.compile(r"^(VX|VDX)-1C(\d*\.?\d+)$")
SPOOL_RE = re.compile(r"^(NX|DX)-(\d+)W(\.\d+)$")
COPPER_DESC_RE = (re.compile(r"\b(cu|tcu|copper|pacw|tacw)\b", re.I),
                  re.compile(r"conductor|wire draw|copper wire|cu rod", re.I))


class ConfigError(Exception):
    """Bad usage or configuration (exit code 2)."""


def api_root() -> Path:
    """The tricab-api folder: the parent of core/ (this file is core/python/conductor_schedule/config.py)."""
    return Path(__file__).resolve().parents[3]


def read_dotenv(root: Path) -> dict[str, str]:
    """Only this module's keys from <root>/.env."""
    out: dict[str, str] = {}
    try:
        text = (root / ".env").read_text(encoding="utf-8-sig")
    except OSError:
        return out
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].lstrip()
        key, sep, value = line.partition("=")
        key = key.strip()
        if not sep or key not in (ENV_DB, ENV_SOURCE, ENV_SETTINGS):
            continue
        value = value.strip()
        if len(value) >= 2 and value[0] in "\"'" and value.endswith(value[0]):
            value = value[1:-1]
        else:
            value = value.split(" #", 1)[0].strip()
        out[key] = value
    return out


def _lookup(flag, key, environ, root: Path):
    if flag and flag.strip():
        return flag.strip()
    v = (environ.get(key) or "").strip()
    if v:
        return v
    return (read_dotenv(root).get(key) or "").strip() or None


def resolve_db(flag=None, environ=None, root: Path | None = None) -> Path:
    environ = os.environ if environ is None else environ
    root = root or api_root()
    p = Path(os.path.expanduser(_lookup(flag, ENV_DB, environ, root) or DEFAULT_DB))
    return p if p.is_absolute() else root / p


def resolve_source(flag=None, environ=None, root: Path | None = None) -> Path:
    environ = os.environ if environ is None else environ
    root = root or api_root()
    raw = _lookup(flag, ENV_SOURCE, environ, root)
    if not raw:
        raise ConfigError(f"no source folder: pass --source DIR or set {ENV_SOURCE}")
    p = Path(os.path.expanduser(raw))
    if not p.is_dir():
        raise ConfigError(f"source folder does not exist: {p.name!r}")
    return p.resolve()


def resolve_settings(flag=None, environ=None, root: Path | None = None) -> Path | None:
    """The override settings file, or None (seed only). A named file that does not exist is an error."""
    environ = os.environ if environ is None else environ
    root = root or api_root()
    raw = _lookup(flag, ENV_SETTINGS, environ, root)
    if not raw:
        return None
    p = Path(os.path.expanduser(raw))
    if not p.is_absolute():
        p = root / p
    if not p.is_file():
        raise ConfigError(f"settings file does not exist: {p.name!r}")
    return p


# ---- scheduler constants (Engine B)
STAGE_RANK = {m: i for i, m in enumerate(MACHINES)}
BATCH_POOLS = frozenset({"15_WIR"})           # wire is made in shared fixed-size batches (CONTRACT-BATCH)
STOCK_WIRE_RE = re.compile(r"^(NX|DX)-1W1\.6$")   # CONTRACT-TRACKING: only 1.6 mm is batched and held at a minimum
PRIOR_BATCH_WEEKS = 52


def is_stock_wire(item: str) -> bool:
    return STOCK_WIRE_RE.match(item) is not None


def step_of(item: str, pool: str):
    """The yield step that makes `item`: 20_BUN bunching, 25_FCON conductor, 15_WIR rod_break (NX-1W1.6), tinning
    (DX-1W1.6) or wire_draw; None for anything else."""
    if pool == "20_BUN":
        return "bunching"
    if pool == "25_FCON":
        return "conductor"
    if pool == "15_WIR":
        if is_stock_wire(item):
            return "rod_break" if item.startswith("NX") else "tinning"
        return "wire_draw"
    return None
