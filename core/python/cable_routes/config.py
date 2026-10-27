"""Where the source extract and the store live: flag, then process environment, then <API root>/.env."""

from __future__ import annotations

import os
from pathlib import Path

ENV_DB = "CABLE_ROUTES_DB_PATH"
ENV_SOURCE = "CABLE_ROUTES_SOURCE_DIR"
DEFAULT_DB = "var/cable-routes/cable-routes.sqlite"


class ConfigError(Exception):
    """Bad usage or configuration (exit code 2)."""


def api_root() -> Path:
    """The tricab-api folder: the parent of core/ (this file is core/python/cable_routes/config.py)."""
    return Path(__file__).resolve().parents[3]


def read_dotenv(root: Path) -> dict[str, str]:
    """Only the two cable-routes keys from <root>/.env, with a few lines of parsing."""
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
        if not sep or key not in (ENV_DB, ENV_SOURCE):
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
