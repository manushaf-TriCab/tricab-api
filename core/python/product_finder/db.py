"""Legacy MySQL — `cable_specs`, `rating`, `price`.

Connection style and env names copied from `rfq-parser-mvp/matcher/db.py`. This
is no longer the default ampacity source: `product_finder.specs` reads the
pinned snapshots at `resources/cable-calculator/v1/` unless
`PRODUCT_FINDER_SIZING_SOURCE` says otherwise. What is still only here is
`tc.price`, so a developer on the VPN can size against real costs.

The connection is lazy and degrades rather than raising at import time, so the
service starts with no database configured at all.

**There is no Azure SQL client here any more.** There was one, and it was only
ever a health probe — nothing in the finder queried the platform catalogue. It
carried `pyodbc`, `azure-identity` and, on the App Service, the `msodbcsql18`
native driver, for a "reachable: true" line. PHP owns Azure SQL access and
already publishes `/health/database`; the finder asking the same question a
second way was cost without an answer.
"""

from __future__ import annotations

import logging
from contextlib import contextmanager
from typing import Any, Iterator

from .config import CONFIG, LegacyDbConfig

log = logging.getLogger(__name__)

CONNECT_TIMEOUT_SECONDS = 15
READ_TIMEOUT_SECONDS = 60  # the sizing sweep can run long queries

# Hostnames on the corporate network are only reachable over the VPN. Off it,
# the connection does not fail cleanly — it hangs — so the hint is worth saying
# out loud rather than leaving someone to guess at a timeout.
_VPN_HINT = (
    " The host resolves and the port accepts connections even off the VPN, so a "
    "timeout here usually means the VPN is down rather than the server."
)


class DatabaseUnavailable(RuntimeError):
    """Raised when a query is attempted against a database that isn't configured."""


# ─────────────────────────────────────────────────────────────────────────────
# Legacy MySQL
# ─────────────────────────────────────────────────────────────────────────────


class LegacyDatabase:
    """Thin PyMySQL wrapper — parameterised queries only.

    The legacy PHP built its SQL by string concatenation, including one query
    that interpolates a *column name* (`rating.$install`). We keep that one
    behaviour because the table genuinely stores one ampacity column per
    installation method, but the column name is validated against a whitelist
    rather than passed through from user input.
    """

    def __init__(self, config: LegacyDbConfig | None = None) -> None:
        self._config = config or CONFIG.legacy_db
        self._checked = False
        self._available = False

    @property
    def configured(self) -> bool:
        return self._config.configured

    @contextmanager
    def connect(self) -> Iterator[Any]:
        if not self._config.configured:
            raise DatabaseUnavailable(
                "Legacy MySQL is not configured. Set TRICAB_DB_HOST, "
                "TRICAB_DB_USER, TRICAB_DB_PASSWORD and TRICAB_DB_NAME in .env."
            )

        try:
            import pymysql
            from pymysql.cursors import DictCursor
        except ImportError as exc:  # pragma: no cover - dependency guard
            raise DatabaseUnavailable("PyMySQL is not installed.") from exc

        conn = pymysql.connect(
            host=self._config.host,
            port=self._config.port,
            user=self._config.user,
            password=self._config.password,
            database=self._config.name,
            cursorclass=DictCursor,
            charset="utf8mb4",
            autocommit=True,
            # Explicit timeouts. Off the VPN, Azure's gateway still accepts the
            # TCP connection and then never completes the MySQL handshake, so
            # the failure looks like an open port that hangs rather than a
            # refused one. Bounded waits turn that into a prompt error.
            connect_timeout=CONNECT_TIMEOUT_SECONDS,
            read_timeout=READ_TIMEOUT_SECONDS,
            write_timeout=READ_TIMEOUT_SECONDS,
        )
        try:
            yield conn
        finally:
            conn.close()

    def fetch_all(self, sql: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
        with self.connect() as conn:
            with conn.cursor() as cur:
                cur.execute(sql, params)
                return list(cur.fetchall())

    def fetch_one(self, sql: str, params: tuple[Any, ...] = ()) -> dict[str, Any] | None:
        with self.connect() as conn:
            with conn.cursor() as cur:
                cur.execute(sql, params)
                return cur.fetchone()

    def health(self) -> dict[str, Any]:
        """Cheap reachability probe for the preview's status strip."""
        if not self._config.configured:
            return {"configured": False, "reachable": False, "detail": "not configured"}
        try:
            self.fetch_one("SELECT 1 AS ok")
        except Exception as exc:  # noqa: BLE001 - surfaced to the user as text
            detail = str(exc)
            if "timed out" in detail.lower() or "2003" in detail:
                detail += _VPN_HINT
            return {"configured": True, "reachable": False, "detail": detail}
        return {"configured": True, "reachable": True, "detail": "ok"}



LEGACY_DB = LegacyDatabase()
