# Generated from the RFQ parser's matcher/cost_api.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
from __future__ import annotations

import hashlib
import http.client
import json
import os
import random
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from threading import Lock
from typing import Any

from tricab_cable_rules.normalize import int_if_whole


REGION_DBCO = {
    "australia": "2507",
    # New Zealand currently uses the Australian cost book; quote currency is separate.
    "newzealand": "2507",
    "usa": "2517",
    "asia": "2519",
}

# A code the API has never heard of is re-requested on every reprice otherwise.
# Cached far shorter than a real price: a product genuinely being added to the
# catalog should show up within a few minutes, not after the full TTL.
NOT_FOUND_CACHE_SECONDS = 60

# Parallel warming width. The Cost API answers in ~100ms, so the ceiling here is
# politeness toward tricab.com rather than local throughput.
MAX_LOOKUP_WORKERS = 8


class CostApiClient:
    def __init__(
        self,
        base_url: str | None = None,
        timeout: float | None = None,
        cache_seconds: int | None = None,
        cache_dir: str | None = None,
    ):
        self.base_url = base_url or os.environ.get("TRICAB_COST_API_URL", "https://www.tricab.com/cost-api/")
        self.timeout = timeout if timeout is not None else float(os.environ.get("TRICAB_COST_API_TIMEOUT_SECONDS", "12"))
        self.cache_seconds = (
            cache_seconds if cache_seconds is not None else int(os.environ.get("TRICAB_COST_API_CACHE_SECONDS", "300"))
        )
        self.default_region = normalize_region(os.environ.get("TRICAB_DEFAULT_REGION", "australia"))
        self._cache: dict[tuple[str, str, str], tuple[float, dict[str, Any]]] = {}
        self._lock = Lock()
        self._disk_cache = DiskCache(
            cache_dir if cache_dir is not None else os.environ.get("TRICAB_COST_API_CACHE_DIR"),
            self.base_url,
        )
        self._pool = ConnectionPool(self.base_url, self.timeout)

    def lookup(self, product_code: str, length: Any, region: str | None = None) -> dict[str, Any]:
        code = str(product_code or "").strip().upper()
        selected_region = normalize_region(region or self.default_region)
        dbco = REGION_DBCO[selected_region]
        normalized_length = int_if_whole(length)

        if not code:
            return self._result("missing_code", code, normalized_length, selected_region, dbco)
        if normalized_length is None or normalized_length <= 0:
            return self._result("missing_length", code, normalized_length, selected_region, dbco)

        cache_key = (code, canonical_length(normalized_length), dbco)
        cached = self._cached(cache_key)
        if cached:
            return {**cached, "cached": True}

        query = urllib.parse.urlencode(
            {
                "userInputLength": canonical_length(normalized_length),
                "productCode": code,
                "DBCO": dbco,
            }
        )
        separator = "&" if "?" in self.base_url else "?"
        url = f"{self.base_url}{separator}{query}"

        try:
            payload = json.loads(self._http_get(url).decode("utf-8"))
        except urllib.error.HTTPError as exc:
            payload = read_error_payload(exc)
            is_not_found = exc.code == 404 or payload.get("error") == "Product not found"
            status = "not_found" if is_not_found else "error"
            result = self._result(
                status, code, normalized_length, selected_region, dbco, error=payload.get("message") or str(exc)
            )
            if is_not_found:
                self._store(cache_key, result, ttl=NOT_FOUND_CACHE_SECONDS)
            return result
        except (urllib.error.URLError, TimeoutError, OSError, json.JSONDecodeError) as exc:
            return self._result("error", code, normalized_length, selected_region, dbco, error=str(exc))

        data = payload.get("data") or {}
        if not payload.get("success") or data.get("calculatedCostPerMeter") in (None, ""):
            return self._result(
                "error",
                code,
                normalized_length,
                selected_region,
                dbco,
                error=payload.get("message") or payload.get("error") or "Cost API returned no calculated cost.",
            )

        result = self._result(
            "available",
            str(data.get("productCode") or code).upper(),
            normalized_length,
            selected_region,
            str(data.get("dbco") or dbco),
        )
        result.update(
            {
                "unit_price": float(data["calculatedCostPerMeter"]),
                "cost_status": data.get("status"),
                "default_cost": data.get("defaultCost"),
                "default_length": data.get("defaultLength"),
                "calculated_cost_per_meter": data.get("calculatedCostPerMeter"),
                "api_timestamp": payload.get("timestamp"),
            }
        )
        self._store(cache_key, result)
        return result

    def warm(self, specs) -> None:
        """Fetch several (product_code, length, region) triples in parallel.

        Pure cache priming: results are discarded, and the caller then runs its
        normal serial ``lookup`` logic, which now answers from cache. That keeps
        quote-wide pricing rules (MAKE MOQ grouping, prepack re-snapping) exactly
        as they were while collapsing N round trips into ceil(N / workers).
        """
        # Warming works by leaving results in the cache for the serial pass to
        # pick up, so with caching switched off it would just double every call.
        if self.cache_seconds <= 0:
            return

        pending = []
        seen = set()
        for product_code, length, region in specs:
            code = str(product_code or "").strip().upper()
            normalized_length = int_if_whole(length)
            if not code or normalized_length is None or normalized_length <= 0:
                continue
            selected_region = normalize_region(region or self.default_region)
            key = (code, canonical_length(normalized_length), REGION_DBCO[selected_region])
            if key in seen or self._cached(key):
                continue
            seen.add(key)
            pending.append((code, normalized_length, region))

        if len(pending) < 2:
            for spec in pending:
                self.lookup(*spec)
            return

        workers = min(MAX_LOOKUP_WORKERS, len(pending))
        with ThreadPoolExecutor(max_workers=workers) as executor:
            list(executor.map(lambda spec: self.lookup(*spec), pending))

    def health(self) -> dict[str, Any]:
        return {
            "source": "tricab_cost_api",
            "endpoint": self.base_url,
            "default_region": self.default_region,
            "regions": [{"region": region, "dbco": dbco} for region, dbco in REGION_DBCO.items()],
            "timeout_seconds": self.timeout,
            "cache_seconds": self.cache_seconds,
            "cache_dir": self._disk_cache.directory,
            "keep_alive": self._pool.enabled,
        }

    def _http_get(self, url: str) -> bytes:
        """GET ``url``, reusing a kept-alive connection when possible.

        Raises ``urllib.error.HTTPError`` on a non-2xx response so callers see
        the same failure shape they did when this went through ``urlopen``.
        """
        return self._pool.get(url)

    def _result(
        self,
        status: str,
        product_code: str,
        length: float | int | None,
        region: str,
        dbco: str,
        error: str = "",
    ) -> dict[str, Any]:
        return {
            "status": status,
            "source": "tricab_cost_api",
            "product_code": product_code,
            "region": region,
            "dbco": dbco,
            "user_input_length": length,
            "unit_price": None,
            "price_uom": "m",
            "cost_status": None,
            "default_cost": None,
            "default_length": None,
            "calculated_cost_per_meter": None,
            "api_timestamp": None,
            "cached": False,
            "error": error,
        }

    def _cached(self, key: tuple[str, str, str]) -> dict[str, Any] | None:
        with self._lock:
            cached = self._cache.get(key)
            if cached:
                created_at, result = cached
                if time.monotonic() - created_at <= self.cache_seconds:
                    return dict(result)
                self._cache.pop(key, None)

        # The PHP bridge starts a fresh Python process per request, so the dict
        # above is always cold there. The disk tier is what actually spares the
        # deployed app a network round trip.
        stored = self._disk_cache.get(key, self.cache_seconds)
        if stored is None:
            return None
        with self._lock:
            self._cache[key] = (time.monotonic(), dict(stored))
        return stored

    def _store(self, key: tuple[str, str, str], result: dict[str, Any], ttl: int | None = None) -> None:
        with self._lock:
            self._cache[key] = (time.monotonic(), dict(result))
        self._disk_cache.set(key, result, ttl if ttl is not None else self.cache_seconds)


class ConnectionPool:
    """One kept-alive HTTP connection per thread.

    ``urllib.request.urlopen`` opens (and discards) a fresh TCP + TLS connection
    per call, so pricing a 30-line quote paid 30 handshakes. Connections are
    thread-local because the warming pool calls this concurrently and
    ``http.client`` connections are not safe to share.
    """

    def __init__(self, base_url: str, timeout: float):
        parsed = urllib.parse.urlsplit(base_url)
        self.host = parsed.hostname or ""
        self.port = parsed.port
        self.secure = parsed.scheme != "http"
        self.timeout = timeout
        # A redirect (or anything else http.client can't transparently handle)
        # turns pooling off for the process and we fall back to urlopen, which
        # follows redirects for us.
        self.enabled = bool(self.host)
        self._local = threading.local()

    def get(self, url: str) -> bytes:
        if not self.enabled or not self._same_origin(url):
            return self._urlopen(url)
        try:
            return self._pooled_get(url)
        except urllib.error.HTTPError:
            raise
        except (http.client.HTTPException, OSError):
            # A pooled connection can be closed by the server between requests.
            # Retry once on a fresh connection before giving up on pooling.
            self._close()
            try:
                return self._pooled_get(url)
            except urllib.error.HTTPError:
                raise
            except (http.client.HTTPException, OSError):
                self._close()
                return self._urlopen(url)

    def _pooled_get(self, url: str) -> bytes:
        connection = self._connection()
        parsed = urllib.parse.urlsplit(url)
        target = parsed.path or "/"
        if parsed.query:
            target = f"{target}?{parsed.query}"
        connection.request(
            "GET",
            target,
            headers={"Accept": "application/json", "Connection": "keep-alive", "Host": parsed.netloc},
        )
        response = connection.getresponse()
        body = response.read()
        if response.status in (301, 302, 303, 307, 308):
            # Not worth chasing here -- disable pooling and let urlopen do it.
            self.enabled = False
            self._close()
            return self._urlopen(url)
        if response.status >= 400:
            raise urllib.error.HTTPError(
                url, response.status, response.reason, response.headers, _BodyStream(body)
            )
        return body

    def _connection(self):
        connection = getattr(self._local, "connection", None)
        if connection is None:
            factory = http.client.HTTPSConnection if self.secure else http.client.HTTPConnection
            connection = factory(self.host, self.port, timeout=self.timeout)
            self._local.connection = connection
        return connection

    def _close(self) -> None:
        connection = getattr(self._local, "connection", None)
        if connection is not None:
            try:
                connection.close()
            except OSError:
                pass
            self._local.connection = None

    def _same_origin(self, url: str) -> bool:
        parsed = urllib.parse.urlsplit(url)
        return parsed.hostname == self.host and parsed.port == self.port

    def _urlopen(self, url: str) -> bytes:
        request = urllib.request.Request(url, headers={"Accept": "application/json"}, method="GET")
        with urllib.request.urlopen(request, timeout=self.timeout) as response:
            return response.read()


class _BodyStream:
    """Minimal file-like wrapper so HTTPError.read() works on a pooled body."""

    def __init__(self, body: bytes):
        self._body = body

    def read(self, *_args) -> bytes:
        body, self._body = self._body, b""
        return body

    def close(self) -> None:
        self._body = b""


class DiskCache:
    """Cross-process price cache.

    In production ``rfq_api.php`` runs ``rfq_cli.py`` in a new interpreter for
    every request, so an in-process dict never survives to serve a second
    lookup. Prices are keyed by (code, length, DBCO) and are immutable for their
    TTL, so a plain file per key is enough -- no locking beyond the atomic
    replace, and a torn or unreadable entry just counts as a miss.
    """

    def __init__(self, directory: str | None, base_url: str):
        if directory == "":
            self.directory = None
        else:
            self.directory = directory or os.path.join(
                tempfile.gettempdir(), "rfq-cost-cache", hashlib.sha1(base_url.encode("utf-8")).hexdigest()[:12]
            )
        self._pruned = False

    def get(self, key: tuple[str, str, str], max_age: float) -> dict[str, Any] | None:
        path = self._path(key)
        if not path:
            return None
        try:
            with open(path, "r", encoding="utf-8") as handle:
                entry = json.load(handle)
        except (OSError, json.JSONDecodeError):
            return None
        stored_at = entry.get("stored_at")
        ttl = entry.get("ttl")
        if not isinstance(stored_at, (int, float)):
            return None
        age = time.time() - stored_at
        # A clock that jumped backwards would otherwise pin an entry forever.
        if age < 0 or age > min(float(ttl if isinstance(ttl, (int, float)) else max_age), float(max_age)):
            return None
        result = entry.get("result")
        return dict(result) if isinstance(result, dict) else None

    def set(self, key: tuple[str, str, str], result: dict[str, Any], ttl: float) -> None:
        path = self._path(key)
        if not path:
            return
        entry = {"stored_at": time.time(), "ttl": ttl, "result": result}
        try:
            os.makedirs(self.directory, exist_ok=True)
            handle, temp_path = tempfile.mkstemp(dir=self.directory, suffix=".tmp")
            try:
                with os.fdopen(handle, "w", encoding="utf-8") as writer:
                    json.dump(entry, writer)
                os.replace(temp_path, path)
            except OSError:
                _unlink(temp_path)
                raise
        except OSError:
            # A read-only or full cache dir must never break pricing.
            return
        self._maybe_prune(ttl)

    def _path(self, key: tuple[str, str, str]) -> str | None:
        if not self.directory:
            return None
        digest = hashlib.sha1("|".join(key).encode("utf-8")).hexdigest()
        return os.path.join(self.directory, f"{digest}.json")

    def _maybe_prune(self, ttl: float) -> None:
        # Cheap opportunistic sweep: entries are tiny, so letting a few stale
        # ones linger is fine -- this only stops unbounded growth over weeks.
        if self._pruned or random.random() > 0.02:
            return
        self._pruned = True
        cutoff = time.time() - max(float(ttl), 60.0) * 10
        try:
            entries = os.listdir(self.directory)
        except OSError:
            return
        for name in entries:
            path = os.path.join(self.directory, name)
            try:
                if os.path.getmtime(path) < cutoff:
                    _unlink(path)
            except OSError:
                continue


def _unlink(path: str) -> None:
    try:
        os.unlink(path)
    except OSError:
        pass


def normalize_region(value: str | None) -> str:
    region = str(value or "").strip().lower()
    aliases = {
        "au": "australia",
        "aus": "australia",
        "us": "usa",
        "united states": "usa",
        "united states of america": "usa",
    }
    region = aliases.get(region, region)
    return region if region in REGION_DBCO else "australia"


def canonical_length(value: float | int) -> str:
    number = float(value)
    return str(int(number)) if number.is_integer() else str(number).rstrip("0").rstrip(".")


def read_error_payload(exc: urllib.error.HTTPError) -> dict[str, Any]:
    try:
        return json.loads(exc.read().decode("utf-8"))
    except (json.JSONDecodeError, OSError):
        return {}
