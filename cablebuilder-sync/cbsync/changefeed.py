"""Which designs changed: read from the CableBuilder message log in Dynamics.

CableBuilder pushes every design it sends to D365 through the CsCbiMessages
entity. Measured 2026-10-20: the item code is in `Name`, e.g.
"DesignBOMVersion: KN12X-PAXN/2C16OR[A0V0L0]", while `ConversationName` is
often a batch label ("Project: CODES SALES 19OCT26"), so key on Name. A day
carries a few hundred messages covering about a hundred items.

The entity is slow and $orderby makes it slower, so the query filters on
ConversationDateTime, selects two fields and pages with @odata.nextLink.

Token, in order: D365_TOKEN as given; else client credentials from
D365_TENANT_ID / D365_CLIENT_ID / D365_CLIENT_SECRET; else the App Service
managed identity (IDENTITY_ENDPOINT, present on the tc-web slots), which needs
no secret but does need the identity's client id added to D365's "Microsoft
Entra applications" list by a D365 administrator; else
`az account get-access-token` for whoever is signed in to the Azure CLI, which
is enough for running it by hand.
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import requests

D365 = os.environ.get("D365_URL", "https://tricab-prod.operations.dynamics.com")

# "<Type>: <code>[A<alt>V<ver>L<n>]" — the bracket is optional on some types.
NAME = re.compile(r"^\s*[^:]+:\s*(?P<code>.+?)\s*(?:\[A(?P<alt>\d+)V(?P<ver>\d+)(?:L\d+)?\])?\s*$")


@dataclass(frozen=True)
class Change:
    code: str
    alt: str | None
    ver: str | None


def parse_name(name: str | None) -> Change | None:
    m = NAME.match(name or "")
    if not m:
        return None
    return Change(m["code"], m["alt"], m["ver"])


def token() -> str:
    if os.environ.get("D365_TOKEN"):
        return os.environ["D365_TOKEN"]
    tenant, client, secret = (os.environ.get(k) for k in
                              ("D365_TENANT_ID", "D365_CLIENT_ID", "D365_CLIENT_SECRET"))
    if tenant and client and secret:
        r = requests.post(
            f"https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token",
            data={"grant_type": "client_credentials", "client_id": client,
                  "client_secret": secret, "scope": f"{D365}/.default"},
            timeout=60,
        )
        r.raise_for_status()
        return r.json()["access_token"]
    if os.environ.get("IDENTITY_ENDPOINT") and os.environ.get("IDENTITY_HEADER"):
        r = requests.get(
            os.environ["IDENTITY_ENDPOINT"],
            params={"resource": D365, "api-version": "2019-08-01"},
            headers={"X-IDENTITY-HEADER": os.environ["IDENTITY_HEADER"]},
            timeout=60,
        )
        r.raise_for_status()
        return r.json()["access_token"]
    az = shutil.which("az") or shutil.which("az.cmd")
    if az:
        out = subprocess.run(
            [az, "account", "get-access-token", "--resource", D365,
             "--query", "accessToken", "-o", "tsv"],
            capture_output=True, text=True, timeout=120,
        )
        if out.returncode == 0 and out.stdout.strip():
            return out.stdout.strip()
        raise SystemExit(f"az could not get a Dynamics token: {out.stderr.strip()[:300]}")
    raise SystemExit("No Dynamics credentials: set D365_TOKEN, the D365_* app registration, or az login.")


def messages_since(since: datetime, until: datetime | None = None,
                   bearer: str | None = None) -> list[dict]:
    """Raw {Name, ConversationDateTime} rows in [since, until)."""
    fmt = "%Y-%m-%dT%H:%M:%SZ"
    flt = f"ConversationDateTime ge {since.strftime(fmt)}"
    if until:
        flt += f" and ConversationDateTime lt {until.strftime(fmt)}"
    http = requests.Session()
    http.headers.update({"Authorization": f"Bearer {bearer or token()}",
                         "Accept": "application/json"})
    url: str | None = f"{D365}/data/CsCbiMessages"
    params: dict | None = {"$filter": flt, "$select": "Name,ConversationDateTime",
                           "cross-company": "true"}
    rows: list[dict] = []
    while url:
        r = http.get(url, params=params, timeout=300)
        r.raise_for_status()
        j = r.json()
        rows.extend(j.get("value", []))
        url, params = j.get("@odata.nextLink"), None  # nextLink carries the query
    return rows


def changes_since(since: datetime, until: datetime | None = None,
                  bearer: str | None = None) -> tuple[list[Change], str | None, int]:
    """Distinct changed items, the newest message time seen, and the message count."""
    rows = messages_since(since, until, bearer)
    seen: dict[str, Change] = {}
    newest = None
    for r in rows:
        c = parse_name(r.get("Name"))
        if c and c.code not in seen:
            seen[c.code] = c
        t = r.get("ConversationDateTime")
        if t and (newest is None or t > newest):
            newest = t
    return list(seen.values()), newest, len(rows)


def default_since(watermark: str | None, overlap_hours: int = 2) -> datetime:
    """Restart a little before the last message seen: re-pulling is free when
    nothing changed (the hash matches), missing a late-committed message is not."""
    if watermark:
        t = datetime.strptime(watermark[:19], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=timezone.utc)
        return t - timedelta(hours=overlap_hours)
    return datetime.now(timezone.utc) - timedelta(days=1)
