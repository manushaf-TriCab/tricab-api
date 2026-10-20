"""Read-only client for the CableBuilder Live servlet.

Same interface the first pull used (planning/cablebuilder/pull.py): every UI
page is a GET with requestType=<x>&xsl=<y>; leaving xsl out returns the XML the
page was built from.

Auth is a JSESSIONID session. With CB_USER and CB_PASSWORD set, the client
signs in itself the way the browser does: the site root meta-refreshes to
requestType=logOn&xsl=login.xsl, a plain HTML form that POSTs requestType,
xsl, requestURL, userid, password, useLangCode and colour back to the servlet
(form read 2026-10-20). If the session lapses mid-run, the next request comes
back as that login page, and the client signs in again once and retries.
Without credentials it falls back to CB_COOKIE, a session copied from a
browser, which expires. Use a dedicated read-only account: signing in as the
shared one disconnects whoever is using it.
"""

from __future__ import annotations

import os
import time
import xml.etree.ElementTree as ET

import requests

BASE = os.environ.get(
    "CB_BASE",
    "http://40.115.95.39:8080/CableBuilderLive/servlet/em.cabbench.CabBenchSrv",
)

STANDARDS = [
    "Tables",
    "Conductors",
    "Cable - General Wire",
    "Cable - Low Voltage Power",
    "Cable - Medium Voltage Power",
    "Import Cables",
    "Raw Materials",
]


class SessionRejected(RuntimeError):
    pass


def normalise_cookie(raw: str) -> str:
    """Accept the bare value, JSESSIONID=value, a whole Cookie header, quotes,
    and the UTF-8 BOM PowerShell 5.1 writes (see pull.py for the history)."""
    c = raw.replace("﻿", "").strip().strip("\"'").strip()
    if c and "=" not in c:
        c = f"JSESSIONID={c}"
    return c


def is_xml(body: bytes) -> bool:
    return body.lstrip().startswith(b"<?xml")


def is_login_page(body: bytes) -> bool:
    """The sign-in form, as opposed to a servlet error page (getDesign's
    NumberFormatException also comes back as non-XML and must not trigger a
    sign-in)."""
    head = body[:20000]
    return b'name="userid"' in head or b"Sign In to CableBuilder" in head


class CableBuilder:
    def __init__(self, cookie: str | None = None, pause: float = 0.4,
                 base: str = BASE, user: str | None = None,
                 password: str | None = None) -> None:
        self.base = base
        self.pause = pause  # this is a shared production server
        self.http = requests.Session()
        self.requests = 0
        self.user = user if user is not None else os.environ.get("CB_USER", "")
        self._password = password if password is not None else os.environ.get("CB_PASSWORD", "")
        if self.user and self._password:
            self.sign_in()
            return
        c = normalise_cookie(cookie if cookie is not None else os.environ.get("CB_COOKIE", ""))
        if not c:
            raise SystemExit("Set CB_USER and CB_PASSWORD, or CB_COOKIE "
                             "(JSESSIONID from a logged-in browser).")
        self.http.headers["Cookie"] = c

    def sign_in(self) -> None:
        """Sign in with CB_USER / CB_PASSWORD. Raises SessionRejected if the
        server answers with the login form again (wrong credentials)."""
        self.http.cookies.clear()
        # Fetch the form first, as a browser does: it issues the session cookie.
        self.http.get(self.base, params={"requestType": "logOn", "xsl": "login.xsl"}, timeout=60)
        r = self.http.post(self.base, timeout=60, data={
            "requestType": "logOn", "xsl": "login.xsl", "requestURL": "",
            "userid": self.user, "password": self._password,
            "useLangCode": "", "colour": "0",
        })
        self.requests += 2
        time.sleep(self.pause)
        if is_login_page(r.content):
            raise SessionRejected(f"CableBuilder sign-in as {self.user!r} was refused.")

    def get(self, params: dict, retries: int = 3, _signed_in_again: bool = False) -> bytes:
        last: Exception | None = None
        for attempt in range(retries):
            try:
                r = self.http.get(self.base, params=params, timeout=120)
                self.requests += 1
                time.sleep(self.pause)
                break
            except requests.RequestException as exc:
                last = exc
                time.sleep(2 * (attempt + 1))
        else:
            raise RuntimeError(f"failed after {retries} attempts: {params} -> {last}")
        if (self.user and self._password and not _signed_in_again
                and not is_xml(r.content) and is_login_page(r.content)):
            self.sign_in()  # the session lapsed: sign in again once, then retry
            return self.get(params, retries, _signed_in_again=True)
        return r.content

    def check(self) -> int:
        body = self.get({"requestType": "getLookupTableList", "standard": "Tables"})
        if not is_xml(body):
            raise SessionRejected("CableBuilder returned a non-XML page: the session is not valid.")
        return len(ET.fromstring(body).findall("lookuptable"))

    # ------------------------------------------------------------- designs

    def design(self, code: str, alt: str, ver: str) -> tuple[str, bytes | None]:
        """("full", xml), ("header", xml) when getDesign fails, or ("failed", None).

        getDesign fails on some designs with NumberFormatException (a blank BOM
        alternative somewhere below); the header is usually still readable.
        """
        args = {"design": code, "designAlternative": alt, "version": ver}
        body = self.get({"requestType": "getDesign", **args})
        if is_xml(body):
            return "full", body
        head = self.get({"requestType": "getDesignHeader", **args})
        if is_xml(head):
            return "header", head
        return "failed", None

    def search_page(self, page: int, rows: int = 200) -> tuple[list[tuple[str, str, str]], int]:
        """One page of the design list as (code, alternative, version), plus totalRows.

        Each quick<field> must be paired with shouldmust<field>, or Lucene throws.
        """
        body = self.get({
            "requestType": "getSearchResults", "pageNumber": str(page),
            "rowsPerPage": str(rows), "resubmit": "true",
            "quickdesign": "*", "shouldmustdesign": "MUST",
            "quicklevel": "single", "shouldmustlevel": "MUST",
        })
        if not is_xml(body):
            raise SessionRejected(f"design list page {page} returned non-XML")
        root = ET.fromstring(body)
        total = int((root.findtext("counters/totalRows") or "0").strip() or 0)
        out = []
        for el in root.findall("headerResults/design"):
            code = (el.findtext("design") or "").strip()
            if code:
                out.append((code,
                            (el.findtext("designAlternative") or "0").strip() or "0",
                            (el.findtext("version") or "0").strip() or "0"))
        return out, total

    # ------------------------------------------------------------- lookups

    def lookup_list(self) -> list[dict]:
        rows = []
        for std in STANDARDS:
            body = self.get({"requestType": "getLookupTableList", "standard": std})
            if not is_xml(body):
                raise SessionRejected(f"lookup list for {std} returned non-XML")
            for el in ET.fromstring(body).findall("lookuptable"):
                g = lambda n: (el.findtext(n) or "").strip()  # noqa: E731
                rows.append({"tablecode": g("tablecode"), "description": g("description"),
                             "standard": g("standard"), "plant": g("plant")})
        return rows

    def lookup(self, tablecode: str) -> bytes | None:
        body = self.get({"requestType": "getLookupTable", "tablecode": tablecode})
        return body if is_xml(body) else None
