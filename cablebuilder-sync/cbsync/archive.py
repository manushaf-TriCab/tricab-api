"""The archive: every distinct design document CableBuilder has ever returned.

Layout in the store:

    designs/<code>/a<alt>v<ver>/<sha16>.<kind>.xml.gz   one blob per distinct body
    lookups/<tablecode>/<sha16>.xml.gz                  same idea for lookup tables
    index/designs.jsonl.gz                              the manifest (below)
    index/lookups.jsonl.gz
    state/changefeed.json                               Dynamics watermark
    state/rotate.json                                   backstop cursor
    runs/<utc>.json                                     one summary per run

<code> is the design code percent-encoded, so it is reversible. The first pull
used a lossy slug for file names, which could map two codes onto one file and
silently skip the second; this layout cannot.

A blob is written only when the body's SHA-256 is new for that design, so
re-pulling an unchanged design costs a manifest update and nothing else, and
the folder for a design is its change history. CableBuilder returns
byte-identical XML for an unchanged design (checked 2026-10-20 by fetching the
same design twice), which is what makes hashing the raw bytes sound.

The manifest holds one line per (code, alternative, version): the current
blob, when it was first and last seen, and every earlier blob. It is the
index the API and the later database load read, so nobody has to list 40k
prefixes to find the latest of anything.
"""

from __future__ import annotations

import gzip
import hashlib
import json
import xml.etree.ElementTree as ET
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from urllib.parse import quote

from .store import Store

DESIGN_INDEX = "index/designs.jsonl.gz"
LOOKUP_INDEX = "index/lookups.jsonl.gz"


def utcnow() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def encode(code: str) -> str:
    return quote(code, safe="-._")


def gz(data: bytes) -> bytes:
    return gzip.compress(data, mtime=0)  # mtime=0: same input, same bytes


def gunzip(data: bytes) -> bytes:
    return gzip.decompress(data)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


@dataclass
class Identity:
    code: str
    alt: str
    ver: str
    erp_itemid: str = ""


def identify(body: bytes) -> Identity | None:
    """Read who a design document is about from the document itself.

    Full documents (<design>) carry the code in header/design. Header-only
    documents (<designHeader>, from getDesignHeader) carry it as a direct child
    of the root, and also have a `header` child that does not hold it.
    """
    try:
        root = ET.fromstring(body)
    except ET.ParseError:
        return None
    code = (root.findtext("header/design") or root.findtext("design") or "").strip()
    if not code:
        return None
    return Identity(
        code=code,
        alt=(root.findtext("designAlternative") or "0").strip() or "0",
        ver=(root.findtext("version") or "0").strip() or "0",
        erp_itemid=(root.findtext("plantHeader/erp_itemid") or "").strip(),
    )


def sub_designs(body: bytes) -> list[tuple[str, str]]:
    """(code, alternative) of every BOM line that names another design.

    Lines in metres are sub-designs; lines in kilograms are purchased
    materials, which have no design to pull.
    """
    try:
        root = ET.fromstring(body)
    except ET.ParseError:
        return []
    out: list[tuple[str, str]] = []
    for b in root.iter("bom"):
        item = (b.findtext("bomitem") or "").strip()
        if item and (b.findtext("bomunit_erp") or "").strip().lower() == "m":
            out.append((item, (b.findtext("bomdesignalternative") or "0").strip() or "0"))
    return out


@dataclass
class Entry:
    code: str
    alt: str
    ver: str
    kind: str  # "full" or "header" (getDesign failed, getDesignHeader used)
    sha: str
    blob: str
    first_seen: str
    last_checked: str
    erp_itemid: str = ""
    history: list[dict] = field(default_factory=list)  # earlier {sha, kind, blob, first_seen}

    @property
    def key(self) -> tuple[str, str, str]:
        return (self.code, self.alt, self.ver)


class Archive:
    def __init__(self, store: Store) -> None:
        self.store = store
        self.designs: dict[tuple[str, str, str], Entry] = {}
        raw = store.get(DESIGN_INDEX)
        if raw:
            for line in gunzip(raw).decode("utf-8").splitlines():
                if line:
                    e = Entry(**json.loads(line))
                    self.designs[e.key] = e
        self.lookups: dict[str, dict] = {}
        raw = store.get(LOOKUP_INDEX)
        if raw:
            for line in gunzip(raw).decode("utf-8").splitlines():
                if line:
                    d = json.loads(line)
                    self.lookups[d["tablecode"]] = d
        self._dirty = False

    # ------------------------------------------------------------- designs

    def codes(self) -> set[str]:
        return {k[0] for k in self.designs}

    def by_erp_item(self) -> dict[str, str]:
        """D365 item id -> design code, for the 42 designs where they differ."""
        return {e.erp_itemid: e.code for e in self.designs.values() if e.erp_itemid}

    def record_design(self, body: bytes, kind: str, when: str | None = None,
                      fallback: Identity | None = None) -> tuple[str, Entry | None]:
        """Archive one design body. Returns (outcome, entry).

        outcome is "new", "changed", "unchanged" or "unidentified".
        """
        when = when or utcnow()
        ident = identify(body) or fallback
        if ident is None:
            return "unidentified", None
        digest = sha(body)
        key = (ident.code, ident.alt, ident.ver)
        cur = self.designs.get(key)
        self._dirty = True
        if cur and cur.sha == digest:
            cur.last_checked = when
            return "unchanged", cur

        blob = f"designs/{encode(ident.code)}/a{ident.alt}v{ident.ver}/{digest[:16]}.{kind}.xml.gz"
        if not self.store.exists(blob):
            self.store.put(blob, gz(body), {"sha256": digest, "pulled": when, "kind": kind})
        history = []
        if cur:
            history = cur.history + [
                {"sha": cur.sha, "kind": cur.kind, "blob": cur.blob, "first_seen": cur.first_seen}
            ]
        entry = Entry(
            code=ident.code, alt=ident.alt, ver=ident.ver, kind=kind, sha=digest,
            blob=blob, first_seen=when, last_checked=when,
            erp_itemid=ident.erp_itemid or (cur.erp_itemid if cur else ""),
            history=history,
        )
        self.designs[key] = entry
        return ("changed" if cur else "new"), entry

    def read_design(self, entry: Entry) -> bytes:
        data = self.store.get(entry.blob)
        if data is None:
            raise FileNotFoundError(entry.blob)
        return gunzip(data)

    # ------------------------------------------------------------- lookups

    def record_lookup(self, tablecode: str, body: bytes, meta: dict,
                      when: str | None = None) -> str:
        when = when or utcnow()
        digest = sha(body)
        cur = self.lookups.get(tablecode)
        self._dirty = True
        if cur and cur["sha"] == digest:
            cur["last_checked"] = when
            return "unchanged"
        blob = f"lookups/{tablecode}/{digest[:16]}.xml.gz"
        if not self.store.exists(blob):
            self.store.put(blob, gz(body), {"sha256": digest, "pulled": when})
        history = (cur.get("history", []) + [{"sha": cur["sha"], "blob": cur["blob"],
                                               "first_seen": cur["first_seen"]}]) if cur else []
        self.lookups[tablecode] = {
            "tablecode": tablecode, **meta, "sha": digest, "blob": blob,
            "first_seen": when, "last_checked": when, "history": history,
        }
        return "changed" if cur else "new"

    # ------------------------------------------------------------- state

    def save(self) -> None:
        if not self._dirty:
            return
        lines = "\n".join(
            json.dumps(asdict(e), ensure_ascii=False)
            for e in sorted(self.designs.values(), key=lambda e: e.key)
        )
        self.store.put(DESIGN_INDEX, gz(lines.encode("utf-8")))
        lines = "\n".join(
            json.dumps(d, ensure_ascii=False) for _, d in sorted(self.lookups.items())
        )
        self.store.put(LOOKUP_INDEX, gz(lines.encode("utf-8")))
        self._dirty = False

    def get_state(self, name: str) -> dict:
        raw = self.store.get(f"state/{name}.json")
        return json.loads(raw) if raw else {}

    def put_state(self, name: str, value: dict) -> None:
        self.store.put(f"state/{name}.json", json.dumps(value, indent=2).encode("utf-8"))

    def write_run(self, summary: dict) -> str:
        name = f"runs/{summary['started'].replace(':', '')}-{summary['command']}.json"
        self.store.put(name, json.dumps(summary, indent=2, ensure_ascii=False).encode("utf-8"))
        return name
