"""PDF inspection: text, tables, annotations, and page/region rendering."""
import io

import pdfplumber
import pypdfium2 as pdfium

from .limits import MAX_ITEMS, MAX_PAGES_RENDERED, MAX_RENDER_PIXELS, MAX_TEXT_CHARS, LimitError, clip

LOW_TEXT_CHARS = 25


def pdf_overview(path):
    unreadable = []
    pages = []
    with pdfplumber.open(path) as pdf:
        n = len(pdf.pages)
        for i, p in enumerate(pdf.pages[:MAX_ITEMS], 1):
            try:
                chars = len((p.extract_text() or "").strip())
                pages.append({"page": i, "width": float(p.width), "height": float(p.height), "textChars": chars,
                              "likelyScanned": chars < LOW_TEXT_CHARS, "images": len(p.images),
                              "annotations": len(p.annots or [])})
            except Exception as e:
                unreadable.append({"page": i, "reason": str(e)})
    trunc = n > MAX_ITEMS
    return {"pageCount": n, "pages": pages, "truncated": trunc, "next_cursor": None,
            "unreadable": unreadable + ([{"reason": f"pages beyond {MAX_ITEMS} not summarised"}] if trunc else []),
            "likelyScannedPages": [p["page"] for p in pages if p["likelyScanned"]]}


def _check_page(pdf, page):
    if not 1 <= page <= len(pdf.pages):
        raise ValueError(f"page {page} out of range 1..{len(pdf.pages)}")
    return pdf.pages[page - 1]


def pdf_text(path, page, cursor=None):
    with pdfplumber.open(path) as pdf:
        p = _check_page(pdf, int(page))
        text = p.extract_text() or ""
    start = int(cursor or 0)
    chunk = text[start:start + MAX_TEXT_CHARS]
    end = start + len(chunk)
    more = end < len(text)
    return {"page": int(page), "text": chunk, "chars": len(text), "likelyScanned": len(text.strip()) < LOW_TEXT_CHARS,
            "truncated": more, "next_cursor": str(end) if more else None, "unreadable": []}


def pdf_tables(path, page):
    unreadable = []
    with pdfplumber.open(path) as pdf:
        p = _check_page(pdf, int(page))
        try:
            tables = p.extract_tables()
        except Exception as e:
            tables, unreadable = [], [{"page": int(page), "reason": str(e)}]
    out = [{"rows": t[:MAX_ITEMS], "rowsTruncated": len(t) > MAX_ITEMS} for t in tables[:MAX_ITEMS]]
    return {"page": int(page), "tables": out, "truncated": len(tables) > MAX_ITEMS, "next_cursor": None, "unreadable": unreadable}


def pdf_annotations(path, page):
    with pdfplumber.open(path) as pdf:
        p = _check_page(pdf, int(page))
        annots = [{"type": str(a.get("data", {}).get("Subtype", "")), "contents": str(a.get("contents") or ""),
                   "uri": a.get("uri"), "bbox": [float(a["x0"]), float(a["top"]), float(a["x1"]), float(a["bottom"])]}
                  for a in (p.annots or [])]
    return {"page": int(page), "annotations": annots[:MAX_ITEMS], "truncated": len(annots) > MAX_ITEMS,
            "next_cursor": None, "unreadable": []}


def render_pdf(path, page=1, scale=1.5, region=None):
    """Render one page (or a region [x0,top,x1,bottom] in PDF points) to PNG bytes."""
    scale = float(scale)
    if not 0.1 <= scale <= 6:
        raise ValueError("scale must be between 0.1 and 6")
    doc = pdfium.PdfDocument(path)
    try:
        n = len(doc)
        if not 1 <= int(page) <= n:
            raise ValueError(f"page {page} out of range 1..{n}")
        pg = doc[int(page) - 1]
        pw, ph = pg.get_size()
        crop = (0, 0, 0, 0)
        w, h = pw, ph
        if region:
            x0, top, x1, bottom = [float(v) for v in region]
            x0, x1 = max(0.0, min(x0, x1)), min(pw, max(x0, x1))
            top, bottom = max(0.0, min(top, bottom)), min(ph, max(top, bottom))
            if x1 - x0 < 1 or bottom - top < 1:
                raise ValueError("region is empty or outside the page")
            crop = (x0, ph - bottom, pw - x1, top)  # margins: left, bottom, right, top
            w, h = x1 - x0, bottom - top
        if w * scale > MAX_RENDER_PIXELS or h * scale > MAX_RENDER_PIXELS:
            scale = min(MAX_RENDER_PIXELS / w, MAX_RENDER_PIXELS / h)
        img = pg.render(scale=scale, crop=crop).to_pil()
    finally:
        doc.close()
    buf = io.BytesIO()
    img.save(buf, "PNG")
    return buf.getvalue(), {"width": img.width, "height": img.height, "page": int(page), "pageCount": n, "scale": scale}


def render_pages_budget(count):
    if count > MAX_PAGES_RENDERED:
        raise LimitError(f"at most {MAX_PAGES_RENDERED} pages per render call")
