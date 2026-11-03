"""Pull the six extract files straight from Dynamics 365 OData, unattended (refresh job, plan item 3.2).

Writes the same six CSVs the loader reads, only the columns the engine uses, so there is one load path. Stdlib only.

Token, in order: D365_TOKEN; D365_TENANT_ID + D365_CLIENT_ID + D365_CLIENT_SECRET; the App Service managed identity
(IDENTITY_ENDPOINT, the same registration cbsync uses). The identity needs read access to the five entities below.

manufactured-items.csv is not an entity: it is derived from the order headers, one row per ordered item, pool taken
from the item's most recent order that has one (latest ScheduledDate, then order number), else the literal "?".
"""

from __future__ import annotations

import csv
import json
import os
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

from .loader import REQUIRED

DEFAULT_URL = "https://tricab-prod.operations.dynamics.com"
DEFAULT_ENTITY = "AU1O"
PAGE = 5000

# file name -> (OData entity, columns)
ENTITIES: dict[str, tuple[str, tuple[str, ...]]] = {
    "production-order-headers-raw.csv": ("ProductionOrderHeaders", REQUIRED["production-order-headers-raw.csv"]),
    "production-bom-lines-raw.csv": ("ProductionOrderBillOfMaterialLines", REQUIRED["production-bom-lines-raw.csv"]),
    "production-route-operations-raw.csv": (
        "ProductionOrderRouteOperations", REQUIRED["production-route-operations-raw.csv"]),
    "released-products.csv": ("ReleasedProductsV2", REQUIRED["released-products.csv"]),
    "purchase-order-lines-all.csv": ("PurchaseOrderLinesV2", REQUIRED["purchase-order-lines-all.csv"]),
}


class D365Error(Exception):
    """Dynamics could not be reached or refused us. Nothing was changed."""


def _post_json(url, data, headers=None):
    req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode(), headers=headers or {})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.load(r)


def get_token(base: str, environ=None) -> str:
    env = os.environ if environ is None else environ
    try:
        if env.get("D365_TOKEN"):
            return env["D365_TOKEN"]
        tenant, client, secret = (env.get(k) for k in ("D365_TENANT_ID", "D365_CLIENT_ID", "D365_CLIENT_SECRET"))
        if tenant and client and secret:
            return _post_json(f"https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token", {
                "grant_type": "client_credentials", "client_id": client, "client_secret": secret,
                "scope": f"{base}/.default"})["access_token"]
        if env.get("IDENTITY_ENDPOINT") and env.get("IDENTITY_HEADER"):
            q = urllib.parse.urlencode({"resource": base, "api-version": "2019-08-01"})
            req = urllib.request.Request(f"{env['IDENTITY_ENDPOINT']}?{q}",
                                         headers={"X-IDENTITY-HEADER": env["IDENTITY_HEADER"]})
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.load(r)["access_token"]
    except (urllib.error.URLError, KeyError, ValueError) as e:
        raise D365Error(f"could not get a Dynamics token: {type(e).__name__}") from e
    raise D365Error("no Dynamics credentials: set D365_TOKEN, the D365_* app registration, or run on App Service "
                    "with a managed identity")


class Client:
    def __init__(self, base=None, legal_entity=None, environ=None, sleep=time.sleep, opener=urllib.request.urlopen):
        env = os.environ if environ is None else environ
        self.env = env
        self.base = (base or env.get("D365_URL") or DEFAULT_URL).rstrip("/")
        self.legal_entity = legal_entity or env.get("D365_LEGAL_ENTITY") or DEFAULT_ENTITY
        self._sleep, self._open = sleep, opener
        self._token = None

    def _get(self, url):
        """One page as parsed JSON. Retries blips with backoff; one token refresh on 401; 403 fails at once."""
        refreshed = False
        for attempt in range(1, 6):
            if self._token is None:
                self._token = get_token(self.base, self.env)
            req = urllib.request.Request(url, headers={
                "Authorization": f"Bearer {self._token}", "Accept": "application/json",
                "Prefer": f"odata.maxpagesize={PAGE}"})
            try:
                with self._open(req, timeout=300) as r:
                    return json.load(r)
            except urllib.error.HTTPError as e:
                if e.code == 401 and not refreshed:
                    self._token, refreshed = None, True
                    continue
                if e.code in (401, 403):
                    raise D365Error(f"Dynamics refused the request (HTTP {e.code}): the identity needs read access "
                                    f"to {url.split('/data/')[1].split('?')[0]}") from e
                if e.code < 500 and e.code != 429:
                    raise D365Error(f"Dynamics returned HTTP {e.code} for {url.split('/data/')[1].split('?')[0]}") from e
                err = f"HTTP {e.code}"
            except (urllib.error.URLError, TimeoutError, ValueError, ConnectionError) as e:
                err = type(e).__name__
            if attempt == 5:
                raise D365Error(f"Dynamics did not answer after 5 tries ({err})")
            self._sleep(2.5 * attempt)
        raise D365Error("unreachable")  # pragma: no cover

    def rows(self, entity: str, columns):
        q = urllib.parse.urlencode({
            "$select": ",".join(columns), "$filter": f"dataAreaId eq '{self.legal_entity}'",
            "cross-company": "true"}, quote_via=urllib.parse.quote)
        url: str | None = f"{self.base}/data/{entity}?{q}"
        while url:
            page = self._get(url)
            yield from page.get("value", [])
            url = page.get("@odata.nextLink")


def _cell(v) -> str:
    if v is None:
        return ""
    if isinstance(v, bool):
        return "Yes" if v else "No"
    return str(v)


def pool_by_item(headers) -> dict[str, str]:
    """item -> pool of its most recent order that has a pool (latest ScheduledDate, then order number), else "?"."""
    best: dict[str, tuple] = {}
    items = set()
    for h in headers:
        item = h["ItemNumber"]
        items.add(item)
        if not h["ProductionPoolId"]:
            continue
        key = (h["ScheduledDate"], h["ProductionOrderNumber"])
        if item not in best or key > best[item][0]:
            best[item] = (key, h["ProductionPoolId"])
    return {i: (best[i][1] if i in best else "?") for i in sorted(items)}


def _write(path: Path, cols, rows):
    n = 0
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(cols)
        for r in rows:
            w.writerow(r)
            n += 1
    return n


def extract(folder: Path, client: Client | None = None, log=lambda m: None) -> dict[str, int]:
    """Write the six CSVs into folder; returns rows per file. Raises D365Error."""
    client = client or Client()
    folder = Path(folder)
    folder.mkdir(parents=True, exist_ok=True)
    counts: dict[str, int] = {}
    headers: list = []
    for name, (entity, cols) in ENTITIES.items():
        def gen(entity=entity, cols=cols, keep=(name == "production-order-headers-raw.csv")):
            for row in client.rows(entity, cols):
                out = [_cell(row.get(c)).strip() for c in cols]
                if keep:
                    headers.append(dict(zip(cols, out)))
                yield out
        counts[name] = _write(folder / name, cols, gen())
        log(f"extracted {entity}: {counts[name]} rows")
    pools = pool_by_item(headers)
    counts["manufactured-items.csv"] = _write(folder / "manufactured-items.csv", REQUIRED["manufactured-items.csv"],
                                              pools.items())
    log(f"derived manufactured-items: {counts['manufactured-items.csv']} items")
    return counts
