"""DOCX structure via python-docx; page rendering via LibreOffice (container only)."""
import os
import shutil
import subprocess
import tempfile

from .limits import MAX_ITEMS, clip


def docx_overview(path, cursor=None):
    import docx
    d = docx.Document(path)
    paras = [{"index": i, "style": p.style.name if p.style is not None else None, "text": p.text}
             for i, p in enumerate(d.paragraphs) if p.text.strip()]
    start = int(cursor or 0)
    chunk = paras[start:start + MAX_ITEMS]
    end = start + len(chunk)
    more = end < len(paras)
    tables = []
    for ti, t in enumerate(d.tables[:MAX_ITEMS]):
        rows = [[c.text for c in r.cells] for r in t.rows[:MAX_ITEMS]]
        tables.append({"index": ti, "rowCount": len(t.rows), "rows": rows, "rowsTruncated": len(t.rows) > MAX_ITEMS})
    images = [{"partName": str(r.target_part.partname), "contentType": r.target_part.content_type,
               "size": len(r.target_part.blob)} for r in d.part.rels.values() if "image" in r.reltype]
    return {"paragraphs": chunk, "paragraphCount": len(paras), "tables": tables, "tableCount": len(d.tables),
            "tablesTruncated": len(d.tables) > MAX_ITEMS, "images": images[:MAX_ITEMS],
            "truncated": more, "next_cursor": str(end) if more else None, "unreadable": []}


def soffice_available():
    return shutil.which("soffice") is not None


def convert_to_pdf(path, timeout=90):
    """Convert office doc to PDF with headless LibreOffice. Returns PDF path (caller owns tmp dir)."""
    if not soffice_available():
        raise RuntimeError("LibreOffice (soffice) is not installed; rendering only works inside the container")
    out = tempfile.mkdtemp(prefix="qgconv-")
    profile = tempfile.mkdtemp(prefix="qgprofile-")
    src = os.path.join(out, "in" + (os.path.splitext(path)[1] or ".docx"))
    shutil.copy(path, src)
    subprocess.run(["soffice", f"-env:UserInstallation=file://{profile}", "--headless", "--convert-to", "pdf", "--outdir", out, src],
                   check=True, timeout=timeout, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    shutil.rmtree(profile, ignore_errors=True)
    pdf = os.path.join(out, "in.pdf")
    if not os.path.exists(pdf):
        raise RuntimeError("LibreOffice produced no PDF")
    return pdf
