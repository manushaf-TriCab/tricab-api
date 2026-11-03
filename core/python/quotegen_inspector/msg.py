"""Outlook .msg inspection via extract-msg, where installed."""
import hashlib

from .limits import clip

try:
    import extract_msg
except Exception:  # not installed or unsupported on this platform
    extract_msg = None


def msg_overview(path):
    if extract_msg is None:
        return {"supported": False, "reason": "extract-msg is not installed", "unreadable": [{"reason": "msg unsupported"}],
                "truncated": False, "next_cursor": None}
    try:
        m = extract_msg.openMsg(path)
    except Exception as e:
        return {"supported": True, "error": f"cannot open: {e}", "unreadable": [{"reason": str(e)}],
                "truncated": False, "next_cursor": None}
    try:
        body, t1 = clip(m.body or "")
        html = m.htmlBody
        if isinstance(html, bytes):
            html = html.decode("utf-8", "replace")
        html, t2 = clip(html or "")
        atts = []
        for a in m.attachments:
            data = getattr(a, "data", None)
            if not isinstance(data, (bytes, bytearray)):
                data = b""
            atts.append({"name": getattr(a, "longFilename", None) or getattr(a, "shortFilename", None),
                         "size": len(data), "sha256": hashlib.sha256(data).hexdigest(),
                         "contentId": getattr(a, "cid", None)})
        return {"supported": True, "headers": {"From": m.sender, "To": m.to, "Cc": m.cc, "Subject": m.subject, "Date": str(m.date)},
                "text": body, "html": html, "attachments": atts, "truncated": t1 or t2, "next_cursor": None,
                "unreadable": [], "note": "derive an EML/HTML view if needed; keep the original"}
    finally:
        try:
            m.close()
        except Exception:
            pass
