"""EML inspection with the standard library `email` package."""
import hashlib
import re
from email import policy
from email.parser import BytesParser
from html.parser import HTMLParser

from .limits import MAX_ITEMS, MAX_NESTED_DEPTH, clip, page

_CID_RE = re.compile(r"cid:([^\"'\s>)]+)", re.I)


def _load(path):
    with open(path, "rb") as f:
        return BytesParser(policy=policy.default).parse(f)


class _Tables(HTMLParser):
    """Collect tables as row/cell text and plain text with row structure kept."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.tables, self._stack, self.text, self._skip = [], [], [], 0
        self._cell = None

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style"):
            self._skip += 1
        elif tag == "table":
            self._stack.append([])
        elif tag == "tr" and self._stack:
            self._stack[-1].append([])
        elif tag in ("td", "th") and self._stack and self._stack[-1]:
            self._cell = []
        elif tag in ("br", "p", "div"):
            self.text.append("\n")

    def handle_endtag(self, tag):
        if tag in ("script", "style"):
            self._skip = max(0, self._skip - 1)
        elif tag in ("td", "th") and self._cell is not None and self._stack:
            self._stack[-1][-1].append(" ".join("".join(self._cell).split()))
            self._cell = None
            self.text.append("\t")
        elif tag == "tr":
            self.text.append("\n")
        elif tag == "table" and self._stack:
            self.tables.append(self._stack.pop())

    def handle_data(self, data):
        if self._skip:
            return
        if self._cell is not None:
            self._cell.append(data)
        self.text.append(data)


def html_structure(html):
    p = _Tables()
    try:
        p.feed(html)
    except Exception as e:  # malformed HTML: report, keep raw
        return {"tables": [], "text": "", "error": str(e)}
    text = re.sub(r"\n{3,}", "\n\n", "".join(p.text)).strip()
    return {"tables": p.tables, "text": text}


def _walk(msg, prefix, depth, out, unreadable):
    """Flatten the MIME tree; nested message/rfc822 are descended into."""
    parts = list(msg.iter_parts()) if msg.is_multipart() else []
    pid = prefix
    node = {
        "partId": pid, "contentType": msg.get_content_type(), "depth": depth,
        "disposition": msg.get_content_disposition(),
        "filename": msg.get_filename(), "contentId": (msg.get("Content-ID") or "").strip("<> ") or None,
        "multipart": msg.is_multipart(),
    }
    out.append(node)
    if depth >= MAX_NESTED_DEPTH:
        unreadable.append({"partId": pid, "reason": f"nesting depth limit {MAX_NESTED_DEPTH}"})
        return
    if msg.get_content_type() == "message/rfc822":
        inner = msg.get_payload(0) if msg.is_multipart() else None
        if inner is not None:
            node["nestedSubject"] = str(inner.get("Subject", ""))
            _walk(inner, pid + ".1", depth + 1, out, unreadable)
        return
    for n, child in enumerate(parts, 1):
        _walk(child, f"{pid}.{n}", depth + 1, out, unreadable)


def _find(msg, part_id, prefix="1"):
    if prefix == part_id:
        return msg
    if msg.get_content_type() == "message/rfc822" and msg.is_multipart():
        return _find(msg.get_payload(0), part_id, prefix + ".1") if part_id.startswith(prefix + ".") else None
    for n, child in enumerate(msg.iter_parts() if msg.is_multipart() else [], 1):
        pid = f"{prefix}.{n}"
        if part_id == pid or part_id.startswith(pid + "."):
            return _find(child, part_id, pid)
    return None


def _payload(part):
    try:
        data = part.get_payload(decode=True)
    except Exception:
        data = None
    if data is None and part.get_content_type() == "message/rfc822" and part.is_multipart():
        data = part.get_payload(0).as_bytes()
    return data or b""


def _headers(msg):
    keep = ("From", "To", "Cc", "Bcc", "Reply-To", "Subject", "Date", "Message-ID", "In-Reply-To", "References", "Return-Path")
    h = {}
    for k in keep:
        v = msg.get_all(k)
        if v:
            h[k] = [str(x) for x in v] if len(v) > 1 else str(v[0])
    return h


def eml_overview(path, cursor=None):
    msg = _load(path)
    nodes, unreadable = [], []
    _walk(msg, "1", 0, nodes, unreadable)
    atts, cids, html_refs = [], {}, {}
    byid = {}
    for n in nodes:
        part = _find(msg, n["partId"])
        byid[n["partId"]] = part
        if n["multipart"] and n["contentType"] != "message/rfc822":
            continue
        data = _payload(part)
        n["size"] = len(data)
        if n["contentType"] in ("text/plain", "text/html") and n["disposition"] != "attachment":
            n["charset"] = part.get_content_charset()
            continue
        if n["contentType"] == "message/rfc822" or n["disposition"] == "attachment" or n["filename"] or n["contentId"]:
            if n["contentType"] == "message/rfc822" and not n["filename"] and n["disposition"] is None:
                continue
            rec = {"partId": n["partId"], "name": n["filename"], "contentType": n["contentType"],
                   "size": len(data), "sha256": hashlib.sha256(data).hexdigest(), "contentId": n["contentId"],
                   "inline": n["disposition"] == "inline" or (n["disposition"] is None and bool(n["contentId"]))}
            atts.append(rec)
            if n["contentId"]:
                cids[n["contentId"]] = n["partId"]
    for n in nodes:
        if n["contentType"] == "text/html" and n["disposition"] != "attachment":
            html = _payload(byid[n["partId"]]).decode(n.get("charset") or "utf-8", "replace")
            refs = sorted(set(m.group(1) for m in _CID_RE.finditer(html)))
            html_refs[n["partId"]] = [{"cid": c, "partId": cids.get(c), "resolved": c in cids} for c in refs]
    tree, trunc, nxt = page(nodes, cursor)
    return {
        "headers": _headers(msg), "tree": tree, "partCount": len(nodes),
        "attachments": atts[:MAX_ITEMS], "attachmentsTruncated": len(atts) > MAX_ITEMS,
        "cidReferences": html_refs, "hasTextPlain": any(n["contentType"] == "text/plain" and n["disposition"] != "attachment" for n in nodes),
        "hasTextHtml": any(n["contentType"] == "text/html" and n["disposition"] != "attachment" for n in nodes),
        "truncated": trunc, "next_cursor": nxt, "unreadable": unreadable,
    }


def eml_part(path, part_id, cursor=None, max_chars=None):
    """Decoded content of one part. Text/HTML come back decoded; HTML also with
    its tables and a row-preserving text view. Binary parts return metadata only."""
    msg = _load(path)
    part = _find(msg, part_id)
    if part is None:
        return {"error": f"no such part {part_id}", "unreadable": [{"partId": part_id, "reason": "not found"}],
                "truncated": False, "next_cursor": None}
    ctype = part.get_content_type()
    data = _payload(part)
    res = {"partId": part_id, "contentType": ctype, "size": len(data), "unreadable": []}
    if ctype in ("text/plain", "text/html"):
        text = data.decode(part.get_content_charset() or "utf-8", "replace")
        start = int(cursor or 0)
        limit = max_chars or 20000
        chunk = text[start:start + limit]
        end = start + len(chunk)
        res.update(text=chunk, truncated=end < len(text), next_cursor=str(end) if end < len(text) else None)
        if ctype == "text/html":
            s = html_structure(text)
            res["tables"] = s["tables"][:MAX_ITEMS]
            res["tablesTruncated"] = len(s["tables"]) > MAX_ITEMS
            res["structuredText"], _ = clip(s["text"])
            res["structuredTextTruncated"] = len(s["text"]) > len(res["structuredText"])
    else:
        res.update(text=None, truncated=False, next_cursor=None, note="binary part; use files endpoint to fetch")
        if ctype == "message/rfc822" and part.is_multipart():
            inner = part.get_payload(0)
            res["nestedHeaders"] = _headers(inner)
    return res


def eml_extract(path, part_id):
    """Raw decoded bytes of a part (attachment) and its metadata name."""
    part = _find(_load(path), part_id)
    if part is None:
        return None, None
    return _payload(part), part.get_filename()
