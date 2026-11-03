"""Tool dispatch: maps (tool, args) to the inspectors, and render requests to PNG."""
import io

from . import docx as docx_mod
from . import eml, image, msg, pdf, xlsx
from .inventory import inventory

XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
XLS = "application/vnd.ms-excel"
DOCX = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"


def _req(args, *names):
    missing = [n for n in names if args.get(n) in (None, "")]
    if missing:
        raise ValueError("missing argument(s): " + ", ".join(missing))


def _text_read(path, args):
    with open(path, "rb") as f:
        data = f.read()
    text = data.decode("utf-8", "replace")
    start = int(args.get("cursor") or 0)
    chunk = text[start:start + 20000]
    end = start + len(chunk)
    more = end < len(text)
    return {"text": chunk, "chars": len(text), "truncated": more, "next_cursor": str(end) if more else None, "unreadable": []}


TOOLS = {
    "inventory": ("*", lambda p, a: inventory(p)),
    "eml_overview": ("message/rfc822", lambda p, a: eml.eml_overview(p, a.get("cursor"))),
    "eml_part": ("message/rfc822", lambda p, a: (_req(a, "partId"), eml.eml_part(p, a["partId"], a.get("cursor")))[1]),
    "msg_overview": ("application/vnd.ms-outlook", lambda p, a: msg.msg_overview(p)),
    "xlsx_overview": (XLSX, lambda p, a: xlsx.xlsx_overview(p)),
    "xlsx_read": (XLSX, lambda p, a: (_req(a, "sheet"), xlsx.xlsx_read(p, a["sheet"], a.get("range"), a.get("cursor"), a.get("maxCells")))[1]),
    "xlsx_images": (XLSX, lambda p, a: xlsx.xlsx_images(p)),
    "xls_overview": (XLS, lambda p, a: xlsx.xls_overview(p)),
    "xls_read": (XLS, lambda p, a: (_req(a, "sheet"), xlsx.xls_read(p, a["sheet"], a.get("range"), a.get("cursor"), a.get("maxCells")))[1]),
    "pdf_overview": ("application/pdf", lambda p, a: pdf.pdf_overview(p)),
    "pdf_text": ("application/pdf", lambda p, a: (_req(a, "page"), pdf.pdf_text(p, a["page"], a.get("cursor")))[1]),
    "pdf_tables": ("application/pdf", lambda p, a: (_req(a, "page"), pdf.pdf_tables(p, a["page"]))[1]),
    "pdf_annotations": ("application/pdf", lambda p, a: (_req(a, "page"), pdf.pdf_annotations(p, a["page"]))[1]),
    "docx_overview": (DOCX, lambda p, a: docx_mod.docx_overview(p, a.get("cursor"))),
    "image_info": ("image/*", lambda p, a: image.image_info(p)),
    "text_read": ("text/*", _text_read),
}


def run_tool(path, media_type, tool, args):
    if tool not in TOOLS:
        raise ValueError(f"unknown tool {tool!r}; available: {sorted(TOOLS)}")
    want, fn = TOOLS[tool]
    ok = want == "*" or media_type == want or (want.endswith("/*") and media_type.startswith(want[:-1]))
    if not ok:
        raise ValueError(f"tool {tool} does not apply to {media_type}")
    return fn(path, args or {})


def _grid_png(path, media_type, sheet, cell_range, scale):
    """Synthetic grid render of a sheet range (no LibreOffice needed); not a visual match to Excel."""
    from PIL import Image, ImageDraw
    data = (xlsx.xlsx_read if media_type == XLSX else xlsx.xls_read)(path, sheet, cell_range, None, 600)
    if "error" in data:
        raise ValueError(data["error"])
    from openpyxl.utils import coordinate_to_tuple
    refs = [coordinate_to_tuple(c["ref"]) for c in data["cells"]]
    if not refs:
        raise ValueError("range has no content to render")
    r0, c0 = min(r for r, _ in refs), min(c for _, c in refs)
    r1, c1 = max(r for r, _ in refs), max(c for _, c in refs)
    cw, ch = int(110 * scale), int(24 * scale)
    img = Image.new("RGB", ((c1 - c0 + 1) * cw, (r1 - r0 + 1) * ch), "white")
    d = ImageDraw.Draw(img)
    for r in range(r0, r1 + 1):
        for c in range(c0, c1 + 1):
            d.rectangle([(c - c0) * cw, (r - r0) * ch, (c - c0 + 1) * cw, (r - r0 + 1) * ch], outline=(200, 200, 200))
    for cell in data["cells"]:
        r, c = coordinate_to_tuple(cell["ref"])
        x, y = (c - c0) * cw, (r - r0) * ch
        fill = cell.get("fill")
        if isinstance(fill, str) and len(fill) == 8 and fill != "00000000":
            d.rectangle([x + 1, y + 1, x + cw - 1, y + ch - 1], fill="#" + fill[2:])
        if cell.get("value") is not None:
            d.text((x + 4, y + 5), str(cell["value"])[:int(16 * scale) + 2], fill="black")
    buf = io.BytesIO()
    img.save(buf, "PNG")
    return buf.getvalue(), {"renderedBy": "synthetic-grid", "width": img.width, "height": img.height,
                            "note": "approximate grid of values and fills, not a screen capture"}


def render(path, media_type, args):
    scale = float(args.get("scale") or 1.5)
    if media_type == "application/pdf":
        png, meta = pdf.render_pdf(path, int(args.get("page") or 1), scale, args.get("region"))
        return png, {**meta, "renderedBy": "pdfium"}
    if media_type.startswith("image/"):
        png, meta = image.image_png(path, int(args.get("maxSide") or 1568))
        return png, {**meta, "renderedBy": "pillow"}
    if media_type == DOCX:
        tmp = docx_mod.convert_to_pdf(path)
        png, meta = pdf.render_pdf(tmp, int(args.get("page") or 1), scale, args.get("region"))
        return png, {**meta, "renderedBy": "libreoffice+pdfium"}
    if media_type in (XLSX, XLS):
        if args.get("sheet"):
            return _grid_png(path, media_type, args["sheet"], args.get("range"), min(scale, 3))
        tmp = docx_mod.convert_to_pdf(path)
        png, meta = pdf.render_pdf(tmp, int(args.get("page") or 1), scale, args.get("region"))
        return png, {**meta, "renderedBy": "libreoffice+pdfium"}
    raise ValueError(f"no renderer for {media_type}")
